<?php
/**
 * check-packing.php — the server packs exactly what the tracker packs.
 *
 * The same fixtures as check-tracker.js (scripts/fixtures/packed-source.json),
 * so a gift's packed source, a Visitors row's sources and the Zapier parse all
 * agree byte for byte. Also: who gets the credit, untracked paths, and the
 * settings that feed them.
 *
 *   php scripts/check-packing.php
 *
 * Exits non-zero on any failure.
 */

// Development checks: never over the web, even if this folder is ever deployed.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/wp-stubs.php';

$fix = json_decode( file_get_contents( __DIR__ . '/fixtures/packed-source.json' ), true );

// ---- packing, both ways ------------------------------------------------------------
foreach ( $fix['pack'] as $c ) {
	$GLOBALS['lk_filters'] = array();
	add_filter( 'leadkit/packed_pair_separator', fn() => $c['sep'] );
	lk_eq( leadkit_pack_source( $c['fields'], $c['visitor'] ), $c['packed'], 'pack: ' . $c['name'] );
	$back = leadkit_unpack_source( $c['packed'] );
	foreach ( $c['unpacked'] as $k => $v ) {
		lk_eq( $back[ $k ] ?? null, $v, "unpack $k: " . $c['name'] );
	}
}
$GLOBALS['lk_filters'] = array();
foreach ( $fix['unpack'] as $c ) {
	lk_eq( leadkit_unpack_source( $c['raw'] ), $c['fields'], 'unpack: ' . $c['name'] );
}
lk_eq( leadkit_pack_pair_sep(), '__', 'default pair separator' );
add_filter( 'leadkit/packed_pair_separator', fn() => '; drop table' );
lk_eq( leadkit_pack_pair_sep(), '__', 'anything but "-" means "__"' );
$GLOBALS['lk_filters'] = array();

