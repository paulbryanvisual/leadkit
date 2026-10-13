<?php
/**
 * Return visits, and the first phone or email tap.
 *
 * Two routes the tracker has always called and WordPress never had. In the
 * Astro build they were Cloudflare Pages Functions; when the form moved into
 * this plugin (1.1.0) the submit handler came with it and these two did not,
 * so every return visit and every first phone tap went to a 404.
 *
 *   POST leadkit/v1/sync   a known lead's pages since they identified themself
 *   POST leadkit/v1/track  the first tel: or mailto: tap, with the session
 *
 * A return visit is attached wherever that visitor is known, by LeadKit's
 * visitor id:
 *
 *   - LeadKit's own leads (the leadkit_lead posts): the visits are stored on
 *     the lead and shown on its screen, and — if "Email me when a lead comes
 *     back" is on — the first return each day is emailed.
 *   - monday.com boards listed in settings: the item whose "visitor_id" column
 *     holds that id gets a "Return visits log", "Last seen" and "Return
 *     visits" column (created on first use) and, once a day, an update on the
 *     item, which notifies the board's subscribers.
 *
 * Both routes are public, as they must be — the visitor is anonymous to
 * WordPress. They only ever ADD visits to a lead that already exists under an
 * unguessable id, they are rate limited, and payloads are capped.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_MONDAY_API   = 'https://api.monday.com/v2';
const LEADKIT_MAX_VIEWS    = 300;
const LEADKIT_LOG_MAX_DAYS = 60;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			'/sync',
			array(
				'methods'             => 'POST',
				'callback'            => 'leadkit_handle_sync',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			LEADKIT_REST_NS,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => 'leadkit_handle_track',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * The monday.com API token: wp-config constant first, then the setting.
 *
 * @return string
 */
function leadkit_monday_token() {
	if ( defined( 'LEADKIT_MONDAY_TOKEN' ) && LEADKIT_MONDAY_TOKEN ) {
		return (string) LEADKIT_MONDAY_TOKEN;
	}
	return (string) ( leadkit_options()['monday_token'] ?? '' );
}

/**
 * Board ids to look for returning visitors on.
 *
 * @return string[]
 */
function leadkit_monday_boards() {
	preg_match_all( '/\d{5,}/', (string) ( leadkit_options()['monday_boards'] ?? '' ), $m );
	return array_values( array_unique( $m[0] ) );
}

/**
 * A visitor id we are willing to act on.
 *
 * @param mixed $id Candidate.
 * @return string Empty when invalid.
 */
function leadkit_clean_visitor_id( $id ) {
	$id = (string) $id;
	return preg_match( '/^[A-Za-z0-9-]{8,64}$/', $id ) ? $id : '';
}

/**
 * Read a JSON body, refusing anything oversized.
 *
 * @param WP_REST_Request $req Request.
 * @return array|null
 */
