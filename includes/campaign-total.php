<?php
/**
 * Campaign total — a live fundraising total from the Donations board.
 *
 * Each background run (the same 15- or 5-minute schedule as the gift linker)
 * adds up Amount on every Donations row whose rule column reads the campaign
 * label — on 3sgf.org, "EOY 2026" = "EOY 2026" — and keeps the result. The
 * site shows it with [leadkit_total] (alias [eoy_total]) and serves it at
 * leadkit/v1/total, so a page from SiteGround's cache still refreshes.
 *
 * Read only: columns are found by title and never created, nothing is written
 * to monday.com. Totals only ever leave WordPress — dollar total, match share,
 * gift count, percent of goal — never a name or a single gift.
 *
 * Any failure keeps the last good total; the site never drops to $0. Settings
 * → Campaign total has a manual override (switches itself off at a set time,
 * every change logged) for when monday.com or Zapier is what's broken: it
 * lives in WordPress so it still works then.
 *
 * Settings → LeadKit → Campaign total turns it on and sets the rule and goal.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_TOTAL_HOOK     = 'leadkit_campaign_total';
const LEADKIT_TOTAL_STATE    = 'leadkit_total_state';    // Last good pull.
const LEADKIT_TOTAL_OVERRIDE = 'leadkit_total_override'; // Manual numbers.
const LEADKIT_TOTAL_LOG      = 'leadkit_total_log';      // Override changes, newest first.
const LEADKIT_TOTAL_ERROR    = 'leadkit_total_error';

add_action(
	'init',
	function () {
		leadkit_keep_scheduled( LEADKIT_TOTAL_HOOK, leadkit_total_enabled() );
	}
);
add_action( LEADKIT_TOTAL_HOOK, 'leadkit_total_pull' );

/**
 * The board the total reads: Campaign total board, else the Donations board.
 *
 * @return string
 */
function leadkit_total_board() {
	$own = preg_replace( '/\D/', '', (string) ( leadkit_options()['total_board'] ?? '' ) );
	return '' !== $own ? $own : leadkit_monday_donations_board();
}

/**
 * Whether the total is switched on and has a token and a board.
 *
 * @return bool
 */
function leadkit_total_enabled() {
	return '1' === (string) ( leadkit_options()['total_on'] ?? '' ) && '' !== leadkit_monday_token() && '' !== leadkit_total_board();
}

/**
 * The rule "Column title = Label", the goal, and the column titles read.
 * Entry labels that are money but not gifts (the match, a lump sum of offline
 * gifts) are left out of the gift count; the match is shown as its own share.
 *
 * @return array
 */
function leadkit_total_rule() {
	$o    = leadkit_options();
	$rule = (string) ( $o['total_rule'] ?? '' );
	list( $col, $label ) = array_map( 'trim', array_pad( explode( '=', $rule, 2 ), 2, '' ) );
	return (array) apply_filters(
		'leadkit/campaign_total_columns',
		array(
			'amount'      => 'Amount',
			'rule'        => $col,
			'label'       => '' !== $label ? $label : $col,
			'entry'       => 'Entry',
			'match_label' => 'Match dollars',
			'not_gifts'   => array( 'Match dollars', 'Offline lump sum' ),
			'goal'        => max( 0, (float) ( $o['total_goal'] ?? 0 ) ), // 0 = not set: no percent, no bar.
		)
	);
}

/**
 * Column ids by title. Read only: a missing Amount or rule column is an error,
 * never created; a missing Entry column only means no match share and every
 * row counted as a gift.
 *
 * @param string $board Board id.
 * @param array  $r     leadkit_total_rule().
 * @return array|null slot => column id; null when monday.com could not be asked
 *                    or a needed column is not on the board.
 */
