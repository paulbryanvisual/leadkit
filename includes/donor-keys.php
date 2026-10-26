<?php
/**
 * Donor keys: an email link that says who the donor is.
 *
 * A link in a donor email can carry ?dk=b-22758-7cf7 (a CRM account) or
 * ?dk=m-13365350571-629a (a People item). The whole key is the person's
 * "Donor key" on the People board — the board's own "Tracked link" formula
 * puts it on the link — so a key is good when it is exactly someone's Donor
 * key, and changing the number in a link matches no one. LeadKit only reads
 * that column; it never writes it.
 *
 * The tracker takes the key out of the address bar and posts it here once
 * the Visits Worker has the page's visit (only a browser that runs the
 * tracker gets that far, so a link scanner opening the email is never a
 * click):
 *
 *   POST leadkit/v1/donor-key  { visitor_id, dk }
 *
 * After the answer, the key is looked up. No one's: noted, and nothing is
 * kept or sent anywhere. Someone's: the Worker is told (POST /donor), which
 * keeps it on the browser's row and makes it known, and that browser is
 * written to monday.com at once — the Visitors row as always, every earlier
 * visit as a Visits row, and on top of that, nothing else:
 *
 *   Person, Open person   the donor, on the Visitors row (and so Open person
 *                         on its visits). Never over a different person
 *                         already there: that is noted instead.
 *   Clicked email link    on the donor's People row: today, if it is empty.
 *
 * A key that cannot be looked up yet (monday.com or the Worker not answering)
 * waits in the leadkit_dk_pending option for the next background run, for a
 * week. Background runs also link browsers the Worker sends with a key.
 *
 * People columns are found by title (the leadkit/donor_key_columns filter
 * changes them) and never created. What happened is kept in the
 * leadkit_dk_log option (the last 50) and shown on the settings screen. The
 * route is public, as it must be: it is rate limited per IP and per visitor,
 * answers the same whether or not a key is anyone's, and only ever connects
 * a browser to the person whose Donor key it brought.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_DK_COLUMNS = array(
	'key'     => 'Donor key',          // The whole key, matched exactly.
	'clicked' => 'Clicked email link', // Set to the day of the first click.
);
const LEADKIT_DK_RE          = '/^[bm]-\d{1,20}-[0-9a-f]{4}$/';
const LEADKIT_DK_LOG_MAX     = 50;
const LEADKIT_DK_PENDING     = 'leadkit_dk_pending';
const LEADKIT_DK_PENDING_MAX = 200;
const LEADKIT_DK_PER_RUN     = 25;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			'/donor-key',
			array(
				'methods'             => 'POST',
				'callback'            => 'leadkit_handle_donor_key',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * The People column titles donor keys use.
 *
 * @return array { key, clicked }
 */
function leadkit_donor_key_columns() {
	/**
	 * The People columns donor keys use, by title: 'key' is where a key is
	 * looked up (the whole key, exactly), 'clicked' the date set on a first
	 * click.
	 *
	 * @param array $titles { key, clicked }.
	 */
	return (array) apply_filters( 'leadkit/donor_key_columns', LEADKIT_DK_COLUMNS ) + LEADKIT_DK_COLUMNS;
}

/**
 * A donor key, cleaned: "b-22758-7cf7", or '' when it is not one.
 *
 * @param mixed $dk A string, or the Worker's { key }.
 * @return string
 */
function leadkit_clean_donor_key( $dk ) {
	$dk = strtolower( trim( (string) ( is_array( $dk ) ? ( $dk['key'] ?? '' ) : $dk ) ) );
	return preg_match( LEADKIT_DK_RE, $dk ) ? $dk : '';
}