function leadkit_json_body( $req ) {
	$raw = (string) $req->get_body();
	if ( '' === $raw || strlen( $raw ) > 512000 ) {
		return null;
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : null;
}

/**
 * The per-visitor state kept between syncs. Not autoloaded.
 *
 * @param string $visitor Visitor id.
 * @return string Option name.
 */
function leadkit_visitor_key( $visitor ) {
	return 'leadkit_visitor_' . md5( $visitor );
}

/**
 * Handle a return-visit sync.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function leadkit_handle_sync( $req ) {
	$body    = leadkit_json_body( $req );
	$visitor = leadkit_clean_visitor_id( $body['visitor_id'] ?? '' );
	if ( ! $body || ! $visitor ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	$lead_at = strtotime( (string) ( $body['lead_at'] ?? '' ) );
	if ( ! $lead_at ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	/*
	 * A return visit starts a while after they identified themself. The
	 * thank-you page and whatever they click straight after submitting are
	 * the same visit, not a comeback — counting them would announce
	 * "they're back!" seconds after every enquiry. Default 30 minutes.
	 */
	$since = $lead_at + (int) apply_filters( 'leadkit/return_gap', 30 * MINUTE_IN_SECONDS );

	/*
	 * The visit is always merged and stored; only the outside calls (monday,
	 * email) are spaced out. hidden and pagehide fire together, and dropping
	 * a sync outright would lose the last page of a visit.
	 */
	$rl       = 'leadkit_sync_' . md5( $visitor );
	$throttle = (bool) get_transient( $rl );

	$key   = leadkit_visitor_key( $visitor );
	$state = get_option( $key, array() );
	$state = is_array( $state ) ? $state : array();
	$state += array(
		'visitor'  => $visitor,
		'lead_at'  => gmdate( 'c', $lead_at ),
		'views'    => array(),
		'notified' => array(),
		'monday'   => array(),
	);

	// Their time zone: as the browser reports it now, else as last seen.
	$tz = leadkit_clean_tz( $body['timezone'] ?? '' ) ?: leadkit_clean_tz( $state['tz'] ?? '' );
	if ( $tz ) {
		$state['tz'] = $tz;
	}
	leadkit_use_visitor_tz( $tz );

	// Merge: one entry per page load (by id), newest values win.
	$incoming = is_array( $body['page_views'] ?? null ) ? array_slice( $body['page_views'], -LEADKIT_MAX_VIEWS ) : array();
	$added    = 0;
	foreach ( $incoming as $v ) {
		if ( ! is_array( $v ) ) {
			continue;
		}
		$t = strtotime( (string) ( $v['time'] ?? '' ) );
		// Everything after they submitted; the first half hour is kept as
		// "after submitting", later pages are return visits.
		if ( ! $t || $t < $lead_at ) {
			continue;
		}
		$id   = substr( sanitize_text_field( (string) ( $v['id'] ?? ( $v['time'] . ( $v['path'] ?? '' ) ) ) ), 0, 80 );
		$path = substr( sanitize_text_field( (string) ( $v['path'] ?? '/' ) ), 0, 300 );
		if ( ! isset( $state['views'][ $id ] ) ) {
			++$added;
		}
		$state['views'][ $id ] = array(
			'path'   => $path ?: '/',
			'ms'     => max( 0, min( 86400000, (int) ( $v['active_time_ms'] ?? 0 ) ) ),
			'scroll' => max( 0, min( 100, (int) ( $v['max_scroll_percent'] ?? 0 ) ) ),
			'time'   => gmdate( 'c', $t ),
			'after'  => $t < $since,
		);
	}
	if ( count( $state['views'] ) > LEADKIT_MAX_VIEWS ) {
		uasort( $state['views'], fn( $a, $b ) => strcmp( $a['time'], $b['time'] ) );
		$state['views'] = array_slice( $state['views'], -LEADKIT_MAX_VIEWS, null, true );
	}

	if ( ! $state['views'] || ( $throttle && ! $added ) ) {
		update_option( $key, $state, false );
		return new WP_REST_Response( array( 'ok' => true, 'views' => count( $state['views'] ), 'throttled' => $throttle ), 200 );
	}
	set_transient( $rl, 1, 2 );

	$after_views  = array_filter( $state['views'], fn( $v ) => ! empty( $v['after'] ) );
	$return_views = array_filter( $state['views'], fn( $v ) => empty( $v['after'] ) );
	$days         = leadkit_group_by_day( $return_views );
	$after        = array_values( leadkit_group_by_day( $after_views ) );
	$after        = $after ? array_merge( ...$after ) : array();
	$today        = leadkit_vdate( 'Y-m-d' );
	$came_back    = isset( $days[ $today ] );
	$latest       = max( array_map( fn( $v ) => strtotime( $v['time'] ), $state['views'] ) );
	$new_day_for = function ( $channel ) use ( &$state, $today ) {
		return empty( $state['notified'][ $channel ] ) || $state['notified'][ $channel ] !== $today;
	};

	// ---- LeadKit's own leads ------------------------------------------------
	$posts = get_posts(
		array(
			'post_type'      => LEADKIT_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_key'       => '_leadkit_visitor_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $visitor, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	foreach ( $posts as $post_id ) {
		update_post_meta( $post_id, '_leadkit_return_visits', wp_json_encode( $days ) );
		update_post_meta( $post_id, '_leadkit_after_submit', wp_json_encode( $after ) );
		if ( $days ) {
			update_post_meta( $post_id, '_leadkit_last_seen', leadkit_vdate( 'Y-m-d', $latest ) );
		}
	}
	// Notifications are for comebacks only, never for the pages straight after submitting.
	if ( $posts && $came_back && ! empty( leadkit_options()['notify_returns'] ) && $new_day_for( 'email' ) ) {
		leadkit_notify_return( (int) $posts[0], $days[ $today ] );
		$state['notified']['email'] = $today;
	}

	// ---- monday.com -----------------------------------------------------------
	$monday = array();
	$people = leadkit_monday_token() ? leadkit_monday_people_board() : '';
	$person = $people ? leadkit_person_for_visitor( $visitor, $state ) : '';
	if ( $person ) {
		// One person, one log: return visits go on the People row.
		$pcols  = leadkit_people_columns( $people );
		$values = array(
			$pcols['log']     => array( 'text' => leadkit_days_text( $days, $after ) ),
			$pcols['returns'] => (string) count( $days ),
		);
		if ( $days ) {
			$values[ $pcols['last'] ] = array( 'date' => leadkit_vdate( 'Y-m-d', $latest ) );
		}
		$res = leadkit_monday(
			'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
			array( 'i' => $person, 'b' => $people, 'v' => wp_json_encode( $values ) )
		);
		$monday[ 'person:' . $person ] = $res ? 'updated' : 'failed';
		if ( $res && $came_back && $new_day_for( 'person' ) ) {
			leadkit_monday(
				'mutation ($i: ID!, $body: String!) { create_update(item_id: $i, body: $body) { id } }',
				array(
					'i'    => $person,
					'body' => '<p><strong>' . esc_html__( 'Came back to the site', 'leadkit' ) . '</strong> — '
						. esc_html( leadkit_vdate( 'M j, Y', strtotime( $today ) ) ) . ': ' . esc_html( leadkit_day_pages( $days[ $today ] ) ) . '</p>'
						. '<p>' . esc_html__( 'The full log is in the "Return visits log" column. — LeadKit', 'leadkit' ) . '</p>',
				)
			);
			$state['notified']['person'] = $today;
		}
	} elseif ( leadkit_monday_token() ) {
		// No People board, or this browser is not on it: add to the submission rows.
		foreach ( leadkit_monday_boards() as $board ) {
			$items = leadkit_monday_find_items( $board, $visitor, $state );
			$cols  = $items ? leadkit_monday_columns( $board ) : null;
			if ( ! $cols ) {
				continue;
			}
			// Every row of this person on the board: this browser's, and rows
			// from their other browsers linked by email.
			foreach ( $items as $item ) {
				$values = array(
					$cols['log']   => array( 'text' => leadkit_days_text( $days, $after ) ),
					$cols['count'] => (string) count( $days ),
				);
				if ( $days ) {
					$values[ $cols['seen'] ] = array( 'date' => leadkit_vdate( 'Y-m-d', $latest ) );
				}
				$res = leadkit_monday(
					'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
					array( 'i' => (string) $item, 'b' => (string) $board, 'v' => wp_json_encode( $values ) )
				);
				$monday[ $board . ':' . $item ] = $res ? 'updated' : 'failed';

				$channel = 'monday_' . $board . '_' . $item;
				if ( $res && $came_back && $new_day_for( $channel ) ) {
					$day  = $days[ $today ];
					$note = '<p><strong>' . esc_html__( 'Came back to the site', 'leadkit' ) . '</strong> — '
						. esc_html( leadkit_vdate( 'M j, Y', strtotime( $today ) ) ) . ': '
						. esc_html( leadkit_day_pages( $day ) ) . '</p>'
						. '<p>' . esc_html__( 'The full log is in the "Return visits log" column. — LeadKit', 'leadkit' ) . '</p>';
					leadkit_monday(
						'mutation ($i: ID!, $body: String!) { create_update(item_id: $i, body: $body) { id } }',
						array( 'i' => (string) $item, 'body' => $note )
					);
					$state['notified'][ $channel ] = $today;
				}
			}
		}
	}

	update_option( $key, $state, false );

	return new WP_REST_Response(
		array(
			'ok'     => true,
			'views'  => count( $state['views'] ),
			'added'  => $added,
			'leads'  => count( $posts ),
			'monday' => $monday,
		),
		200
	);
}

/**
 * Views grouped by local day, oldest first, each with its pages in order.
 *
 * @param array $views id => view.
 * @return array 'Y-m-d' => list of views.
 */
function leadkit_group_by_day( $views ) {
	uasort( $views, fn( $a, $b ) => strcmp( $a['time'], $b['time'] ) );
	$days = array();
	foreach ( $views as $v ) {
		$days[ leadkit_vdate( 'Y-m-d', strtotime( $v['time'] ) ) ][] = $v;
	}
	return array_slice( $days, -LEADKIT_LOG_MAX_DAYS, null, true );
}

/**
 * "/donate/ 40s 80% > /safetynet/ 95s 100%"
 *
 * @param array $day Views of one day.
 * @return string
 */
function leadkit_day_pages( $day ) {
	return implode(
		' > ',
		array_map(
			fn( $v ) => leadkit_vdate( 'g:ia', strtotime( $v['time'] ) ) . ' ' . $v['path'] . ' ' . round( $v['ms'] / 1000 ) . 's ' . $v['scroll'] . '%',
			(array) $day
		)
	);
}

/**
 * The whole log, newest day first — what the "Return visits log" column holds.
 *
 * @param array $days From leadkit_group_by_day().
 * @return string
 */
function leadkit_days_text( $days, $after = array() ) {
	$lines = array();
	foreach ( array_reverse( $days, true ) as $date => $day ) {
		$ms      = array_sum( array_column( $day, 'ms' ) );
		$lines[] = leadkit_vdate( 'M j, Y', strtotime( $date ) ) . ' — '
			/* translators: %d: number of pages. */
			. sprintf( _n( '%d page', '%d pages', count( $day ), 'leadkit' ), count( $day ) )
			. ', ' . leadkit_duration( $ms ) . ': ' . leadkit_day_pages( $day );
	}
	if ( $after ) {
		$ms      = array_sum( array_column( $after, 'ms' ) );
		$lines[] = __( 'After submitting', 'leadkit' ) . ' (' . leadkit_vdate( 'M j, Y', strtotime( $after[0]['time'] ) ) . ') — '
			. sprintf( _n( '%d page', '%d pages', count( $after ), 'leadkit' ), count( $after ) )
			. ', ' . leadkit_duration( $ms ) . ': ' . leadkit_day_pages( $after );
	}
	return implode( "\n", $lines );
}

/**
 * The API-Version header, only when pinned.
 *
 * Unpinned, monday answers with its current version — which is what the forms
 * API needs (an older pinned version, 2025-04, refused the form query). Pin
 * with the leadkit/monday_api_version filter if a later change breaks
 * something.
 *
 * @return array
 */
function leadkit_monday_version_header() {
	$v = (string) apply_filters( 'leadkit/monday_api_version', '' );
	return $v ? array( 'API-Version' => $v ) : array();
}

/**
 * Call the monday.com API.
 *
 * @param string $query     GraphQL.
 * @param array  $variables Variables.
 * @return array|null The data, or null on any error (logged).
 */
function leadkit_monday( $query, $variables = array() ) {
	$token = leadkit_monday_token();
	if ( ! $token ) {
		return null;
	}
	$res = wp_remote_post(
		apply_filters( 'leadkit/monday_api_url', LEADKIT_MONDAY_API ),
		array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => $token,
				'Content-Type'  => 'application/json',
			) + leadkit_monday_version_header(),
			'body'    => wp_json_encode( array( 'query' => $query, 'variables' => (object) $variables ) ),
		)
	);
	if ( is_wp_error( $res ) ) {
		update_option( 'leadkit_last_monday_error', $res->get_error_message(), false );
		return null;
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $json ) || ! empty( $json['errors'] ) || ! isset( $json['data'] ) ) {
		$msg = is_array( $json ) && ! empty( $json['errors'][0]['message'] ) ? $json['errors'][0]['message'] : 'HTTP ' . wp_remote_retrieve_response_code( $res );
		update_option( 'leadkit_last_monday_error', $msg, false );
		return null;
	}
	delete_option( 'leadkit_last_monday_error' );
	return $json['data'];
}

