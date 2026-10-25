<?php
/**
 * check-browsers.php — warm browsers fetched from the Visits Worker and
 * written to monday.com.
 *
 * Against a fake Worker (its /warm, /sent, /known and /stats routes, as
 * readme.txt describes them) and the fake monday.com in
 * scripts/wp-stubs.php: nothing configured, nothing done; a warm browser
 * fetched and written to the Visitors and Visits boards with its earlier
 * visits; /sent reported with each browser's hash; a monday.com failure
 * left pending; a Worker that is down or refuses the secret; untracked
 * pages in a stored story still kept out; a new Visitors row marked known;
 * the tracker getting the Worker's URL; the settings counts, quick to give
 * up on a Worker that is down; and the Worker URL as saved.
 *
 *   php scripts/check-browsers.php
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
const WORKER = 'https://v.3sgf.org';
const SECRET = 'test-secret';

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
function wp_get_schedule( $h ) {
	return $GLOBALS['lk_cron'][ $h ] ?? false;
}

/** A fake Visits Worker: rows in memory, every call recorded. */
class LkWorker {
	public static $rows  = array(); // visitor => { story, hash, warm, pending, known }
	public static $calls = array();
	public static $down  = false;
	public static function reset() {
		self::$rows  = array();
		self::$calls = array();
		self::$down  = false;
	}
	public static function put( $visitor, $story, $warm = 1 ) {
		self::$rows[ $visitor ] = array( 'story' => $story, 'hash' => md5( json_encode( $story ) ), 'warm' => $warm, 'pending' => $warm, 'known' => 0 );
	}
	public static function answer( $url, $args ) {
		$path = substr( $url, strlen( WORKER ) );
		$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		self::$calls[] = array( 'method' => $args['method'], 'path' => $path, 'body' => $body, 'blocking' => $args['blocking'] ?? true, 'timeout' => $args['timeout'] ?? null );
		if ( self::$down ) {
			return new WP_Error( 'http', 'down' );
		}
		if ( ( $args['headers']['Authorization'] ?? '' ) !== 'Bearer ' . SECRET ) {
			return array( 'code' => 401, 'body' => '{"ok":false}' );
		}
		$out = array( 'ok' => true );
		if ( 0 === strpos( $path, '/warm' ) ) {
			parse_str( (string) parse_url( $path, PHP_URL_QUERY ), $q );
			$out = array( 'browsers' => array() );
			foreach ( self::$rows as $v => $r ) {
				if ( $r['warm'] && $r['pending'] && count( $out['browsers'] ) < (int) $q['limit'] ) {
					$out['browsers'][] = array( 'visitor' => $v, 'hash' => $r['hash'], 'story' => $r['story'] );
				}
			}
		} elseif ( '/sent' === $path ) {
			foreach ( $body['sent'] as $s ) {
				if ( isset( self::$rows[ $s['visitor'] ] ) && self::$rows[ $s['visitor'] ]['hash'] === $s['hash'] ) {
					self::$rows[ $s['visitor'] ]['pending'] = 0;
				}
			}
		} elseif ( '/known' === $path ) {
			foreach ( $body['visitors'] as $v ) {
				if ( isset( self::$rows[ $v ] ) ) {
					self::$rows[ $v ]['known'] = 1;
				}
			}
		} elseif ( '/stats' === $path ) {
			$out = array( 'browsers' => count( self::$rows ), 'warm' => 1, 'pending' => 0, 'sent' => 1 );
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

function lk_setup( $opts = array() ) {
	lk_reset();
	LkWorker::reset();
	$GLOBALS['lk_cron'] = array();
	LkMonday::board(
		VB,
		array(
			'name'               => array( 'Name', 'name' ),
			'text_mm849mr5'      => array( 'visitorid', 'text' ),
			'date_mm84kpqp'      => array( 'First seen', 'date' ),
			'date_mm84w8jj'      => array( 'Last seen', 'date' ),
			'numeric_mm84mt3y'   => array( 'Visits', 'numbers' ),
			'text_mm8447yq'      => array( 'First source', 'text' ),
			'text_mm84pej6'      => array( 'Latest source', 'text' ),
			'numeric_mm84e0t6'   => array( 'Donate clicks', 'numbers' ),
			'long_text_mm84qwht' => array( 'Journey log', 'long_text' ),
			'text_mm84778t'      => array( 'First referrer', 'text' ),
			'text_mm84yh3a'      => array( 'Latest referrer', 'text' ),
			'link_mm84cbre'      => array( 'Open person', 'link' ),
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
		$opts + array(
			'monday_token'          => 'test-token',
			'monday_visitors_board' => VB,
			'monday_visits_board'   => VS,
			'untracked_paths'       => '/help/',
			'visits_url'            => WORKER,
			'visits_secret'         => SECRET,
		)
	);
}
function lk_view( $id, $path, $t, $ms = 20000 ) {
	return array( 'id' => $id, 'path' => $path, 'active_time_ms' => $ms, 'max_scroll_percent' => 60, 'time' => gmdate( 'c', $t ) );
}
/** A story as the Worker stores it (the tracker's post, merged). */
function lk_story( $views, $extra = array() ) {
	return array_merge(
		array(
			'started_at'  => $views[0]['time'],
			'referrer'    => '',
			'referrers'   => array(),
			'utm'         => array(),
			'utm_first'   => array(),
			'utm_touches' => array(),
			'page_views'  => $views,
			'donate_clicks' => array(),
			'lead_forms'  => array(),
			'donation_returns' => array(),
			'timezone'    => 'America/Chicago',
		),
		$extra
	);
}
function lk_vrows() {
	return LkMonday::$boards[ VB ]['items'];
}
function lk_worker_paths() {
	return array_map( fn( $c ) => $c['method'] . ' ' . $c['path'], LkWorker::$calls );
}

$A  = 'a11ce000-0000-4000-8000-000000000001';
$B  = 'b0b00000-0000-4000-8000-000000000002';
$t0 = time() - 5 * DAY_IN_SECONDS;
$t1 = $t0 + 2 * DAY_IN_SECONDS;

// ---- 1. nothing configured: nothing done -------------------------------------------
lk_setup( array( 'visits_url' => '' ) );
lk_ok( ! leadkit_browsers_enabled(), 'no Worker URL: off' );
lk_eq( leadkit_visits_post_url(), '', 'no Worker URL: the tracker gets none' );
lk_eq( leadkit_send_warm_browsers(), 0, 'no Worker URL: the run does nothing' );
lk_eq( LkWorker::$calls, array(), 'no Worker URL: no request' );
do_action( 'init' );
lk_ok( ! isset( $GLOBALS['lk_cron'][ LEADKIT_BROWSERS_HOOK ] ), 'no Worker URL: not scheduled' );

// The URL as pasted, with or without /visit or a trailing slash.
foreach ( array( WORKER, WORKER . '/', WORKER . '/visit', WORKER . '/visit/' ) as $u ) {
	lk_setup( array( 'visits_url' => $u ) );
	lk_eq( leadkit_visits_post_url(), WORKER . '/visit', 'Worker URL as pasted: ' . $u );
}
lk_setup( array( 'visits_url' => 'http://v.3sgf.org' ) );
lk_ok( ! leadkit_browsers_enabled(), 'a plain-http Worker URL is refused' );

lk_setup();
do_action( 'init' );
lk_eq( $GLOBALS['lk_cron'][ LEADKIT_BROWSERS_HOOK ] ?? null, 'leadkit_quarter_hour', 'with a Worker and a Visitors board: every 15 minutes' );
$o = get_option( 'leadkit_options' );
$o['background_minutes'] = '5';
update_option( 'leadkit_options', $o );
do_action( 'init' );
lk_eq( $GLOBALS['lk_cron'][ LEADKIT_BROWSERS_HOOK ] ?? null, 'leadkit_five_minutes', 'Background runs set to 5 minutes: rescheduled' );
lk_setup( array( 'background_minutes' => '5' ) );
do_action( 'init' );
lk_eq( $GLOBALS['lk_cron'][ LEADKIT_BROWSERS_HOOK ] ?? null, 'leadkit_five_minutes', 'set to 5 minutes from the start: every 5 minutes' );

// ---- 2. a warm browser: written with its whole history, reported sent ---------------------
lk_setup();
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ), lk_view( 'h1', '/help/', $t0 + 30 ), lk_view( 'p2', '/hope/', $t1 ) ) ) );
LkWorker::put( $B, lk_story( array( lk_view( 'q1', '/', $t0 ) ) ), 0 ); // cold: never offered
lk_eq( leadkit_send_warm_browsers(), 1, 'run: one warm browser sent' );
lk_eq( lk_worker_paths(), array( 'GET /warm?limit=25', 'POST /known', 'POST /sent' ), 'run: fetch, the new row marked known, report' );
lk_eq( LkWorker::$calls[1]['blocking'], false, 'known: fire and forget' );
lk_eq( count( lk_vrows() ), 1, 'one Visitors row' );
$row = reset( LkMonday::$boards[ VB ]['items'] );
lk_eq( $row['name'], $A, 'the row is the warm browser' );
lk_eq( $row['values']['numeric_mm84mt3y'], '2', 'Visitors row: both visits' );
lk_ok( false === strpos( $row['values']['long_text_mm84qwht']['text'], '/help/' ), 'an untracked page in the stored story is still kept out', $row['values']['long_text_mm84qwht']['text'] );
lk_eq( count( LkMonday::$boards[ VS ]['items'] ), 2, 'Visits: both, the first from before it was warm' );
lk_eq( LkWorker::$calls[2]['body'], array( 'sent' => array( array( 'visitor' => $A, 'hash' => LkWorker::$rows[ $A ]['hash'] ) ) ), '/sent carries the hash it was given' );
lk_eq( LkWorker::$rows[ $A ]['pending'], 0, 'the Worker clears it' );