/**
 * A key from the tracker. Answers at once; the work happens after the
 * answer, as for a form.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function leadkit_handle_donor_key( $req ) {
	if ( strlen( (string) $req->get_body() ) > 4096 ) {
		return new WP_REST_Response( array( 'ok' => false ), 413 );
	}
	$body    = leadkit_json_body( $req );
	$visitor = leadkit_clean_visitor_id( $body['visitor_id'] ?? '' );
	$dk      = leadkit_clean_donor_key( $body['dk'] ?? '' );
	if ( ! $visitor || ! $dk ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}
	if ( ! leadkit_browsers_enabled() || ! leadkit_visitors_enabled() ) {
		return new WP_REST_Response( array( 'ok' => true, 'off' => true ), 200 );
	}

	// A brake per IP: 10 in ten minutes; and each browser run once a minute.
	$ip = leadkit_client_ip();
	if ( $ip ) {
		$k = 'leadkit_dkip_' . md5( $ip );
		$n = (int) get_transient( $k );
		if ( $n >= 10 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );
	}
	leadkit_donor_key_queue( $visitor, $dk );
	$k = 'leadkit_dknow_' . md5( $visitor );
	if ( get_transient( $k ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 ); // The next background run has it.
	}
	set_transient( $k, 1, MINUTE_IN_SECONDS );

	add_action(
		'shutdown',
		function () use ( $visitor ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
			ignore_user_abort( true );
			if ( leadkit_donor_keys_run( $visitor ) ) {
				leadkit_send_warm_browsers( $visitor );
			}
		},
		90
	);
	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Keep a key to look up: the latest per browser, the newest
 * LEADKIT_DK_PENDING_MAX browsers.
 *
 * @param string $visitor Visitor id.
 * @param string $dk      Cleaned key.
 * @return void
 */
function leadkit_donor_key_queue( $visitor, $dk ) {
	$all = get_option( LEADKIT_DK_PENDING, array() );
	$all = is_array( $all ) ? $all : array();
	unset( $all[ $visitor ] ); // To the end: the newest.
	$all[ $visitor ] = array( 'dk' => $dk, 'at' => time() );
	update_option( LEADKIT_DK_PENDING, array_slice( $all, -LEADKIT_DK_PENDING_MAX, null, true ), false );
}

/**
 * Look up the keys waiting: someone's goes to the Worker (POST /donor);
 * no one's is noted and dropped; one that cannot be looked up yet waits.
 *
 * @param string $only One visitor id: that browser's key alone.
 * @return string[] Visitor ids whose key is now with the Worker.
 */
function leadkit_donor_keys_run( $only = '' ) {
	$all = get_option( LEADKIT_DK_PENDING, array() );
	$all = is_array( $all ) ? $all : array();
	$todo = $only ? array_intersect_key( $all, array( $only => 1 ) ) : array_slice( $all, 0, LEADKIT_DK_PER_RUN, true );
	$done = array();
	$gone = array();
	foreach ( $todo as $visitor => $p ) {
		$visitor = (string) $visitor;
		$dk      = leadkit_clean_donor_key( $p['dk'] ?? '' );
		if ( ! $dk ) {
			$gone[] = $visitor;
			continue;
		}
		$found = leadkit_donor_key_person( $dk );
		if ( null === $found ) {
			if ( time() - (int) ( $p['at'] ?? 0 ) > 7 * DAY_IN_SECONDS ) {
				leadkit_dk_note( $visitor, $dk, 'given up: monday.com could not be asked for a week' );
				$gone[] = $visitor;
			}
			continue;
		}
		if ( ! $found['id'] ) {
			leadkit_dk_note( $visitor, $dk, $found['why'] );
			$gone[] = $visitor;
			continue;
		}
		if ( null === leadkit_visits_call( 'POST', '/donor', array( 'visitor' => $visitor, 'dk' => $dk ) ) ) {
			continue; // The Worker did not take it: next run.
		}
		$gone[] = $visitor;
		$done[] = $visitor;
	}
	if ( $gone ) {
		// Read again: a key may have arrived meanwhile, and a newer one stays.
		$now = get_option( LEADKIT_DK_PENDING, array() );
		$now = is_array( $now ) ? $now : array();
		foreach ( $gone as $visitor ) {
			if ( isset( $now[ $visitor ] ) && ( $now[ $visitor ]['dk'] ?? '' ) === ( $todo[ $visitor ]['dk'] ?? '' ) ) {
				unset( $now[ $visitor ] );
			}
		}
		update_option( LEADKIT_DK_PENDING, $now, false );
	}
	return $done;
}

