<?php
/**
 * The gift linker — the connections monday.com's own automations cannot make.
 *
 * Gifts reach a monday.com Donations board through Zapier. Two monday.com
 * "match" automations then connect each new gift to an EXISTING visitor (by
 * visitorid) and an EXISTING person (by email). What they cannot do:
 *
 *   - create the person when no one on People has that email yet (the match
 *     template has no create-if-missing, and Zapier's monday.com search has
 *     no find-or-create either);
 *   - give the gift's browser the gift's person — that changes a different
 *     item from the one that triggered the automation;
 *   - write a link that opens the person's own card.
 *
 * So every 15 minutes LeadKit looks at the newest gifts (Settings → LeadKit →
 * monday.com Donations board) and, for each one it has not finished:
 *
 *   1. no Person: finds the donor on People by email, or creates them, and
 *      sets the gift's Person (a two-way column, so the person's Donations and
 *      Total given follow);
 *   2. fills the gift's "Open person" link;
 *   3. a Visitor whose row has no Person yet: gives it this gift's person, and
 *      fills "Open person" on that browser's row and its visit rows. A browser
 *      that already belongs to someone (a shared computer) is left alone: the
 *      gift is already on the right person, by email.
 *
 * A gift is finished when all of that is done, or — for a gift with no
 * visitor, such as one from a QR code — after a week of waiting for one.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_GIFT_HOOK      = 'leadkit_link_gifts';
const LEADKIT_GIFT_WAIT      = 7 * DAY_IN_SECONDS;
const LEADKIT_GIFTS_PER_RUN  = 25;
const LEADKIT_GIFTS_REMEMBER = 3000;

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['leadkit_quarter_hour'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (LeadKit)', 'leadkit' ),
		);
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		$next = wp_next_scheduled( LEADKIT_GIFT_HOOK );
		if ( leadkit_gifts_enabled() && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'leadkit_quarter_hour', LEADKIT_GIFT_HOOK );
		} elseif ( ! leadkit_gifts_enabled() && $next ) {
			wp_clear_scheduled_hook( LEADKIT_GIFT_HOOK );
		}
	}
);
add_action( LEADKIT_GIFT_HOOK, 'leadkit_link_gifts' );

/**
 * The Donations board id, if set.
 *
 * @return string
 */
function leadkit_monday_donations_board() {
	return preg_replace( '/\D/', '', (string) ( leadkit_options()['monday_donations_board'] ?? '' ) );
}

/**
 * Whether there is anything to link: a token, a Donations board, a People board.
 *
 * @return bool
 */
function leadkit_gifts_enabled() {
	return '' !== leadkit_monday_token() && '' !== leadkit_monday_donations_board() && '' !== leadkit_monday_people_board();
}

/**
 * The Donations board's columns. Person is created two-way if it is missing
 * (People's Total given depends on that); Visitor only with a Visitors board.
 *
 * @param string $board Donations board id.
 * @return array|null
 */
function leadkit_gift_columns( $board ) {
	$want = array(
		'person' => array( 'Person', 'board_relation', array( 'boardIds' => array( (int) leadkit_monday_people_board() ), 'allowMultipleItems' => false, 'allowCreateReflectionColumn' => true ) ),
		'email'  => array( 'Donor email', 'email' ),
		'open'   => array( 'Open person', 'link' ),
	);
	$visitors = leadkit_monday_visitors_board();
	if ( $visitors ) {
		$want['visitor'] = array( 'Visitor', 'board_relation', array( 'boardIds' => array( (int) $visitors ), 'allowMultipleItems' => false, 'allowCreateReflectionColumn' => true ) );
		$want['vid']     = array( 'visitorid', 'text' );
	}
	return leadkit_monday_ensure_columns( $board, $want, 'gifts' );
}

/**
 * One run of the linker.
 *
 * @return array What happened, for the checks: { looked, linked, people, browsers }.
 */
