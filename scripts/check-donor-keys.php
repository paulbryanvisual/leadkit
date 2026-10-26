<?php
/**
 * check-donor-keys.php — a donor key's browser, from the Visits Worker to
 * monday.com.
 *
 * The Worker checks the key's code (scripts/check-worker.mjs in the Worker's
 * repository); here a fake Worker hands over browsers it has vouched for, as
 * GET /warm does, and the fake monday.com in scripts/wp-stubs.php records
 * every write: a valid b- key (the donor found by Bloomerang Account,
 * connected with Open person, every earlier visit written and linked,
 * Clicked email link set) and nothing else on the People board; a repeat
 * visit and lost local state writing nothing more; a valid m- key, and one
 * that is not on the People board; a Bloomerang id with no match, tried
 * again a day later; one two people share; a Visitors row that already has
 * a different person; a bad key (the Worker drops it, so nothing happens);
 * a visitor with no key, written exactly as before; and the route that
 * writes a browser the moment its key arrives.
 *
 *   php scripts/check-donor-keys.php
 *
 * Exits non-zero on any failure.
 */

// Development checks: never over the web, even if this folder is ever deployed.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/wp-stubs.php';

const VB     = '18435450271'; // DEMO – Visitors
const VS     = '18435475074'; // DEMO – Visits
const PB     = '18435400063'; // DEMO – People
const WORKER = 'https://v.3sgf.org';
const SECRET = 'test-secret';

/** A fake Visits Worker: the browsers it vouches for, every call recorded. */
class LkWorker {
	public static $rows  = array(); // visitor => { story, hash, pending, dk }
	public static $calls = array();
	public static function put( $visitor, $story, $dk = null ) {
		self::$rows[ $visitor ] = array( 'story' => $story, 'hash' => md5( json_encode( array( $story, $dk ) ) ), 'pending' => 1, 'dk' => $dk );
	}
	public static function answer( $url, $args ) {
		$path          = substr( $url, strlen( WORKER ) );
		$body          = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		self::$calls[] = $args['method'] . ' ' . $path;
		if ( ( $args['headers']['Authorization'] ?? '' ) !== 'Bearer ' . SECRET ) {
			return array( 'code' => 401, 'body' => '{"ok":false}' );
		}
		$out = array( 'ok' => true );
		if ( 0 === strpos( $path, '/warm' ) ) {
			parse_str( (string) parse_url( $path, PHP_URL_QUERY ), $q );
			$out = array( 'browsers' => array() );
			foreach ( self::$rows as $v => $r ) {
				if ( $r['pending'] && count( $out['browsers'] ) < (int) $q['limit'] && ( empty( $q['visitor'] ) || $q['visitor'] === $v ) ) {
					$out['browsers'][] = array( 'visitor' => $v, 'hash' => $r['hash'], 'story' => $r['story'] ) + ( $r['dk'] ? array( 'dk' => $r['dk'] ) : array() );
				}
			}
		} elseif ( '/sent' === $path ) {
			foreach ( $body['sent'] as $s ) {
				if ( isset( self::$rows[ $s['visitor'] ] ) && self::$rows[ $s['visitor'] ]['hash'] === $s['hash'] ) {
					self::$rows[ $s['visitor'] ]['pending'] = 0;
				}
			}
		}
		return array( 'code' => 200, 'body' => json_encode( $out ) );
	}
}
function wp_remote_request( $url, $args ) {
	if ( 0 !== strpos( $url, WORKER . '/' ) ) {
		throw new Exception( 'unexpected request: ' . $url );
	}
	return LkWorker::answer( $url, $args );
}