// The next run: nothing pending, nothing written.
LkWorker::$calls  = array();
LkMonday::$calls  = array();
lk_eq( leadkit_send_warm_browsers(), 0, 'next run: nothing pending' );
lk_eq( lk_worker_paths(), array( 'GET /warm?limit=25' ), 'next run: only the fetch' );
lk_eq( count( LkMonday::mutations() ), 0, 'next run: no monday.com write' );

// It comes back: pending again, same row, a third visit row, not marked known again.
$t2 = $t1 + DAY_IN_SECONDS;
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ), lk_view( 'p2', '/hope/', $t1 ), lk_view( 'p3', '/events/', $t2 ) ) ) );
LkWorker::$calls = array();
leadkit_send_warm_browsers();
lk_eq( count( lk_vrows() ), 1, 'back again: still one Visitors row' );
lk_eq( reset( LkMonday::$boards[ VB ]['items'] )['values']['numeric_mm84mt3y'], '3', 'back again: 3 visits' );
lk_eq( count( LkMonday::$boards[ VS ]['items'] ), 3, 'back again: 3 visit rows' );
lk_eq( lk_worker_paths(), array( 'GET /warm?limit=25', 'POST /sent' ), 'back again: no second "known"' );

// ---- 3. failures ----------------------------------------------------------------------------
// monday.com refuses: not reported sent, so the Worker keeps it pending.
lk_setup();
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ) ) ) );
LkMonday::$fault = fn( $q, $v ) => array( 'errors' => array( array( 'message' => 'down' ) ) );
lk_eq( leadkit_send_warm_browsers(), 0, 'monday.com down: nothing sent' );
lk_ok( ! in_array( 'POST /sent', lk_worker_paths(), true ), 'monday.com down: nothing reported sent' );
lk_eq( LkWorker::$rows[ $A ]['pending'], 1, 'monday.com down: still pending for the next run' );