/**
 * Connect the browser's donor, from the Visitors writer (the row is written,
 * the visits are not yet, so they get Open person too).
 *
 * @param string $board   Visitors board id.
 * @param string $row     Visitors item id.
 * @param string $visitor Visitor id.
 * @param array  $cols    Visitors columns.
 * @param array  $st      Visitor state (by reference: dk_done, dk_miss, linked, brief).
 * @return void Left undone (tried again next write) when monday.com could not be asked.
 */
function leadkit_donor_key_link( $board, $row, $visitor, $cols, &$st ) {
	$key   = (string) $st['dk'];
	$found = leadkit_donor_key_person( $key );
	if ( null === $found ) {
		return;
	}
	if ( ! $found['id'] ) {
		leadkit_dk_note( $visitor, $key, $found['why'] );
		$st['dk_done'] = $key;
		$st['dk_miss'] = time();
		return;
	}
	$st['dk_miss'] = 0;
	$person        = (string) $found['id'];
	$brief         = $found['brief'];

	// The Visitors row: the donor, unless it already has someone else.
	$now = leadkit_visitor_person_now( $row, $cols );
	if ( null === $now ) {
		return;
	}
	if ( ! $now['ids'] || array( (int) $person ) === $now['ids'] ) {
		$values = array();
		if ( ! $now['ids'] ) {
			$values[ $cols['person'] ] = array( 'item_ids' => array( (int) $person ) );
		}
		if ( '' === $now['open'] ) {
			$values[ $cols['open'] ] = leadkit_person_link( $brief );
		}
		if ( $values && ! leadkit_visitor_set( $board, $row, $values ) ) {
			return;
		}
		if ( ! $now['ids'] ) {
			leadkit_dk_note( $visitor, $key, sprintf( 'connected to People item %s', $person ) );
		}
		$st['linked'] = $person;
		$st['brief']  = $brief;
	} else {
		leadkit_dk_note( $visitor, $key, sprintf( 'not connected: the Visitors row already has People item %s, not %s', implode( ', ', $now['ids'] ), $person ) );
	}

	// The donor's People row: the day of the first click on an email link.
	$titles  = leadkit_donor_key_columns();
	$people  = leadkit_monday_people_board();
	$all     = leadkit_monday_board_columns( $people );
	if ( null === $all ) {
		return;
	}
	$clicked = $all[ strtolower( $titles['clicked'] ) ] ?? null;
	if ( ! $clicked || 'date' !== $clicked['type'] ) {
		leadkit_dk_note( $visitor, $key, sprintf( 'no "%s" date column on the People board', $titles['clicked'] ) );
	} else {
		$data = leadkit_monday(
			'query ($i: [ID!], $c: [String!]) { items(ids: $i) { id column_values(ids: $c) { id text } } }',
			array( 'i' => array( $person ), 'c' => array( $clicked['id'] ) )
		);
		if ( null === $data ) {
			return;
		}
		$text = trim( (string) ( $data['items'][0]['column_values'][0]['text'] ?? '' ) );
		if ( '' === $text ) {
			if ( ! leadkit_visitor_set( $people, $person, array( $clicked['id'] => array( 'date' => leadkit_vdate( 'Y-m-d' ) ) ) ) ) {
				return;
			}
			leadkit_dk_note( $visitor, $key, sprintf( '"%s" set on People item %s', $titles['clicked'], $person ) );
		}
	}
	$st['dk_done'] = $key;
}

