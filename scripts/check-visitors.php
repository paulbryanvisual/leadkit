<?php
/**
 * check-visitors.php — what LeadKit writes to the monday.com Visitors board.
 *
 * Against a fake monday.com (scripts/wp-stubs.php) shaped like the real
 * DEMO – Visitors board: the row created on a first Donate click, the same
 * post again writing nothing, a form submission connecting the submission and
 * the person without losing links someone made by hand, a row deleted on
 * monday.com being recreated, failures not creating duplicates, untracked
 * pages kept out, visits counted, the Journey log kept under its limit, and
 * the endpoint's refusals and brakes.
 *
 *   php scripts/check-visitors.php
 *
 * Exits non-zero on any failure.
 */

// Development checks: never over the web, even if this folder is ever deployed.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/wp-stubs.php';

const VB     = '18435450271'; // DEMO – Visitors
const PEOPLE = '18435180712';
const FORMS  = '18435173046'; // DEMO – Volunteer Inquiry
const VS     = '18435475074'; // DEMO – Visits

/** A board like the real one (column ids from its board info). */
function lk_boards( $with_people = true ) {
	lk_reset();
	LkMonday::board(
		VB,
		array(
			'name'                    => array( 'Name', 'name' ),
			'text_mm849mr5'           => array( 'visitorid', 'text' ),
			'board_relation_mm84ysfv' => array( 'Person', 'board_relation' ),
			'board_relation_mm84rrd5' => array( 'Donations', 'board_relation' ),
			'board_relation_mm84wp2d' => array( 'Form submissions', 'board_relation' ),
			'date_mm84kpqp'           => array( 'First seen', 'date' ),
			'date_mm84w8jj'           => array( 'Last seen', 'date' ),
			'numeric_mm84mt3y'        => array( 'Visits', 'numbers' ),
			'text_mm8447yq'           => array( 'First source', 'text' ),
			'text_mm84pej6'           => array( 'Latest source', 'text' ),
			'numeric_mm84e0t6'        => array( 'Donate clicks', 'numbers' ),
			'long_text_mm84qwht'      => array( 'Journey log', 'long_text' ),
			'text_mm84778t'           => array( 'First referrer', 'text' ),
			'text_mm84yh3a'           => array( 'Latest referrer', 'text' ),
			'board_relation_mm84vyhe' => array( 'Visit rows', 'board_relation' ),
			'link_mm84cbre'           => array( 'Open person', 'link' ),
		)
	);
	LkMonday::board(
		VS,
		array(
			'name'                    => array( 'Name', 'name' ),
			'text_mm84n0qv'           => array( 'visitid', 'text' ),
			'board_relation_mm8460nd' => array( 'Visitor', 'board_relation' ),
			'text_mm84dxxx'           => array( 'visitorid', 'text' ),
			'date_mm84c9a8'           => array( 'Started', 'date' ),
			'text_mm84sz4m'           => array( 'Referrer', 'text' ),
			'text_mm84r9fm'           => array( 'Source', 'text' ),
			'text_mm84vvee'           => array( 'Landing page', 'text' ),
			'numeric_mm84xnm1'        => array( 'Pages', 'numbers' ),
			'numeric_mm84302z'        => array( 'Seconds on site', 'numbers' ),
			'numeric_mm84t2yq'        => array( 'Donate clicks', 'numbers' ),
			'text_mm847jmh'           => array( 'Forms', 'text' ),
			'long_text_mm84vzah'      => array( 'Journey', 'long_text' ),
			'link_mm84xwdw'           => array( 'Open person', 'link' ),
		)
	);
	update_option(
		'leadkit_options',
		array(
			'monday_token'          => 'test-token',
			'monday_visitors_board' => VB,
			'monday_visits_board'   => VS,
			'monday_people_board'   => $with_people ? PEOPLE : '',
			'monday_boards'         => FORMS,
			'untracked_paths'       => '/help/',
		)
	);
	leadkit_use_visitor_tz( 'America/Chicago' );
}
function lk_row( $id ) {
	return LkMonday::$boards[ VB ]['items'][ $id ] ?? null;
}
function lk_rows() {
	return LkMonday::$boards[ VB ]['items'];
}
/** Visit rows, oldest first by Started. */
function lk_visits() {
	$rows = array_values( LkMonday::$boards[ VS ]['items'] );
	usort( $rows, fn( $a, $b ) => strcmp( $a['values']['date_mm84c9a8']['date'] . $a['values']['date_mm84c9a8']['time'], $b['values']['date_mm84c9a8']['date'] . $b['values']['date_mm84c9a8']['time'] ) );
	return $rows;
}
function lk_visit_mutations() {
	return array_values( array_filter( LkMonday::mutations(), fn( $c ) => ( $c['v']['b'] ?? '' ) === VS ) );
}
/** A Donate-click post, as tracker.js visitorBody() sends it. */
function lk_post( $extra = array() ) {
	$t0 = strtotime( '2026-10-14 14:00:00 UTC' );
	return array_merge(
		array(
			'visitor_id'    => 'c0ffee00-1234-4abc-9def-0123456789ab',
			'started_at'    => gmdate( 'c', $t0 - 3 * DAY_IN_SECONDS ),
			'referrer'      => 'facebook.com',
			'first_referrer' => 'facebook.com',
			'referrers'     => array(
				array( 'domain' => 'facebook.com', 'time' => gmdate( 'c', $t0 - 3 * DAY_IN_SECONDS ), 'landing' => '/' ),
				array( 'domain' => 'mail.google.com', 'time' => gmdate( 'c', $t0 ), 'landing' => '/hope/' ),
			),
			'utm'           => array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1', 'landing' => '/hope/', 'time' => gmdate( 'c', $t0 ) ),
			'utm_first'     => array( 'medium' => 'social', 'source' => 'facebook', 'campaign' => 'light-the-path', 'content' => 'post-3', 'landing' => '/', 'time' => gmdate( 'c', $t0 - 3 * DAY_IN_SECONDS ) ),
			'utm_touches'   => array(
				array( 'medium' => 'social', 'source' => 'facebook', 'campaign' => 'light-the-path', 'content' => 'post-3', 'time' => gmdate( 'c', $t0 - 3 * DAY_IN_SECONDS ) ),
				array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1', 'time' => gmdate( 'c', $t0 ) ),
			),
			'page_views'    => array(
				array( 'id' => 'v1', 'path' => '/', 'active_time_ms' => 30000, 'max_scroll_percent' => 50, 'time' => gmdate( 'c', $t0 - 3 * DAY_IN_SECONDS ) ),
				array( 'id' => 'v2', 'path' => '/hope/', 'active_time_ms' => 45000, 'max_scroll_percent' => 80, 'time' => gmdate( 'c', $t0 ) ),
				array( 'id' => 'v3', 'path' => '/help/', 'active_time_ms' => 9000, 'max_scroll_percent' => 10, 'time' => gmdate( 'c', $t0 + 60 ) ),
				array( 'id' => 'v4', 'path' => '/donate/', 'active_time_ms' => 12000, 'max_scroll_percent' => 30, 'time' => gmdate( 'c', $t0 + 120 ) ),
			),
			'donate_clicks' => array( array( 'id' => 'c1', 'time' => gmdate( 'c', $t0 + 130 ), 'path' => '/donate/', 'how' => 'link' ) ),
			'lead_forms'    => array(),
			'timezone'      => 'America/Chicago',
		),
		$extra
	);
}
function lk_request( $body, $ip = '203.0.113.7' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	return leadkit_handle_visitor( new WP_REST_Request( is_string( $body ) ? $body : json_encode( $body ) ) );
}
$V = 'c0ffee00-1234-4abc-9def-0123456789ab';

