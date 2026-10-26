<?php
/**
 * check-donor-keys.php — a donor key from an email link, from the tracker's
 * post to monday.com.
 *
 * A key is good when it is exactly a person's "Donor key" on the People
 * board. Against a fake Visits Worker (its /warm, /donor, /sent and /known)
 * and the fake monday.com in scripts/wp-stubs.php, which records every
 * write: a valid b- key (matched, sent to the Worker, the browser written at
 * once with Person, Open person, every earlier visit linked, and Clicked
 * email link set — nothing else on People); a repeat visit and lost local
 * state writing nothing more; a valid m- key; a bad code and an id with no
 * match (nothing kept, nothing sent to the Worker); a key two people share;
 * a Visitors row that already has a different person; monday.com or the
 * Worker down (the key waits for the next run, for a week); a visitor with
 * no key, written exactly as before; and the route's refusals and brakes.
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

/** A fake Visits Worker: rows in memory, every call recorded. */
class LkWorker {
	public static $rows  = array(); // visitor => { story, hash, pending, dk }
	public static $calls = array();
	public static $down  = array(); // Paths that answer 500.
	/** @var callable|null Runs during POST /donor: another request arriving meanwhile. */
	public static $onDonor = null;
	public static function put( $visitor, $story, $dk = null, $pending = 1 ) {
		self::$rows[ $visitor ] = array( 'story' => $story, 'hash' => md5( json_encode( array( $story, $dk ) ) ), 'pending' => $pending, 'dk' => $dk );
	}
	public static function answer( $url, $args ) {
		$path          = substr( $url, strlen( WORKER ) );
		$body          = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		self::$calls[] = $args['method'] . ' ' . $path;
		if ( ( $args['headers']['Authorization'] ?? '' ) !== 'Bearer ' . SECRET ) {
			return array( 'code' => 401, 'body' => '{"ok":false}' );
		}
		if ( in_array( strtok( $path, '?' ), self::$down, true ) ) {
			return array( 'code' => 500, 'body' => '{"ok":false}' );
		}
		$out = array( 'ok' => true );
		if ( 0 === strpos( $path, '/warm' ) ) {
			parse_str( (string) parse_url( $path, PHP_URL_QUERY ), $q );
			$out = array( 'browsers' => array() );
			foreach ( self::$rows as $v => $r ) {
				if ( $r['pending'] && $r['story'] && count( $out['browsers'] ) < (int) $q['limit'] && ( empty( $q['visitor'] ) || $q['visitor'] === $v ) ) {
					$out['browsers'][] = array( 'visitor' => $v, 'hash' => $r['hash'], 'story' => $r['story'] ) + ( $r['dk'] ? array( 'dk' => $r['dk'] ) : array() );
				}
			}
		} elseif ( '/donor' === $path ) {
			if ( self::$onDonor ) {
				( self::$onDonor )();
			}
			list( $prefix, $id ) = explode( '-', $body['dk'] );
			$r = self::$rows[ $body['visitor'] ] ?? array( 'story' => null, 'dk' => null );
			self::put( $body['visitor'], $r['story'], array( 'prefix' => $prefix, 'id' => $id, 'key' => $body['dk'], 'first_click_at' => $r['dk']['first_click_at'] ?? gmdate( 'c' ) ) );
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
	LkWorker::$down  = array();
	LkWorker::$onDonor = null;
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
			'text_mm8gnbs8'  => array( 'Donor key', 'text' ),
			'date_mm8gs213'  => array( 'Clicked email link', 'date' ),
		)
	);
	$people = array(
		'7001' => array( 'Donor One', 'b-12345-7cf7', null ),
		'7002' => array( 'Donor Two', 'm-7002-629a', '2026-10-01' ),
		'7003' => array( 'Someone Else', 'm-7003-0ad6', null ),
		'7004' => array( 'Twin A', 'b-999-aaaa', null ),
		'7005' => array( 'Twin B', 'b-999-aaaa', null ),
		'7006' => array( 'No Key Yet', '', null ),
	);
	foreach ( $people as $id => $p ) {
		LkMonday::$boards[ PB ]['items'][ $id ] = array(
			'name'   => $p[0],
			'values' => array_filter( array( 'text_mm8gnbs8' => $p[1], 'date_mm8gs213' => $p[2] ? array( 'date' => $p[2] ) : null ) ),
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

/** The tracker's post, and what runs after the answer. */
function lk_post( $visitor, $dk ) {
	remove_all_actions( 'shutdown' );
	$res = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $visitor, 'dk' => $dk ) ) ) );
	do_action( 'shutdown' );
	delete_transient( 'leadkit_dknow_' . md5( $visitor ) ); // A minute later.
	return $res;
}
function lk_pending() {
	return array_map( fn( $p ) => $p['dk'], (array) get_option( LEADKIT_DK_PENDING, array() ) );
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// ---- 1. a valid b- link: matched, the browser written at once, the first click -------------------
lk_setup();
// Two anonymous visits days ago, then today's from the email link; not warm in the Worker.
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/', $t0 ), lk_view( 'a2', '/hope/', $t1 ), lk_view( 'a3', '/donate/', time() - 60 ) ) ), null, 0 );
LkWorker::put( $B, lk_story( array( lk_view( 'b0', '/', time() - 900 ) ) ) ); // Another browser, warm.
$res = lk_post( $A, 'B-12345-7CF7' );
lk_eq( $res->get_status(), 200, 'b-: answered' );
lk_eq( LkWorker::$calls, array( 'POST /donor', 'GET /warm?limit=1&visitor=' . $A, 'POST /known', 'POST /sent' ), 'b-: matched, so the Worker is told, and that browser alone is fetched, written and reported' );
lk_eq( LkWorker::$rows[ $A ]['dk']['key'] ?? null, 'b-12345-7cf7', 'b-: the Worker keeps the key, lowercased' );
$row = lk_vrow( $A );
lk_eq( $row['values']['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( 7001 ) ), 'b-: Person is the People row whose Donor key it is' );
lk_eq( $row['values']['link_mm84cbre'] ?? null, array( 'url' => $url( '7001' ), 'text' => 'Donor One' ), 'b-: Open person goes to their card' );
$visits = array_values( LkMonday::$boards[ VS ]['items'] );
lk_eq( count( $visits ), 3, 'b-: every earlier visit is written, not only the click' );
lk_ok( array_filter( $visits, fn( $v ) => ( $v['values']['link_mm84xwdw']['url'] ?? '' ) === $url( '7001' ) ) === $visits, 'b-: and each one links to the donor', array_map( fn( $v ) => $v['values']['link_mm84xwdw'] ?? null, $visits ) );
lk_eq( lk_person( '7001' )['values']['date_mm8gs213'] ?? null, array( 'date' => $today ), 'b-: Clicked email link set to today' );
$pw = lk_people_writes();
lk_ok( 1 === count( $pw ) && array( 'date_mm8gs213' ) === array_keys( json_decode( $pw[0]['v']['v'], true ) ), 'b-: on the People board, that date is the only write — Donor key untouched, no column made', array_map( fn( $c ) => $c['v'], $pw ) );
lk_eq( array_keys( LkMonday::$boards[ PB ]['cols'] ), array( 'name', 'email_mm84abcd', 'text_mm8cb5hv', 'text_mm8gnbs8', 'date_mm8gs213' ), 'b-: the People board\'s columns are as they were' );
lk_ok( null === lk_vrow( $B ), 'b-: no other browser written' );
lk_eq( array( lk_pending(), lk_dk_log() ), array( array(), array( 'b-12345-7cf7 connected to People item 7001', 'b-12345-7cf7 "Clicked email link" set on People item 7001' ) ), 'b-: nothing left waiting; noted' );

// ---- 2. a repeat visit: nothing more ------------------------------------------------------------
$looked  = fn() => count( array_filter( LkMonday::$calls, fn( $c ) => false !== strpos( $c['q'], 'items_page_by_column_values' ) && PB === lk_board_of( $c ) ) );
$before  = count( LkMonday::mutations() );
$looked0 = $looked();
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/', $t0 ), lk_view( 'a2', '/hope/', $t1 ), lk_view( 'a3', '/donate/', time() - 60 ), lk_view( 'a4', '/about/', time() - 10 ) ) ), LkWorker::$rows[ $A ]['dk'] );
leadkit_send_warm_browsers();
lk_eq( array( count( lk_people_writes() ), count( lk_person_writes() ) ), array( 1, 1 ), 'repeat: no new People write, no new Person write' );
lk_ok( count( LkMonday::mutations() ) > $before, 'repeat: the new page is still written (the usual sync)' );
lk_eq( array( count( lk_dk_log() ), $looked() - $looked0 ), array( 2, 0 ), 'repeat: nothing new noted, the key not even looked up again' );
// The same link clicked again: looked up and told to the Worker again, but nothing more written.
lk_post( $A, 'b-12345-7cf7' );
lk_eq( array( count( lk_people_writes() ), count( lk_person_writes() ) ), array( 1, 1 ), 'repeat click: still no new People or Person write' );

// The browser's local state lost: monday.com is read first, so still nothing more.
delete_option( leadkit_visitor_row_key( $A ) );
LkWorker::$rows[ $A ]['pending'] = 1;
LkWorker::$rows[ $A ]['hash']   .= 'x';
leadkit_send_warm_browsers();
lk_eq( array( count( lk_people_writes() ), count( lk_person_writes() ) ), array( 1, 1 ), 'state lost: still no new People or Person write' );
lk_eq( lk_vrow( $A )['values']['board_relation_mm84ysfv']['item_ids'] ?? null, array( 7001 ), 'state lost: the same row, the same person' );

// ---- 3. a valid m- link ---------------------------------------------------------------------------
LkWorker::put( $C, lk_story( array( lk_view( 'c1', '/', time() - 30 ) ) ), null, 0 );
lk_post( $C, 'm-7002-629a' );
lk_eq( lk_vrow( $C )['values']['board_relation_mm84ysfv'] ?? null, array( 'item_ids' => array( 7002 ) ), 'm-: Person is the row with that Donor key' );
lk_eq( lk_person( '7002' )['values']['date_mm8gs213'] ?? null, array( 'date' => '2026-10-01' ), 'm-: a Clicked email link already set is left alone' );

// ---- 4. a bad code, an id with no match, a key two people share: nothing kept, nothing sent ------
foreach ( array( 'b-12345-0000' => 'a bad code', 'b-55555-abcd' => 'a Bloomerang id with no match', 'b-999-aaaa' => 'a key two people share' ) as $dk => $what ) {
	lk_setup();
	LkWorker::put( $D, lk_story( array( lk_view( 'd1', '/', time() - 30 ) ) ), null, 0 );
	lk_post( $D, $dk );
	lk_eq( array( LkWorker::$calls, LkMonday::mutations(), lk_pending() ), array( array(), array(), array() ), $what . ': not sent to the Worker, nothing written, nothing left waiting' );
	lk_eq( LkWorker::$rows[ $D ]['dk'], null, $what . ': the Worker never has it' );
}
lk_eq( lk_dk_log(), array( 'b-999-aaaa not connected: 2 People rows have Donor key b-999-aaaa (7004, 7005)' ), 'a key two people share: noted, with both' );
lk_setup();
lk_post( $D, 'b-55555-abcd' );
lk_eq( lk_dk_log(), array( 'b-55555-abcd no match: no People row has Donor key b-55555-abcd' ), 'no match: noted' );

// ---- 5. a Visitors row that already has someone else ---------------------------------------------
lk_setup();
// Written before, from a form: Person is someone else.
LkMonday::$boards[ VB ]['items']['5001'] = array(
	'name'   => $C,
	'values' => array( 'text_mm849mr5' => $C, 'board_relation_mm84ysfv' => array( 'item_ids' => array( 7003 ) ), 'link_mm84cbre' => array( 'url' => $url( '7003' ), 'text' => 'Someone Else' ) ),
);
LkWorker::put( $C, lk_story( array( lk_view( 'c1', '/', time() - 30 ) ) ), null, 0 );
lk_post( $C, 'b-12345-7cf7' );
$row = lk_vrow( $C );
lk_eq( array( $row['id'], $row['values']['board_relation_mm84ysfv'], $row['values']['link_mm84cbre']['url'] ), array( '5001', array( 'item_ids' => array( 7003 ) ), $url( '7003' ) ), 'a different Person already: kept, Person and Open person untouched' );
lk_eq( count( lk_person_writes() ), 0, 'a different Person already: no Person write at all' );
lk_eq( lk_dk_log()[0] ?? '', 'b-12345-7cf7 not connected: the Visitors row already has People item 7003, not 7001', 'a different Person already: the conflict noted' );
lk_eq( lk_person( '7001' )['values']['date_mm8gs213'] ?? null, array( 'date' => $today ), 'a different Person already: the donor did click, so Clicked email link is still set' );

// ---- 6. monday.com or the Worker down: the key waits for the next run ------------------------------
lk_setup();
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/donate/', time() - 30 ) ) ), null, 0 );
LkMonday::$fault = fn( $q ) => false !== strpos( $q, 'items_page_by_column_values' ) ? array( 'errors' => array( array( 'message' => 'down' ) ) ) : null;
lk_post( $A, 'b-12345-7cf7' );
lk_eq( array( LkWorker::$calls, lk_pending() ), array( array(), array( $A => 'b-12345-7cf7' ) ), 'monday.com down: nothing sent, the key waits' );
LkMonday::$fault = null;
LkWorker::$down  = array( '/donor' );
leadkit_send_warm_browsers();
lk_eq( array( lk_pending(), lk_vrow( $A ) ), array( array( $A => 'b-12345-7cf7' ), null ), 'the Worker refusing /donor: still waits, nothing written' );
LkWorker::$down = array();
leadkit_send_warm_browsers();
lk_eq( array( lk_pending(), lk_vrow( $A )['values']['board_relation_mm84ysfv'] ?? null ), array( array(), array( 'item_ids' => array( 7001 ) ) ), 'the next run: matched, sent, written and connected' );
// A newer key arriving while an older one is being sent: the newer one still waits.
lk_setup();
LkWorker::put( $A, lk_story( array( lk_view( 'a1', '/donate/', time() - 30 ) ) ), null, 0 );
leadkit_donor_key_queue( $A, 'b-12345-7cf7' );
LkWorker::$onDonor = function () use ( $A ) {
	LkWorker::$onDonor = null;
	leadkit_donor_key_queue( $A, 'm-7002-629a' );
};
leadkit_send_warm_browsers();
lk_eq( lk_pending(), array( $A => 'm-7002-629a' ), 'a newer key arriving meanwhile: it is not thrown away with the old one' );

// A key that could not be looked up for a week is given up.
lk_setup();
leadkit_donor_key_queue( $E, 'b-12345-7cf7' );
update_option( LEADKIT_DK_PENDING, array( $E => array( 'dk' => 'b-12345-7cf7', 'at' => time() - 8 * DAY_IN_SECONDS ) ) );
LkMonday::$fault = fn( $q ) => false !== strpos( $q, 'items_page_by_column_values' ) ? array( 'errors' => array( array( 'message' => 'down' ) ) ) : null;
leadkit_send_warm_browsers();
lk_eq( array( lk_pending(), lk_dk_log() ), array( array(), array( 'b-12345-7cf7 given up: monday.com could not be asked for a week' ) ), 'a week waiting: given up, noted' );
LkMonday::$fault = null;

// A key the Worker holds that is no longer anyone's (the Donor key changed since): noted, retried a day later.
lk_setup();
LkWorker::put( $E, lk_story( array( lk_view( 'e1', '/', time() - 30 ) ) ), array( 'prefix' => 'b', 'id' => '777', 'key' => 'b-777-abcd' ) );
leadkit_send_warm_browsers();
lk_ok( lk_vrow( $E ) && empty( lk_vrow( $E )['values']['board_relation_mm84ysfv'] ), 'a key no longer anyone\'s: the visitor written, with no Person' );
$n = $looked();
LkWorker::put( $E, lk_story( array( lk_view( 'e1', '/', time() - 30 ), lk_view( 'e2', '/hope/', time() - 5 ) ) ), array( 'prefix' => 'b', 'id' => '777', 'key' => 'b-777-abcd' ) );
leadkit_send_warm_browsers();
lk_eq( $looked(), $n, 'a key no longer anyone\'s: not looked up again within the day' );

// ---- 7. no key at all ---------------------------------------------------------------------------------
lk_setup();
// A browser warm for another reason (a Donate click), with no key: as before.
LkWorker::put( $D, lk_story( array( lk_view( 'd1', '/give/', time() - 30 ) ) ) );
leadkit_send_warm_browsers();
lk_ok( lk_vrow( $D ) && empty( lk_vrow( $D )['values']['board_relation_mm84ysfv'] ), 'no key: written, no Person' );
$asked = array_filter( LkMonday::$calls, fn( $c ) => PB === lk_board_of( $c ) || false !== strpos( $c['q'], 'BoardRelationValue' ) );
lk_eq( array( array_values( $asked ), lk_people_writes(), get_option( 'leadkit_dk_log', array() ), array_values( array_diff( LkWorker::$calls, array( 'GET /warm?limit=25', 'POST /known', 'POST /sent' ) ) ) ), array( array(), array(), array(), array() ), 'no key: the People board is not even read, nothing noted, the Worker asked only what it always was' );

// ---- 8. the route's refusals and brakes ----------------------------------------------------------------
lk_setup();
foreach ( array( array( 'no!', 'b-12345-7cf7' ), array( $A, 'b-12345' ), array( $A, 'x-12345-7cf7' ), array( $A, '' ) ) as $bad ) {
	lk_eq( leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $bad[0], 'dk' => $bad[1] ) ) ) )->get_status(), 400, 'route: refused — ' . json_encode( $bad ) );
}
lk_eq( leadkit_handle_donor_key( new WP_REST_Request( str_repeat( 'x', 5000 ) ) )->get_status(), 413, 'route: a body over 4 KB refused' );
remove_all_actions( 'shutdown' );
lk_eq( leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $A, 'dk' => 'b-12345-7cf7' ) ) ) )->get_status(), 200, 'route: taken' );
remove_all_actions( 'shutdown' );
$res = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $A, 'dk' => 'm-7002-629a' ) ) ) );
lk_eq( array( $res->get_status(), has_shutdown_work(), lk_pending() ), array( 200, false, array( $A => 'm-7002-629a' ) ), 'route: the same browser within a minute is not run again, but its newer key waits for the next run' );
for ( $i = 0; $i < 12; $i++ ) {
	$last = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => sprintf( 'f%07d-0000-4000-8000-000000000000', $i ), 'dk' => 'b-12345-7cf7' ) ) ) );
}
lk_eq( $last->get_status(), 429, 'route: braked per IP' );
lk_setup();
update_option( 'leadkit_options', array( 'visits_url' => '' ) + get_option( 'leadkit_options' ) );
remove_all_actions( 'shutdown' );
$res = leadkit_handle_donor_key( new WP_REST_Request( json_encode( array( 'visitor_id' => $A, 'dk' => 'b-12345-7cf7' ) ) ) );
lk_eq( array( $res->get_status(), $res->data['off'] ?? null, has_shutdown_work(), lk_pending() ), array( 200, true, false, array() ), 'route: no Visits Worker, nothing to do, nothing kept' );

lk_done();

/** Whether anything is waiting for the end of the request. */
function has_shutdown_work() {
	return ! empty( $GLOBALS['lk_actions']['shutdown'] );
}