lk_setup();
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ) ) ) );
LkWorker::$down = true;
lk_eq( leadkit_send_warm_browsers(), 0, 'Worker down: nothing sent' );
lk_eq( count( LkMonday::mutations() ), 0, 'Worker down: no monday.com write' );

lk_setup( array( 'visits_secret' => 'wrong' ) );
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ) ) ) );
lk_eq( leadkit_send_warm_browsers(), 0, 'wrong secret: refused, nothing sent' );

lk_setup( array( 'visits_secret' => '' ) );
lk_eq( leadkit_send_warm_browsers(), 0, 'no secret: nothing sent' );
lk_eq( LkWorker::$calls, array(), 'no secret: no request at all' );

// The constant wins over the setting.
define( 'LEADKIT_VISITS_SECRET', SECRET );
lk_setup( array( 'visits_secret' => 'wrong' ) );
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ) ) ) );
lk_eq( leadkit_send_warm_browsers(), 1, 'LEADKIT_VISITS_SECRET takes precedence' );

// A browser the Worker sends with junk is skipped, not written.
lk_setup();
LkWorker::$rows['not ok!'] = array( 'story' => lk_story( array( lk_view( 'p1', '/', $t0 ) ) ), 'hash' => 'x', 'warm' => 1, 'pending' => 1, 'known' => 0 );
lk_eq( leadkit_send_warm_browsers(), 0, 'a bad visitor id from the Worker is skipped' );
lk_eq( count( lk_vrows() ), 0, 'and nothing is written for it' );

// ---- 4. the settings counts, cached ---------------------------------------------------------
lk_setup();
LkWorker::put( $A, lk_story( array( lk_view( 'p1', '/', $t0 ) ) ) );
lk_eq( leadkit_browsers_counts(), array( 'browsers' => 1, 'warm' => 1, 'pending' => 0, 'sent' => 1 ), 'counts from /stats' );
lk_eq( array_column( LkWorker::$calls, 'timeout' ), array( 3 ), 'counts: the settings screen waits 3 seconds at most' );
LkWorker::$calls = array();
leadkit_browsers_counts();
lk_eq( LkWorker::$calls, array(), 'counts: cached' );
do_action( 'update_option_leadkit_options' );
leadkit_browsers_counts();
lk_eq( lk_worker_paths(), array( 'GET /stats' ), 'counts: asked for again once the settings are saved' );