// ---- 1. first Donate click: the row is created ----------------------------------------
lk_boards();
$res = lk_request( lk_post() );
lk_eq( $res->get_status(), 200, 'first click: 200 once written' );
lk_eq( count( lk_rows() ), 1, 'first click: one row' );
$id  = array_key_first( lk_rows() );
$row = lk_row( $id );
lk_eq( $row['name'], $V, 'row named by its visitorid' );
$vals = $row['values'];
lk_eq( $vals['text_mm849mr5'], $V, 'visitorid column' );
lk_eq( $vals['date_mm84kpqp'], array( 'date' => '2026-10-11' ), 'First seen: started_at, in their zone' );
lk_eq( $vals['date_mm84w8jj'], array( 'date' => '2026-10-14' ), 'Last seen' );
lk_eq( $vals['numeric_mm84mt3y'], '2', 'Visits: two, three days apart' );
lk_eq( $vals['text_mm8447yq'], 'medium.social__source.facebook__campaign.light-the-path__content.post-3', 'First source: packed, no visitorid' );
lk_eq( $vals['text_mm84pej6'], 'medium.email__source.mailchimp__campaign.light-the-path__content.email-1', 'Latest source: packed, no visitorid' );
lk_eq( $vals['numeric_mm84e0t6'], '1', 'Donate clicks' );
lk_eq( $vals['text_mm84778t'], 'facebook.com', 'First referrer: the first visit\'s site' );
lk_eq( $vals['text_mm84yh3a'], 'mail.google.com', 'Latest referrer: the latest visit\'s site' );
$log = $vals['long_text_mm84qwht']['text'] ?? '';
lk_eq(
	explode( "\n", $log ),
	array(
		'Oct 14, 2026 9:00am — Email · light-the-path · email-1 — 2 pages, 57s: /hope/ 45s 80% > /donate/ 12s 30% > Donate click',
		'Oct 11, 2026 9:00am — Social · facebook · light-the-path · post-3 — 1 page, 30s: / 30s 50%',
	),
	'Journey log: newest visit first, campaign per visit, click in place, /help/ left out'
);
lk_eq( array_values( array_column( get_option( leadkit_visitor_row_key( $V ) )['views'], 'path' ) ), array( '/', '/hope/', '/donate/' ), 'untracked page not even kept in local state' );
lk_ok( ! isset( $vals['board_relation_mm84rrd5'] ), 'Donations column is never written by LeadKit' );

// ---- 2. the same post again (an outbox resend) changes nothing -----------------------------
$before = count( LkMonday::mutations() );
LkMonday::$calls = array();
$res = lk_request( lk_post() );
lk_eq( $res->get_status(), 200, 'resend: still 200' );
lk_eq( count( LkMonday::mutations() ), 0, 'resend: no write at all' );
lk_eq( count( lk_rows() ), 1, 'resend: still one row' );

// ---- 3. a form submission: connects the submission and the person ------------------------
// Someone linked an older submission by hand on monday.com; it must survive.
LkMonday::$boards[ VB ]['items'][ $id ]['values']['board_relation_mm84wp2d'] = array( 'item_ids' => array( 111 ) );
$analytics = lk_post();
unset( $analytics['visitor_id'] );
$analytics['id'] = $V;
$GLOBALS['lk_referer'] = 'https://3sgf.org/volunteer/';
remove_all_actions( 'shutdown' );
leadkit_queue_visitor( $V, $analytics, array( 'item' => '222', 'person' => '333', 'form' => 'Volunteer Inquiry' ) );
lk_eq( count( lk_rows() ), 1, 'form: nothing written before the visitor is answered' );
do_action( 'shutdown' );
$vals = lk_row( $id )['values'];
lk_eq( $vals['board_relation_mm84wp2d'], array( 'item_ids' => array( 111, 222 ) ), 'form: submission connected, the hand-made link kept' );
lk_eq( $vals['board_relation_mm84ysfv'], array( 'item_ids' => array( 333 ) ), 'form: person connected' );
lk_ok( false !== strpos( $vals['long_text_mm84qwht']['text'], 'Sent Volunteer Inquiry' ), 'form: in the Journey log', $vals['long_text_mm84qwht']['text'] );

