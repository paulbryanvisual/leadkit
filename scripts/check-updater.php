<?php
/**
 * check-updater.php — the update WordPress is offered, and "Check again".
 *
 * Against a fake GitHub and a stand-in for WordPress's own plugin check
 * (wp_update_plugins on load-update-core.php, at most once a minute): a
 * normal visit to Dashboard → Updates reads the twelve-hour cache; "Check
 * again" asks GitHub afresh and offers the new version, also when WordPress
 * itself checked less than a minute ago; not for a user who cannot update
 * plugins; "latest version" when GitHub has nothing newer; and a fresh check
 * after any update.
 *
 *   php scripts/check-updater.php
 *
 * Exits non-zero on any failure.
 */

// Development checks: never over the web, even if this folder is ever deployed.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/wp-stubs.php';

// ---- what wp-stubs.php leaves out ------------------------------------------------------
$GLOBALS['lk_site_transients'] = array();
$GLOBALS['lk_can']             = array();
function get_site_transient( $k ) {
	return $GLOBALS['lk_site_transients'][ $k ] ?? false;
}
function set_site_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['lk_site_transients'][ $k ] = apply_filters( "pre_set_site_transient_{$k}", $v, $k );
	return true;
}
function delete_site_transient( $k ) {
	unset( $GLOBALS['lk_site_transients'][ $k ] );
	return true;
}
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['lk_can'][ $cap ] );
}
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}
function get_bloginfo( $what = '' ) {
	return '6.8';
}

/** A fake GitHub: the latest release, every call recorded. */
class LkGitHub {
	public static $tag   = '';
	public static $calls = array();
	public static function answer( $url ) {
		self::$calls[] = $url;
		if ( false !== strpos( $url, '/releases/latest' ) ) {
			return array( 'code' => 200, 'body' => json_encode( array( 'tag_name' => self::$tag, 'body' => 'notes', 'published_at' => '2026-10-24T18:42:36Z' ) ) );
		}
		return array( 'code' => 200, 'body' => json_encode( array( array( 'name' => self::$tag ) ) ) );
	}
}
function wp_remote_get( $url, $args = array() ) {
	if ( 0 !== strpos( $url, 'https://api.github.com/' ) ) {
		throw new Exception( 'unexpected request: ' . $url );
	}
	return LkGitHub::answer( $url );
}

$basename = leadkit_basename();
$parts    = array_map( 'intval', explode( '.', LEADKIT_VERSION ) );
$parts[2] = ( $parts[2] ?? 0 ) + 1;
$next     = implode( '.', $parts );

/**
 * A site where the cache was filled two hours ago, before the release, and
 * WordPress last checked $ago seconds ago. GitHub now has $tag.
 */
function lk_site( $ago, $tag, $can = true ) {
	lk_reset();
	$GLOBALS['lk_site_transients'] = array();
	$GLOBALS['lk_can']             = array( 'update_plugins' => $can );
	$_GET                          = array();
	LkGitHub::$calls               = array();
	set_site_transient( 'leadkit_latest_release', array( 'version' => LEADKIT_VERSION, 'zip' => 'z', 'url' => 'u', 'notes' => '', 'published' => '' ), 12 * HOUR_IN_SECONDS );
	set_site_transient( 'update_plugins', (object) array( 'last_checked' => time() - $ago, 'response' => array(), 'no_update' => array() ) );
	LkGitHub::$calls = array();
	LkGitHub::$tag   = $tag;
	// WordPress's own plugin check on that page: at most once a minute.
	add_action(
		'load-update-core.php',
		function () {
			$cur = get_site_transient( 'update_plugins' );
			if ( is_object( $cur ) && time() - $cur->last_checked < MINUTE_IN_SECONDS ) {
				return;
			}
			set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'response' => array(), 'no_update' => array() ) );
		},
		10
	);
}
function lk_offered( $basename ) {
	$u = get_site_transient( 'update_plugins' );
	return isset( $u->response[ $basename ] ) ? $u->response[ $basename ]->new_version : null;
}

// ---- 1. a normal visit to Dashboard → Updates: the cache answers ------------------------
lk_site( 2 * MINUTE_IN_SECONDS, 'v' . $next );
do_action( 'load-update-core.php' );
lk_eq( LkGitHub::$calls, array(), 'normal visit: GitHub not asked (twelve-hour cache)' );
lk_eq( lk_offered( $basename ), null, 'normal visit: the cached answer, no update yet' );

// ---- 2. "Check again": GitHub asked afresh, the new version offered ----------------------
lk_site( 2 * MINUTE_IN_SECONDS, 'v' . $next );
$_GET['force-check'] = '1';
do_action( 'load-update-core.php' );
lk_ok( count( LkGitHub::$calls ) >= 1, 'Check again: GitHub asked' );
lk_eq( lk_offered( $basename ), $next, 'Check again: ' . $next . ' offered' );
lk_eq( get_site_transient( 'leadkit_latest_release' )['version'] ?? null, $next, 'Check again: the cache now holds the fresh answer' );

// Inside the minute WordPress does not re-check by itself.
lk_site( 10, 'v' . $next );
$_GET['force-check'] = '1';
do_action( 'load-update-core.php' );
lk_eq( lk_offered( $basename ), $next, 'Check again inside WordPress\'s minute: still offered' );

// ---- 3. not for a user who cannot update plugins ----------------------------------------
lk_site( 2 * MINUTE_IN_SECONDS, 'v' . $next, false );
$_GET['force-check'] = '1';
do_action( 'load-update-core.php' );
lk_eq( LkGitHub::$calls, array(), 'cannot update plugins: GitHub not asked' );
lk_eq( get_site_transient( 'leadkit_latest_release' )['version'] ?? null, LEADKIT_VERSION, 'cannot update plugins: cache kept' );

// ---- 4. nothing newer on GitHub: "latest version" ---------------------------------------
lk_site( 2 * MINUTE_IN_SECONDS, 'v' . LEADKIT_VERSION );
$_GET['force-check'] = '1';
do_action( 'load-update-core.php' );
lk_eq( lk_offered( $basename ), null, 'nothing newer: no update offered' );
lk_eq( get_site_transient( 'update_plugins' )->no_update[ $basename ]->new_version ?? null, LEADKIT_VERSION, 'nothing newer: marked as the latest version' );

// ---- 5. after any update: asked afresh next time -----------------------------------------
lk_site( 2 * MINUTE_IN_SECONDS, 'v' . $next );
do_action( 'upgrader_process_complete' );
lk_eq( get_site_transient( 'leadkit_latest_release' ), false, 'after an update: cache cleared' );

$_GET = array();
lk_done();