function leadkit_total_columns( $board, $r ) {
	$data = leadkit_monday( 'query ($b: [ID!]) { boards(ids: $b) { columns { id title } } }', array( 'b' => array( (string) $board ) ) );
	$list = $data['boards'][0]['columns'] ?? null;
	if ( ! is_array( $list ) || ! $list ) { // No board, or one this token sees no columns on.
		if ( null !== $data ) {
			update_option( LEADKIT_TOTAL_ERROR, sprintf( 'Board %s not found, or not readable with this token', $board ), false );
		}
		return null;
	}
	$by_title = array();
	foreach ( $list as $c ) {
		$by_title[ strtolower( trim( (string) $c['title'] ) ) ] = (string) $c['id'];
	}
	$cols = array();
	foreach ( array( 'amount', 'rule', 'entry' ) as $slot ) {
		$id = $by_title[ strtolower( (string) $r[ $slot ] ) ] ?? '';
		if ( '' === $id && 'entry' !== $slot ) {
			update_option( LEADKIT_TOTAL_ERROR, sprintf( 'No column titled "%s" on board %s', $r[ $slot ], $board ), false );
			return null;
		}
		if ( '' !== $id ) {
			$cols[ $slot ] = $id;
		}
	}
	return $cols;
}

/**
 * Turn an Amount cell's text into dollars. Empty (an offline total nobody has
 * filled yet) is 0, not an error.
 *
 * @param string $text Column text, e.g. "1,250.5".
 * @return float
 */
function leadkit_total_amount( $text ) {
	$n = preg_replace( '/[^0-9.\-]/', '', (string) $text );
	return is_numeric( $n ) ? (float) $n : 0.0;
}

/**
 * Add a page of items to the running sums.
 *
 * @param array $items monday.com items with column_values {id, text}.
 * @param array $cols  leadkit_total_columns().
 * @param array $r     leadkit_total_rule().
 * @param array $sum   total, match, gifts, rows.
 * @return array
 */
function leadkit_total_add( array $items, array $cols, array $r, array $sum ) {
	foreach ( $items as $item ) {
		$t = array();
		foreach ( (array) ( $item['column_values'] ?? array() ) as $cv ) {
			$t[ $cv['id'] ?? '' ] = trim( (string) ( $cv['text'] ?? '' ) );
		}
		if ( ( $t[ $cols['rule'] ] ?? '' ) !== $r['label'] ) {
			continue;
		}
		$amount = leadkit_total_amount( $t[ $cols['amount'] ] ?? '' );
		$entry  = isset( $cols['entry'] ) ? ( $t[ $cols['entry'] ] ?? '' ) : '';
		$sum['total'] += $amount;
		++$sum['rows'];
		if ( $entry === $r['match_label'] ) {
			$sum['match'] += $amount;
		}
		if ( ! in_array( $entry, (array) $r['not_gifts'], true ) ) {
			++$sum['gifts'];
		}
	}
	return $sum;
}

/**
 * Pull the board and keep the result. On any failure — a later page included,
 * since a partial sum would understate the total — the last good state stays
 * exactly as it was.
 *
 * @return bool Whether a fresh total was saved.
 */
function leadkit_total_pull() {
	$board = leadkit_total_board();
	if ( '' === leadkit_monday_token() || '' === $board ) {
		update_option( LEADKIT_TOTAL_ERROR, 'Needs a monday.com token and a Donations board (Settings → LeadKit)', false );
		return false;
	}
	$r    = leadkit_total_rule();
	$cols = leadkit_total_columns( $board, $r );
	if ( null === $cols ) {
		return false;
	}
	$ids   = array_values( $cols );
	$limit = (int) apply_filters( 'leadkit/campaign_total_page', 500 );
	$sum   = array( 'total' => 0.0, 'match' => 0.0, 'gifts' => 0, 'rows' => 0 );
	$fail  = function () {
		update_option( LEADKIT_TOTAL_ERROR, (string) get_option( 'leadkit_last_monday_error', 'monday.com could not be asked' ), false );
		return false;
	};

	$data = leadkit_monday(
		'query ($b: [ID!], $c: [String!], $l: Int!) { boards(ids: $b) { items_page(limit: $l) { cursor items { column_values(ids: $c) { id text } } } } }',
		array( 'b' => array( $board ), 'c' => $ids, 'l' => $limit )
	);
	$page = $data['boards'][0]['items_page'] ?? null;
	if ( ! is_array( $page ) ) {
		return $fail();
	}
	$sum = leadkit_total_add( (array) ( $page['items'] ?? array() ), $cols, $r, $sum );

	for ( $cursor = $page['cursor'] ?? null, $guard = 0; $cursor && $guard < 40; ++$guard ) {
		$data = leadkit_monday(
			'query ($k: String!, $c: [String!], $l: Int!) { next_items_page(limit: $l, cursor: $k) { cursor items { column_values(ids: $c) { id text } } } }',
			array( 'k' => $cursor, 'c' => $ids, 'l' => $limit )
		);
		if ( ! isset( $data['next_items_page'] ) ) {
			return $fail();
		}
		$sum    = leadkit_total_add( (array) ( $data['next_items_page']['items'] ?? array() ), $cols, $r, $sum );
		$cursor = $data['next_items_page']['cursor'] ?? null;
	}

	update_option( LEADKIT_TOTAL_STATE, $sum + array( 'at' => time() ), false );
	delete_option( LEADKIT_TOTAL_ERROR );
	return true;
}

