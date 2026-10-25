<?php
/**
 * The gift linker — the connections monday.com's own automations cannot make.
 *
 * Gifts reach a monday.com Donations board through Zapier, which only creates
 * the gift. A monday.com "match" automation may connect it to an EXISTING
 * person by email at once. What automations cannot do:
 *
 *   - unpack the packed source a donation link carried (formula columns can
 *     neither trigger nor feed an automation);
 *   - create the person when no one on People has that email yet (the match
 *     template has no create-if-missing, and Zapier's monday.com search has
 *     no find-or-create either);
 *   - give the gift's browser the gift's person — that changes a different
 *     item from the one that triggered the automation;
 *   - write a link that opens the person's own card.
 *
 * So every 15 minutes (or 5: Settings → LeadKit → Background runs) LeadKit
 * looks at the newest gifts (Settings → LeadKit → monday.com Donations board)
 * and, for each one it has not finished:
 *
 *   1. unpacks "Packed source (raw)" into the gift's empty utm_medium,
 *      utm_source, utm_campaign, utm_content, utm_term and visitorid columns
 *      — those the board has; none is created;
 *   2. connects the gift's Visitor: the Visitors row with its visitorid;
 *   3. no Person some minutes after the gift arrived (Settings → LeadKit →
 *      Wait before making a donor, 10 by default; Zapier and the email match
 *      go first): finds the donor on People by email, or creates them — in
 *      the group set for new people, with the gift's Donor phone, street and
 *      the like copied — and sets the gift's Person (a two-way column, so the
 *      person's Donations and Total given follow);
 *   4. fills the gift's "Open person" link;
 *   5. a Visitor whose row has no Person yet: gives it this gift's person, and
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
const LEADKIT_GIFT_GRACE     = 10 * MINUTE_IN_SECONDS; // Unless the setting says otherwise.
const LEADKIT_GIFTS_PER_RUN  = 25;
const LEADKIT_GIFTS_REMEMBER = 3000;
// A packed source's fields, and the Donations columns (by title) they fill.
const LEADKIT_GIFT_UNPACK = array(
	'medium'    => 'utm_medium',
	'source'    => 'utm_source',
	'campaign'  => 'utm_campaign',
	'content'   => 'utm_content',
	'term'      => 'utm_term',
	'visitorid' => 'visitorid',
);

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['leadkit_quarter_hour'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (LeadKit)', 'leadkit' ),
		);
		$schedules['leadkit_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (LeadKit)', 'leadkit' ),
		);
		return $schedules;
	}
);

/**
 * The background runs' schedule — the gift linker and the warm-browser
 * sender: Settings → LeadKit → Background runs, 15 minutes unless set to 5.
 *
 * @return string
 */
function leadkit_background_schedule() {
	return '5' === (string) ( leadkit_options()['background_minutes'] ?? '' ) ? 'leadkit_five_minutes' : 'leadkit_quarter_hour';
}

/**
 * Keep a background hook scheduled while it has work to do, on the set
 * schedule — moved to it when the setting changes — and cleared when not.
 *
 * @param string $hook Cron hook.
 * @param bool   $on   Whether it has work to do.
 */
function leadkit_keep_scheduled( $hook, $on ) {
	$next = wp_next_scheduled( $hook );
	$want = leadkit_background_schedule();
	if ( $on && ( ! $next || wp_get_schedule( $hook ) !== $want ) ) {
		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $want, $hook );
	} elseif ( ! $on && $next ) {
		wp_clear_scheduled_hook( $hook );
	}
}