/**
 * The People row whose Donor key is exactly this key.
 *
 * @param string $key "b-22758-7cf7".
 * @return array|null { id, brief, why }: id '' with why when it is no one's; null when monday.com could not be asked.
 */
function leadkit_donor_key_person( $key ) {
	$none   = fn( $why ) => array( 'id' => '', 'brief' => array(), 'why' => $why );
	$people = leadkit_monday_people_board();
	if ( ! $people ) {
		return $none( 'no People board set' );
	}
	$all = leadkit_monday_board_columns( $people );
	if ( null === $all ) {
		return null;
	}
	$title = leadkit_donor_key_columns()['key'];
	$col   = $all[ strtolower( $title ) ]['id'] ?? '';
	if ( ! $col ) {
		return $none( sprintf( 'no match: no "%s" column on the People board', $title ) );
	}
	$data = leadkit_monday(
		'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 5, columns: [{column_id: $c, column_values: $v}]) { items { id name url column_values(ids: $r) { text } } } }',
		array( 'b' => (string) $people, 'c' => $col, 'v' => array( $key ), 'r' => array( $col ) )
	);
	if ( null === $data ) {
		return null;
	}
	$hits = array_values(
		array_filter(
			(array) ( $data['items_page_by_column_values']['items'] ?? array() ),
			fn( $it ) => strtolower( trim( (string) ( $it['column_values'][0]['text'] ?? '' ) ) ) === $key && ! empty( $it['url'] )
		)
	);
	if ( ! $hits ) {
		return $none( sprintf( 'no match: no People row has %s %s', $title, $key ) );
	}
	if ( count( $hits ) > 1 ) {
		return $none( sprintf( 'not connected: %d People rows have %s %s (%s)', count( $hits ), $title, $key, implode( ', ', array_column( $hits, 'id' ) ) ) );
	}
	return array( 'id' => (string) $hits[0]['id'], 'brief' => array( 'name' => (string) $hits[0]['name'], 'url' => (string) $hits[0]['url'] ), 'why' => '' );
}

/**
 * Who the Visitors row has now: its Person and whether Open person is set.
 *
 * @param string $row  Visitors item id.
 * @param array  $cols Visitors columns.
 * @return array|null { ids: int[], open: string }; null when monday.com could not be asked.
 */
function leadkit_visitor_person_now( $row, $cols ) {
	$data = leadkit_monday(
		'query ($i: [ID!], $c: [String!]) { items(ids: $i) { column_values(ids: $c) { id text ... on BoardRelationValue { linked_item_ids } } } }',
		array( 'i' => array( (string) $row ), 'c' => array( (string) $cols['person'], (string) $cols['open'] ) )
	);
	if ( null === $data ) {
		return null;
	}
	$out = array( 'ids' => array(), 'open' => '' );
	foreach ( (array) ( $data['items'][0]['column_values'] ?? array() ) as $cv ) {
		if ( (string) $cv['id'] === (string) $cols['person'] ) {
			$out['ids'] = array_map( 'intval', (array) ( $cv['linked_item_ids'] ?? array() ) );
		} elseif ( (string) $cv['id'] === (string) $cols['open'] ) {
			$out['open'] = trim( (string) ( $cv['text'] ?? '' ) );
		}
	}
	return $out;
}

/**
 * Note what a donor key did, keeping the last LEADKIT_DK_LOG_MAX. Nothing
 * about the donor but their item id.
 *
 * @param string $visitor Visitor id.
 * @param string $key     The key.
 * @param string $what    What happened.
 * @return void
 */
function leadkit_dk_note( $visitor, $key, $what ) {
	$log   = get_option( 'leadkit_dk_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array( 'time' => gmdate( 'c' ), 'visitor' => (string) $visitor, 'key' => (string) $key, 'what' => (string) $what );
	update_option( 'leadkit_dk_log', array_slice( $log, -LEADKIT_DK_LOG_MAX ), false );
}