/**
 * Every item on a board for this visitor, cached.
 *
 * The item whose visitor_id is this browser, plus any whose "Visitor IDs"
 * holds it — rows of the same person submitted from other browsers, linked by
 * email (monday-people.php). A miss is cached for 10 minutes rather than
 * forever: the visitor is marked a lead the moment the form redirects, which
 * can be a few seconds before monday.com has written the item.
 *
 * @param string $board   Board id.
 * @param string $visitor Visitor id.
 * @param array  $state   Visitor state (by reference, holds the cache).
 * @return string[] Item ids.
 */
function leadkit_monday_find_items( $board, $visitor, &$state ) {
	$cached = $state['monday'][ $board ] ?? null;
	if ( is_array( $cached ) ) {
		if ( ! empty( $cached['items'] ) ) {
			return array_map( 'strval', (array) $cached['items'] );
		}
		if ( ! empty( $cached['item'] ) ) { // Stored by an earlier build.
			return array( (string) $cached['item'] );
		}
		if ( time() - (int) ( $cached['checked'] ?? 0 ) < 600 ) {
			return array();
		}
	}

	$items = array();
	$cols  = leadkit_monday_columns( $board );
	if ( $cols && ! empty( $cols['visitor'] ) ) {
		$data = leadkit_monday(
			'query ($b: ID!, $c: String!, $v: [String]!) { items_page_by_column_values(board_id: $b, limit: 10, columns: [{column_id: $c, column_values: $v}]) { items { id } } }',
			array( 'b' => (string) $board, 'c' => $cols['visitor'], 'v' => array( $visitor ) )
		);
		foreach ( (array) ( $data['items_page_by_column_values']['items'] ?? array() ) as $it ) {
			$items[] = (string) $it['id'];
		}
	}
	$pcols = get_transient( 'leadkit_monday_pcols_' . $board );
	if ( is_array( $pcols ) && ! empty( $pcols['ids'] ) ) {
		$data = leadkit_monday(
			'query ($b: [ID!], $c: String!, $v: CompareValue!) { boards(ids: $b) { items_page(limit: 25, query_params: {rules: [{column_id: $c, compare_value: $v, operator: contains_text}]}) { items { id } } } }',
			array( 'b' => array( (string) $board ), 'c' => $pcols['ids'], 'v' => array( $visitor ) )
		);
		foreach ( (array) ( $data['boards'][0]['items_page']['items'] ?? array() ) as $it ) {
			$items[] = (string) $it['id'];
		}
	}
	$items = array_values( array_unique( $items ) );
	$state['monday'][ $board ] = array( 'items' => $items, 'checked' => time() );
	return $items;
}