/**
 * The manual override, switched off (and logged) the first time anyone looks
 * after its set time.
 *
 * @return array on, total, gifts ('' = keep monday.com's), note, at, expires.
 */
function leadkit_total_override() {
	$o = wp_parse_args(
		(array) get_option( LEADKIT_TOTAL_OVERRIDE, array() ),
		array( 'on' => false, 'total' => 0, 'gifts' => '', 'note' => '', 'at' => 0, 'expires' => 0 )
	);
	if ( $o['on'] && $o['expires'] && time() >= (int) $o['expires'] ) {
		$o['on'] = false;
		update_option( LEADKIT_TOTAL_OVERRIDE, $o, false );
		leadkit_total_log( 'Override switched itself off (its set time passed)', 'system' );
	}
	return $o;
}

/**
 * Keep the last 50 changes: when, who, what.
 *
 * @param string $what What changed.
 * @param string $who  Login, or "system".
 */
function leadkit_total_log( $what, $who = '' ) {
	if ( '' === $who ) {
		$u   = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$who = $u && $u->exists() ? $u->user_login : 'unknown';
	}
	$log = (array) get_option( LEADKIT_TOTAL_LOG, array() );
	array_unshift( $log, array( 'at' => time(), 'who' => $who, 'what' => $what ) );
	update_option( LEADKIT_TOTAL_LOG, array_slice( $log, 0, 50 ), false );
}

/**
 * What the public sees: the override while it's on, else the last good pull.
 * `total` is null when there has never been a number, so the display says
 * "—" instead of $0.
 *
 * @return array total, match, gifts, goal, percent, at, source.
 */
function leadkit_total_public() {
	$o     = leadkit_total_override();
	$state = get_option( LEADKIT_TOTAL_STATE, array() );
	$goal  = leadkit_total_rule()['goal'];

	if ( $o['on'] ) {
		$total  = (float) $o['total'];
		$gifts  = '' === $o['gifts'] ? ( $state['gifts'] ?? null ) : (int) $o['gifts'];
		$match  = $state['match'] ?? null;
		$at     = (int) $o['at'];
		$source = 'manual';
	} elseif ( isset( $state['total'] ) ) {
		$total  = (float) $state['total'];
		$gifts  = (int) $state['gifts'];
		$match  = (float) $state['match'];
		$at     = (int) $state['at'];
		$source = 'monday';
	} else {
		$total  = null;
		$gifts  = null;
		$match  = null;
		$at     = 0;
		$source = 'none';
	}

	return array(
		'total'   => null === $total ? null : round( $total, 2 ),
		'match'   => null === $match ? null : round( min( (float) $match, (float) $total ), 2 ),
		'gifts'   => $gifts,
		'goal'    => $goal,
		'percent' => null === $total || $goal <= 0 ? null : (int) floor( min( 100, $total / $goal * 100 ) ),
		'at'      => $at,
		'source'  => $source,
	);
}

/**
 * One value as shown. Missing data is a dash, never $0.
 *
 * @param array  $p     leadkit_total_public().
 * @param string $field total, match, goal, percent, gifts or updated.
 * @return string
 */
function leadkit_total_field( array $p, $field ) {
	$money = function ( $n ) {
		return '$' . number_format( (float) $n, 0 );
	};
	switch ( $field ) {
		case 'total':
			return null === $p['total'] ? '—' : $money( $p['total'] );
		case 'match':
			return null === $p['match'] ? '—' : $money( $p['match'] );
		case 'goal':
			return $p['goal'] > 0 ? $money( $p['goal'] ) : '—';
		case 'percent':
			return null === $p['percent'] ? '—' : $p['percent'] . '%';
		case 'gifts':
			return null === $p['gifts'] ? '—' : number_format( (int) $p['gifts'] );
		case 'updated':
			return $p['at'] ? wp_date( 'M j, g:i a', $p['at'] ) : '—';
	}
	return '';
}