// The browser later reports the same submission under its short name: not twice.
$p                 = lk_post();
$p['lead_forms']   = array( array( 'form' => 'volunteer', 'time' => gmdate( 'c', time() + 5 ), 'path' => '/volunteer/' ) );
$p['donate_clicks'][] = array( 'id' => 'c2', 'time' => gmdate( 'c', time() + 30 ), 'path' => '/thank-you/', 'how' => 'link' );
LkMonday::$calls = array();
lk_request( $p );
$vals = lk_row( $id )['values'];
lk_eq( substr_count( $vals['long_text_mm84qwht']['text'], 'Sent ' ), 1, 'the server\'s and the browser\'s record of one submission count once' );
lk_eq( $vals['numeric_mm84e0t6'], '2', 'second click counted' );
lk_eq( count( array_filter( LkMonday::mutations(), fn( $c ) => false !== strpos( $c['v']['v'] ?? '', 'board_relation_mm84ysfv' ) ) ), 0, 'person not rewritten once connected' );

// ---- 4. a connection monday.com refuses does not take the row down ------------------------------
lk_boards();
LkMonday::$fault = function ( $q, $v ) {
	if ( false !== strpos( $q, 'change_multiple_column_values' ) && false !== strpos( (string) ( $v['v'] ?? '' ), 'board_relation_mm84wp2d' ) ) {
		return array( 'errors' => array( array( 'message' => 'Linked item is not on a connected board' ) ) );
	}
	return null;
};
leadkit_visitor_upsert( $V, lk_post(), array( 'item' => '444' ) );
$rid = array_key_first( lk_rows() );
lk_ok( $rid && '1' === lk_row( $rid )['values']['numeric_mm84e0t6'], 'refused connection: the row is still written' );
lk_eq( get_option( leadkit_visitor_row_key( $V ) )['items'], array( 444 ), 'refused connection: kept to try again' );
LkMonday::$fault = null;
leadkit_visitor_upsert( $V, lk_post() );
lk_eq( lk_row( $rid )['values']['board_relation_mm84wp2d'] ?? null, array( 'item_ids' => array( 444 ) ), 'refused connection: made on the next write' );

// ---- 5. the row deleted on monday.com: recreated, once -----------------------------------------
LkMonday::$boards[ VB ]['items'] = array();
$p = lk_post();
$p['donate_clicks'][] = array( 'id' => 'c9', 'time' => gmdate( 'c', strtotime( '2026-10-14 15:00:00 UTC' ) ), 'path' => '/donate/' );
$res = lk_request( $p );
lk_eq( $res->get_status(), 200, 'deleted row: 200' );
lk_eq( count( lk_rows() ), 1, 'deleted row: recreated, one' );
lk_ok( array_key_first( lk_rows() ) !== $rid, 'deleted row: a new item id' );

// ---- 6. monday.com down: no row is invented, and the browser is told to retry -----------------
lk_boards();
LkMonday::$fault = fn( $q, $v ) => false !== strpos( $q, 'items_page_by_column_values' ) || false !== strpos( $q, 'create_item' ) ? array( 'errors' => array( array( 'message' => 'Internal' ) ) ) : null;
$res = lk_request( lk_post() );
lk_eq( $res->get_status(), 502, 'monday.com down: 502, so the outbox keeps it' );
lk_eq( count( lk_rows() ), 0, 'monday.com down: nothing created' );
lk_eq( count( array_filter( LkMonday::$calls, fn( $c ) => false !== strpos( $c['q'], 'create_item' ) ) ), 0, 'lookup failed: create not even tried (a second row would be the result)' );
LkMonday::$fault = null;
$res = lk_request( lk_post(), '203.0.113.8' );
lk_eq( $res->get_status(), 200, 'monday.com back: the resend writes' );
lk_eq( count( lk_rows() ), 1, 'monday.com back: one row' );

// The row exists on monday.com but this site has never seen it (state lost): found, not duplicated.
delete_option( leadkit_visitor_row_key( $V ) );
lk_request( lk_post(), '203.0.113.9' );
lk_eq( count( lk_rows() ), 1, 'state lost: the row is found by visitorid, not duplicated' );

// ---- 7. no People board: no Person column is asked for -------------------------------------------
lk_boards( false );
leadkit_visitor_upsert( $V, lk_post(), array( 'person' => '333' ) );
$vals = lk_row( array_key_first( lk_rows() ) )['values'];
lk_ok( ! isset( $vals['board_relation_mm84ysfv'] ), 'no People board: Person left alone' );

// ---- 8. visits, and the log's limit ----------------------------------------------------------------
$views = array(
	'a' => array( 'path' => '/', 'ms' => 40 * 60000, 'scroll' => 0, 'time' => '2026-10-14T10:00:00+00:00' ),
	'b' => array( 'path' => '/b/', 'ms' => 1000, 'scroll' => 0, 'time' => '2026-10-14T10:45:00+00:00' ),
	'c' => array( 'path' => '/c/', 'ms' => 1000, 'scroll' => 0, 'time' => '2026-10-14T11:30:00+00:00' ),
);
lk_eq( count( leadkit_visitor_visits( $views ) ), 2, 'visits: a long read bridges the gap, 44 minutes away does not' );