function leadkit_link_gifts() {
	$out = array( 'looked' => 0, 'linked' => 0, 'people' => 0, 'browsers' => 0 );
	if ( ! leadkit_gifts_enabled() ) {
		return $out;
	}
	$lock = leadkit_visitor_lock( 'gift-linker' );
	if ( ! $lock ) {
		return $out;
	}
	$board  = leadkit_monday_donations_board();
	$people = leadkit_monday_people_board();
	$cols   = leadkit_gift_columns( $board );
	$pcols  = $cols ? leadkit_people_columns( $people ) : null;
	if ( ! $cols || ! $pcols ) {
		leadkit_visitor_unlock( $lock );
		return $out;
	}

	$ids  = array_values( array_filter( array( $cols['person'], $cols['email'], $cols['open'], $cols['visitor'] ?? '', $cols['vid'] ?? '' ) ) );
	$data = leadkit_monday(
		'query ($b: [ID!], $c: [String!]) { boards(ids: $b) { items_page(limit: 100, query_params: {order_by: [{column_id: "__creation_log__", direction: desc}]}) { items { id name created_at column_values(ids: $c) { id text ... on BoardRelationValue { linked_item_ids } } } } } }',
		array( 'b' => array( $board ), 'c' => $ids )
	);
	$items = $data['boards'][0]['items_page']['items'] ?? null;
	if ( ! is_array( $items ) ) {
		leadkit_visitor_unlock( $lock );
		return $out;
	}

	$done  = get_option( 'leadkit_gifts_done', array() );
	$done  = is_array( $done ) ? $done : array();
	$todo  = array();
	foreach ( $items as $it ) {
		if ( isset( $done[ $it['id'] ] ) ) {
			continue;
		}
		$v = array();
		foreach ( (array) $it['column_values'] as $cv ) {
			$v[ $cv['id'] ] = $cv;
		}
		$todo[] = array(
			'id'      => (string) $it['id'],
			'name'    => (string) $it['name'],
			'age'     => time() - (int) strtotime( (string) ( $it['created_at'] ?? '' ) ),
			'person'  => (string) ( $v[ $cols['person'] ]['linked_item_ids'][0] ?? '' ),
			'email'   => strtolower( trim( (string) ( $v[ $cols['email'] ]['text'] ?? '' ) ) ),
			'open'    => trim( (string) ( $v[ $cols['open'] ]['text'] ?? '' ) ),
			'visitor' => isset( $cols['visitor'] ) ? (string) ( $v[ $cols['visitor'] ]['linked_item_ids'][0] ?? '' ) : '',
			'vid'     => isset( $cols['vid'] ) ? trim( (string) ( $v[ $cols['vid'] ]['text'] ?? '' ) ) : '',
		);
		if ( count( $todo ) >= LEADKIT_GIFTS_PER_RUN ) {
			break;
		}
	}
	$out['looked'] = count( $todo );

	// Who each gift's browser belongs to now, in one request.
	$browsers = array();
	$vcols    = leadkit_monday_visitors_board() ? leadkit_visitor_columns( leadkit_monday_visitors_board() ) : null;
	$vids     = array_values( array_unique( array_filter( array_column( $todo, 'visitor' ) ) ) );
	if ( $vids && $vcols && ! empty( $vcols['person'] ) ) {
		$res = leadkit_monday(
			'query ($i: [ID!], $c: [String!]) { items(ids: $i) { id column_values(ids: $c) { id ... on BoardRelationValue { linked_item_ids } } } }',
			array( 'i' => $vids, 'c' => array( $vcols['person'] ) )
		);
		if ( null === $res ) {
			$vids = array(); // Could not ask: leave browsers for the next run.
		}
		foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
			$browsers[ (string) $it['id'] ] = (string) ( $it['column_values'][0]['linked_item_ids'][0] ?? '' );
		}
	}

	foreach ( $todo as $g ) {
		$brief = null;

		// 1. The person: theirs already, found by email, or made.
		if ( ! $g['person'] ) {
			if ( ! $g['email'] ) {
				if ( $g['age'] > LEADKIT_GIFT_WAIT ) {
					$done[ $g['id'] ] = time(); // Nothing to go on.
				}
				continue;
			}
			$found = leadkit_monday(
				'query ($b: ID!, $c: String!, $v: [String]!) { items_page_by_column_values(board_id: $b, limit: 1, columns: [{column_id: $c, column_values: $v}]) { items { id name url } } }',
				array( 'b' => $people, 'c' => $pcols['email'], 'v' => array( $g['email'] ) )
			);
			if ( null === $found ) {
				continue; // Could not ask: creating now could make a second person.
			}
			$hit = $found['items_page_by_column_values']['items'][0] ?? null;
			if ( $hit ) {
				$g['person'] = (string) $hit['id'];
				$brief       = array( 'name' => (string) $hit['name'], 'url' => (string) $hit['url'] );
			} else {
				$made = leadkit_monday(
					'mutation ($b: ID!, $n: String!, $v: JSON) { create_item(board_id: $b, item_name: $n, column_values: $v) { id url } }',
					array(
						'b' => $people,
						'n' => $g['name'] ?: $g['email'],
						'v' => wp_json_encode( array( $pcols['email'] => array( 'email' => $g['email'], 'text' => $g['email'] ) ) ),
					)
				);
				$g['person'] = (string) ( $made['create_item']['id'] ?? '' );
				if ( ! $g['person'] ) {
					continue;
				}
				$brief = array( 'name' => $g['name'] ?: $g['email'], 'url' => (string) ( $made['create_item']['url'] ?? '' ) );
				++$out['people'];
			}
			$values = array( $cols['person'] => array( 'item_ids' => array( (int) $g['person'] ) ) );
			if ( ! empty( $brief['url'] ) ) {
				$values[ $cols['open'] ] = leadkit_person_link( $brief );
			}
			if ( ! leadkit_visitor_set( $board, $g['id'], $values ) ) {
				continue;
			}
			$g['open'] = $brief['url'] ?? '';
			++$out['linked'];
		}

		// 2. The gift's own link to its person.
		if ( '' === $g['open'] ) {
			$brief = $brief ?: leadkit_monday_item_brief( $g['person'] );
			if ( ! $brief || ! leadkit_visitor_set( $board, $g['id'], array( $cols['open'] => leadkit_person_link( $brief ) ) ) ) {
				continue;
			}
		}

		// 3. The gift's browser.
		if ( ! $g['visitor'] ) {
			if ( ! $g['vid'] || $g['age'] > LEADKIT_GIFT_WAIT ) {
				$done[ $g['id'] ] = time(); // None, or none is coming.
			}
			continue; // The visitorid match automation may not have run yet.
		}
		if ( ! in_array( $g['visitor'], $vids, true ) ) {
			continue;
		}
		if ( '' === ( $browsers[ $g['visitor'] ] ?? '' ) ) {
			$brief = $brief ?: leadkit_monday_item_brief( $g['person'] );
			if ( ! $brief || ! leadkit_give_browser_person( $g['visitor'], $g['vid'], $g['person'], $brief, $vcols ) ) {
				continue;
			}
			$browsers[ $g['visitor'] ] = $g['person'];
			++$out['browsers'];
		}
		$done[ $g['id'] ] = time();
	}

	if ( count( $done ) > LEADKIT_GIFTS_REMEMBER ) {
		asort( $done );
		$done = array_slice( $done, -LEADKIT_GIFTS_REMEMBER, null, true );
	}
	update_option( 'leadkit_gifts_done', $done, false );
	leadkit_visitor_unlock( $lock );
	return $out;
}

