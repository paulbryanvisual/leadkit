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
	$to = ! empty( $opts['notify_email'] ) ? $opts['notify_email'] : get_option( 'admin_email' );

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

	$body = implode(
		"\n",
		array(
			__( 'Name:', 'leadkit' ) . ' ' . $fields['name'],
			__( 'Email:', 'leadkit' ) . ' ' . $fields['email'],
			__( 'Phone:', 'leadkit' ) . ' ' . $fields['phone'],
			__( 'Project:', 'leadkit' ) . ' ' . ( $fields['type'] ?: '—' ),
			'',
			__( 'Message:', 'leadkit' ),
			$fields['message'],
			'',
			'— ' . home_url( '/' ),
			admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		)
	);

	$headers = array(
		'From: ' . $from_name . ' <' . $from . '>',
		'Reply-To: ' . $fields['name'] . ' <' . $fields['email'] . '>',
		'Content-Type: text/plain; charset=UTF-8',
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
 * Verify a Turnstile token.
 *
 * @param string $token  Client token.
 * @param string $secret Secret key.
 * @param string $ip     Client IP.
 * @return bool
 */
function leadkit_verify_turnstile( $token, $secret, $ip ) {
	if ( '' === $token ) {
		return false;
	}
	$res = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 8,
			'body'    => array(
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => $ip,
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		/*
		 * Cloudflare being unreachable is our problem, not the visitor's. Fail
		 * OPEN: a spam lead is an annoyance, a lost customer is not.
		 */
		return true;
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return ! empty( $body['success'] );
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
