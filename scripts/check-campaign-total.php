<?php
/**
 * check-campaign-total.php — the live campaign total from the Donations board.
 *
 * Against the fake monday.com in scripts/wp-stubs.php: only rows whose rule
 * column reads the label count; Match dollars is the match share; Match
 * dollars and Offline lump sum rows count toward the total but not the gift
 * count; an empty Amount is 0; the board is read a page at a time by cursor;
 * nothing is ever written to monday.com (no column created either); any
 * failure, a later page included, keeps the last good total; never pulled
 * shows a dash, never $0; no goal shows no percent; the override wins, then
 * switches itself off and is logged; the card, inline fields and REST
 * payload carry totals only.
 *
 *   php scripts/check-campaign-total.php
 *
 * Exits non-zero on any failure.
 */

// Development checks: never over the web, even if this folder is ever deployed.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/wp-stubs.php';

const DB = '18436673024'; // 2026 EOY Donations

/** The Donations board as on 3sgf.org: the columns the total reads, plus one it ignores. */
function lkt_board( $rows ) {
	LkMonday::board(
		DB,
		array(
			'numeric_amt' => array( 'Amount', 'numbers' ),
			'color_eoy'   => array( 'EOY 2026', 'status' ),
			'color_entry' => array( 'Entry', 'status' ),
			'text_name'   => array( 'Donor name', 'text' ),
		)
	);
	$i = 100;
	foreach ( $rows as $r ) {
		LkMonday::$boards[ DB ]['items'][ (string) $i++ ] = array(
			'name'   => 'gift',
			'values' => array_filter(
				array(
					'numeric_amt' => $r[1],
					'color_eoy'   => $r[0],
					'color_entry' => $r[2] ?? 'Give Lively',
					'text_name'   => 'Jane Donor',
				),
				fn( $v ) => null !== $v
			),
		);
	}
}

function lkt_setup( $extra = array() ) {
	lk_reset();
	update_option(
		'leadkit_options',
		$extra + array(
			'monday_token'           => 'test-token',
			'monday_donations_board' => DB,
			'total_on'               => '1',
			'total_rule'             => 'EOY 2026 = EOY 2026',
			'total_goal'             => '200000',
		)
	);
}

// ---- off, or nothing to read ----------------------------------------------------------
lk_reset();
lk_ok( ! leadkit_total_enabled(), 'off by default' );
lkt_setup( array( 'total_on' => '' ) );
lk_ok( ! leadkit_total_enabled(), 'off when the box is unticked' );
lkt_setup();
lk_ok( leadkit_total_enabled(), 'on with token, Donations board and the box ticked' );
lk_eq( leadkit_total_board(), DB, 'reads the Donations board by default' );
lkt_setup( array( 'total_board' => '555555' ) );
lk_eq( leadkit_total_board(), '555555', 'its own board when set' );

// ---- never pulled ----------------------------------------------------------------------
lkt_setup();
$p = leadkit_total_public();
lk_ok( null === $p['total'] && 'none' === $p['source'], 'never pulled: total is null' );
lk_eq( leadkit_total_field( $p, 'total' ), '—', 'never pulled: a dash, not $0' );

// ---- the rule ---------------------------------------------------------------------------
lkt_setup();
lkt_board(
	array(
		array( 'EOY 2026', '1250.50' ),
		array( 'EOY 2026', '100' ),
		array( 'Other', '5000' ),                         // Another campaign.
		array( '', '75' ),                                // No label.
		array( 'EOY 2026', '79500', 'Match dollars' ),
		array( 'EOY 2026', null, 'Offline lump sum' ),    // Offline total row, not filled yet.
		array( 'EOY 2026', '2000', 'Offline lump sum' ),
		array( 'EOY 2026', '50', 'Offline gift' ),
	)
);
lk_ok( leadkit_total_pull(), 'pull succeeds' );
$s = get_option( LEADKIT_TOTAL_STATE );
lk_ok( abs( $s['total'] - 82900.5 ) < 0.001, 'total = 1,250.50 + 100 + 79,500 + 2,000 + 50', $s['total'] );
lk_eq( (float) $s['match'], 79500.0, 'match share = the Match dollars row' );
lk_eq( $s['gifts'], 3, 'gifts: Give Lively ×2 + Offline gift; match and lump sums are not gifts' );
lk_eq( count( LkMonday::mutations() ), 0, 'read only: no mutation, no column created' );
lk_ok( ! preg_match( '/text_name/', json_encode( LkMonday::$calls ) ), 'never asks for a donor\'s name' );
$p = leadkit_total_public();
lk_eq( $p['percent'], 41, '41% of $200,000' );
lk_eq( leadkit_total_field( $p, 'total' ), '$82,901', 'shown in whole dollars' );