$many = array();
for ( $i = 0; $i < 300; $i++ ) {
	$many[ 'v' . $i ] = array( 'path' => '/a-page-with-a-long-name/' . $i . '/', 'ms' => 5000, 'scroll' => 50, 'time' => gmdate( 'c', strtotime( '2026-01-01 UTC' ) + $i * DAY_IN_SECONDS ) );
}
$st   = array( 'clicks' => array(), 'forms' => array(), 'returns' => array(), 'touches' => array(), 'refs' => array() );
$text = leadkit_visitor_log( leadkit_visits_details( leadkit_visitor_visits( $many ), $st ) );
lk_ok( mb_strlen( $text ) <= 2000, 'log: within 2,000 characters', mb_strlen( $text ) );
lk_ok( false !== strpos( strtok( $text, "\n" ), '/299/' ), 'log: newest visit kept, first' );
lk_ok( (bool) preg_match( '/… and \d+ earlier visits$/', $text ), 'log: says what was left off', substr( $text, -60 ) );

// ---- 9. the endpoint's refusals and brakes ----------------------------------------------------------
lk_boards();
lk_eq( lk_request( str_repeat( 'x', 70000 ) )->get_status(), 413, 'over 64 KB: 413' );
lk_eq( lk_request( array_merge( lk_post(), array( 'visitor_id' => 'bad id!' ) ) )->get_status(), 400, 'bad visitor id: 400' );
lk_eq( lk_request( array_merge( lk_post(), array( 'donate_clicks' => array() ) ) )->get_status(), 400, 'no click: 400' );
update_option( 'leadkit_options', array( 'monday_visitors_board' => '' ) );
$res = lk_request( lk_post() );
lk_eq( array( $res->get_status(), $res->data['off'] ?? null ), array( 200, true ), 'no Visitors board: 200 off, so the browser stops resending' );

lk_boards();
$codes = array();
for ( $i = 0; $i < 7; $i++ ) {
	$codes[] = lk_request( array_merge( lk_post(), array( 'visitor_id' => 'newvisitor' . $i ) ), '198.51.100.1' )->get_status();
}
lk_eq( $codes, array( 200, 200, 200, 200, 200, 429, 429 ), 'five new rows an hour per IP' );
lk_boards();
$codes = array();
for ( $i = 0; $i < 31; $i++ ) {
	$codes[] = lk_request( lk_post(), '198.51.100.2' )->get_status();
}
lk_eq( array( $codes[0], $codes[29], $codes[30] ), array( 200, 200, 429 ), 'thirty posts in ten minutes per IP' );

// ---- 10. the lock --------------------------------------------------------------------------------------
lk_boards();
$GLOBALS['wpdb']->rows[ 'leadkit_vlock_' . md5( $V ) ] = (string) ( time() - 120 );
$t = microtime( true );
leadkit_visitor_upsert( $V, lk_post() );
lk_ok( microtime( true ) - $t < 2, 'a lock left by a dead request is taken over at once' );
lk_eq( count( lk_rows() ), 1, 'and the write happens' );
lk_eq( $GLOBALS['wpdb']->rows, array(), 'lock released' );

// ---- 11. the Visits board: a row per visit ----------------------------------------------------
lk_boards();
lk_request( lk_post() );
$vid  = array_key_first( lk_rows() );
$rows = lk_visits();
lk_eq( count( $rows ), 2, 'Visits: one row per visit' );
lk_eq( count( lk_visit_mutations() ), 1, 'Visits: both written in one request' );
list( $a, $b ) = $rows;
lk_eq( $a['name'], 'Oct 11, 2026 9:00am · Social · facebook · light-the-path · post-3', 'Visits: named by when and where from, in their zone' );
lk_eq( $b['name'], 'Oct 14, 2026 9:00am · Email · light-the-path · email-1', 'Visits: the second visit\'s name' );
lk_eq(
	array_intersect_key( $b['values'], array_flip( array( 'text_mm84dxxx', 'board_relation_mm8460nd', 'date_mm84c9a8', 'text_mm84sz4m', 'text_mm84r9fm', 'text_mm84vvee', 'numeric_mm84xnm1', 'numeric_mm84302z', 'numeric_mm84t2yq', 'text_mm847jmh' ) ) ),
	array(
		'text_mm84dxxx'           => $V,
		'board_relation_mm8460nd' => array( 'item_ids' => array( (int) $vid ) ),
		'date_mm84c9a8'           => array( 'date' => '2026-10-14', 'time' => '14:00:00' ),
		'text_mm84sz4m'           => 'mail.google.com',
		'text_mm84r9fm'           => 'medium.email__source.mailchimp__campaign.light-the-path__content.email-1',
		'text_mm84vvee'           => '/hope/',
		'numeric_mm84xnm1'        => '2',
		'numeric_mm84302z'        => '57',
		'numeric_mm84t2yq'        => '1',
		'text_mm847jmh'           => '',
	),
	'Visits: the email visit — connected to its Visitor, Started in UTC, its own referrer and source, /help/ left out'
);
lk_eq( $b['values']['long_text_mm84vzah']['text'], '/hope/ 45s 80% > /donate/ 12s 30% > Donate click', 'Visits: its Journey' );
lk_eq( array( $a['values']['text_mm84sz4m'], $a['values']['numeric_mm84t2yq'] ), array( 'facebook.com', '0' ), 'Visits: the first visit\'s referrer, no clicks' );
lk_ok( 0 === strpos( $a['values']['text_mm84n0qv'], $V . '-' ), 'Visits: visitid is the visitorid plus a visit key', $a['values']['text_mm84n0qv'] );
lk_ok( $a['values']['text_mm84n0qv'] !== $b['values']['text_mm84n0qv'], 'Visits: each visit its own visitid' );