/**
 * Column ids on a board: the visitor_id column it already has, and the three
 * return-visit columns, created the first time they are needed. Cached a day.
 *
 * @param string $board Board id.
 * @return array|null { visitor, log, seen, count }
 */
function leadkit_monday_columns( $board ) {
	$key    = 'leadkit_monday_cols_' . $board;
	$cached = get_transient( $key );
	if ( is_array( $cached ) && ! empty( $cached['log'] ) ) {
		return $cached;
	}

	$data = leadkit_monday( 'query ($b: [ID!]) { boards(ids: $b) { columns { id title type } } }', array( 'b' => array( (string) $board ) ) );
	$list = $data['boards'][0]['columns'] ?? null;
	if ( ! is_array( $list ) ) {
		return null;
	}

	$by_title = array();
	foreach ( $list as $c ) {
		$by_title[ strtolower( trim( (string) $c['title'] ) ) ] = $c['id'];
	}

	$want = array(
		'log'   => array( 'Return visits log', 'long_text' ),
		'seen'  => array( 'Last seen', 'date' ),
		'count' => array( 'Return visits', 'numbers' ),
	);
	$cols = array( 'visitor' => $by_title['visitor_id'] ?? '' );
	foreach ( $want as $slot => list( $title, $type ) ) {
		$id = $by_title[ strtolower( $title ) ] ?? '';
		if ( ! $id ) {
			// The column type is an enum, so it is written into the query from
			// this fixed list rather than passed as a variable.
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
 * Email: a lead came back.
 *
 * @param int   $post_id Lead post.
 * @param array $day     Today's views.
 * @return void
 */
function leadkit_notify_return( $post_id, $day ) {
	$opts = leadkit_options();
	$to   = array_filter( array_map( 'trim', explode( ',', (string) $opts['notify_email'] ) ) ) ?: array( get_option( 'admin_email' ) );
	$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$from = $opts['from_email'] ?: 'wordpress@' . $host;
	$name = (string) get_post_meta( $post_id, '_leadkit_name', true ) ?: get_the_title( $post_id );

	$body = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;color:#1d2327;line-height:1.6">'
		. '<p><strong>' . esc_html( $name ) . '</strong> ' . esc_html__( 'came back to the site today:', 'leadkit' ) . '</p>'
		. '<p>' . esc_html( leadkit_day_pages( $day ) ) . '</p>'
		. '<p><a href="' . esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '">' . esc_html__( 'Open this lead', 'leadkit' ) . '</a></p></div>';

	wp_mail(
		$to,
		/* translators: %s: lead name. */
		sprintf( __( '%s is back on the site', 'leadkit' ), $name ),
		$body,
		array(
			'From: ' . ( $opts['from_name'] ?: get_bloginfo( 'name' ) ) . ' <' . $from . '>',
			'Content-Type: text/html; charset=UTF-8',
		)
	);
}

/**
 * Handle the first phone or email tap: store it as a lead and notify.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function leadkit_handle_track( $req ) {
	$body      = leadkit_json_body( $req );
	$analytics = is_array( $body['analyticsData'] ?? null ) ? $body['analyticsData'] : array();
	$visitor   = leadkit_clean_visitor_id( $analytics['id'] ?? '' );
	$type      = in_array( $body['interactionType'] ?? '', array( 'phone_click', 'email_click' ), true ) ? $body['interactionType'] : '';
	$value     = substr( sanitize_text_field( (string) ( $body['interactionValue'] ?? '' ) ), 0, 120 );
	if ( ! $visitor || ! $type ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	// One per visitor, and a per-IP brake.
	$ip = leadkit_client_ip();
	if ( get_transient( 'leadkit_track_' . md5( $visitor ) ) || ( $ip && get_transient( 'leadkit_trackip_' . md5( $ip ) ) ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'throttled' => true ), 200 );
	}
	set_transient( 'leadkit_track_' . md5( $visitor ), 1, DAY_IN_SECONDS );
	if ( $ip ) {
		set_transient( 'leadkit_trackip_' . md5( $ip ), 1, 20 );
	}

	$label   = 'phone_click' === $type ? __( 'Tapped the phone number', 'leadkit' ) : __( 'Tapped the email address', 'leadkit' );
	$post_id = wp_insert_post(
		array(
			'post_type'   => LEADKIT_CPT,
			'post_status' => 'publish',
			'post_title'  => $label . ( $value ? ' — ' . $value : '' ),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 500 );
	}

	update_post_meta( $post_id, '_leadkit_project_type', $label );
	update_post_meta( $post_id, '_leadkit_message', $label . ( $value ? ': ' . $value : '' ) . '. ' . __( 'They have not sent a message — this is the moment they reached for the phone or email.', 'leadkit' ) );
	update_post_meta( $post_id, '_leadkit_source_url', esc_url_raw( wp_get_referer() ?: '' ) );
	update_post_meta( $post_id, '_leadkit_ip', $ip );
	update_post_meta( $post_id, '_leadkit_visitor_id', $visitor );
	update_post_meta( $post_id, '_leadkit_analytics', wp_json_encode( $analytics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	leadkit_store_campaign( $post_id, $analytics );

	$opts = leadkit_options();
	$to   = array_filter( array_map( 'trim', explode( ',', (string) $opts['notify_email'] ) ) ) ?: array( get_option( 'admin_email' ) );
	$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$from = $opts['from_email'] ?: 'wordpress@' . $host;
	$sent = wp_mail(
		$to,
		$label . ( $value ? ' — ' . $value : '' ),
		'<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif">'
			. '<p>' . esc_html( $label ) . '. ' . esc_html__( 'How they got here:', 'leadkit' ) . '</p>'
			. leadkit_render_journey( (string) get_post_meta( $post_id, '_leadkit_analytics', true ) )
			. '<p><a href="' . esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '">' . esc_html__( 'Open this lead', 'leadkit' ) . '</a></p></div>',
		array(
			'From: ' . ( $opts['from_name'] ?: get_bloginfo( 'name' ) ) . ' <' . $from . '>',
			'Content-Type: text/html; charset=UTF-8',
		)
	);
	update_post_meta( $post_id, '_leadkit_notified', $sent ? '1' : '0' );

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}
