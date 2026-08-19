<?php
/**
 * The submission endpoint.
 *
 * LeadKit shipped without one: the form POSTed to whatever `submit_url` named,
 * and the reference implementation was a Cloudflare Pages Function. That is
 * fine until the site moves off Cloudflare — at which point `/api/submit`
 * becomes a 404, every visitor who fills the form lands on "Page not found",
 * and no email, no record and no error is produced anywhere. It failed silently
 * on a live site and the only symptom was an inbox that had gone quiet.
 *
 * So the server side lives in the plugin now, and travels with it.
 *
 * ONE route, TWO answers, because the form must work without JavaScript:
 *
 *   fetch / XHR      JSON  { ok, message }
 *   a plain form     303 back to the page it came from, with ?leadkit=sent
 *
 * The order of operations is the important part. The lead is STORED first and
 * notified second, and the notification result is recorded on the lead. Mail is
 * the part that fails — a wrong From address, a host with no sendmail, a
 * throttled relay — and a lead that exists only inside a mail attempt is a lead
 * you lose without knowing.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_REST_NS    = 'leadkit/v1';
const LEADKIT_REST_ROUTE = '/submit';

/**
 * The endpoint the form should point at on this site.
 *
 * @return string
 */
function leadkit_submit_endpoint() {
	return rest_url( LEADKIT_REST_NS . LEADKIT_REST_ROUTE );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			LEADKIT_REST_ROUTE,
			array(
				'methods'  => 'POST',
				'callback' => 'leadkit_handle_submit',
				/*
				 * Public by design — this is a contact form. The protections
				 * are a honeypot, a per-IP rate limit and Turnstile, not a
				 * capability check.
				 */
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Did this request come from fetch(), or from a browser submitting a form?
 *
 * @param WP_REST_Request $req Request.
 * @return bool
 */
function leadkit_wants_json( $req ) {
	$accept = (string) $req->get_header( 'accept' );
	return false !== stripos( $accept, 'application/json' )
		|| 'xmlhttprequest' === strtolower( (string) $req->get_header( 'x-requested-with' ) );
}

/**
 * Handle a submission.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function leadkit_handle_submit( $req ) {
	$opts   = leadkit_options();
	$fields = array(
		'name'    => sanitize_text_field( (string) $req->get_param( 'name' ) ),
		'email'   => sanitize_email( (string) $req->get_param( 'email' ) ),
		'phone'   => sanitize_text_field( (string) $req->get_param( 'phone' ) ),
		'type'    => sanitize_text_field( (string) $req->get_param( 'projectType' ) ),
		'message' => sanitize_textarea_field( (string) $req->get_param( 'message' ) ),
	);
	$referer = wp_get_referer() ?: home_url( '/' );

	/*
	 * The honeypot. A field a person never sees and never fills; a bot fills
	 * everything. Answer 200 and pretend it worked — telling a bot it failed
	 * only teaches it what to change.
	 */
	if ( '' !== trim( (string) $req->get_param( 'leadkit_hp' ) ) ) {
		return leadkit_answer( $req, true, __( 'Thank you — we will be in touch shortly.', 'leadkit' ), $referer );
	}

	if ( '' === $fields['name'] || ! is_email( $fields['email'] ) || '' === $fields['message'] ) {
		return leadkit_answer( $req, false, __( 'Please add your name, a valid email address, and a message.', 'leadkit' ), $referer );
	}

	// One submission per IP per 30s. Stops a stuck submit button becoming ten leads.
	$ip  = leadkit_client_ip();
	$key = 'leadkit_rl_' . md5( $ip );
	if ( $ip && get_transient( $key ) ) {
		return leadkit_answer( $req, false, __( 'That has already been sent — give us a moment.', 'leadkit' ), $referer );
	}
	if ( $ip ) {
		set_transient( $key, 1, 30 );
	}

	// Turnstile, only when a secret is configured. A site key alone verifies nothing.
	$turnstile_secret = leadkit_turnstile_secret();
	if ( '' !== $turnstile_secret ) {
		$token = (string) $req->get_param( 'cf-turnstile-response' );
		if ( ! leadkit_verify_turnstile( $token, $turnstile_secret, $ip ) ) {
			return leadkit_answer( $req, false, __( 'Could not verify that you are human. Please try again.', 'leadkit' ), $referer );
		}
	}

	// ---- store first -----------------------------------------------------
	$post_id = wp_insert_post(
		array(
			'post_type'   => LEADKIT_CPT,
			'post_status' => 'publish',
			'post_title'  => $fields['name'] . ( $fields['type'] ? ' — ' . $fields['type'] : '' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return leadkit_answer( $req, false, __( 'Something went wrong saving your message. Please call us instead.', 'leadkit' ), $referer );
	}

	$analytics = (string) $req->get_param( 'analyticsData' );
	update_post_meta( $post_id, '_leadkit_name', $fields['name'] );
	update_post_meta( $post_id, '_leadkit_email', $fields['email'] );
	update_post_meta( $post_id, '_leadkit_phone', $fields['phone'] );
	update_post_meta( $post_id, '_leadkit_project_type', $fields['type'] );
	update_post_meta( $post_id, '_leadkit_message', $fields['message'] );
	update_post_meta( $post_id, '_leadkit_source_url', esc_url_raw( $referer ) );
	update_post_meta( $post_id, '_leadkit_ip', $ip );
	if ( $analytics ) {
		update_post_meta( $post_id, '_leadkit_analytics', wp_json_encode( json_decode( $analytics, true ) ?: $analytics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	// ---- then notify -----------------------------------------------------
	leadkit_notify( $post_id, $fields, $opts );

	/**
	 * Fires after a lead has been stored and the notification attempted.
	 *
	 * @param int   $post_id Lead post ID.
	 * @param array $fields  Sanitised fields.
	 */
	do_action( 'leadkit_lead_received', $post_id, $fields );

	return leadkit_answer( $req, true, __( 'Thank you — we will be in touch shortly.', 'leadkit' ), $referer );
}

/**
 * Email the lead, and record on the lead whether that worked.
 *
 * @param int   $post_id Lead post ID.
 * @param array $fields  Sanitised fields.
 * @param array $opts    Plugin options.
 * @return void
 */
function leadkit_notify( $post_id, $fields, $opts ) {
	/*
	 * Several recipients, because a lead usually needs to reach more than one
	 * person — the owner and whoever handles the enquiries. One wp_mail() call
	 * with a list, not one call each: a single message with everyone on it
	 * means a reply is visible to all of them, and it is one send against the
	 * provider's rate limit rather than N.
	 */
	$to = array_filter( array_map( 'trim', explode( ',', (string) $opts['notify_email'] ) ) );
	if ( ! $to ) {
		$to = array( get_option( 'admin_email' ) );
	}

	/*
	 * From must be an address the SENDER is authorised to use — which is not
	 * the same as "this site's domain", and assuming it was is how the first
	 * version of this got it wrong. rogerenglandhomeremodeling.com has no SPF,
	 * no DKIM and no DMARC, so `wordpress@` there is precisely the shape of a
	 * forgery and Gmail treats it accordingly.
	 *
	 * So it is configurable, and falls back to the site domain only because
	 * something has to be there. Set it to an address on the domain verified
	 * with your mail provider (Settings → LeadKit).
	 *
	 * The visitor's own address never goes here. It goes in Reply-To, where
	 * hitting Reply still reaches them and no authentication check is failed.
	 */
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$host = preg_replace( '/^www\./', '', (string) $host );
	$from = $opts['from_email'] ?: 'wordpress@' . $host;
	$from_name = $opts['from_name'] ?: get_bloginfo( 'name' );
	$subject = sprintf(
		/* translators: 1: visitor name, 2: project type */
		__( 'New enquiry — %1$s%2$s', 'leadkit' ),
		$fields['name'],
		$fields['type'] ? ' (' . $fields['type'] . ')' : ''
	);

	/*
	 * HTML, with a plain-text alternative below it in the same body.
	 *
	 * The journey is the reason this email is worth reading — which page they
	 * lingered on, which photographs they opened, whether they tapped the phone
	 * number before writing. None of that survives as plain text, and a lead
	 * notification that omits it is just a name and a number.
	 */
	$journey = leadkit_render_journey( (string) get_post_meta( $post_id, '_leadkit_analytics', true ) );
	$ink     = '#1d2327';
	$muted   = '#646970';
	$line    = '#dcdcde';

	$rows = array(
		__( 'Name', 'leadkit' )    => esc_html( $fields['name'] ),
		__( 'Email', 'leadkit' )   => '<a href="mailto:' . esc_attr( $fields['email'] ) . '" style="color:#2271b1">' . esc_html( $fields['email'] ) . '</a>',
		__( 'Phone', 'leadkit' )   => $fields['phone'] ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $fields['phone'] ) ) . '" style="color:#2271b1">' . esc_html( $fields['phone'] ) . '</a>' : '&mdash;',
		__( 'Project', 'leadkit' ) => esc_html( $fields['type'] ?: '—' ),
	);

	$body = '<div style="background:#f0f0f1;padding:20px 0;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;margin:0 auto;background:#fff;border:1px solid ' . $line . ';border-collapse:collapse">'
		. '<tr><td style="padding:22px 24px 0">'
			. '<div style="font-size:19px;font-weight:700;color:' . $ink . '">' . esc_html__( 'New enquiry', 'leadkit' ) . '</div>'
			. '<div style="height:3px;background:' . $ink . ';margin:10px 0 0;width:56px"></div>'
		. '</td></tr>'
		. '<tr><td style="padding:18px 24px 0">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px">';

	foreach ( $rows as $label => $value ) {
		$body .= '<tr>'
			. '<td width="90" style="padding:5px 0;color:' . $muted . ';vertical-align:top">' . esc_html( $label ) . '</td>'
			. '<td style="padding:5px 0;color:' . $ink . '">' . $value . '</td>'
			. '</tr>';
	}

	$body .= '</table></td></tr>'
		. '<tr><td style="padding:16px 24px 0">'
			. '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . $muted . ';padding:0 0 6px">' . esc_html__( 'What they wrote', 'leadkit' ) . '</div>'
			. '<div style="background:#f6f7f7;border-left:4px solid ' . $ink . ';padding:11px 13px;font-size:14px;line-height:1.6;color:' . $ink . ';white-space:pre-wrap">'
			. esc_html( $fields['message'] ) . '</div>'
		. '</td></tr>';

	if ( $journey ) {
		$body .= '<tr><td style="padding:20px 24px 0">'
			. '<div style="border-top:1px solid ' . $line . ';padding:16px 0 0">'
			. '<div style="font-size:16px;font-weight:700;color:' . $ink . ';padding:0 0 12px">' . esc_html__( 'How they got here', 'leadkit' ) . '</div>'
			. $journey
			. '</div></td></tr>';
	}

	$body .= '<tr><td style="padding:18px 24px 22px">'
			. '<a href="' . esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '" '
			. 'style="display:inline-block;background:' . $ink . ';color:#fff;text-decoration:none;padding:10px 16px;font-size:14px;border-radius:3px">'
			. esc_html__( 'Open this lead', 'leadkit' ) . '</a>'
			. '<div style="font-size:12px;color:' . $muted . ';padding:12px 0 0">'
			. esc_html__( 'Reply to this email and it goes straight to them.', 'leadkit' ) . ' &middot; '
			. esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
			. '</div>'
		. '</td></tr>'
		. '</table></div>';

	$headers = array(
		'From: ' . $from_name . ' <' . $from . '>',
		'Reply-To: ' . $fields['name'] . ' <' . $fields['email'] . '>',
		'Content-Type: text/html; charset=UTF-8',
	);

	/*
	 * wp_mail() returning false tells you it failed but not why. PHPMailer's
	 * exception carries the reason, and the reason is the whole diagnosis on a
	 * new host — "Could not instantiate mail function" is a missing sendmail,
	 * "SMTP connect() failed" is a blocked port.
	 */
	$error = '';
	$catch = function ( $wp_error ) use ( &$error ) {
		$error = $wp_error->get_error_message();
	};
	add_action( 'wp_mail_failed', $catch );
	$sent = wp_mail( $to, $subject, $body, $headers );
	remove_action( 'wp_mail_failed', $catch );

	update_post_meta( $post_id, '_leadkit_notified', $sent ? '1' : '0' );
	if ( ! $sent ) {
		update_post_meta( $post_id, '_leadkit_notify_error', $error ?: __( 'wp_mail() returned false', 'leadkit' ) );
		// So a broken mail path is visible on the dashboard, not only per-lead.
		update_option( 'leadkit_last_mail_error', $error ?: 'wp_mail() returned false', false );
	} else {
		delete_option( 'leadkit_last_mail_error' );
	}
}

/**
 * The Turnstile actions this site will accept.
 *
 * Checking the action stops a token minted for somebody else's widget — or for
 * a different surface — being replayed here. It is checked as an ALLOWLIST
 * rather than per-form on purpose: the form tells us which form it is, and the
 * form is the part an attacker controls, so trusting it to say what to expect
 * would verify nothing. Both surfaces here are the same public contact form at
 * the same trust level, so one list is honest about what is actually enforced.
 *
 * @return string[]
 */
function leadkit_turnstile_actions() {
	$opts    = leadkit_options();
	$actions = array_filter( array_map( 'trim', explode( ',', (string) $opts['turnstile_actions'] ) ) );

	if ( ! $actions ) {
		$actions = array( 'leadkit-form' );
	}

	/**
	 * Filter the accepted Turnstile actions.
	 *
	 * @param string[] $actions Accepted `data-action` values.
	 */
	return array_values( array_unique( (array) apply_filters( 'leadkit_turnstile_actions', $actions ) ) );
}

/**
 * The hostnames a token may have been solved on.
 *
 * Derived from this install's own home URL, which makes it correct per
 * environment without configuration — production allows the production host,
 * and a local site allows the local host. That is the reason not to hard-code a
 * list: a production allowlist containing `localhost` accepts a token solved
 * anywhere, which is the whole check gone.
 *
 * @return string[]
 */
function leadkit_turnstile_hostnames() {
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$hosts = array( $host );

	// Cloudflare reports the hostname the widget was solved on, which may carry
	// the www the canonical URL does not.
	$hosts[] = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;

	/*
	 * The setting ADDS to this site's own hostname; it cannot replace it. A
	 * field that replaced the derived value would let someone type `localhost`
	 * into a production box and accept a token solved anywhere, which is the
	 * entire check gone — and it would look like a working configuration.
	 */
	$opts  = leadkit_options();
	$hosts = array_merge( $hosts, array_filter( array_map( 'trim', explode( ',', (string) $opts['turnstile_hostnames'] ) ) ) );

	/**
	 * Filter the accepted Turnstile hostnames.
	 *
	 * @param string[] $hosts Accepted hostnames.
	 */
	return array_values( array_unique( array_filter( (array) apply_filters( 'leadkit_turnstile_hostnames', $hosts ) ) ) );
}

/**
 * Verify a Turnstile token: solved, for us, on one of our pages.
 *
 * `success` alone is not verification. A token is minted for a specific widget
 * and a specific action on a specific hostname, and siteverify reports all
 * three — so checking only the first accepts a token solved on any site whose
 * widget shares this secret, and any token replayed from another surface.
 *
 * @param string $token  Client token.
 * @param string $secret Secret key.
 * @param string $ip     Client IP.
 * @return bool
 */
function leadkit_verify_turnstile( $token, $secret, $ip ) {
	// Bound the input before spending a request on it. Real tokens are well
	// under this; anything longer is someone probing.
	if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) {
		return false;
	}

	$res = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => $ip,
			),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		/**
		 * FAIL CLOSED when Cloudflare cannot be reached.
		 *
		 * The earlier version of this returned true here, reasoning that a spam
		 * lead is an annoyance and a lost customer is not. That reasoning is
		 * about the business, and it is not wrong — but as a default it means an
		 * unreachable verifier silently disables verification, which is exactly
		 * the condition an attacker would arrange. Cloudflare's guidance is to
		 * fail closed, so that is the default — and it is a SETTING rather than
		 * only a filter, because the trade-off is the site owner's to make and
		 * they should not need a developer to make it.
		 *
		 * @param bool $fail_open Whether to accept when siteverify is unreachable.
		 */
		$opts = leadkit_options();

		return (bool) apply_filters( 'leadkit_turnstile_fail_open', '1' === $opts['turnstile_fail_open'] );
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

	if ( ! is_array( $body ) || empty( $body['success'] ) ) {
		return false;
	}
	if ( ! in_array( (string) ( $body['action'] ?? '' ), leadkit_turnstile_actions(), true ) ) {
		return false;
	}
	if ( ! in_array( (string) ( $body['hostname'] ?? '' ), leadkit_turnstile_hostnames(), true ) ) {
		return false;
	}

	return true;
}

/**
 * The client IP, trusting Cloudflare's header only when it is present.
 *
 * @return string
 */
function leadkit_client_ip() {
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			$ip = explode( ',', (string) wp_unslash( $_SERVER[ $k ] ) )[0]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$ip = filter_var( trim( $ip ), FILTER_VALIDATE_IP );
			if ( $ip ) {
				return $ip;
			}
		}
	}
	return '';
}

/**
 * Answer in whichever way the caller asked.
 *
 * @param WP_REST_Request $req     Request.
 * @param bool            $ok      Success.
 * @param string          $message Human-readable message.
 * @param string          $referer Page to return to.
 * @return WP_REST_Response
 */
function leadkit_answer( $req, $ok, $message, $referer ) {
	if ( leadkit_wants_json( $req ) ) {
		return new WP_REST_Response(
			array(
				'ok'      => (bool) $ok,
				'message' => $message,
			),
			$ok ? 200 : 400
		);
	}

	/*
	 * POST-redirect-GET for a plain form submit: without it the browser shows
	 * raw JSON, and a refresh re-submits. 303 specifically, so the redirect is
	 * fetched with GET whatever the original method was.
	 */
	$which = sanitize_key( (string) $req->get_param( 'leadkit_form' ) );
	$url   = add_query_arg(
		array(
			'leadkit'      => $ok ? 'sent' : 'error',
			'leadkit_form' => $which,
		),
		remove_query_arg( array( 'leadkit', 'leadkit_form' ), $referer )
	);
	$res = new WP_REST_Response( null, 303 );
	$res->header( 'Location', $url . '#leadkit-message' );
	return $res;
}