function lk_setup() {
	lk_reset();
	LkWorker::$rows  = array();
	LkWorker::$calls = array();
	LkMonday::board(
		VB,
		array(
			'name'                    => array( 'Name', 'name' ),
			'text_mm849mr5'           => array( 'visitorid', 'text' ),
			'date_mm84kpqp'           => array( 'First seen', 'date' ),
			'date_mm84w8jj'           => array( 'Last seen', 'date' ),
			'numeric_mm84mt3y'        => array( 'Visits', 'numbers' ),
			'text_mm8447yq'           => array( 'First source', 'text' ),
			'text_mm84pej6'           => array( 'Latest source', 'text' ),
			'numeric_mm84e0t6'        => array( 'Donate clicks', 'numbers' ),
			'long_text_mm84qwht'      => array( 'Journey log', 'long_text' ),
			'text_mm84778t'           => array( 'First referrer', 'text' ),
			'text_mm84yh3a'           => array( 'Latest referrer', 'text' ),
			'link_mm84cbre'           => array( 'Open person', 'link' ),
			'board_relation_mm84ysfv' => array( 'Person', 'board_relation' ),
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
	LkMonday::board(
		PB,
		array(
			'name'           => array( 'Name', 'name' ),
			'email_mm84abcd' => array( 'Email', 'email' ),
			'text_mm8cb5hv'  => array( 'Bloomerang Account', 'text' ),
			'date_mm8gs213'  => array( 'Clicked email link', 'date' ),
		)
	);
	$people = array(
		'7001' => array( 'Donor One', '12345', null ),
		'7002' => array( 'Donor Two', '', '2026-10-01' ),
		'7003' => array( 'Someone Else', '', null ),
		'7004' => array( 'Twin A', '999', null ),
		'7005' => array( 'Twin B', '999', null ),
		'7006' => array( 'Near Miss', '123456', null ),
	);
	foreach ( $people as $id => $p ) {
		LkMonday::$boards[ PB ]['items'][ $id ] = array(
			'name'   => $p[0],
			'values' => array_filter( array( 'text_mm8cb5hv' => $p[1], 'date_mm8gs213' => $p[2] ? array( 'date' => $p[2] ) : null ) ),
		);
	}
	update_option(
		'leadkit_options',
		array(
			'monday_token'          => 'test-token',
			'monday_visitors_board' => VB,
			'monday_visits_board'   => VS,
			'monday_people_board'   => PB,
			'visits_url'            => WORKER,
			'visits_secret'         => SECRET,
		)
	);
}
function lk_view( $id, $path, $t, $ms = 20000 ) {
	return array( 'id' => $id, 'path' => $path, 'active_time_ms' => $ms, 'max_scroll_percent' => 60, 'time' => gmdate( 'c', $t ) );
}
function lk_story( $views ) {
	return array(
		'started_at'       => $views[0]['time'],
		'referrer'         => '',
		'referrers'        => array(),
		'utm'              => array(),
		'utm_first'        => array(),
		'utm_touches'      => array(),
		'page_views'       => $views,
		'donate_clicks'    => array(),
		'lead_forms'       => array(),
		'donation_returns' => array(),
		'timezone'         => 'America/Chicago',
	);
}
/** The Visitors row for a visitor id, or null. */
function lk_vrow( $visitor ) {
	foreach ( LkMonday::$boards[ VB ]['items'] as $id => $it ) {
		if ( ( $it['values']['text_mm849mr5'] ?? '' ) === $visitor ) {
			return array( 'id' => (string) $id ) + $it;
		}
	}
	return null;
}
function lk_person( $id ) {
	return LkMonday::$boards[ PB ]['items'][ $id ];
}
/** The board a call names (one id, or a list of them). */
function lk_board_of( $c ) {
	return implode( ',', (array) ( $c['v']['b'] ?? '' ) );
}
/** Every write that touched the People board, columns included. */
function lk_people_writes() {
	return array_values( array_filter( LkMonday::mutations(), fn( $c ) => PB === lk_board_of( $c ) ) );
}
/** Writes that set a Visitors row's Person. */
function lk_person_writes() {
	return array_values( array_filter( LkMonday::mutations(), fn( $c ) => false !== strpos( (string) ( $c['v']['v'] ?? '' ), 'board_relation_mm84ysfv' ) ) );
}
function lk_dk_log() {
	return array_map( fn( $n ) => $n['key'] . ' ' . $n['what'], (array) get_option( 'leadkit_dk_log', array() ) );
}

$A     = 'a11ce000-0000-4000-8000-000000000001';
$B     = 'b0b00000-0000-4000-8000-000000000002';
$C     = 'c0ffee00-0000-4000-8000-000000000003';
$D     = 'd00d0000-0000-4000-8000-000000000004';
$E     = 'e1e10000-0000-4000-8000-000000000005';
$t0    = time() - 5 * DAY_IN_SECONDS;
$t1    = $t0 + 2 * DAY_IN_SECONDS;
$today = ( new DateTime( 'now', new DateTimeZone( 'America/Chicago' ) ) )->format( 'Y-m-d' );
$url   = fn( $id ) => LkMonday::url( PB, $id );

// ---- 1. a valid b- link: the donor, every earlier visit, the first click --------------------
lk_setup();
// Two anonymous visits days ago, then today's from the email link.
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/', $t0 ), lk_view( 'a2', '/hope/', $t1 ), lk_view( 'a3', '/give/', time() - 60 ) ) ), array( 'prefix' => 'b', 'id' => '12345', 'first_click_at' => gmdate( 'c' ) ) );
lk_eq( leadkit_send_warm_browsers(), 1, 'b-: the browser is written' );
$row = lk_vrow( $A );
lk_eq( $row['values']['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( 7001 ) ), 'b-: Person is the People row whose Bloomerang Account is 12345 (not 123456)' );
lk_eq( $row['values']['link_mm84cbre'] ?? null, array( 'url' => $url( '7001' ), 'text' => 'Donor One' ), 'b-: Open person goes to their card' );
$visits = array_values( LkMonday::$boards[ VS ]['items'] );
lk_eq( count( $visits ), 3, 'b-: every earlier visit is written, not only the click' );
lk_ok( array_filter( $visits, fn( $v ) => ( $v['values']['link_mm84xwdw']['url'] ?? '' ) === $url( '7001' ) ) === $visits, 'b-: and each one links to the donor', array_map( fn( $v ) => $v['values']['link_mm84xwdw'] ?? null, $visits ) );
lk_eq( lk_person( '7001' )['values']['date_mm8gs213'] ?? null, array( 'date' => $today ), 'b-: Clicked email link set to today' );
$pw = lk_people_writes();
lk_ok( 1 === count( $pw ) && array( 'date_mm8gs213' ) === array_keys( json_decode( $pw[0]['v']['v'], true ) ), 'b-: on the People board, that date is the only write — no column made, nothing else changed', array_map( fn( $c ) => $c['v'], $pw ) );
lk_eq( array_keys( LkMonday::$boards[ PB ]['cols'] ), array( 'name', 'email_mm84abcd', 'text_mm8cb5hv', 'date_mm8gs213' ), 'b-: the People board\'s columns are as they were' );
lk_eq( LkWorker::$rows[ $A ]['pending'], 0, 'b-: reported to the Worker as sent' );
lk_eq( lk_dk_log(), array( 'b-12345 connected to People item 7001', 'b-12345 "Clicked email link" set on People item 7001' ), 'b-: noted' );

// ---- 2. a repeat visit: nothing more -------------------------------------------------------
$before  = count( LkMonday::mutations() );
$looked  = fn() => count( array_filter( LkMonday::$calls, fn( $c ) => false !== strpos( $c['q'], 'items_page_by_column_values' ) && PB === lk_board_of( $c ) ) );
$looked0 = $looked();
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/', $t0 ), lk_view( 'a2', '/hope/', $t1 ), lk_view( 'a3', '/give/', time() - 60 ), lk_view( 'a4', '/about/', time() - 10 ) ) ), array( 'prefix' => 'b', 'id' => '12345', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
lk_eq( array( count( lk_people_writes() ), count( lk_person_writes() ) ), array( 1, 1 ), 'repeat: no new People write, no new Person write' );
lk_ok( count( LkMonday::mutations() ) > $before, 'repeat: the new page is still written (the usual sync)' );
lk_eq( count( lk_dk_log() ), 2, 'repeat: nothing new noted' );
lk_eq( $looked() - $looked0, 0, 'repeat: the donor is not even looked up again' );

// The browser's local state lost: monday.com is read first, so still nothing more.
delete_option( leadkit_visitor_row_key( $A ) );
LkWorker::put( $A, LkWorker::$rows[ $A ]['story'], array( 'prefix' => 'b', 'id' => '12345', 'first_click_at' => gmdate( 'c' ) ) );
LkWorker::$rows[ $A ]['hash'] .= 'x';
leadkit_send_warm_browsers();
lk_eq( array( count( lk_people_writes() ), count( lk_person_writes() ) ), array( 1, 1 ), 'state lost: still no new People or Person write' );
lk_eq( lk_vrow( $A )['values']['board_relation_mm84ysfv']['item_ids'] ?? null, array( 7001 ), 'state lost: the same row, the same person' );

// ---- 3. a valid m- link ---------------------------------------------------------------------
LkWorker::put( $B, lk_story( array( lk_view( 'b1', '/', time() - 30 ) ) ), array( 'prefix' => 'm', 'id' => '7002', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
lk_eq( lk_vrow( $B )['values']['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( 7002 ) ), 'm-: Person is that People item' );
lk_eq( lk_person( '7002' )['values']['date_mm8gs213'] ?? null, array( 'date' => '2026-10-01' ), 'm-: a Clicked email link already set is left alone' );

// An m- id that is an item, but not on the People board.
$visit_item = (string) array_key_first( LkMonday::$boards[ VS ]['items'] );
LkWorker::put( $D, lk_story( array( lk_view( 'd1', '/', time() - 30 ) ) ), array( 'prefix' => 'm', 'id' => $visit_item, 'first_click_at' => gmdate( 'c' ) ) );
$pw = count( lk_people_writes() );
leadkit_send_warm_browsers();
lk_ok( lk_vrow( $D ) && empty( lk_vrow( $D )['values']['board_relation_mm84ysfv'] ), 'm- off the People board: the visitor is written, with no Person' );
lk_eq( count( lk_people_writes() ), $pw, 'm- off the People board: nothing on People' );
lk_eq( array_slice( lk_dk_log(), -1 ), array( 'm-' . $visit_item . ' no match: no such item on the People board' ), 'm- off the People board: noted' );

// ---- 4. a Bloomerang id with no match, then one two people share --------------------------------
lk_setup();
LkWorker::put( $C, lk_story( array( lk_view( 'c1', '/', time() - 30 ) ) ), array( 'prefix' => 'b', 'id' => '55555', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
lk_ok( lk_vrow( $C ) && empty( lk_vrow( $C )['values']['board_relation_mm84ysfv'] ), 'no match: the visitor is still written, connected to no one' );
lk_eq( array( count( lk_people_writes() ), lk_dk_log() ), array( 0, array( 'b-55555 no match: no People row has Bloomerang Account 55555' ) ), 'no match: nothing on People, noted' );
$lookups = fn() => count( array_filter( LkMonday::$calls, fn( $c ) => false !== strpos( $c['q'], 'items_page_by_column_values' ) && PB === lk_board_of( $c ) ) );
$n       = $lookups();
LkWorker::put( $C, lk_story( array( lk_view( 'c1', '/', time() - 30 ), lk_view( 'c2', '/hope/', time() - 5 ) ) ), array( 'prefix' => 'b', 'id' => '55555', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
lk_eq( $lookups(), $n, 'no match: not looked up again within the day' );
// A day later, and the donor has been added to People since.
$st            = get_option( leadkit_visitor_row_key( $C ) );
$st['dk_miss'] = time() - DAY_IN_SECONDS - 60;
update_option( leadkit_visitor_row_key( $C ), $st );
LkMonday::$boards[ PB ]['items']['7007'] = array( 'name' => 'Added Later', 'values' => array( 'text_mm8cb5hv' => '55555' ) );
LkWorker::put( $C, lk_story( array( lk_view( 'c1', '/', time() - 30 ), lk_view( 'c2', '/hope/', time() - 5 ), lk_view( 'c3', '/give/', time() - 1 ) ) ), array( 'prefix' => 'b', 'id' => '55555', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
lk_eq( lk_vrow( $C )['values']['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( 7007 ) ), 'no match: a day later it is looked up again, and connected' );

LkWorker::put( $E, lk_story( array( lk_view( 'e1', '/', time() - 30 ) ) ), array( 'prefix' => 'b', 'id' => '999', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
lk_ok( empty( lk_vrow( $E )['values']['board_relation_mm84ysfv'] ), 'two People rows share the id: connected to neither' );
lk_eq( array_slice( lk_dk_log(), -1 ), array( 'b-999 not connected: 2 People rows have Bloomerang Account 999 (7004, 7005)' ), 'two People rows share the id: noted' );

// ---- 5. a Visitors row that already has someone else -----------------------------------------
lk_setup();
// Written before, from a form: Person is someone else.
LkMonday::$boards[ VB ]['items']['5001'] = array(
	'name'   => $C,
	'values' => array( 'text_mm849mr5' => $C, 'board_relation_mm84ysfv' => array( 'item_ids' => array( 7003 ) ), 'link_mm84cbre' => array( 'url' => $url( '7003' ), 'text' => 'Someone Else' ) ),
);
LkWorker::put( $C, lk_story( array( lk_view( 'c1', '/', time() - 30 ) ) ), array( 'prefix' => 'b', 'id' => '12345', 'first_click_at' => gmdate( 'c' ) ) );
leadkit_send_warm_browsers();
$row = lk_vrow( $C );
lk_eq( array( $row['id'], $row['values']['board_relation_mm84ysfv'], $row['values']['link_mm84cbre']['url'] ), array( '5001', array( 'item_ids' => array( 7003 ) ), $url( '7003' ) ), 'a different Person already: kept, Person and Open person untouched' );
lk_eq( count( lk_person_writes() ), 0, 'a different Person already: no Person write at all' );
lk_eq( lk_dk_log()[0] ?? '', 'b-12345 not connected: the Visitors row already has People item 7003, not 7001', 'a different Person already: the conflict noted' );
lk_eq( lk_person( '7001' )['values']['date_mm8gs213'] ?? null, array( 'date' => $today ), 'a different Person already: the donor did click, so Clicked email link is still set' );

// ---- 6. a bad key, and no key at all ------------------------------------------------------------
lk_setup();
// A bad code: the Worker dropped it, so the browser is not warm and is not handed over.
lk_eq( leadkit_send_warm_browsers(), 0, 'bad code: the Worker hands nothing over' );
lk_eq( LkMonday::mutations(), array(), 'bad code: nothing written to monday.com' );
// A browser warm for another reason (a Donate click), with no key: as before.
LkWorker::put( $D, lk_story( array( lk_view( 'd1', '/give/', time() - 30 ) ) ) );
leadkit_send_warm_browsers();
lk_ok( lk_vrow( $D ) && empty( lk_vrow( $D )['values']['board_relation_mm84ysfv'] ), 'no key: written, no Person' );
$asked = array_filter( LkMonday::$calls, fn( $c ) => PB === lk_board_of( $c ) || false !== strpos( $c['q'], 'BoardRelationValue' ) || false !== strpos( $c['q'], 'board { id }' ) );
lk_eq( array( array_values( $asked ), lk_people_writes(), get_option( 'leadkit_dk_log', array() ) ), array( array(), array(), array() ), 'no key: the People board is not even read, nothing noted' );

// A key the Worker sent malformed is ignored.
lk_setup();
LkWorker::put( $D, lk_story( array( lk_view( 'd1', '/', time() - 30 ) ) ), array( 'prefix' => 'x', 'id' => '12345' ) );
leadkit_send_warm_browsers();
lk_eq( array( count( lk_person_writes() ), count( lk_people_writes() ) ), array( 0, 0 ), 'a malformed key from the Worker: ignored' );

// ---- 7. written the moment the key arrives -------------------------------------------------------
lk_setup();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
LkWorker::put( $B, lk_story( array( lk_view( 'b0', '/', time() - 900 ) ) ) ); // Another warm browser.
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/give/', time() - 5 ) ) ), array( 'prefix' => 'b', 'id' => '12345', 'first_click_at' => gmdate( 'c' ) ) );
$res = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $A ) ) ) );
lk_eq( array( $res->get_status(), LkWorker::$calls ), array( 200, array() ), 'now: answered at once, the work left for after the answer' );
do_action( 'shutdown' );
lk_eq( LkWorker::$calls, array( 'GET /warm?limit=1&visitor=' . $A, 'POST /known', 'POST /sent' ), 'now: that browser alone is asked for, marked known (a new Visitors row, as always) and reported' );
lk_eq( lk_vrow( $A )['values']['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( 7001 ) ), 'now: connected to the donor without waiting for the background run' );
lk_ok( null === lk_vrow( $B ), 'now: no other browser written' );
remove_all_actions( 'shutdown' );
$res = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $A ) ) ) );
lk_eq( array( $res->get_status(), has_shutdown_work() ), array( 200, false ), 'now: the same browser again within a minute is not run twice' );
lk_eq( leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => 'no!' ) ) ) )->get_status(), 400, 'now: a bad visitor id is refused' );
for ( $i = 0; $i < 12; $i++ ) {
	$last = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => sprintf( 'f%07d-0000-4000-8000-000000000000', $i ) ) ) ) );
}
lk_eq( $last->get_status(), 429, 'now: braked per IP' );
lk_setup();
update_option( 'leadkit_options', array( 'visits_url' => '' ) + get_option( 'leadkit_options' ) );
remove_all_actions( 'shutdown' );
$res = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $A ) ) ) );
lk_eq( array( $res->get_status(), $res->data['off'] ?? null, has_shutdown_work() ), array( 200, true, false ), 'now: no Visits Worker, nothing to do' );

lk_done();

/** Whether anything is waiting for the end of the request. */
function has_shutdown_work() {
	return ! empty( $GLOBALS['lk_actions']['shutdown'] );
}