// ---- paging -----------------------------------------------------------------------------
lkt_setup();
lkt_board( array( array( 'EOY 2026', '10' ), array( 'EOY 2026', '20' ), array( 'Other', '999' ), array( 'EOY 2026', '30' ), array( 'EOY 2026', '40' ) ) );
add_filter( 'leadkit/campaign_total_page', fn() => 2 );
lk_ok( leadkit_total_pull(), 'paged pull succeeds' );
lk_eq( (float) get_option( LEADKIT_TOTAL_STATE )['total'], 100.0, 'three pages of two summed = 100' );
lk_eq( count( array_filter( LkMonday::$calls, fn( $c ) => false !== strpos( $c['q'], 'next_items_page' ) ) ), 2, 'followed the cursor twice' );

// A later page failing: the earlier total stays.
LkMonday::$boards[ DB ]['items']['900'] = array( 'name' => 'gift', 'values' => array( 'numeric_amt' => '5000', 'color_eoy' => 'EOY 2026' ) );
LkMonday::$fault = fn( $q ) => false !== strpos( $q, 'next_items_page' ) ? array( 'errors' => array( array( 'message' => 'Complexity budget exhausted' ) ) ) : null;
lk_ok( ! leadkit_total_pull(), 'a failed later page fails the pull' );
lk_eq( (float) get_option( LEADKIT_TOTAL_STATE )['total'], 100.0, 'a failed later page keeps the last good total' );
lk_eq( get_option( LEADKIT_TOTAL_ERROR ), 'Complexity budget exhausted', 'its error is shown on the screen' );

// ---- failures ----------------------------------------------------------------------------
LkMonday::$fault = fn() => array( 'errors' => array( array( 'message' => 'Rate limit exceeded' ) ) );
lk_ok( ! leadkit_total_pull(), 'monday.com down: pull fails' );
lk_eq( (float) get_option( LEADKIT_TOTAL_STATE )['total'], 100.0, 'monday.com down: last good total kept' );
LkMonday::$fault = null;

lkt_setup( array( 'total_rule' => 'Campaign = EOY 2026' ) );
lkt_board( array( array( 'EOY 2026', '10' ) ) );
lk_ok( ! leadkit_total_pull(), 'rule column not on the board: fails' );
lk_ok( false !== strpos( (string) get_option( LEADKIT_TOTAL_ERROR ), 'No column titled "Campaign"' ), 'and says which column', get_option( LEADKIT_TOTAL_ERROR ) );
lk_eq( count( LkMonday::mutations() ), 0, 'and does not create it' );

lkt_setup( array( 'monday_donations_board' => '424242' ) );
lk_ok( ! leadkit_total_pull(), 'board not readable: fails' );
lk_ok( false !== strpos( (string) get_option( LEADKIT_TOTAL_ERROR ), 'not found' ), 'and says so', get_option( LEADKIT_TOTAL_ERROR ) );

// No Entry column: every row a gift, no match share — still a total.
lkt_setup();
lkt_board( array( array( 'EOY 2026', '10' ), array( 'EOY 2026', '15' ) ) );
unset( LkMonday::$boards[ DB ]['cols']['color_entry'] );
lk_ok( leadkit_total_pull(), 'no Entry column: still pulls' );
lk_eq( array( (float) get_option( LEADKIT_TOTAL_STATE )['total'], get_option( LEADKIT_TOTAL_STATE )['gifts'] ), array( 25.0, 2 ), 'no Entry column: total 25, 2 gifts' );