// The same post again: nothing.
LkMonday::$calls = array();
lk_request( lk_post() );
lk_eq( count( lk_visit_mutations() ), 0, 'Visits: a resend writes no visit' );

// A new page in the latest visit: that row alone changes, in place.
$p = lk_post();
$p['page_views'][] = array( 'id' => 'v5', 'path' => '/thank-you/', 'active_time_ms' => 5000, 'max_scroll_percent' => 100, 'time' => gmdate( 'c', strtotime( '2026-10-14 14:05:00 UTC' ) ) );
LkMonday::$calls = array();
lk_request( $p );
$m = lk_visit_mutations();
lk_eq( count( $m ), 1, 'Visits: one request' );
lk_eq( preg_match_all( '/\b[uc]\d+: /', $m[0]['q'] ?? '' ), 1, 'Visits: for one visit' );
lk_ok( false !== strpos( $m[0]['q'] ?? '', 'u0: change_multiple_column_values' ), 'Visits: updated, not created', $m[0]['q'] ?? '' );
lk_eq( count( lk_visits() ), 2, 'Visits: still two rows' );
lk_eq( lk_visits()[1]['values']['numeric_mm84xnm1'], '3', 'Visits: the page counted' );

// Local state lost: rows found by visitid, not doubled.
delete_option( leadkit_visitor_row_key( $V ) );
$p['page_views'][] = array( 'id' => 'v6', 'path' => '/about/', 'active_time_ms' => 1000, 'max_scroll_percent' => 10, 'time' => gmdate( 'c', strtotime( '2026-10-14 14:06:00 UTC' ) ) );
lk_request( $p, '203.0.113.20' );
lk_eq( count( lk_visits() ), 2, 'Visits: state lost — found by visitid, not doubled' );

// The latest visit's row deleted on monday.com: dropped, then made again.
$latest = array_key_last( array_filter( LkMonday::$boards[ VS ]['items'], fn( $it ) => '2026-10-14' === $it['values']['date_mm84c9a8']['date'] ) );
unset( LkMonday::$boards[ VS ]['items'][ $latest ] );
$p['page_views'][] = array( 'id' => 'v7', 'path' => '/team/', 'active_time_ms' => 1000, 'max_scroll_percent' => 10, 'time' => gmdate( 'c', strtotime( '2026-10-14 14:07:00 UTC' ) ) );
lk_request( $p, '203.0.113.21' );
lk_request( $p, '203.0.113.21' );
lk_eq( count( lk_visits() ), 2, 'Visits: a deleted row is made again, once' );

// No campaign at all: each visit's source from the site that sent it.
lk_boards();
$p = lk_post(
	array(
		'utm'         => array(),
		'utm_first'   => array(),
		'utm_touches' => array(),
		'referrer'    => 'google.com',
		'first_referrer' => 'google.com',
		'referrers'   => array(
			array( 'domain' => 'google.com', 'time' => gmdate( 'c', strtotime( '2026-10-11 14:00:00 UTC' ) ) ),
			array( 'domain' => 'facebook.com', 'time' => gmdate( 'c', strtotime( '2026-10-14 14:00:00 UTC' ) ) ),
		),
	)
);
lk_request( $p );
$vals = lk_row( array_key_first( lk_rows() ) )['values'];
lk_eq( array( $vals['text_mm8447yq'], $vals['text_mm84pej6'] ), array( 'medium.organic__source.google', 'medium.referral__source.facebook-com' ), 'no campaign: First source organic Google, Latest source referral Facebook' );
lk_eq( array( $vals['text_mm84778t'], $vals['text_mm84yh3a'] ), array( 'google.com', 'facebook.com' ), 'no campaign: the two referrers' );
lk_eq( array_map( fn( $r ) => $r['values']['text_mm84r9fm'], lk_visits() ), array( 'medium.organic__source.google', 'medium.referral__source.facebook-com' ), 'no campaign: each visit\'s own source' );
lk_eq( array_map( fn( $r ) => explode( ' — ', $r )[1], explode( "\n", $vals['long_text_mm84qwht']['text'] ) ), array( 'Referral · facebook.com', 'Organic search · google.com' ), 'no campaign: the Journey log says where each visit came from' );

// Typed in, every time: nothing invented.
lk_boards();
lk_request( lk_post( array( 'utm' => array(), 'utm_first' => array(), 'utm_touches' => array(), 'referrer' => '', 'first_referrer' => '', 'referrers' => array() ) ) );
$vals = lk_row( array_key_first( lk_rows() ) )['values'];
lk_eq( array( $vals['text_mm8447yq'], $vals['text_mm84pej6'], $vals['text_mm84778t'], $vals['text_mm84yh3a'] ), array( '', '', '', '' ), 'typed in: no source, no referrer' );
lk_eq( array_map( fn( $r ) => array( $r['values']['text_mm84r9fm'], substr( $r['name'], -6 ) ), lk_visits() ), array( array( '', 'Direct' ), array( '', 'Direct' ) ), 'typed in: visits named Direct, blank source' );