/**
 * [leadkit_total] — the tracker card.
 * [leadkit_total field="total|percent|gifts|goal|match|updated"] — one value inline.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function leadkit_total_shortcode( $atts ) {
	$a = shortcode_atts( array( 'field' => '' ), $atts, 'leadkit_total' );
	$p = leadkit_total_public();

	if ( '' !== $a['field'] ) {
		return '<span class="lkt-field" data-lkt="' . esc_attr( $a['field'] ) . '">' . esc_html( leadkit_total_field( $p, $a['field'] ) ) . '</span>';
	}

	static $styled = false;
	$out = '';
	if ( ! $styled ) {
		$styled = true;
		$out   .= '<style>' . leadkit_total_css() . '</style><script>' . leadkit_total_js() . '</script>';
	}

	$has     = null !== $p['percent'];
	$pct     = $has ? $p['percent'] : 0;
	$match_w = $has && $p['match'] ? (int) min( $pct, floor( $p['match'] / $p['goal'] * 100 ) ) : 0;
	$comm_w  = max( 0, $pct - $match_w );
	$label   = $has
		? sprintf( '%s raised of a %s goal, %d percent', leadkit_total_field( $p, 'total' ), leadkit_total_field( $p, 'goal' ), $pct )
		: 'Campaign total updating';

	ob_start();
	?>
<div class="lkt" data-lkt-src="<?= esc_url( rest_url( LEADKIT_REST_NS . '/total' ) ) ?>">
	<div class="lkt-live"><span class="lkt-pulse" aria-hidden="true"></span>Raised so far</div>
	<div class="lkt-total" data-lkt="total"><?= esc_html( leadkit_total_field( $p, 'total' ) ) ?></div>
	<div class="lkt-of">of a <span data-lkt="goal"><?= esc_html( leadkit_total_field( $p, 'goal' ) ) ?></span> goal · <b data-lkt="percent"><?= esc_html( leadkit_total_field( $p, 'percent' ) ) ?></b></div>
	<div class="lkt-bar" role="img" aria-label="<?= esc_attr( $label ) ?>"><span class="lkt-c" style="width:<?= (int) $comm_w ?>%"></span><span class="lkt-m" style="width:<?= (int) $match_w ?>%"></span></div>
	<div class="lkt-meta"><span><b data-lkt="gifts"><?= esc_html( leadkit_total_field( $p, 'gifts' ) ) ?></b> gifts</span><span>Updated <span data-lkt="updated"><?= esc_html( leadkit_total_field( $p, 'updated' ) ) ?></span></span></div>
</div>
	<?php
	return $out . trim( ob_get_clean() );
}
add_shortcode( 'leadkit_total', 'leadkit_total_shortcode' );
add_shortcode( 'eoy_total', 'leadkit_total_shortcode' ); // The name the Lighting the Path page uses.

/**
 * Card styles: 3SGF brand tokens by default, scoped to .lkt, every value set
 * so a theme's globals (Divi's h/p padding) can't reach in. Filterable for
 * another site's colours.
 *
 * @return string
 */