// A Worker that is down: asked once, then left alone for a minute.
lk_setup();
LkWorker::$down = true;
lk_eq( leadkit_browsers_counts(), null, 'counts: Worker down, none shown' );
lk_eq( leadkit_browsers_counts(), null, 'counts: Worker down, still none a moment later' );
lk_eq( lk_worker_paths(), array( 'GET /stats' ), 'counts: a failure is remembered, not asked again on every page load' );
$left = ( $GLOBALS['lk_transients'][ LEADKIT_BROWSERS_STATS ][1] ?? 0 ) - time();
lk_ok( $left > 0 && $left <= MINUTE_IN_SECONDS, 'counts: a failure is remembered for a minute, not five', $left );
$GLOBALS['lk_transients'][ LEADKIT_BROWSERS_STATS ][1] = time() - 1; // A minute later.
LkWorker::$down = false;
lk_eq( leadkit_browsers_counts()['browsers'] ?? null, 0, 'counts: the Worker back, asked again' );

// ---- 5. the Worker URL as saved -------------------------------------------------------------
do_action( 'admin_init' );
$save = $GLOBALS['lk_settings']['leadkit_options']['sanitize_callback'];

foreach ( array( WORKER, WORKER . '/visit/', '  ' . WORKER . "/visit \n", 'https://lk-visits.acct.workers.dev' ) as $u ) {
	lk_setup( array( 'visits_url' => '' ) );
	lk_eq( $save( array( 'visits_url' => $u ) )['visits_url'], trim( $u ), 'Worker URL saved: ' . json_encode( $u ) );
	lk_eq( $GLOBALS['lk_notices'], array(), 'and no notice: ' . json_encode( $u ) );
}

// A typo is refused with a notice; the URL it had is kept, so visits go on being kept.
foreach ( array( 'http://v.3sgf.org', 'v.3sgf.org', 'HTTPS://v.3sgf.org', 'https:///', WORKER . '/visit?x=1', WORKER . '#x', WORKER . '"><script>', 'javascript:alert(1)' ) as $u ) {
	lk_setup();
	lk_eq( $save( array( 'visits_url' => $u ) )['visits_url'], WORKER, 'a bad Worker URL keeps the one saved: ' . $u );
	lk_eq( $GLOBALS['lk_notices'], array( 'leadkit_visits_url' ), 'and says so: ' . $u );
}
lk_setup( array( 'visits_url' => '' ) );
lk_eq( $save( array( 'visits_url' => 'http://v.3sgf.org' ) )['visits_url'], '', 'a bad Worker URL with none saved: still none' );

lk_setup();
lk_eq( $save( array( 'visits_url' => '' ) )['visits_url'], '', 'an emptied Worker URL turns it off' );
lk_eq( $GLOBALS['lk_notices'], array(), 'without a notice' );

// ---- 6. the background-run and gift-linker settings as saved ----------------------------------
lk_setup();
$saved = fn( $raw ) => array_intersect_key( $save( $raw ), array_flip( array( 'background_minutes', 'gift_grace', 'people_group' ) ) );
lk_eq( $saved( array() ), array( 'people_group' => '', 'gift_grace' => '10', 'background_minutes' => '15' ), 'settings: nothing given — 15 minutes, a 10-minute wait, the top group' );
lk_eq( $saved( array( 'background_minutes' => '5', 'gift_grace' => '2', 'people_group' => ' New donors (from Give Lively) ' ) ), array( 'people_group' => 'New donors (from Give Lively)', 'gift_grace' => '2', 'background_minutes' => '5' ), 'settings: 5 minutes, a 2-minute wait, a group by its title' );
lk_eq( array( $saved( array( 'background_minutes' => '1' ) )['background_minutes'] ?? null, $saved( array( 'gift_grace' => '600' ) )['gift_grace'] ?? null ), array( '15', '60' ), 'settings: an interval other than 5 is 15; a wait over an hour is an hour' );
lk_eq( array( $saved( array( 'gift_grace' => '-3' ) )['gift_grace'] ?? null, $saved( array( 'gift_grace' => '0' ) )['gift_grace'] ?? null ), array( '0', '0' ), 'settings: a wait of 0 or less is no wait' );

lk_done();