add_action(
	'init',
	function () {
		leadkit_keep_scheduled( LEADKIT_GIFT_HOOK, leadkit_gifts_enabled() );
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
 * How long a gift with no Person is left to others before LeadKit makes the
 * donor (Settings → LeadKit → Wait before making a donor): Zapier or the email
 * match making the person at the same moment would make two.
 *
 * @return int Seconds.
 */
function leadkit_gift_grace() {
	$min = trim( (string) ( leadkit_options()['gift_grace'] ?? '' ) );
	return '' === $min ? LEADKIT_GIFT_GRACE : max( 0, (int) $min ) * MINUTE_IN_SECONDS;
}

/**
 * The group new people go in (Settings → LeadKit → Group for new people), set
 * by its title or id.
 *
 * @param string $people People board id.
 * @return string|null Group id, or '' for monday.com's own choice (the top
 *                     group) when none is set; null when the one set is not
 *                     on the board, or monday.com could not be asked — then
 *                     nobody is made, rather than put in the wrong group.
 */
function leadkit_gift_people_group( $people ) {
	$want = trim( (string) ( leadkit_options()['people_group'] ?? '' ) );
	if ( '' === $want ) {
		return '';
	}
	$groups = leadkit_monday_groups( $people );
	if ( null === $groups ) {
		return null;
	}
	foreach ( $groups as $id => $title ) {
		if ( (string) $id === $want || 0 === strcasecmp( trim( (string) $title ), $want ) ) {
			return (string) $id;
		}
	}
	update_option( 'leadkit_last_monday_error', sprintf( 'No group "%s" on the People board', $want ), false );
	return null;
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
 * A gift's "Donor X" columns as a new person's "X" columns — Donor phone to
 * Phone, Donor city to City — where the People board has a column of that
 * title: text into a text column, a phone number into a phone column.
 *
 * @param array  $g      The gift, with its column texts and values.
 * @param array  $donor  Gift column id => title without "donor ".
 * @param string $people People board id.
 * @return array Column values.
 */
function leadkit_gift_donor_details( $g, $donor, $people ) {
	$to  = $donor ? leadkit_monday_board_columns( $people ) : null;
	$out = array();
	foreach ( $donor as $id => $title ) {
		$col  = $to[ $title ] ?? null;
		$text = trim( (string) ( $g['text'][ $id ] ?? '' ) );
		if ( ! $col || '' === $text ) {
			continue;
		}
		if ( 'text' === $col['type'] ) {
			$out[ $col['id'] ] = mb_substr( $text, 0, 2000 );
		} elseif ( 'long_text' === $col['type'] ) {
			$out[ $col['id'] ] = array( 'text' => mb_substr( $text, 0, 20000 ) );
		} elseif ( 'phone' === $col['type'] ) {
			// Only a phone column's own value, which carries its country: a
			// number monday.com refused would stop the person being made.
			$raw = json_decode( (string) ( $g['value'][ $id ] ?? '' ), true );
			if ( ! empty( $raw['phone'] ) && ! empty( $raw['countryShortName'] ) ) {
				$out[ $col['id'] ] = array( 'phone' => (string) $raw['phone'], 'countryShortName' => (string) $raw['countryShortName'] );
			}
		}
	}
	return $out;
}

/**
 * One run of the linker.
 *
 * @return array What happened, for the checks: { looked, unpacked, visitors, linked, people, browsers }.
 */
function leadkit_link_gifts() {
	$out = array( 'looked' => 0, 'unpacked' => 0, 'visitors' => 0, 'linked' => 0, 'people' => 0, 'browsers' => 0 );
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
	$all    = $pcols ? leadkit_monday_board_columns( $board ) : null;
	if ( ! $cols || ! $pcols || null === $all ) {
		leadkit_visitor_unlock( $lock );
		return $out;
	}

	// The packed source, and the text columns its fields go in, where the
	// board has them; and the donor's details, for a person LeadKit makes.
	$src    = $all['packed source (raw)']['id'] ?? '';
	$unpack = array();
	foreach ( $src ? LEADKIT_GIFT_UNPACK : array() as $k => $title ) {
		if ( 'text' === ( $all[ $title ]['type'] ?? '' ) ) {
			$unpack[ $k ] = $all[ $title ]['id'];
		}
	}
	$donor = array();
	foreach ( $all as $title => $c ) {
		if ( 0 === strpos( $title, 'donor ' ) && 'donor email' !== $title ) {
			$donor[ $c['id'] ] = substr( $title, 6 );
		}
	}

	$ids  = array_values( array_unique( array_filter( array_merge( array( $cols['person'], $cols['email'], $cols['open'], $cols['visitor'] ?? '', $cols['vid'] ?? '', $src ), array_values( $unpack ), array_keys( $donor ) ) ) ) );
	$data = leadkit_monday(
		'query ($b: [ID!], $c: [String!]) { boards(ids: $b) { items_page(limit: 100, query_params: {order_by: [{column_id: "__creation_log__", direction: desc}]}) { items { id name created_at column_values(ids: $c) { id text value ... on BoardRelationValue { linked_item_ids } } } } } }',
		array( 'b' => array( $board ), 'c' => $ids )
	);
	$items = $data['boards'][0]['items_page']['items'] ?? null;
	if ( ! is_array( $items ) ) {
		leadkit_visitor_unlock( $lock );
		return $out;
	}
	// A column id that is gone (deleted, or renamed away) is simply left out
	// of what monday.com sends back. Rather than read it as empty and write to
	// a column that is not there, find the columns again by title next run.
	if ( $items && array_diff( $ids, array_column( (array) $items[0]['column_values'], 'id' ) ) ) {
		leadkit_monday_forget_columns();
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
			'text'    => array_map( fn( $cv ) => trim( (string) ( $cv['text'] ?? '' ) ), $v ),
			'value'   => array_map( fn( $cv ) => (string) ( $cv['value'] ?? '' ), $v ),
		);
		if ( count( $todo ) >= LEADKIT_GIFTS_PER_RUN ) {
			break;
		}
	}
	$out['looked'] = count( $todo );

	// 1. Each packed source into its gift's empty columns.
	$fill = array();
	foreach ( $todo as $i => $g ) {
		foreach ( (array) ( $unpack ? leadkit_unpack_source( $g['text'][ $src ] ?? '' ) : null ) as $k => $val ) {
			if ( isset( $unpack[ $k ] ) && '' !== $val && '' === ( $g['text'][ $unpack[ $k ] ] ?? '' ) ) {
				$fill[ $i ][ $unpack[ $k ] ] = $val;
				if ( 'visitorid' === $k && '' === $g['vid'] ) {
					$todo[ $i ]['vid'] = $val;
				}
			}
		}
	}

	// 2. Each gift's Visitor, by visitorid — the rows found in one request.
	$vboard = leadkit_monday_visitors_board();
	$vcols  = $vboard ? leadkit_visitor_columns( $vboard ) : null;
	$find   = array();
	foreach ( $todo as $g ) {
		if ( ! $g['visitor'] && $g['vid'] && ! empty( $cols['visitor'] ) && ! empty( $vcols['vid'] ) ) {
			$find[] = $g['vid'];
		}
	}
	$rows = $find ? leadkit_visitor_find_rows( $vboard, $vcols['vid'], array_unique( $find ) ) : array();
	foreach ( $todo as $i => $g ) {
		$values = $fill[ $i ] ?? array();
		$row    = $g['visitor'] ? '' : (string) ( $rows[ $g['vid'] ] ?? '' );
		if ( $row ) {
			$values[ $cols['visitor'] ] = array( 'item_ids' => array( (int) $row ) );
		}
		if ( ! $values ) {
			continue;
		}
		if ( ! leadkit_visitor_set( $board, $g['id'], $values ) ) {
			$todo[ $i ]['retry'] = true; // Tried again next run, with the rest.
			continue;
		}
		if ( isset( $fill[ $i ] ) ) {
			++$out['unpacked'];
		}
		if ( $row ) {
			$todo[ $i ]['visitor'] = $row;
			++$out['visitors'];
		}
	}

	// Who each gift's browser belongs to now, in one request.
	$browsers = array();
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

	$grace = leadkit_gift_grace();
	foreach ( $todo as $g ) {
		if ( ! empty( $g['retry'] ) ) {
			continue;
		}
		$brief = null;

		// 3. The person: theirs already, found by email, or made.
		if ( ! $g['person'] ) {
			if ( $g['age'] < $grace ) {
				continue; // Zapier and the email match go first; both creating at once makes two people.
			}
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
				$group = leadkit_gift_people_group( $people );
				if ( null === $group ) {
					continue;
				}
				$vars = array(
					'b' => $people,
					'n' => $g['name'] ?: $g['email'],
					'v' => wp_json_encode( array( $pcols['email'] => array( 'email' => $g['email'], 'text' => $g['email'] ) ) + leadkit_gift_donor_details( $g, $donor, $people ) ),
				);
				if ( $group ) {
					$vars['g'] = $group;
				}
				$made = leadkit_monday(
					'mutation ($b: ID!, $n: String!, $v: JSON' . ( $group ? ', $g: String' : '' ) . ') { create_item(board_id: $b, item_name: $n, column_values: $v' . ( $group ? ', group_id: $g' : '' ) . ') { id url } }',
					$vars
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

		// 4. The gift's own link to its person.
		if ( '' === $g['open'] ) {
			$brief = $brief ?: leadkit_monday_item_brief( $g['person'] );
			if ( ! $brief || ! leadkit_visitor_set( $board, $g['id'], array( $cols['open'] => leadkit_person_link( $brief ) ) ) ) {
				continue;
			}
		}

		// 5. The gift's browser.
		if ( ! $g['visitor'] ) {
			if ( ! $g['vid'] || $g['age'] > LEADKIT_GIFT_WAIT ) {
				$done[ $g['id'] ] = time(); // None, or none is coming.
			}
			continue; // Its Visitors row may not have been written yet.
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