// A long history: 40 a write, newest first; the rest next time.
lk_boards();
$t  = strtotime( '2026-08-01 14:00:00 UTC' );
$pv = array();
for ( $i = 0; $i < 45; $i++ ) {
	$pv[] = array( 'id' => 'h' . $i, 'path' => '/p' . $i . '/', 'active_time_ms' => 2000, 'max_scroll_percent' => 10, 'time' => gmdate( 'c', $t + $i * DAY_IN_SECONDS ) );
}
$p = lk_post( array( 'page_views' => $pv, 'donate_clicks' => array( array( 'id' => 'hc', 'time' => gmdate( 'c', $t + 44 * DAY_IN_SECONDS + 10 ), 'path' => '/p44/' ) ) ) );
LkMonday::$calls = array();
lk_request( $p );
lk_eq( array( count( lk_visits() ), count( lk_visit_mutations() ) ), array( 40, 2 ), 'long history: 40 visits in two requests of 20' );
$all = lk_visits();
lk_ok( '/p44/' === $all[39]['values']['text_mm84vvee'] && '/p5/' === $all[0]['values']['text_mm84vvee'], 'long history: the newest 40 first' );
lk_request( $p, '203.0.113.30' );
lk_eq( count( lk_visits() ), 45, 'long history: the rest on the next post' );

// monday.com refuses one visit of a batch: the others are kept; it is made later, once.
lk_boards();
LkMonday::$aliasFault = fn( $alias, $op, $args ) => 'c1' === $alias;
lk_request( lk_post() );
lk_eq( count( lk_visits() ), 1, 'one refused: the other visit is written and remembered' );
LkMonday::$aliasFault = null;
lk_request( lk_post(), '203.0.113.31' );
lk_eq( count( lk_visits() ), 2, 'one refused: written next time, no double' );

// No Visits board set: nothing goes there.
lk_boards();
$o = get_option( 'leadkit_options' );
$o['monday_visits_board'] = '';
update_option( 'leadkit_options', $o );
lk_request( lk_post() );
lk_eq( count( LkMonday::$boards[ VS ]['items'] ), 0, 'no Visits board: no visit rows' );
lk_eq( count( lk_rows() ), 1, 'and the Visitors row is written as before' );

// ---- 12. Open person links, and the gift linker ------------------------------------------------
const GIFTS = '18435185080'; // DEMO – Donations
function lk_gift_boards() {
	lk_boards();
	LkMonday::board( PEOPLE, array( 'name' => array( 'Name', 'name' ), 'email_mm83fpfp' => array( 'Email', 'email' ) ) );
	LkMonday::board(
		GIFTS,
		array(
			'name'                    => array( 'Name', 'name' ),
			'board_relation_mm83z7sa' => array( 'Person', 'board_relation' ),
			'email_mm846aas'          => array( 'Donor email', 'email' ),
			'link_mm84y7n4'           => array( 'Open person', 'link' ),
			'board_relation_mm84k7qr' => array( 'Visitor', 'board_relation' ),
			'text_mm84wm8e'           => array( 'visitorid', 'text' ),
		)
	);
	$o                           = get_option( 'leadkit_options' );
	$o['monday_donations_board'] = GIFTS;
	update_option( 'leadkit_options', $o );
}
function lk_person( $name, $email ) {
	$r = LkMonday::answer( 'mutation ($b: ID!, $n: String!, $v: JSON) { create_item(board_id: $b, item_name: $n, column_values: $v) { id url } }', array( 'b' => PEOPLE, 'n' => $name, 'v' => json_encode( array( 'email_mm83fpfp' => array( 'email' => $email, 'text' => $email ) ) ) ) );
	return $r['data']['create_item']['id'];
}
function lk_gift( $name, $vals, $age = 20 * MINUTE_IN_SECONDS ) {
	$id = (string) ( LkMonday::$next++ );
	LkMonday::$boards[ GIFTS ]['items'][ $id ] = array( 'name' => $name, 'created_at' => gmdate( 'c', time() - $age ), 'values' => $vals );
	return $id;
}
function lk_item( $board, $id ) {
	return LkMonday::$boards[ $board ]['items'][ $id ]['values'] ?? array();
}
function lk_people_named( $name ) {
	return array_keys( array_filter( LkMonday::$boards[ PEOPLE ]['items'], fn( $it ) => $it['name'] === $name ) );
}

// A form submission: the browser's row and its visits link to the person's card.
lk_gift_boards();
$maria = lk_person( 'Maria Delgado', 'maria@example.org' );
$analytics = lk_post();
$analytics['id'] = $V;
remove_all_actions( 'shutdown' );
leadkit_queue_visitor( $V, $analytics, array( 'person' => $maria, 'form' => 'Volunteer Inquiry' ) );
do_action( 'shutdown' );
$vrow = array_key_first( lk_rows() );
lk_eq( lk_item( VB, $vrow )['link_mm84cbre'] ?? null, array( 'url' => LkMonday::url( PEOPLE, $maria ), 'text' => 'Maria Delgado' ), 'form: the browser row opens the person' );
lk_eq( array_values( array_unique( array_map( fn( $r ) => $r['values']['link_mm84xwdw']['text'] ?? '-', lk_visits() ) ) ), array( 'Maria Delgado' ), 'form: every visit row opens the person' );

// The gift linker.
lk_gift_boards();
$grace = lk_person( 'Grace Liu', 'grace@example.org' );
$other = lk_person( 'Someone Else', 'else@example.org' );
// A browser LeadKit knows, with no person yet (a donor who never sent a form).
lk_request( lk_post() );
$vrow = array_key_first( lk_rows() );
// And a second browser that already belongs to someone (a shared computer).
lk_request( array_merge( lk_post(), array( 'visitor_id' => 'b0b0b0b0-shared' ) ), '203.0.113.40' );
$shared = array_key_last( lk_rows() );
LkMonday::$boards[ VB ]['items'][ $shared ]['values']['board_relation_mm84ysfv'] = array( 'item_ids' => array( (int) $other ) );

