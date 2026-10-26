<?php
/**
 * Donor keys: an email link that says who the donor is.
 *
 * A link in a donor email can carry ?dk=b-<CRM account id>-<code> or
 * ?dk=m-<People item id>-<code>. The tracker takes it out of the address bar
 * and sends it only to the Visits Worker, which checks the code against
 * LEADKIT_DK_SECRET — a secret WordPress never holds — and keeps a valid key
 * on the browser's row, marked known. Only a browser that runs the tracker
 * gets that far, so a link scanner opening the email is never a click.
 *
 * The browser then comes to WordPress like any warm one, with its key (dk on
 * GET /warm), and its Visitors row is written as always — every earlier
 * visit with it, as a Visits row each. On top of that, and nothing else:
 *
 *   Person, Open person   the donor's People row, on the Visitors row (and so
 *                         Open person on its visits). b-: the People row whose
 *                         "Bloomerang Account" is exactly that id; m-: that
 *                         item, once it is confirmed to be on the People
 *                         board. Never over a different person already there:
 *                         that is noted instead.
 *   Clicked email link    on the donor's People row: today, if it is empty.
 *
 * People columns are found by title (the leadkit/donor_key_columns filter
 * changes them) and never created. A key with no donor connects nothing and
 * is tried again a day later; the browser stays known in D1 either way.
 * What happened is kept in the leadkit_dk_log option (the last 50) and shown
 * on the settings screen.
 *
 *   POST leadkit/v1/donor-key  { visitor_id }, from the tracker the moment the
 *                              Worker has a new key: that one browser is
 *                              written now, not on the next background run.
 *
 * The route is public, as it must be, but it only ever writes what the Worker
 * says about that browser: it can make a write happen sooner, never a
 * different one. The key itself never reaches WordPress from a browser. Rate
 * limited per IP and per visitor.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_DK_COLUMNS = array(
	'b'       => 'Bloomerang Account', // Where a b- id is looked up, exactly.
	'clicked' => 'Clicked email link', // Set to the day of the first click.
);
const LEADKIT_DK_LOG_MAX = 50;

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
 * @return array { b, clicked }
 */
function leadkit_donor_key_columns() {
	/**
	 * The People columns donor keys use, by title: 'b' is where a b- id is
	 * looked up (an exact match), 'clicked' the date set on a first click.
	 *
	 * @param array $titles { b, clicked }.
	 */
	return (array) apply_filters( 'leadkit/donor_key_columns', LEADKIT_DK_COLUMNS ) + LEADKIT_DK_COLUMNS;
}

/**
 * A donor key as the Worker sends it, checked: { prefix, id } => "b-12345".
 *
 * @param mixed $dk From GET /warm.
 * @return string '' when there is none.
 */
function leadkit_clean_donor_key( $dk ) {
	$prefix = is_array( $dk ) ? (string) ( $dk['prefix'] ?? '' ) : '';
	$id     = is_array( $dk ) ? (string) ( $dk['id'] ?? '' ) : '';
	return preg_match( '/^[bm]$/', $prefix ) && preg_match( '/^\d{1,20}$/', $id ) ? $prefix . '-' . $id : '';
}

/**
 * Write one browser now: the tracker has just given the Worker a donor key.
 *
 * Answers at once; the work happens after the answer, as for a form. The
 * browser is taken from the Worker, so a visitor id with no valid key, or
 * none at all, writes nothing the background run would not.
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
	if ( ! $visitor ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}
	if ( ! leadkit_browsers_enabled() || ! leadkit_visitors_enabled() ) {
		return new WP_REST_Response( array( 'ok' => true, 'off' => true ), 200 );
	}

	// A brake per IP: 10 in ten minutes; and each browser once a minute.
	$ip = leadkit_client_ip();
	if ( $ip ) {
		$k = 'leadkit_dkip_' . md5( $ip );
		$n = (int) get_transient( $k );
		if ( $n >= 10 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );
	}
	$k = 'leadkit_dknow_' . md5( $visitor );
	if ( get_transient( $k ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
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
			leadkit_send_warm_browsers( $visitor );
		},
		90
	);
	return new WP_REST_Response( array( 'ok' => true ), 200 );
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
	$key    = (string) $st['dk'];
	$people = leadkit_monday_people_board();
	$all    = $people ? leadkit_monday_board_columns( $people ) : null;
	if ( null === $all ) {
		return;
	}
	$titles = leadkit_donor_key_columns();
	$found  = leadkit_donor_key_person( $people, $key, $all, $titles );
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
 * The People row a donor key names.
 *
 * @param string $people People board id.
 * @param string $key    "b-12345" or "m-6789".
 * @param array  $all    People columns by lowercase title.
 * @param array  $titles From leadkit_donor_key_columns().
 * @return array|null { id, brief, why }: id '' with why when there is none; null when monday.com could not be asked.
 */
function leadkit_donor_key_person( $people, $key, $all, $titles ) {
	list( $prefix, $id ) = explode( '-', $key, 2 );
	$none                = fn( $why ) => array( 'id' => '', 'brief' => array(), 'why' => $why );

	if ( 'm' === $prefix ) {
		$data = leadkit_monday( 'query ($i: [ID!]) { items(ids: $i) { id name url board { id } } }', array( 'i' => array( $id ) ) );
		if ( null === $data ) {
			return null;
		}
		$it = $data['items'][0] ?? null;
		if ( ! $it || (string) ( $it['board']['id'] ?? '' ) !== (string) $people || empty( $it['url'] ) ) {
			return $none( 'no match: no such item on the People board' );
		}
		return array( 'id' => (string) $it['id'], 'brief' => array( 'name' => (string) $it['name'], 'url' => (string) $it['url'] ), 'why' => '' );
	}

	$col = $all[ strtolower( $titles['b'] ) ]['id'] ?? '';
	if ( ! $col ) {
		return $none( sprintf( 'no match: no "%s" column on the People board', $titles['b'] ) );
	}
	$data = leadkit_monday(
		'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 5, columns: [{column_id: $c, column_values: $v}]) { items { id name url column_values(ids: $r) { text } } } }',
		array( 'b' => (string) $people, 'c' => $col, 'v' => array( $id ), 'r' => array( $col ) )
	);
	if ( null === $data ) {
		return null;
	}
	$hits = array_values(
		array_filter(
			(array) ( $data['items_page_by_column_values']['items'] ?? array() ),
			fn( $it ) => trim( (string) ( $it['column_values'][0]['text'] ?? '' ) ) === $id && ! empty( $it['url'] )
		)
	);
	if ( ! $hits ) {
		return $none( sprintf( 'no match: no People row has %s %s', $titles['b'], $id ) );
	}
	if ( count( $hits ) > 1 ) {
		return $none( sprintf( 'not connected: %d People rows have %s %s (%s)', count( $hits ), $titles['b'], $id, implode( ', ', array_column( $hits, 'id' ) ) ) );
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
 * Note what a donor key did, keeping the last LEADKIT_DK_LOG_MAX. The key is
 * kept without its code; nothing about the donor but their item id.
 *
 * @param string $visitor Visitor id.
 * @param string $key     "b-12345".
 * @param string $what    What happened.
 * @return void
 */
function leadkit_dk_note( $visitor, $key, $what ) {
	$log   = get_option( 'leadkit_dk_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array( 'time' => gmdate( 'c' ), 'visitor' => (string) $visitor, 'key' => (string) $key, 'what' => (string) $what );
	update_option( 'leadkit_dk_log', array_slice( $log, -LEADKIT_DK_LOG_MAX ), false );
}