/**
 * Give a browser its person: the Visitors row's Person and Open person, and
 * Open person on its visit rows. LeadKit's own state for the browser learns
 * the person too, so its next write keeps them.
 *
 * @param string $row     Visitors item id.
 * @param string $visitor Visitor id (the gift's visitorid), or ''.
 * @param string $person  People item id.
 * @param array  $brief   { name, url }.
 * @param array  $vcols   Visitors columns.
 * @return bool
 */
function leadkit_give_browser_person( $row, $visitor, $person, $brief, $vcols ) {
	$vboard = leadkit_monday_visitors_board();
	$values = array( $vcols['person'] => array( 'item_ids' => array( (int) $person ) ) );
	if ( ! empty( $vcols['open'] ) ) {
		$values[ $vcols['open'] ] = leadkit_person_link( $brief );
	}
	if ( ! leadkit_visitor_set( $vboard, $row, $values ) ) {
		return false;
	}

	$visitor = leadkit_clean_visitor_id( $visitor );
	if ( ! $visitor ) {
		return true;
	}
	$lock = leadkit_visitor_lock( $visitor );
	$key  = leadkit_visitor_row_key( $visitor );
	wp_cache_delete( $key, 'options' );
	$st = get_option( $key, array() );
	if ( is_array( $st ) && ! empty( $st['row'] ) ) {
		$st['linked'] = (string) $person;
		$st['brief']  = $brief;
		// The visit rows LeadKit made for this browser link to the person too.
		$rows = array_filter( array_column( (array) ( $st['visits'] ?? array() ), 'row' ) );
		$vs   = leadkit_monday_visits_board();
		$open = $vs && $rows ? ( leadkit_visit_columns( $vs )['open'] ?? '' ) : '';
		foreach ( array_chunk( array_values( $rows ), LEADKIT_VISITS_PER_REQUEST ) as $chunk ) {
			if ( ! $open ) {
				break;
			}
			$decl = array( '$b: ID!', '$v: JSON!' );
			$body = array();
			$vars = array( 'b' => (string) $vs, 'v' => wp_json_encode( array( $open => leadkit_person_link( $brief ) ) ) );
			foreach ( $chunk as $i => $vrow ) {
				$decl[]           = '$i' . $i . ': ID!';
				$vars[ 'i' . $i ] = (string) $vrow;
				$body[]           = 'u' . $i . ': change_multiple_column_values(item_id: $i' . $i . ', board_id: $b, column_values: $v) { id }';
			}
			leadkit_monday( 'mutation (' . implode( ', ', $decl ) . ') { ' . implode( ' ', $body ) . ' }', $vars, true );
		}
		// The rows' stored hashes no longer match; they are rewritten, the
		// same, the next time this browser posts — harmless.
		update_option( $key, $st, false );
	}
	leadkit_visitor_unlock( $lock );
	return true;
}
