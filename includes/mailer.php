<?php
/**
 * Send through an HTTPS API instead of the host's mail transport.
 *
 * The default path is PHP `mail()`, which on shared hosting means an
 * unauthenticated message from a shared IP, claiming to come from a domain the
 * host has no authority over. This site is the worst case of that: no MX, no
 * SPF, no DKIM, no DMARC on the domain at all. Gmail's options are the spam
 * folder or a silent drop, and it picks one without telling anybody.
 *
 * So mail goes over HTTPS to a provider that IS authorised for the sending
 * domain. HTTPS rather than SMTP deliberately — shared hosts routinely block
 * outbound 587 and 465, and an SMTP plugin that cannot connect fails in exactly
 * the same invisible way as the thing it was installed to fix.
 *
 * THE KEY IS A SETTING. Paste it into Settings → LeadKit and the plugin works;
 * needing a file edit to send an email is not a working plugin.
 *
 * It is stored write-only: the field never renders the saved value, and saving
 * the form with the box empty keeps what is already there. That removes the
 * everyday exposure — a secret echoed into admin HTML is read by anything that
 * can see the screen.
 *
 * What it cannot remove is that an option travels in every database export and
 * sits in every backup. For sites that mind — this one moves databases between
 * hosts routinely — the same value in wp-config.php takes precedence:
 *
 *     define( 'LEADKIT_RESEND_API_KEY', 're_xxxxxxxx' );
 *
 * Both work. The constant is the harder option, not the required one.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The API key, or '' when none is configured.
 *
 * @return string
 */
function leadkit_api_key() {
	if ( defined( 'LEADKIT_RESEND_API_KEY' ) && LEADKIT_RESEND_API_KEY ) {
		return (string) LEADKIT_RESEND_API_KEY;
	}

	$opts = leadkit_options();

	/**
	 * Filter the API key, for sites keeping secrets somewhere else again.
	 *
	 * @param string $key API key.
	 */
	return (string) apply_filters( 'leadkit_api_key', (string) $opts['resend_api_key'] );
}

/**
 * Route wp_mail() through the API.
 *
 * `pre_wp_mail` short-circuits the whole function: return a bool and WordPress
 * uses it as the result, return null and it carries on to PHPMailer. So this is
 * a REPLACEMENT for the transport, not a filter on it, and everything that
 * calls wp_mail() — password resets, core update notices, this plugin's own
 * notifications — travels the authenticated path without knowing.
 */
add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		$key = leadkit_api_key();
		if ( ! $key ) {
			return $short; // No key: leave WordPress exactly as it was.
		}

		/*
		 * Attachments are not handled here. Rather than silently dropping one,
		 * hand the message back to PHPMailer — a mail that arrives badly beats
		 * a mail that arrives incomplete without saying so.
		 */
		if ( ! empty( $atts['attachments'] ) ) {
			return $short;
		}

		$parsed  = leadkit_parse_mail_headers( $atts['headers'] ?? array() );
		$to      = array_values( array_filter( array_map( 'trim', (array) ( is_string( $atts['to'] ) ? explode( ',', $atts['to'] ) : $atts['to'] ) ) ) );
		$opts    = leadkit_options();
		$from    = $parsed['from'];

		if ( '' === $from ) {
			$name = $opts['from_name'] ?: get_bloginfo( 'name' );
			$from = $opts['from_email'] ? sprintf( '%s <%s>', $name, $opts['from_email'] ) : '';
		}
		if ( '' === $from ) {
			// Nothing safe to claim to be. PHPMailer's guess is no worse.
			return $short;
		}

		$payload = array(
			'from'    => $from,
			'to'      => $to,
			'subject' => (string) ( $atts['subject'] ?? '' ),
		);

		if ( false !== stripos( $parsed['content_type'], 'text/html' ) ) {
			$payload['html'] = (string) ( $atts['message'] ?? '' );
		} else {
			$payload['text'] = (string) ( $atts['message'] ?? '' );
		}
		if ( $parsed['reply_to'] ) {
			$payload['reply_to'] = $parsed['reply_to'];
		}
		if ( $parsed['cc'] ) {
			$payload['cc'] = $parsed['cc'];
		}
		if ( $parsed['bcc'] ) {
			$payload['bcc'] = $parsed['bcc'];
		}

		$res = wp_remote_post(
			'https://api.resend.com/emails',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return leadkit_mail_failed( $atts, 'HTTP request failed: ' . $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			/*
			 * The provider's message is the diagnosis and must survive. A 403
			 * here almost always means the From domain is not verified, and
			 * "wp_mail returned false" would send you looking at the wrong
			 * thing for an afternoon.
			 */
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$why  = $body['message'] ?? wp_remote_retrieve_response_message( $res );
			return leadkit_mail_failed( $atts, sprintf( 'API %d: %s', $code, $why ) );
		}

		return true;
	},
	10,
	2
);

