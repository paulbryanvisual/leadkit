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
		)
	);
	update_option(
		'leadkit_options',
		array(
			'monday_token'          => 'test-token',
			'monday_visitors_board' => VB,
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
/** A Donate-click post, as tracker.js visitorBody() sends it. */
function lk_post( $extra = array() ) {
	$t0 = strtotime( '2026-10-14 14:00:00 UTC' );
	return array_merge(
		array(
			'visitor_id'    => 'c0ffee00-1234-4abc-9def-0123456789ab',
			'started_at'    => gmdate( 'c', $t0 - 3 * DAY_IN_SECONDS ),
			'referrer'      => '',
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
$st   = array( 'clicks' => array(), 'forms' => array(), 'returns' => array(), 'touches' => array() );
$text = leadkit_visitor_log( leadkit_visitor_visits( $many ), $st );
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

lk_done();
