<?php
/**
 * People and their submissions — the Salesforce shape, on monday.com.
 *
 * With a People board set (Settings → LeadKit → monday.com People board):
 *
 *   People board   one row per person, matched by email address. It holds
 *                  what is true of the person across every form: contact
 *                  details, every browser id seen for them, first and last
 *                  seen, how many forms they have sent, their best engagement,
 *                  where they first and most recently came from, and their
 *                  return visits.
 *   Form boards    one row per submission, as before, each connected to its
 *                  person through a "Person" connect-boards column.
 *
 * On every [leadkit_monday_form] submission LeadKit finds the person by email
 * (or creates them), connects the submission both ways, rolls the person's
 * columns up, and posts an update on the person. Return visits go to the
 * person once, not to each submission. Columns are found by title and created
 * the first time, so the People board can start empty.
 *
 * In Salesforce terms: People is Contact, each submission is the record that
 * relates to it — so the same data moves across when 3SGF is ready.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_LEVELS = array( 'Browsing' => 1, 'Warm' => 2, 'Hot' => 3 );

/**
 * The People board id, if set.
 *
 * @return string
 */
function leadkit_monday_people_board() {
	return preg_replace( '/\D/', '', (string) ( leadkit_options()['monday_people_board'] ?? '' ) );
}

/**
 * Find columns on a board by title, creating the missing ones. Cached a day.
 *
 * @param string $board Board id.
 * @param array  $want  slot => array( title, type, defaults|null ).
 * @param string $cache Cache name.
 * @return array|null slot => column id.
 */
function leadkit_monday_ensure_columns( $board, $want, $cache ) {
	$key    = 'leadkit_mc_' . $cache . '_' . $board;
	$cached = get_transient( $key );
	if ( is_array( $cached ) && count( $cached ) === count( $want ) ) {
		return $cached;
	}

	$data = leadkit_monday( 'query ($b: [ID!]) { boards(ids: $b) { columns { id title } } }', array( 'b' => array( (string) $board ) ) );
	$list = $data['boards'][0]['columns'] ?? null;
	if ( ! is_array( $list ) ) {
		return null;
	}
	$by_title = array();
	foreach ( $list as $c ) {
		$by_title[ strtolower( trim( (string) $c['title'] ) ) ] = $c['id'];
	}

	$cols = array();
	foreach ( $want as $slot => $spec ) {
		list( $title, $type ) = $spec;
		$defaults             = $spec[2] ?? null;
		$id                   = $by_title[ strtolower( $title ) ] ?? '';
		if ( ! $id ) {
			$vars = array( 'b' => (string) $board, 't' => $title );
			if ( $defaults ) {
				$vars['d'] = wp_json_encode( $defaults );
			}
			$made = leadkit_monday(
				'mutation ($b: ID!, $t: String!' . ( $defaults ? ', $d: JSON' : '' ) . ') { create_column(board_id: $b, title: $t, column_type: ' . $type . ( $defaults ? ', defaults: $d' : '' ) . ') { id } }',
				$vars
			);
			$id = (string) ( $made['create_column']['id'] ?? '' );
		}
		if ( ! $id ) {
			return null;
		}
		$cols[ $slot ] = $id;
	}

	set_transient( $key, $cols, DAY_IN_SECONDS );
	return $cols;
}

/**
 * The People board's columns.
 *
 * @param string $people People board id.
 * @return array|null
 */
function leadkit_people_columns( $people ) {
	$forms = array_map( 'intval', leadkit_monday_boards() );
	return leadkit_monday_ensure_columns(
		$people,
		array(
			'email'   => array( 'Email', 'email' ),
			'phone'   => array( 'Phone', 'phone' ),
			'level'   => array(
				'Engagement',
				'status',
				array(
					'labels' => array(
						array( 'label' => 'Hot', 'color' => 'done_green', 'index' => 0 ),
						array( 'label' => 'Warm', 'color' => 'egg_yolk', 'index' => 1 ),
						array( 'label' => 'Browsing', 'color' => 'american_gray', 'index' => 2 ),
					),
				),
			),
			'count'   => array( 'Submissions', 'numbers' ),
			'first'   => array( 'First seen', 'date' ),
			'last'    => array( 'Last seen', 'date' ),
			'returns' => array( 'Return visits', 'numbers' ),
			'fsrc'    => array( 'First source', 'text' ),
			'lsrc'    => array( 'Latest source', 'text' ),
			'forms'   => array( 'Form submissions', 'board_relation', array( 'boardIds' => $forms, 'allowMultipleItems' => true ) ),
			'log'     => array( 'Return visits log', 'long_text' ),
			'ids'     => array( 'Visitor IDs', 'long_text' ),
		),
		'people'
	);
}