$g_exist   = lk_gift( 'Grace Liu', array( 'email_mm846aas' => array( 'email' => 'Grace@Example.org', 'text' => 'Grace@Example.org' ) ) );
$g_new     = lk_gift( 'New Donor', array( 'email_mm846aas' => array( 'email' => 'new@example.org', 'text' => 'new@example.org' ), 'text_mm84wm8e' => $V, 'board_relation_mm84k7qr' => array( 'item_ids' => array( (int) $vrow ) ) ) );
$g_linked  = lk_gift( 'Grace Liu', array( 'email_mm846aas' => array( 'email' => 'grace@example.org', 'text' => 'grace@example.org' ), 'board_relation_mm83z7sa' => array( 'item_ids' => array( (int) $grace ) ) ) );
$g_shared  = lk_gift( 'Grace Liu', array( 'email_mm846aas' => array( 'email' => 'grace@example.org', 'text' => 'grace@example.org' ), 'text_mm84wm8e' => 'b0b0b0b0-shared', 'board_relation_mm84k7qr' => array( 'item_ids' => array( (int) $shared ) ) ) );
$g_pending = lk_gift( 'Grace Liu', array( 'email_mm846aas' => array( 'email' => 'grace@example.org', 'text' => 'grace@example.org' ), 'text_mm84wm8e' => 'c1c1c1c1-pending' ) );
$g_noemail = lk_gift( 'Anonymous', array() );