// No goal: no percent, no made-up 100%.
lkt_setup( array( 'total_goal' => '' ) );
lkt_board( array( array( 'EOY 2026', '10' ) ) );
leadkit_total_pull();
$p = leadkit_total_public();
lk_ok( null === $p['percent'] && '—' === leadkit_total_field( $p, 'goal' ), 'no goal: no percent, goal shows a dash' );

// ---- override ----------------------------------------------------------------------------
lkt_setup();
lkt_board( array( array( 'EOY 2026', '60' ) ) );
leadkit_total_pull();
lk_eq( leadkit_total_save( array( 'on' => '1', 'total' => '$150,000', 'gifts' => '', 'note' => 'Zap down', 'expires' => gmdate( 'Y-m-d\TH:i', time() + 3600 ) ) ), 'saved', 'override saved' );
$p = leadkit_total_public();
lk_eq( array( $p['source'], $p['total'], $p['percent'] ), array( 'manual', 150000.0, 75 ), 'override on: $150,000, 75%' );
lk_eq( get_option( LEADKIT_TOTAL_LOG )[0]['what'], 'Override ON at $150,000 — Zap down', 'switching on is logged' );
leadkit_total_save( array( 'on' => '1', 'total' => '150000', 'gifts' => '', 'note' => 'Zap down', 'expires' => gmdate( 'Y-m-d\TH:i', time() + 3600 ) ) );
lk_eq( count( get_option( LEADKIT_TOTAL_LOG ) ), 1, 'saving the same numbers logs nothing new' );
$o            = get_option( LEADKIT_TOTAL_OVERRIDE );
$o['expires'] = time() - 1;
update_option( LEADKIT_TOTAL_OVERRIDE, $o );
$p = leadkit_total_public();
lk_eq( array( $p['source'], $p['total'] ), array( 'monday', 60.0 ), 'past its time: back to monday.com' );
lk_ok( ! get_option( LEADKIT_TOTAL_OVERRIDE )['on'], 'past its time: switched off' );
lk_eq( get_option( LEADKIT_TOTAL_LOG )[0]['who'], 'system', 'switching itself off is logged as system' );
lk_eq( leadkit_total_save( array( 'pull' => '1' ) ), 'pulled', 'Pull now pulls' );

// ---- output ------------------------------------------------------------------------------
lkt_setup();
lkt_board( array( array( 'EOY 2026', '41000' ), array( 'EOY 2026', '79500', 'Match dollars' ) ) );
leadkit_total_pull();
$html = leadkit_total_shortcode( array() );
lk_ok( false !== strpos( $html, '$120,500' ) && false !== strpos( $html, '60%' ), 'card: $120,500 and 60%' );
lk_ok( false !== strpos( $html, 'style="width:21%"' ) && false !== strpos( $html, 'style="width:39%"' ), 'bar: community 21% + match 39%' );
lk_ok( false !== strpos( $html, 'aria-label="$120,500 raised of a $200,000 goal, 60 percent"' ), 'bar has a text alternative' );
lk_ok( false !== strpos( $html, 'leadkit/v1/total' ), 'card refreshes from leadkit/v1/total' );
lk_eq( substr_count( leadkit_total_shortcode( array() ), '<style>' ), 0, 'styles printed once a page' );
lk_eq( leadkit_total_shortcode( array( 'field' => 'total' ) ), '<span class="lkt-field" data-lkt="total">$120,500</span>', 'field="total" inline' );
lk_ok( false === strpos( $html, 'Jane' ), 'no donor name anywhere in the card' );
$json = json_encode( leadkit_total_payload() );
lk_ok( false === strpos( $json, 'Jane' ) && false !== strpos( $json, '"total":120500' ), 'REST payload: totals only', $json );

lk_done();