/**
 * The "Person" connect column on a form board.
 *
 * @param string $board  Form board id.
 * @param string $people People board id.
 * @return string Column id, or ''.
 */
function leadkit_form_person_column( $board, $people ) {
	$cols = leadkit_monday_ensure_columns(
		$board,
		array( 'person' => array( 'Person', 'board_relation', array( 'boardIds' => array( (int) $people ), 'allowMultipleItems' => false ) ) ),
		'person'
	);
	return (string) ( $cols['person'] ?? '' );
}

/**
 * Find the person by email, or create them; connect the submission; roll up.
 *
 * @param array $args {
 *     @type string $board    Form board id.
 *     @type string $item_id  The submission just created.
 *     @type string $name     Person's name.
 *     @type string $email    Email (lowercase).
 *     @type array  $phone    monday phone value, or null.
 *     @type string $visitor  Browser visitor id.
 *     @type array  $summary  leadkit_journey_summary() result, or null.
 *     @type string $form     Form title.
 * }
 * @return string Person item id, or ''.
 */
function leadkit_person_upsert( $args ) {
	$people = leadkit_monday_people_board();
	$email  = strtolower( trim( (string) $args['email'] ) );
	if ( ! $people || ! $email ) {
		return '';
	}
	$cols = leadkit_people_columns( $people );
	if ( ! $cols ) {
		return '';
	}

	$found = leadkit_monday(
		'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 1, columns: [{column_id: $c, column_values: $v}]) { items { id url column_values(ids: $r) { id text ... on BoardRelationValue { linked_item_ids } } } } }',
		array(
			'b' => $people,
			'c' => $cols['email'],
			'v' => array( $email ),
			'r' => array( $cols['ids'], $cols['forms'], $cols['level'], $cols['first'], $cols['fsrc'] ),
		)
	);
	$person = $found['items_page_by_column_values']['items'][0] ?? null;

	$today  = leadkit_vdate( 'Y-m-d' );
	$level  = $args['summary']['level'] ?? '';
	$source = $args['summary']['source'] ?? '';
	$first  = $args['summary']['first'] ?? '';

	if ( ! $person ) {
		$values = array(
			$cols['email'] => array( 'email' => $email, 'text' => $email ),
			$cols['count'] => '1',
			$cols['first'] => array( 'date' => $today ),
			$cols['last']  => array( 'date' => $today ),
			$cols['forms'] => array( 'item_ids' => array( (int) $args['item_id'] ) ),
		);
		if ( ! empty( $args['phone'] ) ) {
			$values[ $cols['phone'] ] = $args['phone'];
		}
		if ( $level ) {
			$values[ $cols['level'] ] = array( 'label' => $level );
		}
		if ( $source ) {
			$values[ $cols['lsrc'] ] = $source;
			$values[ $cols['fsrc'] ] = $first ?: $source;
		}
		if ( $args['visitor'] ) {
			$values[ $cols['ids'] ] = array( 'text' => $args['visitor'] );
		}
		$made      = leadkit_monday(
			'mutation ($b: ID!, $n: String!, $v: JSON) { create_item(board_id: $b, item_name: $n, column_values: $v) { id } }',
			array( 'b' => $people, 'n' => $args['name'] ?: $email, 'v' => wp_json_encode( $values ) )
		);
		$person_id = (string) ( $made['create_item']['id'] ?? '' );
		$is_new    = true;
	} else {
		$person_id = (string) $person['id'];
		$is_new    = false;
		$current   = array();
		foreach ( (array) $person['column_values'] as $cv ) {
			$current[ $cv['id'] ] = $cv;
		}

		$ids = preg_split( '/[\s,]+/', (string) ( $current[ $cols['ids'] ]['text'] ?? '' ) );
		if ( $args['visitor'] ) {
			$ids[] = $args['visitor'];
		}
		$ids    = array_values( array_unique( array_filter( array_map( 'leadkit_clean_visitor_id', $ids ) ) ) );
		$linked = array_map( 'intval', (array) ( $current[ $cols['forms'] ]['linked_item_ids'] ?? array() ) );
		$linked = array_values( array_unique( array_merge( $linked, array( (int) $args['item_id'] ) ) ) );
		$had    = (string) ( $current[ $cols['level'] ]['text'] ?? '' );

		$values = array(
			$cols['ids']   => array( 'text' => implode( "\n", $ids ) ),
			$cols['forms'] => array( 'item_ids' => $linked ),
			$cols['count'] => (string) count( $linked ),
			$cols['last']  => array( 'date' => $today ),
		);
		// Engagement is their best ever, not their latest.
		if ( $level && ( LEADKIT_LEVELS[ $level ] ?? 0 ) > ( LEADKIT_LEVELS[ $had ] ?? 0 ) ) {
			$values[ $cols['level'] ] = array( 'label' => $level );
		}
		if ( $source ) {
			$values[ $cols['lsrc'] ] = $source;
			if ( '' === trim( (string) ( $current[ $cols['fsrc'] ]['text'] ?? '' ) ) ) {
				$values[ $cols['fsrc'] ] = $first ?: $source;
			}
		}
		if ( '' === trim( (string) ( $current[ $cols['first'] ]['text'] ?? '' ) ) ) {
			$values[ $cols['first'] ] = array( 'date' => $today );
		}
		if ( ! empty( $args['phone'] ) ) {
			$values[ $cols['phone'] ] = $args['phone'];
		}
		leadkit_monday(
			'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
			array( 'i' => $person_id, 'b' => $people, 'v' => wp_json_encode( $values ) )
		);
	}
	if ( ! $person_id ) {
		return '';
	}

	// The submission points at its person.
	$pcol = leadkit_form_person_column( $args['board'], $people );
	if ( $pcol ) {
		leadkit_monday(
			'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
			array( 'i' => (string) $args['item_id'], 'b' => (string) $args['board'], 'v' => wp_json_encode( array( $pcol => array( 'item_ids' => array( (int) $person_id ) ) ) ) )
		);
	}

	// What happened, on the person.
	$note = '<p><strong>' . esc_html( $is_new ? __( 'New person', 'leadkit' ) : __( 'New submission', 'leadkit' ) ) . '</strong> — '
		. esc_html( $args['form'] ) . ', ' . esc_html( leadkit_vdate( 'M j, Y g:i a' ) ) . '</p>';
	if ( ! empty( $args['summary'] ) ) {
		$note .= leadkit_journey_update_html( $args['summary'] );
	}
	leadkit_monday(
		'mutation ($i: ID!, $body: String!) { create_update(item_id: $i, body: $body) { id } }',
		array( 'i' => $person_id, 'body' => $note )
	);

	// Every browser of this person now resolves to them on the next visit.
	foreach ( array_filter( array( $args['visitor'] ) ) as $vid ) {
		$key   = leadkit_visitor_key( $vid );
		$state = get_option( $key, array() );
		$state = is_array( $state ) ? $state : array();
		$state['person'] = array( 'id' => $person_id, 'checked' => time() );
		update_option( $key, $state, false );
	}

	return $person_id;
}