$run = leadkit_link_gifts();
lk_eq( array( $run['looked'], $run['people'] ), array( 6, 1 ), 'linker: six gifts looked at, one new person made' );
lk_eq( lk_item( GIFTS, $g_exist )['board_relation_mm83z7sa'] ?? null, array( 'item_ids' => array( (int) $grace ) ), 'linker: an existing donor is found by email (any case), not duplicated' );
lk_eq( count( lk_people_named( 'Grace Liu' ) ), 1, 'linker: still one Grace' );
$made = lk_people_named( 'New Donor' );
lk_eq( count( $made ), 1, 'linker: a new donor is created on People' );
lk_eq( LkMonday::$boards[ PEOPLE ]['items'][ $made[0] ]['values']['email_mm83fpfp']['email'] ?? null, 'new@example.org', 'linker: with their email' );
lk_eq( lk_item( GIFTS, $g_new )['board_relation_mm83z7sa'] ?? null, array( 'item_ids' => array( (int) $made[0] ) ), 'linker: and the gift points at them' );
lk_eq( lk_item( GIFTS, $g_new )['link_mm84y7n4']['text'] ?? null, 'New Donor', 'linker: the gift opens the person' );
lk_eq( lk_item( GIFTS, $g_linked )['link_mm84y7n4']['url'] ?? null, LkMonday::url( PEOPLE, $grace ), 'linker: a gift the automation already connected gets its link' );
lk_eq( lk_item( VB, $vrow )['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( (int) $made[0] ) ), 'linker: the gift\'s browser gets the gift\'s person' );
lk_eq( lk_item( VB, $vrow )['link_mm84cbre']['text'] ?? null, 'New Donor', 'linker: and opens them' );
lk_eq( array_values( array_unique( array_map( fn( $r ) => $r['values']['link_mm84xwdw']['text'] ?? '-', array_filter( lk_visits(), fn( $r ) => $r['values']['text_mm84dxxx'] === $V ) ) ) ), array( 'New Donor' ), 'linker: so do that browser\'s visit rows' );
lk_eq( get_option( leadkit_visitor_row_key( $V ) )['linked'] ?? null, (string) $made[0], 'linker: LeadKit\'s own record of the browser knows the person' );
lk_eq( lk_item( VB, $shared )['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( (int) $other ) ), 'linker: a browser that belongs to someone else is left alone' );
lk_eq( lk_item( GIFTS, $g_shared )['board_relation_mm83z7sa'] ?? null, array( 'item_ids' => array( (int) $grace ) ), 'linker: and that gift is still on the right person, by email' );
$done = get_option( 'leadkit_gifts_done' );
lk_eq( array( isset( $done[ $g_exist ] ), isset( $done[ $g_new ] ), isset( $done[ $g_linked ] ), isset( $done[ $g_shared ] ), isset( $done[ $g_pending ] ), isset( $done[ $g_noemail ] ) ), array( true, true, true, true, false, false ), 'linker: finished gifts are remembered; one waiting for its visitor, and one with no email, are not' );

// Run again: nothing to do, nothing written.
LkMonday::$calls = array();
leadkit_link_gifts();
lk_eq( count( LkMonday::mutations() ), 0, 'linker: a second run writes nothing' );

// The visitor match arrives later: the next run links that browser.
lk_request( array_merge( lk_post(), array( 'visitor_id' => 'c1c1c1c1-pending' ) ), '203.0.113.41' );
$late = array_key_last( lk_rows() );
LkMonday::$boards[ GIFTS ]['items'][ $g_pending ]['values']['board_relation_mm84k7qr'] = array( 'item_ids' => array( (int) $late ) );
leadkit_link_gifts();
lk_eq( lk_item( VB, $late )['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( (int) $grace ) ), 'linker: a visitor match that comes later is picked up' );

// A QR gift (no visitorid) is finished once its person is linked; a gift with no
// email and no hope of one is let go after a week.
$g_qr  = lk_gift( 'Tom Becker', array( 'email_mm846aas' => array( 'email' => 'tom@example.org', 'text' => 'tom@example.org' ) ) );
$g_old = lk_gift( 'Old anonymous', array(), 8 * DAY_IN_SECONDS );
leadkit_link_gifts();
$done = get_option( 'leadkit_gifts_done' );
lk_eq( array( isset( $done[ $g_qr ] ), isset( $done[ $g_old ] ) ), array( true, true ), 'linker: QR gift finished; week-old gift with nothing to go on let go' );

// A gift that just arrived with no Person is Zapier's for 10 minutes: creating
// the donor while the Zap does would make two people. One already connected is
// not waited on.
lk_gift_boards();
$g_young = lk_gift( 'Fresh Donor', array( 'email_mm846aas' => array( 'email' => 'fresh@example.org', 'text' => 'fresh@example.org' ) ), 60 );
$g_auto  = lk_gift( 'Grace Liu', array( 'board_relation_mm83z7sa' => array( 'item_ids' => array( (int) $grace ) ) ), 60 );
leadkit_link_gifts();
$done = get_option( 'leadkit_gifts_done' );
lk_eq( array( count( lk_people_named( 'Fresh Donor' ) ), isset( $done[ $g_young ] ) ), array( 0, false ), 'linker: a gift under 10 minutes old with no Person is left to Zapier, and not let go' );
lk_eq( lk_item( GIFTS, $g_auto )['link_mm84y7n4']['url'] ?? null, LkMonday::url( PEOPLE, $grace ), 'linker: a young gift already connected gets its link at once' );
LkMonday::$boards[ GIFTS ]['items'][ $g_young ]['created_at'] = gmdate( 'c', time() - 11 * MINUTE_IN_SECONDS );
leadkit_link_gifts();
lk_eq( count( lk_people_named( 'Fresh Donor' ) ), 1, 'linker: after 10 minutes with no Person, LeadKit makes the donor' );

// monday.com unreachable mid-run: no person is invented.
lk_gift_boards();
$g = lk_gift( 'Careful Donor', array( 'email_mm846aas' => array( 'email' => 'careful@example.org', 'text' => 'careful@example.org' ) ) );
LkMonday::$fault = fn( $q, $v ) => false !== strpos( $q, 'items_page_by_column_values' ) ? array( 'errors' => array( array( 'message' => 'Internal' ) ) ) : null;
leadkit_link_gifts();
LkMonday::$fault = null;
lk_eq( count( lk_people_named( 'Careful Donor' ) ), 0, 'linker: lookup failed — nobody created' );
leadkit_link_gifts();
lk_eq( count( lk_people_named( 'Careful Donor' ) ), 1, 'linker: next run, created once' );

// Off without a Donations board, and the schedule follows the setting.
$GLOBALS['lk_cron'] = array();
function wp_next_scheduled( $h ) {
	return $GLOBALS['lk_cron'][ $h ] ?? false;
}
function wp_schedule_event( $t, $r, $h ) {
	$GLOBALS['lk_cron'][ $h ] = $r;
}
function wp_clear_scheduled_hook( $h ) {
	unset( $GLOBALS['lk_cron'][ $h ] );
}
do_action( 'init' );
lk_eq( $GLOBALS['lk_cron']['leadkit_link_gifts'] ?? null, 'leadkit_quarter_hour', 'linker: scheduled every 15 minutes when set up' );
$o = get_option( 'leadkit_options' );
$o['monday_donations_board'] = '';
update_option( 'leadkit_options', $o );
do_action( 'init' );
lk_eq( $GLOBALS['lk_cron'], array(), 'linker: unscheduled when the board is cleared' );
lk_eq( leadkit_link_gifts()['looked'], 0, 'linker: does nothing without a Donations board' );
lk_eq( apply_filters( 'cron_schedules', array() )['leadkit_quarter_hour']['interval'] ?? null, 900, 'linker: the 15-minute schedule exists' );

// Return visits keep the Visitors board current — from a 1.5.1+ tracker only.
lk_boards();
lk_request( lk_post() );
$vrow = array_key_first( lk_rows() );
$sync = lk_post();
$sync['lead_at']      = gmdate( 'c', strtotime( '2026-10-14 13:00:00 UTC' ) );
$sync['page_views'][] = array( 'id' => 'later', 'path' => '/events/', 'active_time_ms' => 20000, 'max_scroll_percent' => 40, 'time' => gmdate( 'c', strtotime( '2026-10-14 15:30:00 UTC' ) ) );
remove_all_actions( 'shutdown' );
leadkit_handle_sync( new WP_REST_Request( json_encode( $sync ) ) );
do_action( 'shutdown' );
lk_eq( lk_item( VB, $vrow )['numeric_mm84mt3y'] ?? null, '3', 'return visit: the Visitors row counts the new visit' );
lk_ok( false !== strpos( lk_item( VB, $vrow )['long_text_mm84qwht']['text'] ?? '', '/events/' ), 'return visit: and logs it' );
lk_eq( count( lk_visits() ), 3, 'return visit: with its own visit row' );
$old = $sync;
unset( $old['referrers'] );
$old['page_views'][] = array( 'id' => 'later2', 'path' => '/blog/', 'active_time_ms' => 20000, 'max_scroll_percent' => 40, 'time' => gmdate( 'c', strtotime( '2026-10-14 17:00:00 UTC' ) ) );
delete_transient( 'leadkit_vsync_' . md5( $V ) );
remove_all_actions( 'shutdown' );
leadkit_handle_sync( new WP_REST_Request( json_encode( $old ) ) );
do_action( 'shutdown' );
lk_eq( count( lk_visits() ), 3, 'return visit from an older tracker: Visitors board left alone' );

lk_done();
