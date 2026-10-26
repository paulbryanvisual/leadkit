<?php
/**
 * Every browser's visits, kept in Cloudflare D1; only warm or known ones come
 * to monday.com.
 *
 * With a Visits Worker set (Settings → LeadKit → Visits Worker URL), the
 * tracker posts the browser's story straight to the Worker each time a page
 * is hidden or left — anonymous visitors included — so neither WordPress nor
 * its database sees that traffic. The Worker (its routes are in readme.txt) keeps one
 * row per browser in D1 and decides which are "warm" by its WARM_RULES
 * setting: by default a Donate click, a form, or a browser WordPress has
 * marked known; returning visitors and campaign arrivals can be added. Until
 * then a browser's visits stay in D1 only.
 *
 * Every 15 minutes (or 5: Settings → LeadKit → Background runs) LeadKit asks
 * the Worker for the warm browsers whose story changed (GET /warm), writes
 * each to the Visitors and Visits boards with
 * the same writer a Donate click uses — so a browser's whole history arrives
 * the moment it turns warm — and tells the Worker which it sent (POST /sent,
 * by hash, so a story that changed meanwhile stays pending).
 *
 * The Worker's routes other than /visit take a shared secret: the constant
 * LEADKIT_VISITS_SECRET in wp-config.php, or the setting. The monday.com
 * token never leaves WordPress.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_BROWSERS_HOOK    = 'leadkit_send_warm';
const LEADKIT_BROWSERS_PER_RUN = 25;
const LEADKIT_BROWSERS_STATS   = 'leadkit_visits_stats';

add_action(
	'init',
	function () {
		leadkit_keep_scheduled( LEADKIT_BROWSERS_HOOK, leadkit_browsers_enabled() && leadkit_visitors_enabled() );
	}
);
add_action( LEADKIT_BROWSERS_HOOK, 'leadkit_send_warm_browsers' );

// Settings saved (a new URL or secret, say): the counts are asked for again.
add_action(
	'update_option_leadkit_options',
	function () {
		delete_transient( LEADKIT_BROWSERS_STATS );
	}
);

/**
 * The Worker's base URL, without a trailing slash, or ''.
 *
 * @return string
 */
function leadkit_visits_worker() {
	$url = trim( (string) ( leadkit_options()['visits_url'] ?? '' ) );
	$url = preg_replace( '#/(visit)?/*$#', '', $url );
	return preg_match( '#^https://[^/\s]+#', (string) $url ) ? $url : '';
}

/**
 * The shared secret: wp-config constant first, then the setting.
 *
 * @return string
 */
function leadkit_visits_secret() {
	if ( defined( 'LEADKIT_VISITS_SECRET' ) && LEADKIT_VISITS_SECRET ) {
		return (string) LEADKIT_VISITS_SECRET;
	}
	return (string) ( leadkit_options()['visits_secret'] ?? '' );
}

/**
 * Whether the tracker posts every visit to the Worker.
 *
 * @return bool
 */
function leadkit_browsers_enabled() {
	return '' !== leadkit_visits_worker();
}

/**
 * The URL the tracker posts to.
 *
 * @return string
 */
function leadkit_visits_post_url() {
	$base = leadkit_visits_worker();
	return $base ? $base . '/visit' : '';
}

/**
 * Call one of the Worker's private routes.
 *
 * @param string     $method  GET or POST.
 * @param string     $path    e.g. '/warm?limit=25'.
 * @param array|null $body    JSON body for POST.
 * @param bool       $wait    False: send and do not wait for the answer.
 * @param int        $timeout Seconds to wait for the answer.
 * @return array|null Decoded answer; null on any failure (or when not waiting).
 */