function leadkit_total_css() {
	return (string) apply_filters(
		'leadkit/campaign_total_css',
		'.lkt{box-sizing:border-box;width:100%;max-width:440px;margin:0 auto;text-align:left;font-family:Quicksand,Arial,sans-serif;color:#2b3338;line-height:1.5}'
		. '.lkt *{box-sizing:border-box}'
		. '.lkt-live{display:flex;align-items:center;gap:8px;font-family:Montserrat,Arial,sans-serif;font-weight:700;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#0c5257}'
		. '.lkt-pulse{width:10px;height:10px;border-radius:50%;background:#00747e;box-shadow:0 0 0 0 rgba(0,116,126,.5);animation:lkt-p 2.4s ease-out infinite}'
		. '@keyframes lkt-p{70%{box-shadow:0 0 0 10px rgba(0,116,126,0)}100%{box-shadow:0 0 0 0 rgba(0,116,126,0)}}'
		. '@media (prefers-reduced-motion:reduce){.lkt-pulse{animation:none}.lkt-c,.lkt-m{transition:none}}'
		. '.lkt-total{font-family:Montserrat,Arial,sans-serif;font-weight:800;font-size:56px;line-height:1.05;color:#05353c;margin-top:10px;letter-spacing:-.01em}'
		. '.lkt-of{font-size:17px;color:#2b3338;margin-top:6px}.lkt-of b{font-family:Montserrat,Arial,sans-serif;font-weight:800;color:#00747e}'
		. '.lkt-bar{display:flex;height:18px;border-radius:999px;overflow:hidden;background:#fff;border:1px solid rgba(0,116,126,.25);margin-top:18px}'
		. '.lkt-c{background:#00747e}.lkt-m{background:#daba83}.lkt-c,.lkt-m{display:block;height:100%;transition:width .6s ease-out}'
		. '.lkt-meta{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px 16px;font-size:14px;color:#0c5257;margin-top:12px}.lkt-meta b{font-family:Montserrat,Arial,sans-serif;font-weight:800}'
		. '@media (max-width:600px){.lkt-total{font-size:44px}}'
	);
}

/**
 * Refresh every card from leadkit/v1/total on load. textContent only.
 *
 * @return string
 */
function leadkit_total_js() {
	return "document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.lkt[data-lkt-src]').forEach(function(el){fetch(el.getAttribute('data-lkt-src'),{credentials:'omit'}).then(function(r){return r.ok?r.json():null}).then(function(d){if(!d||d.total===null||d.percent===null)return;var set=function(k,v){el.querySelectorAll('[data-lkt=\"'+k+'\"]').forEach(function(n){n.textContent=v})};['total','percent','gifts','goal','updated'].forEach(function(k){set(k,d.display[k])});var m=d.match?Math.min(d.percent,Math.floor(d.match/d.goal*100)):0;el.querySelector('.lkt-c').style.width=Math.max(0,d.percent-m)+'%';el.querySelector('.lkt-m').style.width=m+'%';el.querySelector('.lkt-bar').setAttribute('aria-label',d.display.total+' raised of a '+d.display.goal+' goal, '+d.percent+' percent')}).catch(function(){})})});";
}

/**
 * The public JSON: totals and their display strings, nothing else.
 *
 * @return array
 */
function leadkit_total_payload() {
	$p            = leadkit_total_public();
	$p['display'] = array();
	foreach ( array( 'total', 'match', 'goal', 'percent', 'gifts', 'updated' ) as $f ) {
		$p['display'][ $f ] = leadkit_total_field( $p, $f );
	}
	return $p;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			'/total',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // Totals only; nothing personal.
				'callback'            => function () {
					$res = new WP_REST_Response( leadkit_total_payload() );
					$res->header( 'Cache-Control', 'public, max-age=300' );
					return $res;
				},
			)
		);
	}
);

/* ---------- Settings → Campaign total: live numbers, pull now, override, log ---------- */

add_action(
	'admin_menu',
	function () {
		add_options_page( __( 'Campaign total', 'leadkit' ), __( 'Campaign total', 'leadkit' ), 'manage_options', 'leadkit-total', 'leadkit_total_render_page' );
	}
);

/**
 * Save the override form, or pull now. Split from the admin-post hook so the
 * check script can call it with a posted array.
 *
 * @param array $post Posted fields (unslashed).
 * @return string Result: saved, pulled or pull_failed.
 */
