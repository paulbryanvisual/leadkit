<?php
/**
 * One person, several browsers: link monday.com rows that share an email.
 *
 * A visitor id belongs to a browser, not a person. Someone who volunteers from
 * a laptop and later enquires from a phone arrives with two ids, and without
 * this their two rows would never be connected — and visits from the phone
 * would never reach the laptop's row.
 *
 * So when [leadkit_monday_form] creates an item, the board is searched for
 * earlier items with the same email address. Each submission stays its own row,
 * and every row of that person gets:
 *
 *   - "Visitor IDs": every browser id seen for them, one per line. Return-visit
 *     syncs look here as well as in visitor_id, so a visit from any of their
 *     browsers is added to all of their rows;
 *   - "Submissions": how many times they have filled in a form on this board;
 *   - an update linking the rows ("Same person as …" / "Submitted again …").
 *
 * The same is done for LeadKit's own leads in WordPress: leads with the same
 * email share their visitor ids, so return visits reach all of them.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Visitor IDs" and "Submissions" on a board, created the first time. Cached.
 *
 * @param string $board Board id.
 * @return array|null { ids, count } => column id.
 */
function leadkit_monday_people_columns( $board ) {
	$key    = leadkit_monday_columns_key( 'pcols', $board );
	$cached = get_transient( $key );
	if ( is_array( $cached ) && ! empty( $cached['ids'] ) && ! empty( $cached['count'] ) ) {
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
	foreach ( array( 'ids' => array( 'Visitor IDs', 'long_text' ), 'count' => array( 'Submissions', 'numbers' ) ) as $slot => list( $title, $type ) ) {
		$id = $by_title[ strtolower( $title ) ] ?? '';
		if ( ! $id ) {
			$made = leadkit_monday(
				'mutation ($b: ID!, $t: String!) { create_column(board_id: $b, title: $t, column_type: ' . $type . ') { id } }',
				array( 'b' => (string) $board, 't' => $title )
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
 * Link a new item to earlier items on the board with the same email.
 *
 * @param string $board      Board id.
 * @param string $item_id    The item just created.
 * @param string $email      Its email address.
 * @param string $email_col  The board's email column.
 * @param string $visitor    The submitting browser's visitor id ('' if none).
 * @param string $name       Item name, for the update text.
 * @return int How many earlier items were linked.
 */
function leadkit_monday_link_person( $board, $item_id, $email, $email_col, $visitor, $name ) {
	$email = strtolower( trim( (string) $email ) );
	if ( ! $email || ! $email_col || ! $item_id ) {
		return 0;
	}
	$pcols = leadkit_monday_people_columns( $board );
	$vcols = leadkit_monday_columns( $board );
	if ( ! $pcols ) {
		return 0;
	}

	$read = array_values( array_filter( array( $pcols['ids'], $vcols['visitor'] ?? '' ) ) );
	$data = leadkit_monday(
		'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 50, columns: [{column_id: $c, column_values: $v}]) { items { id name url created_at column_values(ids: $r) { id text } } } }',
		array( 'b' => (string) $board, 'c' => $email_col, 'v' => array( $email ), 'r' => $read )
	);
	$items = $data['items_page_by_column_values']['items'] ?? array();

	$earlier = array();
	$ids     = $visitor ? array( $visitor ) : array();
	$own_url = '';
	foreach ( (array) $items as $it ) {
		foreach ( (array) ( $it['column_values'] ?? array() ) as $cv ) {
			foreach ( preg_split( '/[\s,]+/', (string) ( $cv['text'] ?? '' ) ) as $vid ) {
				$vid = leadkit_clean_visitor_id( $vid );
				if ( $vid ) {
					$ids[] = $vid;
				}
			}
		}
		if ( (string) $it['id'] === (string) $item_id ) {
			$own_url = (string) $it['url'];
			continue;
		}
		$earlier[] = $it;
	}
	if ( ! $earlier ) {
		return 0;
	}
	$ids = array_values( array_unique( $ids ) );

	usort( $earlier, fn( $a, $b ) => strcmp( (string) $a['created_at'], (string) $b['created_at'] ) );
	if ( ! $own_url ) {
		$own_url = preg_replace( '#/pulses/\d+#', '/pulses/' . (int) $item_id, (string) $earlier[0]['url'] );
	}
	$count = count( $earlier ) + 1;

	// Every row of this person: the full id list and the submission count.
	$all = array_merge( array( (string) $item_id ), array_map( fn( $it ) => (string) $it['id'], $earlier ) );
	foreach ( $all as $id ) {
		leadkit_monday(
			'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
			array(
				'i' => $id,
				'b' => (string) $board,
				'v' => wp_json_encode(
					array(
						$pcols['ids']   => array( 'text' => implode( "\n", $ids ) ),
						$pcols['count'] => (string) $count,
					)
				),
			)
		);
	}

	// The updates that connect them.
	$links = array();
	foreach ( $earlier as $it ) {
		$links[] = '<li><a href="' . esc_url( $it['url'] ) . '">' . esc_html( $it['name'] ) . '</a> — '
			. esc_html( leadkit_vdate( 'M j, Y g:i a', strtotime( (string) $it['created_at'] ) ) ) . '</li>';
	}
	leadkit_monday(
		'mutation ($i: ID!, $body: String!) { create_update(item_id: $i, body: $body) { id } }',
		array(
			'i'    => (string) $item_id,
			'body' => '<p><strong>' . esc_html__( 'Same person as earlier submissions', 'leadkit' ) . '</strong> '
				. esc_html__( '(matched by email address)', 'leadkit' ) . ':</p><ul>' . implode( '', $links ) . '</ul>'
				. '<p><em>' . esc_html__( 'Return visits from any of their browsers are added to all of these rows. — LeadKit', 'leadkit' ) . '</em></p>',
		)
	);
	foreach ( $earlier as $it ) {
		leadkit_monday(
			'mutation ($i: ID!, $body: String!) { create_update(item_id: $i, body: $body) { id } }',
			array(
				'i'    => (string) $it['id'],
				'body' => '<p><strong>' . esc_html__( 'Submitted again', 'leadkit' ) . '</strong> — '
					. esc_html( leadkit_vdate( 'M j, Y g:i a' ) ) . ': <a href="' . esc_url( $own_url ) . '">' . esc_html( $name ) . '</a> '
					. esc_html__( '(same email address)', 'leadkit' ) . '</p>',
			)
		);
	}

	// Forget each browser's cached lookup, so its next sync finds all rows.
	foreach ( $ids as $vid ) {
		$key   = leadkit_visitor_key( $vid );
		$state = get_option( $key, null );
		if ( is_array( $state ) && isset( $state['monday'][ $board ] ) ) {
			unset( $state['monday'][ $board ] );
			update_option( $key, $state, false );
		}
	}

	return count( $earlier );
}

/**
 * WordPress side: leads with the same email share their visitor ids.
 *
 * @param int    $post_id New lead.
 * @param string $email   Its email.
 * @return void
 */
function leadkit_link_wp_leads( $post_id, $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( ! $email || ! $post_id ) {
		return;
	}
	$same = get_posts(
		array(
			'post_type'      => LEADKIT_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'meta_key'       => '_leadkit_email', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( count( $same ) < 2 ) {
		return;
	}
	$ids = array();
	foreach ( $same as $id ) {
		$ids = array_merge( $ids, (array) get_post_meta( $id, '_leadkit_visitor_id' ) );
	}
	$ids = array_unique( array_filter( array_map( 'leadkit_clean_visitor_id', $ids ) ) );
	foreach ( $same as $id ) {
		$have = (array) get_post_meta( $id, '_leadkit_visitor_id' );
		foreach ( $ids as $vid ) {
			if ( ! in_array( $vid, $have, true ) ) {
				add_post_meta( $id, '_leadkit_visitor_id', $vid );
			}
		}
		update_post_meta( $id, '_leadkit_submissions', count( $same ) );
	}
}