// The Zapier Code step's parse, as the plan describes it: split on "__", then
// on the first ".". It must read what LeadKit writes.
$zap = function ( $s ) {
	$out = array();
	foreach ( explode( '__', $s ) as $pair ) {
		$dot = strpos( $pair, '.' );
		if ( false !== $dot ) {
			$out[ substr( $pair, 0, $dot ) ] = substr( $pair, $dot + 1 );
		}
	}
	return $out;
};
lk_eq(
	$zap( leadkit_pack_source( array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1' ), '8f3k2q9a' ) ),
	array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1', 'visitorid' => '8f3k2q9a' ),
	'the Zapier split reads LeadKit\'s packing'
);

// ---- labels ---------------------------------------------------------------------------
lk_eq( leadkit_packed_label( 'medium.email__source.mailchimp__campaign.light-the-path__content.email-1__visitorid.x1234567' ), 'Email · light-the-path · email-1', 'label: labeled format' );
lk_eq( leadkit_packed_label( 'email__mailchimp__light-the-path__email-1' ), 'Email · light-the-path · email-1', 'label: positional format still reads' );
lk_eq( leadkit_pack_campaign( array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1' ) ), 'email__mailchimp__light-the-path__email-1', 'history packing unchanged' );

// ---- who gets the credit ----------------------------------------------------------------
$utm = array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1', 'landing' => '/hope/', 'time' => gmdate( 'c', time() - 40 * DAY_IN_SECONDS ) );
lk_eq( leadkit_credit_fields( array( 'utm' => $utm ) ), array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1' ), 'credit: latest campaign, only its five fields' );
update_option( 'leadkit_options', array( 'attribution_days' => '30' ) );
lk_eq( leadkit_credit_fields( array( 'utm' => $utm, 'referrer' => 'www.google.com' ) ), array( 'medium' => 'organic', 'source' => 'google' ), 'credit: past the 30-day window, falls back to the referrer (engine name)' );
lk_eq( leadkit_credit_fields( array( 'utm_first' => $utm ), true ), array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1' ), 'credit: the first campaign never expires' );
update_option( 'leadkit_options', array() );
lk_eq( leadkit_credit_fields( array( 'referrer' => 'news.example.org' ) ), array( 'medium' => 'referral', 'source' => 'news.example.org' ), 'credit: referral' );
lk_eq( leadkit_credit_fields( array() ), array(), 'credit: typed in — nothing' );
lk_eq( leadkit_pack_source( leadkit_credit_fields( array() ), 'x1234567' ), 'visitorid.x1234567', 'typed in packs to the id alone, as the tracker writes it' );
lk_eq( leadkit_pack_source( leadkit_credit_fields( array( 'referrer' => 'www.google.com' ) ) ), 'medium.organic__source.google', 'credit packs like the tracker\'s fallback (no visitorid on a board column)' );

// ---- the referring site ------------------------------------------------------------------
$refs = array(
	'referrer'  => 'google.com',
	'referrers' => array(
		array( 'domain' => 'facebook.com', 'time' => '2026-10-14T10:00:00Z' ),
		array( 'domain' => 'google.com', 'time' => '2026-10-10T10:00:00Z' ),
	),
);
lk_eq( leadkit_latest_referrer( $refs ), 'facebook.com', 'latest referrer: by time, not by order' );
lk_eq( leadkit_credit_fields( $refs ), array( 'medium' => 'referral', 'source' => 'facebook.com' ), 'no campaign: the latest site gets the credit' );
lk_eq( leadkit_pack_source( leadkit_credit_fields( $refs ) ), 'medium.referral__source.facebook-com', 'referral domain folded in the packed source' );
lk_eq( leadkit_first_referrer( $refs ), 'google.com', 'first referrer: a pre-1.5 session falls back to referrer' );
lk_eq( leadkit_first_referrer( $refs + array( 'first_referrer' => '' ) ), '', 'first referrer: a typed-in first visit stays empty, even with a later referrer' );
lk_eq( leadkit_credit_fields( $refs + array( 'first_referrer' => '' ), true ), array(), 'First source: typed-in first visit, no campaign — nothing' );
lk_eq( leadkit_latest_referrer( array( 'referrer' => 'www.news.example.org' ) ), 'news.example.org', 'latest referrer: falls back to referrer, www. dropped' );
$utm_now = array( 'medium' => 'email', 'source' => 'mailchimp', 'campaign' => 'light-the-path', 'content' => 'email-1', 'time' => gmdate( 'c', time() - DAY_IN_SECONDS ) );
lk_eq( leadkit_credit_fields( $refs + array( 'utm' => $utm_now ) )['medium'], 'email', 'a campaign keeps the credit over a later referrer' );
foreach ( array(
	'l.facebook.com'     => 'facebook.com',
	'M.Facebook.com:443' => 'facebook.com',
	'www2.example.org'   => 'example.org',
	'mail.google.com'    => 'mail.google.com',
) as $in => $want ) {
	lk_eq( leadkit_clean_domain( $in ), $want, "clean domain $in" );
}
foreach ( array(
	'google.com'        => 'google',
	'google.co.uk'      => 'google',
	'google.com.au'     => 'google',
	'mail.google.com'   => '',
	'news.google.com'   => '',
	'bing.com'          => 'bing',
	'cn.bing.com'       => 'bing',
	'r.search.yahoo.com' => 'yahoo',
	'mail.yahoo.com'    => '',
	'duckduckgo.com'    => 'duckduckgo',
	'facebook.com'      => '',
) as $host => $want ) {
	lk_eq( leadkit_search_engine( $host ), $want, "search engine $host" );
}
lk_eq( leadkit_source_label( array( 'referrer' => 'mail.google.com' ) ), 'Referral · mail.google.com', 'form summaries: Gmail is a referral too' );
lk_eq( leadkit_source_label( array( 'referrer' => 'www.google.com' ) ), 'Organic search · google.com', 'form summaries: Google search still organic' );

// ---- untracked pages -------------------------------------------------------------------
update_option( 'leadkit_options', array( 'untracked_paths' => '/help/, /get-help/' ) );
foreach ( array(
	'/help/'             => true,
	'/help'              => true,
	'/HELP/hotline/'     => true,
	'/get-help/'         => true,
	'/helpful/'          => false,
	'/'                  => false,
	'/about/help/'       => false,
) as $path => $want ) {
	lk_eq( leadkit_is_untracked_path( $path ), $want, "untracked $path" );
}
update_option( 'leadkit_options', array( 'untracked_paths' => '/' ) );
lk_eq( leadkit_is_untracked_path( '/anything/' ), true, 'a lone "/" untracks everything' );
update_option( 'leadkit_options', array() );
lk_eq( leadkit_is_untracked_path( '/help/' ), false, 'nothing set: everything tracked' );

// What the settings screen saves.
do_action( 'admin_init' );
$clean = $GLOBALS['lk_settings']['leadkit_options']['sanitize_callback'](
	array(
		'untracked_paths'       => " /help, https://3sgf.org/Get-Help/ ,\n/x/y <script>",
		'monday_visitors_board' => 'https://3sgf-team.monday.com/boards/18435450271',
	)
);
lk_eq( $clean['untracked_paths'], '/help/, /get-help/, /x/y/, /script/', 'settings: paths normalized, markup stripped' );
lk_eq( $clean['monday_visitors_board'], '18435450271', 'settings: board id from a pasted URL' );

// The tracker is not loaded on an untracked page.
update_option( 'leadkit_options', array( 'untracked_paths' => '/help/' ) );
$GLOBALS['lk_enqueued'] = array();
function wp_enqueue_script( $h ) {
	$GLOBALS['lk_enqueued'][] = $h;
}
function wp_add_inline_script( $h, $js ) {
	$GLOBALS['lk_inline'] = $js;
}
function wp_register_style() {}
foreach ( array( '/help/hotline/?x=1' => array(), '/hope/' => array( 'leadkit-tracker' ) ) as $uri => $want ) {
	$GLOBALS['lk_enqueued'] = array();
	$_SERVER['REQUEST_URI'] = $uri;
	do_action( 'wp_enqueue_scripts' );
	lk_eq( $GLOBALS['lk_enqueued'], $want, "tracker enqueued on $uri" );
}
$cfg = json_decode( preg_replace( '/^window\.LeadKitCfg = |;$/', '', $GLOBALS['lk_inline'] ), true );
lk_eq( $cfg['untrackedPaths'], array( '/help/' ), 'config carries the untracked paths' );
lk_eq( $cfg['visitorUrl'], '', 'no Visitors board: no visitorUrl' );
lk_eq( $cfg['packPairSep'], '__', 'config carries the pair separator' );
update_option( 'leadkit_options', array( 'monday_visitors_board' => '18435450271', 'monday_token' => 'test-token' ) );
do_action( 'wp_enqueue_scripts' );
$cfg = json_decode( preg_replace( '/^window\.LeadKitCfg = |;$/', '', $GLOBALS['lk_inline'] ), true );
lk_eq( $cfg['visitorUrl'], 'https://example.test/wp-json/leadkit/v1/visitor', 'Visitors board and token: visitorUrl set' );

lk_done();