/**
 * The person a browser belongs to, by "Visitor IDs". Cached.
 *
 * @param string $visitor Visitor id.
 * @param array  $state   Visitor state (by reference).
 * @return string Person item id, or ''.
 */
function leadkit_person_for_visitor( $visitor, &$state ) {
	$people = leadkit_monday_people_board();
	if ( ! $people ) {
		return '';
	}
	$cached = $state['person'] ?? null;
	if ( is_array( $cached ) ) {
		if ( ! empty( $cached['id'] ) ) {
			return (string) $cached['id'];
		}
		if ( time() - (int) ( $cached['checked'] ?? 0 ) < 600 ) {
			return '';
		}
	}
	$cols = leadkit_people_columns( $people );
	$id   = '';
	if ( $cols ) {
		$data = leadkit_monday(
			'query ($b: [ID!], $c: String!, $v: CompareValue!) { boards(ids: $b) { items_page(limit: 1, query_params: {rules: [{column_id: $c, compare_value: $v, operator: contains_text}]}) { items { id } } } }',
			array( 'b' => array( $people ), 'c' => $cols['ids'], 'v' => array( $visitor ) )
		);
		$id = (string) ( $data['boards'][0]['items_page']['items'][0]['id'] ?? '' );
	}
	$state['person'] = array( 'id' => $id, 'checked' => time() );
	return $id;
}