/**
 * Report a send failure the way WordPress does, so existing listeners work.
 *
 * This plugin's own notifier listens on `wp_mail_failed` to write the reason
 * onto the lead; so does every logging plugin anyone might add later.
 *
 * @param array  $atts   Original wp_mail() arguments.
 * @param string $reason Human-readable reason.
 * @return false
 */
function leadkit_mail_failed( $atts, $reason ) {
	$error = new WP_Error( 'leadkit_mail_failed', $reason, $atts );

	/** This action is documented in wp-includes/pluggable.php */
	do_action( 'wp_mail_failed', $error );

	return false;
}

/**
 * Pull the headers the API needs out of whatever wp_mail() was handed.
 *
 * Headers arrive as an array of strings, one string of lines, or nothing —
 * every caller in WordPress picks a different one.
 *
 * @param array|string $headers Raw headers.
 * @return array{from:string,reply_to:array,cc:array,bcc:array,content_type:string}
 */
function leadkit_parse_mail_headers( $headers ) {
	$out = array(
		'from'         => '',
		'reply_to'     => array(),
		'cc'           => array(),
		'bcc'          => array(),
		'content_type' => 'text/plain',
	);

	if ( is_string( $headers ) ) {
		$headers = preg_split( "/\r\n|\n|\r/", $headers );
	}

	foreach ( (array) $headers as $line ) {
		if ( ! is_string( $line ) || false === strpos( $line, ':' ) ) {
			continue;
		}
		list( $name, $value ) = explode( ':', $line, 2 );
		$name                 = strtolower( trim( $name ) );
		$value                = trim( $value );

		switch ( $name ) {
			case 'from':
				$out['from'] = $value;
				break;
			case 'reply-to':
				$out['reply_to'] = array_map( 'trim', explode( ',', $value ) );
				break;
			case 'cc':
				$out['cc'] = array_map( 'trim', explode( ',', $value ) );
				break;
			case 'bcc':
				$out['bcc'] = array_map( 'trim', explode( ',', $value ) );
				break;
			case 'content-type':
				$out['content_type'] = $value;
				break;
		}
	}

	return $out;
}

/**
 * The Turnstile secret, from a constant first.
 *
 * Same reasoning as the API key: a secret in `wp_options` rides along in every
 * database export and backup, and this project moves databases between hosts
 * routinely. The site KEY is public — it ships in the HTML — so that stays an
 * ordinary setting. The secret is what does the verifying and belongs in
 * wp-config.php:
 *
 *     define( 'LEADKIT_TURNSTILE_SECRET', '0x4AAA…' );
 *
 * @return string
 */
function leadkit_turnstile_secret() {
	if ( defined( 'LEADKIT_TURNSTILE_SECRET' ) && LEADKIT_TURNSTILE_SECRET ) {
		return (string) LEADKIT_TURNSTILE_SECRET;
	}

	$opts = leadkit_options();

	return (string) $opts['turnstile_secret'];
}