function leadkit_total_save( array $post ) {
	if ( ! empty( $post['pull'] ) ) {
		return leadkit_total_pull() ? 'pulled' : 'pull_failed';
	}
	$old = leadkit_total_override();
	$new = $old;

	$new['on']    = ! empty( $post['on'] );
	$new['total'] = max( 0, leadkit_total_amount( $post['total'] ?? '' ) );
	$gifts        = trim( (string) ( $post['gifts'] ?? '' ) );
	$new['gifts'] = '' === $gifts ? '' : max( 0, (int) $gifts );
	$new['note']  = sanitize_text_field( (string) ( $post['note'] ?? '' ) );
	$exp          = sanitize_text_field( (string) ( $post['expires'] ?? '' ) );
	try {
		$new['expires'] = '' === $exp ? 0 : ( new DateTimeImmutable( $exp, wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		$new['expires'] = 0;
	}

	$total_changed = (float) $new['total'] !== (float) $old['total'];
	if ( $new['on'] && ( ! $old['on'] || $total_changed || (string) $new['gifts'] !== (string) $old['gifts'] ) ) {
		$new['at'] = time(); // "Updated" on the site is when the number was typed.
	}
	$money = '$' . number_format( (float) $new['total'], 0 );
	if ( $new['on'] !== (bool) $old['on'] ) {
		leadkit_total_log( ( $new['on'] ? 'Override ON at ' . $money : 'Override OFF' ) . ( '' !== $new['note'] ? ' — ' . $new['note'] : '' ) );
	} elseif ( $new['on'] && $total_changed ) {
		leadkit_total_log( 'Override total changed to ' . $money );
	}
	update_option( LEADKIT_TOTAL_OVERRIDE, $new, false );
	return 'saved';
}

add_action(
	'admin_post_leadkit_total_save',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'leadkit' ) );
		}
		check_admin_referer( 'leadkit_total_save' );
		$result = leadkit_total_save( wp_unslash( $_POST ) );
		wp_safe_redirect( add_query_arg( 'leadkit_total', $result, wp_get_referer() ) );
		exit;
	}
);

/**
 * The Campaign total screen.
 */