function leadkit_visits_call( $method, $path, $body = null, $wait = true, $timeout = 15 ) {
	$base   = leadkit_visits_worker();
	$secret = leadkit_visits_secret();
	if ( ! $base || ! $secret ) {
		return null;
	}
	$args = array(
		'method'   => $method,
		'timeout'  => $wait ? $timeout : 1,
		'blocking' => $wait,
		'headers'  => array(
			'Authorization' => 'Bearer ' . $secret,
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( $base . $path, $args );
	if ( ! $wait || is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return is_array( $data ) ? $data : null;
}

/**
 * Each background run: send the warm browsers whose story changed.
 *
 * @param string $only One visitor id: that browser alone, if it is pending
 *                     (a donor key just arrived: donor-keys.php).
 * @return int Browsers sent.
 */
function leadkit_send_warm_browsers( $only = '' ) {
	if ( ! leadkit_browsers_enabled() || ! leadkit_visitors_enabled() ) {
		return 0;
	}

	/**
	 * How many browsers one run sends. Default 25.
	 *
	 * @param int $max Browsers.
	 */
	$max  = max( 1, min( 100, (int) apply_filters( 'leadkit/browsers_per_run', LEADKIT_BROWSERS_PER_RUN ) ) );
	$only = leadkit_clean_visitor_id( (string) $only );
	$warm = leadkit_visits_call( 'GET', $only ? '/warm?limit=1&visitor=' . rawurlencode( $only ) : '/warm?limit=' . $max );
	if ( ! $warm ) {
		return 0;
	}

	$sent = array();
	foreach ( array_slice( (array) ( $warm['browsers'] ?? array() ), 0, $max ) as $b ) {
		$visitor = leadkit_clean_visitor_id( $b['visitor'] ?? '' );
		$story   = is_array( $b['story'] ?? null ) ? $b['story'] : null;
		if ( ! $visitor || ! $story || ( $only && $visitor !== $only ) ) {
			continue;
		}
		// The Worker is trusted for what it decides, not for what it stores:
		// the story came from a browser, so it is cleaned like any other post.
		// A donor key it sends has been checked there.
		leadkit_use_visitor_tz( (string) ( $story['timezone'] ?? '' ) );
		$dk  = leadkit_clean_donor_key( $b['dk'] ?? null );
		$row = leadkit_visitor_upsert( $visitor, $story, $dk ? array( 'dk' => $dk ) : array() );
		if ( '' === $row ) {
			continue; // monday.com did not take it: still pending, tried next run.
		}
		$sent[] = array( 'visitor' => $visitor, 'hash' => (string) ( $b['hash'] ?? '' ) );
	}
	leadkit_use_visitor_tz( '' );

	if ( $sent ) {
		leadkit_visits_call( 'POST', '/sent', array( 'sent' => $sent ) );
	}
	return count( $sent );
}

/**
 * Tell the Worker a browser has a Visitors row, so it counts as warm from
 * now on. Fire and forget: a browser with a row already carries a Donate
 * click or a form, which the Worker counts anyway; this covers the rest.
 *
 * @param string $visitor Visitor id.
 * @return void
 */
function leadkit_visits_mark_known( $visitor ) {
	if ( leadkit_browsers_enabled() ) {
		leadkit_visits_call( 'POST', '/known', array( 'visitors' => array( $visitor ) ), false );
	}
}

/**
 * Counts for the settings screen, cached five minutes.
 *
 * The screen waits for them, so the Worker gets three seconds, not fifteen,
 * and a failure is remembered for a minute: a Worker that is down or refuses
 * the secret costs one short wait a minute, not one on every page load.
 *
 * @return array|null browsers, warm, pending, sent.
 */
function leadkit_browsers_counts() {
	$cached = get_transient( LEADKIT_BROWSERS_STATS );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( false !== $cached ) {
		return null; // It failed a moment ago.
	}
	$data = leadkit_visits_call( 'GET', '/stats', null, true, 3 );
	if ( ! $data ) {
		set_transient( LEADKIT_BROWSERS_STATS, 'failed', MINUTE_IN_SECONDS );
		return null;
	}
	$out = array();
	foreach ( array( 'browsers', 'warm', 'pending', 'sent' ) as $k ) {
		$out[ $k ] = (int) ( $data[ $k ] ?? 0 );
	}
	set_transient( LEADKIT_BROWSERS_STATS, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}