function leadkit_total_render_page() {
	$o     = leadkit_total_override();
	$state = get_option( LEADKIT_TOTAL_STATE, array() );
	$err   = (string) get_option( LEADKIT_TOTAL_ERROR, '' );
	$log   = (array) get_option( LEADKIT_TOTAL_LOG, array() );
	$next  = wp_next_scheduled( LEADKIT_TOTAL_HOOK );
	$r     = leadkit_total_rule();
	$msg   = sanitize_key( $_GET['leadkit_total'] ?? '' );
	$fmt   = 'M j, Y g:i a';
	$money = function ( $n ) {
		return '$' . number_format( (float) $n, 0 );
	};
	?>
<div class="wrap">
	<h1><?= esc_html__( 'Campaign total', 'leadkit' ) ?></h1>
	<?php if ( 'saved' === $msg ) : ?><div class="notice notice-success"><p><?= esc_html__( 'Saved.', 'leadkit' ) ?></p></div><?php endif; ?>
	<?php if ( 'pulled' === $msg ) : ?><div class="notice notice-success"><p><?= esc_html__( 'Fresh total pulled from monday.com.', 'leadkit' ) ?></p></div><?php endif; ?>
	<?php if ( 'pull_failed' === $msg ) : ?><div class="notice notice-error"><p><?= esc_html__( 'The pull failed; the site still shows the last good total.', 'leadkit' ) ?></p></div><?php endif; ?>
	<?php if ( ! leadkit_total_enabled() ) : ?>
		<div class="notice notice-warning"><p><?= esc_html__( 'Not running: turn on Campaign total and set its rule in Settings → LeadKit (it needs the monday.com token and a Donations board there too).', 'leadkit' ) ?></p></div>
	<?php endif; ?>

	<p>
		<?= esc_html( sprintf( 'Board %s · adds up "%s" where "%s" is "%s" · goal %s', leadkit_total_board() ?: '—', $r['amount'], $r['rule'] ?: '—', $r['label'] ?: '—', $money( $r['goal'] ) ) ) ?>
		· <?= esc_html__( 'shortcode', 'leadkit' ) ?> <code>[leadkit_total]</code>
	</p>

	<h2><?= esc_html__( 'Live from monday.com', 'leadkit' ) ?></h2>
	<table class="widefat striped" style="max-width:640px">
		<tr><th><?= esc_html__( 'Total', 'leadkit' ) ?></th><td><strong><?= isset( $state['total'] ) ? esc_html( $money( $state['total'] ) ) : '—' ?></strong></td></tr>
		<tr><th><?= esc_html__( 'Of which match', 'leadkit' ) ?></th><td><?= isset( $state['match'] ) ? esc_html( $money( $state['match'] ) ) : '—' ?></td></tr>
		<tr><th><?= esc_html__( 'Gifts counted', 'leadkit' ) ?></th><td><?= isset( $state['gifts'] ) ? (int) $state['gifts'] : '—' ?></td></tr>
		<tr><th><?= esc_html__( 'Last good pull', 'leadkit' ) ?></th><td><?= ! empty( $state['at'] ) ? esc_html( wp_date( $fmt, $state['at'] ) ) : esc_html__( 'never', 'leadkit' ) ?></td></tr>
		<tr><th><?= esc_html__( 'Next pull', 'leadkit' ) ?></th><td><?= $next ? esc_html( wp_date( $fmt, $next ) ) : esc_html__( 'not scheduled', 'leadkit' ) ?></td></tr>
		<tr><th><?= esc_html__( 'Last error', 'leadkit' ) ?></th><td><?= '' !== $err ? '<span style="color:#b32d2e">' . esc_html( $err ) . '</span>' : esc_html__( 'none', 'leadkit' ) ?></td></tr>
	</table>

	<form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
		<?php wp_nonce_field( 'leadkit_total_save' ); ?>
		<input type="hidden" name="action" value="leadkit_total_save">
		<p><button class="button" name="pull" value="1"><?= esc_html__( 'Pull from monday.com now', 'leadkit' ) ?></button></p>

		<h2><?= esc_html__( 'Manual override', 'leadkit' ) ?></h2>
		<p><?= esc_html__( 'Only when the monday.com number is wrong or stuck. The scheduled pull keeps running underneath, so compare the two above and switch back when they agree.', 'leadkit' ) ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?= esc_html__( 'Use manual total', 'leadkit' ) ?></th><td><label><input type="checkbox" name="on" value="1" <?php checked( $o['on'] ); ?>> <?= esc_html__( 'Show the numbers below on the site', 'leadkit' ) ?></label>
				<?php if ( $o['on'] ) : ?><p><strong style="color:#b32d2e"><?= esc_html( sprintf( 'ON since %s', wp_date( $fmt, $o['at'] ) ) ) ?></strong></p><?php endif; ?></td></tr>
			<tr><th scope="row"><label for="lkt-total"><?= esc_html__( 'Manual total ($)', 'leadkit' ) ?></label></th><td><input id="lkt-total" type="text" name="total" value="<?= esc_attr( $o['total'] ) ?>" class="regular-text" inputmode="decimal"></td></tr>
			<tr><th scope="row"><label for="lkt-gifts"><?= esc_html__( 'Manual gift count', 'leadkit' ) ?></label></th><td><input id="lkt-gifts" type="number" min="0" name="gifts" value="<?= esc_attr( $o['gifts'] ) ?>"><p class="description"><?= esc_html__( 'Optional. Empty keeps monday.com\'s count.', 'leadkit' ) ?></p></td></tr>
			<tr><th scope="row"><label for="lkt-note"><?= esc_html__( 'Why', 'leadkit' ) ?></label></th><td><input id="lkt-note" type="text" name="note" value="<?= esc_attr( $o['note'] ) ?>" class="large-text"></td></tr>
			<tr><th scope="row"><label for="lkt-exp"><?= esc_html__( 'Switch off automatically at', 'leadkit' ) ?></label></th><td><input id="lkt-exp" type="datetime-local" name="expires" value="<?= $o['expires'] ? esc_attr( wp_date( 'Y-m-d\TH:i', $o['expires'] ) ) : '' ?>"><p class="description"><?= esc_html__( 'Site time. Empty = on until someone turns it off.', 'leadkit' ) ?></p></td></tr>
		</table>
		<?php submit_button( __( 'Save', 'leadkit' ) ); ?>
	</form>

	<h2><?= esc_html__( 'Change log', 'leadkit' ) ?></h2>
	<?php if ( ! $log ) : ?>
		<p><?= esc_html__( 'No changes yet.', 'leadkit' ) ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:800px"><thead><tr><th><?= esc_html__( 'When', 'leadkit' ) ?></th><th><?= esc_html__( 'Who', 'leadkit' ) ?></th><th><?= esc_html__( 'What', 'leadkit' ) ?></th></tr></thead><tbody>
		<?php foreach ( $log as $row ) : ?>
			<tr><td><?= esc_html( wp_date( $fmt, (int) $row['at'] ) ) ?></td><td><?= esc_html( $row['who'] ) ?></td><td><?= esc_html( $row['what'] ) ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>
</div>
	<?php
}
