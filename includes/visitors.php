<?php
/**
 * The Visitors board — one monday.com row per browser.
 *
 * With a Visitors board set (Settings → LeadKit → monday.com Visitors board),
 * LeadKit keeps a row for each visitor id — the same id the forms send and
 * the one packed into every donation link — so a gift that arrives on a
 * Donations board through Give Lively can be joined to the visits and forms
 * that came before it:
 *
 *   POST leadkit/v1/visitor   a Donate click, sent by the tracker with the
 *                             visitor's recent story (queued in the browser
 *                             and resent if it fails)
 *   form submissions          from the server, after the visitor is answered
 *                             (monday-form.php, submit.php)
 *
 * Columns are found by title and created the first time if missing:
 *
 *   visitorid         the exact-match key; the item name is the same value
 *   First seen / Last seen, Visits (30 minutes apart start a new one)
 *   First source / Latest source, packed as on donation links, no visitorid
 *   Donate clicks, Journey log (each visit, newest first)
 *   Person            → the People board, from a form submission's email
 *   Form submissions  → the form boards, each submission from this browser
 *
 * The board's Donations column is not LeadKit's: gifts are connected to the
 * row by an automation on the Donations board matching visitorid.
 *
 * The monday.com token stays on the server; the browser only ever posts here.
 * The route is public, as it must be. It writes only to the row of the id it
 * is given, merges rather than replaces (so a resent post changes nothing),
 * is rate limited per IP, and refuses bodies over 64 KB.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_VISIT_GAP       = 1800;
const LEADKIT_VISITOR_VIEWS   = 300;
const LEADKIT_VISITOR_LOG_MAX = 2000;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			'/visitor',
			array(
				'methods'             => 'POST',
				'callback'            => 'leadkit_handle_visitor',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * The Visitors board id, if set.
 *
 * @return string
 */
function leadkit_monday_visitors_board() {
	return preg_replace( '/\D/', '', (string) ( leadkit_options()['monday_visitors_board'] ?? '' ) );
}

/**
 * Whether there is a Visitors board to write to.
 *
 * @return bool
 */
function leadkit_visitors_enabled() {
	return '' !== leadkit_monday_visitors_board() && '' !== leadkit_monday_token();
}

/**
 * The Visitors board's columns. The connections only when there is something
 * to connect to.
 *
 * @param string $board Visitors board id.
 * @return array|null slot => column id.
 */
function leadkit_visitor_columns( $board ) {
	$want = array(
		'vid'    => array( 'visitorid', 'text' ),
		'first'  => array( 'First seen', 'date' ),
		'last'   => array( 'Last seen', 'date' ),
		'visits' => array( 'Visits', 'numbers' ),
		'fsrc'   => array( 'First source', 'text' ),
		'lsrc'   => array( 'Latest source', 'text' ),
		'clicks' => array( 'Donate clicks', 'numbers' ),
		'log'    => array( 'Journey log', 'long_text' ),
		'fref'   => array( 'First referrer', 'text' ),
		'lref'   => array( 'Latest referrer', 'text' ),
		'open'   => array( 'Open person', 'link' ),
	);
	$people = leadkit_monday_people_board();
	if ( $people ) {
		$want['person'] = array( 'Person', 'board_relation', array( 'boardIds' => array( (int) $people ), 'allowMultipleItems' => false ) );
	}
	$forms = array_map( 'intval', leadkit_monday_boards() );
	if ( $forms ) {
		$want['forms'] = array( 'Form submissions', 'board_relation', array( 'boardIds' => $forms, 'allowMultipleItems' => true ) );
	}
	return leadkit_monday_ensure_columns( $board, $want, 'visitors' );
}

/**
 * Handle a Donate click.
 *
 * Answers 200 only once the row is written (or already says the same), so a
 * post the browser does not hear back from stays queued there; 5xx and 429
 * are retried later, anything else is not.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function leadkit_handle_visitor( $req ) {
	if ( strlen( (string) $req->get_body() ) > 65536 ) {
		return new WP_REST_Response( array( 'ok' => false ), 413 );
	}
	$body    = leadkit_json_body( $req );
	$visitor = leadkit_clean_visitor_id( $body['visitor_id'] ?? '' );
	$clicks  = is_array( $body['donate_clicks'] ?? null ) ? $body['donate_clicks'] : array();
	if ( ! $body || ! $visitor || ! $clicks ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}
	if ( ! leadkit_visitors_enabled() ) {
		// Nothing to write to: done, so the browser stops resending it.
		return new WP_REST_Response( array( 'ok' => true, 'off' => true ), 200 );
	}

	// A brake per IP: 30 posts in ten minutes, and five new rows an hour.
	$ip = leadkit_client_ip();
	if ( $ip ) {
		$k = 'leadkit_vip_' . md5( $ip );
		$n = (int) get_transient( $k );
		if ( $n >= 30 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );

		$known = get_option( leadkit_visitor_row_key( $visitor ), array() );
		if ( empty( $known['row'] ) ) {
			$k = 'leadkit_vnew_' . md5( $ip );
			$n = (int) get_transient( $k );
			if ( $n >= 5 ) {
				return new WP_REST_Response( array( 'ok' => false ), 429 );
			}
			set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		}
	}

	leadkit_use_visitor_tz( leadkit_clean_tz( $body['timezone'] ?? '' ) );
	$row = leadkit_visitor_upsert( $visitor, $body );

	return new WP_REST_Response( array( 'ok' => (bool) $row ), $row ? 200 : 502 );
}

/**
 * Write the Visitors row after the visitor has been answered.
 *
 * @param string $visitor   Visitor id.
 * @param array  $analytics Decoded analyticsData from the form.
 * @param array  $link      { item?: submission item id, person?: People item id, form?: form title }.
 * @return void
 */
function leadkit_queue_visitor( $visitor, $analytics, $link = array() ) {
	$visitor = leadkit_clean_visitor_id( $visitor );
	if ( ! $visitor || ! is_array( $analytics ) || ! leadkit_visitors_enabled() ) {
		return;
	}

	/*
	 * The session was serialized into the form before the browser logged the
	 * submission itself, so it is added here — with the form's own title,
	 * which reads better than the browser's short name for it.
	 */
	if ( ! empty( $link['form'] ) ) {
		$analytics['lead_forms']   = is_array( $analytics['lead_forms'] ?? null ) ? $analytics['lead_forms'] : array();
		$analytics['lead_forms'][] = array(
			'form' => (string) $link['form'],
			'time' => gmdate( 'c' ),
			'path' => (string) wp_parse_url( (string) wp_get_referer(), PHP_URL_PATH ),
		);
	}
	$tz = leadkit_use_visitor_tz();
	$tz = $tz ? $tz->getName() : '';

	add_action(
		'shutdown',
		function () use ( $visitor, $analytics, $link, $tz ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
			ignore_user_abort( true );
			leadkit_use_visitor_tz( $tz );
			leadkit_visitor_upsert( $visitor, $analytics, $link );
		},
		90 // Before the page subitems (100), which can take a minute of screenshots.
	);
}

/**
 * The local state for a visitor's row. Not autoloaded, and apart from the
 * return-visit state so neither overwrites the other.
 *
 * @param string $visitor Visitor id.
 * @return string Option name.
 */
function leadkit_visitor_row_key( $visitor ) {
	return 'leadkit_vrow_' . md5( $visitor );
}

/**
 * Create or update the visitor's row, one request at a time per visitor.
 *
 * @param string $visitor Visitor id.
 * @param array  $data    analyticsData, or a Visitors post: started_at, referrer,
 *                        utm, utm_first, utm_touches, page_views, donate_clicks,
 *                        lead_forms, donation_returns (or events).
 * @param array  $link    { item?, person? } to connect; dk?: a donor key
 *                        matched in monday.com ("b-22758-7cf7").
 * @return string Row item id, '' when nothing could be written.
 */
function leadkit_visitor_upsert( $visitor, $data, $link = array() ) {
	$board = leadkit_monday_visitors_board();
	if ( ! $board || ! leadkit_monday_token() ) {
		return '';
	}
	$lock = leadkit_visitor_lock( $visitor );
	$row  = leadkit_visitor_write( $board, $visitor, (array) $data, (array) $link );
	leadkit_visitor_unlock( $lock );
	return $row;
}

/**
 * A lock per visitor, so a form submission and a Donate click landing
 * together cannot both create a row.
 *
 * INSERT IGNORE on the options table, because it either inserts or does not;
 * add_option() would not do — it upserts, so two requests both "win". A lock
 * older than a minute is a request that died, and is taken over.
 *
 * @param string $visitor Visitor id.
 * @return string Lock name, '' when it could not be had in ten seconds.
 */
function leadkit_visitor_lock( $visitor ) {
	global $wpdb;
	$name = 'leadkit_vlock_' . md5( $visitor );
	for ( $i = 0; $i < 40; $i++ ) {
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $got ) {
			return $name;
		}
		$at = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $at && time() - $at > 60 ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			continue;
		}
		usleep( 250000 );
	}
	return ''; // Go ahead unlocked rather than lose the write.
}

/**
 * @param string $name From leadkit_visitor_lock().
 * @return void
 */
function leadkit_visitor_unlock( $name ) {
	global $wpdb;
	if ( $name ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Merge what arrived into the visitor's state; write the row; connect it.
 *
 * @param string $board   Visitors board id.
 * @param string $visitor Visitor id.
 * @param array  $data    See leadkit_visitor_upsert().
 * @param array  $link    See leadkit_visitor_upsert().
 * @return string Row item id, or ''.
 */
function leadkit_visitor_write( $board, $visitor, $data, $link ) {
	$key = leadkit_visitor_row_key( $visitor );
	wp_cache_delete( $key, 'options' ); // Another request may have just written it.
	$st = get_option( $key, array() );
	$st = ( is_array( $st ) ? $st : array() ) + array(
		'row'     => '',
		'hash'    => '',
		'started' => '',
		'views'   => array(),
		'clicks'  => array(),
		'forms'   => array(),
		'returns' => array(),
		'touches' => array(),
		'refs'    => array(),
		'visits'  => array(), // visitid => { row, hash } on the Visits board.
		'items'   => array(), // Submissions still to connect.
		'person'  => '',      // Person still to connect.
		'linked'  => '',      // Person already connected.
		'brief'   => array(), // That person's name and address, for Open person links.
		'dk'      => '',      // The latest donor key matched in monday.com: "b-22758-7cf7".
		'dk_done' => '',      // The key last seen through (donor-keys.php).
		'dk_miss' => 0,       // When that key last matched no donor.
		'dk_person' => '',    // The People item that key named.
		'eids'    => array(), // Email ids seen on this browser's touches (email-clicks.php).
	);
	$st = leadkit_visitor_merge( $st, $data );
	if ( ! empty( $link['item'] ) ) {
		$st['items'][] = (int) $link['item'];
	}
	if ( ! empty( $link['person'] ) ) {
		$st['person'] = (string) $link['person'];
	}
	if ( ! empty( $link['dk'] ) ) {
		$st['dk'] = (string) $link['dk'];
	}

	$cols = leadkit_visitor_columns( $board );
	if ( ! $cols || ! $st['views'] ) {
		update_option( $key, $st, false );
		return '';
	}

	$visits  = leadkit_visitor_visits( $st['views'] );
	$details = leadkit_visits_details( $visits, $st );
	$times  = array_merge(
		array_map( fn( $v ) => strtotime( $v['time'] ), $st['views'] ),
		array_map( fn( $c ) => strtotime( $c['time'] ), $st['clicks'] )
	);
	$first  = min( $times );
	if ( $st['started'] && strtotime( $st['started'] ) ) {
		$first = min( $first, strtotime( $st['started'] ) );
	}
	$values = array(
		$cols['vid']    => $visitor,
		$cols['first']  => array( 'date' => leadkit_vdate( 'Y-m-d', $first ) ),
		$cols['last']   => array( 'date' => leadkit_vdate( 'Y-m-d', max( $times ) ) ),
		$cols['visits'] => (string) count( $visits ),
		$cols['fsrc']   => leadkit_pack_source( leadkit_credit_fields( $data, true ) ),
		$cols['lsrc']   => leadkit_pack_source( leadkit_credit_fields( $data ) ),
		$cols['clicks'] => (string) count( $st['clicks'] ),
		$cols['log']    => array( 'text' => leadkit_visitor_log( $details ) ),
		$cols['fref']   => leadkit_first_referrer( $data ),
		$cols['lref']   => leadkit_latest_referrer( $data ),
	);
	$hash = md5( (string) wp_json_encode( $values ) );

	// The row: remembered, else found by visitorid, else created.
	$row = (string) $st['row'];
	if ( ! $row ) {
		$row = leadkit_visitor_find_row( $board, $cols['vid'], $visitor );
		if ( null === $row ) {
			// Could not ask. Creating now could make a second row.
			update_option( $key, $st, false );
			return '';
		}
	}
	$wrote = false;
	if ( $row && $hash !== $st['hash'] ) {
		$wrote = leadkit_visitor_set( $board, $row, $values );
		if ( ! $wrote ) {
			// Deleted on monday.com since? Find it again, or start over.
			$again = leadkit_visitor_find_row( $board, $cols['vid'], $visitor );
			$row   = null === $again ? '' : $again;
			if ( null !== $again ) {
				$st['linked'] = '';
			}
			if ( $row ) {
				$wrote = leadkit_visitor_set( $board, $row, $values );
			} elseif ( null !== $again ) {
				$row = leadkit_visitor_create( $board, $visitor, $values );
				$wrote = (bool) $row;
			}
		}
	} elseif ( ! $row ) {
		$row   = leadkit_visitor_create( $board, $visitor, $values );
		$wrote = (bool) $row;
	} else {
		$wrote = true; // Already says this.
	}
	if ( ! $row || ! $wrote ) {
		update_option( $key, $st, false );
		return '';
	}
	if ( $row !== (string) $st['row'] ) {
		leadkit_visits_mark_known( $visitor ); // New on the board: warm in D1 from now on.
	}
	$st['row']  = $row;
	$st['hash'] = $hash;

	/*
	 * The connections, each in its own request: a submission on a board the
	 * column does not connect to is refused by monday.com, and that must not
	 * take the person — or the columns above — down with it.
	 */
	if ( $st['items'] && ! empty( $cols['forms'] ) ) {
		$now = leadkit_visitor_linked( $row, $cols['forms'] );
		if ( null !== $now ) {
			$all = array_values( array_unique( array_merge( $now, array_map( 'intval', $st['items'] ) ) ) );
			if ( $all === $now || leadkit_visitor_set( $board, $row, array( $cols['forms'] => array( 'item_ids' => $all ) ) ) ) {
				$st['items'] = array();
			}
		}
	}
	if ( $st['person'] && ! empty( $cols['person'] ) && $st['person'] !== $st['linked'] ) {
		// One person per browser on the board; the latest to give an email.
		$brief  = leadkit_monday_item_brief( $st['person'] );
		$values = array( $cols['person'] => array( 'item_ids' => array( (int) $st['person'] ) ) );
		if ( $brief ) {
			$values[ $cols['open'] ] = leadkit_person_link( $brief );
		}
		if ( leadkit_visitor_set( $board, $row, $values ) ) {
			$st['linked'] = $st['person'];
			$st['brief']  = $brief ?: array();
		}
	}
	if ( $st['person'] === $st['linked'] ) {
		$st['person'] = '';
	}

	// A donor key from an email link: the donor, if this browser has no one
	// else (donor-keys.php). A key that matched no one is tried again daily.
	$dk_due = $st['dk'] !== $st['dk_done'] || ( $st['dk_miss'] && time() - (int) $st['dk_miss'] > DAY_IN_SECONDS );
	if ( $st['dk'] && $dk_due && ! empty( $cols['person'] ) ) {
		leadkit_donor_key_link( $board, $row, $visitor, $cols, $st );
	}

	// An officer email's id on a campaign touch (utm_term e20261026-104312): that
	// email's Emailed subitem gets its Clicked date (email-clicks.php).
	if ( leadkit_email_clicks_queue( $visitor, $st ) ) {
		leadkit_email_clicks_run();
	}

	// A row per visit on the Visits board, connected to this one — after the
	// person, so each visit can link to them too.
	leadkit_visits_write( $visitor, $row, $details, $st );
	if ( count( $st['items'] ) > 20 ) {
		$st['items'] = array_slice( $st['items'], -20 ); // A board that never takes them.
	}

	update_option( $key, $st, false );
	return $row;
}

/**
 * Fold an incoming story into the state, by id, newest values winning.
 * Pages that are never tracked are dropped, wherever they came from.
 *
 * @param array $st   State.
 * @param array $data Incoming.
 * @return array State.
 */
function leadkit_visitor_merge( $st, $data ) {
	$started = strtotime( (string) ( $data['started_at'] ?? '' ) );
	if ( $started && ( ! $st['started'] || $started < strtotime( $st['started'] ) ) ) {
		$st['started'] = gmdate( 'c', $started );
	}

	foreach ( array_slice( (array) ( $data['page_views'] ?? array() ), -LEADKIT_VISITOR_VIEWS ) as $v ) {
		$t    = is_array( $v ) ? strtotime( (string) ( $v['time'] ?? '' ) ) : 0;
		$path = is_array( $v ) ? substr( sanitize_text_field( (string) ( $v['path'] ?? '/' ) ), 0, 300 ) : '';
		if ( ! $t || $t > time() + 300 || leadkit_is_untracked_path( $path ) ) {
			continue;
		}
		$id                  = substr( sanitize_text_field( (string) ( $v['id'] ?? ( $v['time'] . $path ) ) ), 0, 80 );
		$st['views'][ $id ] = array(
			'path'   => $path ?: '/',
			'ms'     => max( 0, min( 86400000, (int) ( $v['active_time_ms'] ?? 0 ) ) ),
			'scroll' => max( 0, min( 100, (int) ( $v['max_scroll_percent'] ?? 0 ) ) ),
			'time'   => gmdate( 'c', $t ),
		);
	}
	if ( count( $st['views'] ) > LEADKIT_VISITOR_VIEWS ) {
		uasort( $st['views'], fn( $a, $b ) => strcmp( $a['time'], $b['time'] ) );
		$st['views'] = array_slice( $st['views'], -LEADKIT_VISITOR_VIEWS, null, true );
	}

	foreach ( (array) ( $data['donate_clicks'] ?? array() ) as $c ) {
		$t    = is_array( $c ) ? strtotime( (string) ( $c['time'] ?? '' ) ) : 0;
		$path = is_array( $c ) ? substr( sanitize_text_field( (string) ( $c['path'] ?? '' ) ), 0, 300 ) : '';
		if ( ! $t || $t > time() + 300 || leadkit_is_untracked_path( $path ) ) {
			continue;
		}
		$id                   = substr( sanitize_text_field( (string) ( $c['id'] ?? $c['time'] ) ), 0, 80 );
		$st['clicks'][ $id ] = array( 'time' => gmdate( 'c', $t ), 'path' => $path );
	}
	$st['clicks'] = array_slice( $st['clicks'], -200, null, true );

	// Forms and phone or email taps. The server logs a submission a moment
	// before the browser does, under a different name: within two minutes is
	// the same one.
	foreach ( (array) ( $data['lead_forms'] ?? array() ) as $f ) {
		$t = is_array( $f ) ? strtotime( (string) ( $f['time'] ?? '' ) ) : 0;
		if ( ! $t ) {
			continue;
		}
		$dupe = false;
		foreach ( $st['forms'] as $have ) {
			if ( abs( strtotime( $have['time'] ) - $t ) < 120 ) {
				$dupe = true;
				break;
			}
		}
		if ( ! $dupe ) {
			$st['forms'][] = array( 'time' => gmdate( 'c', $t ), 'form' => substr( sanitize_text_field( (string) ( $f['form'] ?? '' ) ), 0, 80 ) );
		}
	}
	$st['forms'] = array_slice( $st['forms'], -50 );

	// Back from the donation form: as the tracker posts it, or from the
	// session's events when it is a form submission.
	$returns = (array) ( $data['donation_returns'] ?? array() );
	foreach ( (array) ( $data['events'] ?? array() ) as $e ) {
		if ( is_array( $e ) && 'donation_return' === ( $e['type'] ?? '' ) ) {
			$returns[] = $e;
		}
	}
	foreach ( $returns as $r ) {
		$t = is_array( $r ) ? strtotime( (string) ( $r['time'] ?? '' ) ) : 0;
		if ( $t ) {
			$st['returns'][ gmdate( 'c', $t ) ] = true;
		}
	}
	$st['returns'] = array_slice( $st['returns'], -50, null, true );

	// Campaign arrivals and arrivals from other sites, to say where each
	// visit came from.
	foreach ( (array) ( $data['utm_touches'] ?? array() ) as $u ) {
		$t = is_array( $u ) ? strtotime( (string) ( $u['time'] ?? '' ) ) : 0;
		if ( ! $t ) {
			continue;
		}
		$fields = array();
		foreach ( array( 'medium', 'source', 'campaign', 'content', 'term' ) as $k ) {
			$fields[ $k ] = substr( sanitize_text_field( (string) ( $u[ $k ] ?? '' ) ), 0, 100 );
		}
		if ( array_filter( $fields ) ) {
			$st['touches'][ gmdate( 'c', $t ) ] = $fields;
		}
	}
	$st['touches'] = array_filter( $st['touches'], 'is_array' ); // 1.4.0 kept labels here.
	ksort( $st['touches'] );
	$st['touches'] = array_slice( $st['touches'], -50, null, true );

	foreach ( (array) ( $data['referrers'] ?? array() ) as $r ) {
		$t      = is_array( $r ) ? strtotime( (string) ( $r['time'] ?? '' ) ) : 0;
		$domain = is_array( $r ) ? leadkit_clean_domain( $r['domain'] ?? '' ) : '';
		if ( $t && $domain ) {
			$st['refs'][ gmdate( 'c', $t ) ] = $domain;
		}
	}
	ksort( $st['refs'] );
	$st['refs'] = array_slice( $st['refs'], -50, null, true );

	return $st;
}

/**
 * One visit, worked out once for both the Journey log line and its row on
 * the Visits board: when, from where, and what happened, in order.
 *
 * @param array $views The visit's views, in order (from leadkit_visitor_visits()).
 * @param array $events [ time, label, kind ] that fall in this visit.
 * @param array $st     State (touches, refs).
 * @return array start, end, referrer, fields, label, pages, ms, clicks, forms, items
 */
function leadkit_visit_details( $views, $events, $st ) {
	$start = strtotime( $views[0]['time'] );
	$last  = end( $views );
	$end   = strtotime( $last['time'] ) + (int) round( $last['ms'] / 1000 );

	// Where it came from: a campaign link it arrived on, else the outside
	// site that sent it. A minute either side, for clocks and redirects.
	$in       = fn( $time ) => strtotime( $time ) >= $start - 60 && strtotime( $time ) <= $end + 60;
	$referrer = '';
	foreach ( $st['refs'] as $time => $domain ) {
		if ( $in( $time ) ) {
			$referrer = $domain;
			break;
		}
	}
	$fields = array();
	foreach ( $st['touches'] as $time => $t ) {
		if ( $in( $time ) ) {
			$fields = $t;
			break;
		}
	}
	if ( $fields ) {
		$label = leadkit_source_label( array_combine( array_map( fn( $k ) => 'utm_' . $k, array_keys( $fields ) ), $fields ) );
	} else {
		$fields = leadkit_referrer_fields( $referrer );
		$label  = $referrer ? leadkit_source_label( array( 'referrer' => $referrer ) ) : '';
	}

	$items = array();
	foreach ( $views as $v ) {
		$items[] = array( strtotime( $v['time'] ), $v['path'] . ' ' . round( $v['ms'] / 1000 ) . 's ' . $v['scroll'] . '%', 0 );
	}
	$clicks = 0;
	$forms  = array();
	foreach ( $events as $e ) {
		$items[] = array( $e[0], $e[1], 1 );
		if ( 'click' === $e[2] ) {
			++$clicks;
		} elseif ( 'form' === $e[2] ) {
			$forms[] = $e[3];
		}
	}
	// By time; a page before what happened on it.
	usort( $items, fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[2] <=> $b[2] );

	return array(
		'start'    => $start,
		'end'      => $end,
		'first'    => (string) $views[0]['id'],
		'landing'  => (string) $views[0]['path'],
		'referrer' => $referrer,
		'fields'   => $fields,
		'label'    => $label,
		'pages'    => count( $views ),
		'ms'       => array_sum( array_column( $views, 'ms' ) ),
		'clicks'   => $clicks,
		'forms'    => array_values( array_unique( $forms ) ),
		'items'    => array_column( $items, 1 ),
	);
}

/**
 * Every visit, worked out: events placed in the visit where they happened.
 *
 * @param array[] $visits From leadkit_visitor_visits().
 * @param array   $st     State.
 * @return array[] From leadkit_visit_details(), oldest first.
 */
function leadkit_visits_details( $visits, $st ) {
	$events = array();
	foreach ( $st['clicks'] as $c ) {
		$events[] = array( strtotime( $c['time'] ), __( 'Donate click', 'leadkit' ), 'click', '' );
	}
	foreach ( $st['forms'] as $f ) {
		$name = array(
			'phone_click' => __( 'Tapped the phone number', 'leadkit' ),
			'email_click' => __( 'Tapped the email address', 'leadkit' ),
		)[ $f['form'] ] ?? '';
		$sent     = $f['form'] ?: __( 'a form', 'leadkit' );
		/* translators: %s: form name. */
		$events[] = array( strtotime( $f['time'] ), $name ?: sprintf( __( 'Sent %s', 'leadkit' ), $sent ), 'form', $name ?: $sent );
	}
	foreach ( array_keys( $st['returns'] ) as $time ) {
		$events[] = array( strtotime( $time ), __( 'Back from the donation form', 'leadkit' ), 'return', '' );
	}

	// Each event goes in the last visit that started before it.
	$starts = array_map( fn( $v ) => strtotime( $v[0]['time'] ), $visits );
	$extra  = array_fill( 0, count( $visits ), array() );
	foreach ( $events as $e ) {
		$i = 0;
		foreach ( $starts as $n => $s ) {
			if ( $s <= $e[0] + 60 ) {
				$i = $n;
			}
		}
		$extra[ $i ][] = $e;
	}

	$out = array();
	foreach ( $visits as $n => $views ) {
		$out[] = leadkit_visit_details( $views, $extra[ $n ], $st );
	}
	return $out;
}

/**
 * Page views as visits: a new visit starts when the visitor has been away
 * more than 30 minutes from the end of their time on the last page.
 *
 * @param array $views id => view.
 * @return array[] Visits, oldest first, each a list of views (with their id) in order.
 */
function leadkit_visitor_visits( $views ) {
	uasort( $views, fn( $a, $b ) => strcmp( $a['time'], $b['time'] ) );
	$visits = array();
	$until  = 0;
	foreach ( $views as $id => $v ) {
		$t = strtotime( $v['time'] );
		if ( ! $visits || $t - $until > LEADKIT_VISIT_GAP ) {
			$visits[] = array();
		}
		$visits[ count( $visits ) - 1 ][] = $v + array( 'id' => (string) $id );
		$until = max( $until, $t + (int) round( $v['ms'] / 1000 ) );
	}
	return $visits;
}

/**
 * The Journey log: one line per visit, newest first, in the visitor's time.
 *
 *   Oct 14, 2026 9:58pm — Email · spring-appeal · email-1 — 3 pages, 2m 5s:
 *   /hope/ 45s 80% > /donate/ 12s 30% > Donate click
 *
 * Each visit says where it came from — the campaign link it arrived on, or
 * the site that sent it. Form submissions, Donate clicks and returns from the
 * donation form sit in the visit where they happened. Kept within
 * monday.com's long-text limit by dropping the oldest visits, with a count of
 * what was left off.
 *
 * @param array[] $details From leadkit_visits_details().
 * @return string
 */
function leadkit_visitor_log( $details ) {
	$lines = array();
	foreach ( $details as $d ) {
		$lines[] = leadkit_vdate( 'M j, Y g:ia', $d['start'] ) . ' — '
			. ( $d['label'] ? $d['label'] . ' — ' : '' )
			/* translators: %d: number of pages. */
			. sprintf( _n( '%d page', '%d pages', $d['pages'], 'leadkit' ), $d['pages'] )
			. ', ' . leadkit_duration( $d['ms'] ) . ': '
			. implode( ' > ', $d['items'] );
	}

	/**
	 * The longest the Journey log may be, in characters.
	 *
	 * @param int $max Default 2000, monday.com's long-text limit.
	 */
	$max  = max( 200, (int) apply_filters( 'leadkit/visitor_log_max', LEADKIT_VISITOR_LOG_MAX ) );
	$note = 40; // Room kept for the "earlier visits" line.
	$all  = array_reverse( $lines );
	$out  = array();
	foreach ( $all as $line ) {
		if ( mb_strlen( implode( "\n", array_merge( $out, array( $line ) ) ) ) + $note > $max ) {
			break;
		}
		$out[] = $line;
	}
	if ( ! $out && $all ) {
		// One visit longer than the whole column: its start, cut.
		$out[] = mb_substr( $all[0], 0, $max - $note - 1 ) . '…';
	}
	$left = count( $all ) - count( $out );
	if ( $left ) {
		/* translators: %d: number of visits. */
		$out[] = sprintf( _n( '… and %d earlier visit', '… and %d earlier visits', $left, 'leadkit' ), $left );
	}
	return implode( "\n", $out );
}

/**
 * The row whose visitorid is this visitor.
 *
 * @param string $board  Board id.
 * @param string $col    visitorid column id.
 * @param string $visitor Visitor id.
 * @return string|null Item id; '' when there is none; null when monday.com could not be asked.
 */
function leadkit_visitor_find_row( $board, $col, $visitor ) {
	$data = leadkit_monday(
		'query ($b: ID!, $c: String!, $v: [String]!) { items_page_by_column_values(board_id: $b, limit: 1, columns: [{column_id: $c, column_values: $v}]) { items { id } } }',
		array( 'b' => (string) $board, 'c' => (string) $col, 'v' => array( $visitor ) )
	);
	if ( null === $data ) {
		return null;
	}
	return (string) ( $data['items_page_by_column_values']['items'][0]['id'] ?? '' );
}

/**
 * The rows for several visitors, in one request.
 *
 * @param string   $board    Board id.
 * @param string   $col      visitorid column id.
 * @param string[] $visitors Visitor ids.
 * @return array|null visitor id => item id, for those found; null when monday.com could not be asked.
 */
function leadkit_visitor_find_rows( $board, $col, $visitors ) {
	$data = leadkit_monday(
		'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 100, columns: [{column_id: $c, column_values: $v}]) { items { id column_values(ids: $r) { text } } } }',
		array( 'b' => (string) $board, 'c' => (string) $col, 'v' => array_values( $visitors ), 'r' => array( (string) $col ) )
	);
	if ( null === $data ) {
		return null;
	}
	$rows = array();
	foreach ( (array) ( $data['items_page_by_column_values']['items'] ?? array() ) as $it ) {
		$visitor = trim( (string) ( $it['column_values'][0]['text'] ?? '' ) );
		if ( '' !== $visitor && ! isset( $rows[ $visitor ] ) ) {
			$rows[ $visitor ] = (string) $it['id'];
		}
	}
	return $rows;
}

/**
 * Create the row, named by its visitor id.
 *
 * @param string $board   Board id.
 * @param string $visitor Visitor id.
 * @param array  $values  Column values.
 * @return string Item id, or ''.
 */
function leadkit_visitor_create( $board, $visitor, $values ) {
	$made = leadkit_monday(
		'mutation ($b: ID!, $n: String!, $v: JSON) { create_item(board_id: $b, item_name: $n, column_values: $v) { id } }',
		array( 'b' => (string) $board, 'n' => $visitor, 'v' => wp_json_encode( $values ) )
	);
	return (string) ( $made['create_item']['id'] ?? '' );
}

/**
 * @param string $board  Board id.
 * @param string $row    Item id.
 * @param array  $values Column values.
 * @return bool
 */
function leadkit_visitor_set( $board, $row, $values ) {
	return (bool) leadkit_monday(
		'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
		array( 'i' => (string) $row, 'b' => (string) $board, 'v' => wp_json_encode( $values ) )
	);
}

/**
 * The items a connect column on the row links to now.
 *
 * @param string $row Item id.
 * @param string $col Column id.
 * @return int[]|null Null when monday.com could not be asked.
 */
function leadkit_visitor_linked( $row, $col ) {
	$data = leadkit_monday(
		'query ($i: [ID!], $c: [String!]) { items(ids: $i) { column_values(ids: $c) { id ... on BoardRelationValue { linked_item_ids } } } }',
		array( 'i' => array( (string) $row ), 'c' => array( (string) $col ) )
	);
	if ( null === $data ) {
		return null;
	}
	return array_map( 'intval', (array) ( $data['items'][0]['column_values'][0]['linked_item_ids'] ?? array() ) );
}

// ---- the Visits board: one row per visit -------------------------------------------

const LEADKIT_VISITS_PER_WRITE   = 40;
const LEADKIT_VISITS_PER_REQUEST = 20;

/**
 * The Visits board id, if set. It needs the Visitors board too: each visit
 * row is connected to its visitor's row there.
 *
 * @return string
 */
function leadkit_monday_visits_board() {
	return leadkit_monday_visitors_board() ? preg_replace( '/\D/', '', (string) ( leadkit_options()['monday_visits_board'] ?? '' ) ) : '';
}

/**
 * The Visits board's columns, found by title or created.
 *
 * @param string $board Visits board id.
 * @return array|null
 */
function leadkit_visit_columns( $board ) {
	return leadkit_monday_ensure_columns(
		$board,
		array(
			'key'      => array( 'visitid', 'text' ),
			'visitor'  => array( 'visitorid', 'text' ),
			'link'     => array( 'Visitor', 'board_relation', array( 'boardIds' => array( (int) leadkit_monday_visitors_board() ), 'allowMultipleItems' => false ) ),
			'started'  => array( 'Started', 'date' ),
			'referrer' => array( 'Referrer', 'text' ),
			'source'   => array( 'Source', 'text' ),
			'landing'  => array( 'Landing page', 'text' ),
			'pages'    => array( 'Pages', 'numbers' ),
			'secs'     => array( 'Seconds on site', 'numbers' ),
			'clicks'   => array( 'Donate clicks', 'numbers' ),
			'forms'    => array( 'Forms', 'text' ),
			'journey'  => array( 'Journey', 'long_text' ),
			'open'     => array( 'Open person', 'link' ),
		),
		'visits'
	);
}

/**
 * A visit's key: the browser, plus the visit's first page view, which never
 * changes as the visit goes on.
 *
 * @param string $visitor  Visitor id.
 * @param string $first_id Id of the visit's first page view.
 * @return string
 */
function leadkit_visit_key( $visitor, $first_id ) {
	return $visitor . '-' . substr( md5( (string) $first_id ), 0, 10 );
}

/**
 * "Oct 14, 2026 9:58am · Email · spring-appeal · email-1", or "· Direct".
 *
 * @param array $d From leadkit_visit_details().
 * @return string
 */
function leadkit_visit_name( $d ) {
	return mb_substr( leadkit_vdate( 'M j, Y g:ia', $d['start'] ) . ' · ' . ( $d['label'] ?: __( 'Direct', 'leadkit' ) ), 0, 255 );
}

/**
 * A visit's column values.
 *
 * @param string $visitor Visitor id.
 * @param string $row     The visitor's row on the Visitors board.
 * @param string $key     Visit key.
 * @param array  $d       From leadkit_visit_details().
 * @param array  $cols    Column ids.
 * @param array  $brief   The browser's person { name, url }, if known.
 * @return array
 */
function leadkit_visit_values( $visitor, $row, $key, $d, $cols, $brief = array() ) {
	$values = array(
		$cols['key']      => $key,
		$cols['visitor']  => $visitor,
		$cols['link']     => array( 'item_ids' => array( (int) $row ) ),
		// monday stores date columns in UTC and shows them in each viewer's zone.
		$cols['started']  => array( 'date' => gmdate( 'Y-m-d', $d['start'] ), 'time' => gmdate( 'H:i:s', $d['start'] ) ),
		$cols['referrer'] => $d['referrer'],
		$cols['source']   => leadkit_pack_source( $d['fields'] ),
		$cols['landing']  => mb_substr( $d['landing'], 0, 2000 ),
		$cols['pages']    => (string) $d['pages'],
		$cols['secs']     => (string) (int) round( $d['ms'] / 1000 ),
		$cols['clicks']   => (string) $d['clicks'],
		$cols['forms']    => mb_substr( implode( ', ', $d['forms'] ), 0, 2000 ),
		$cols['journey']  => array( 'text' => mb_substr( implode( ' > ', $d['items'] ), 0, LEADKIT_VISITOR_LOG_MAX ) ),
	);
	if ( ! empty( $brief['url'] ) ) {
		$values[ $cols['open'] ] = leadkit_person_link( $brief );
	}
	return $values;
}

/**
 * Write the visits that are new or have changed, newest first.
 *
 * At most LEADKIT_VISITS_PER_WRITE per call, LEADKIT_VISITS_PER_REQUEST to a
 * request: a first Donate click can bring a long history with it, and what
 * does not fit is written on the visitor's next post. A visit this site has
 * no row for is looked up by visitid first, so lost local state cannot
 * double a row.
 *
 * @param string  $visitor Visitor id.
 * @param string  $row     The visitor's row on the Visitors board.
 * @param array[] $details From leadkit_visits_details().
 * @param array   $st      State (by reference: visits).
 * @return int Rows written.
 */
function leadkit_visits_write( $visitor, $row, $details, &$st ) {
	$board = leadkit_monday_visits_board();
	if ( ! $board || ! $row || ! $details ) {
		return 0;
	}
	$cols = leadkit_visit_columns( $board );
	if ( ! $cols ) {
		return 0;
	}

	/**
	 * How many visit rows one write may create or change. Default 40.
	 *
	 * @param int $max Rows.
	 */
	$max  = max( 1, (int) apply_filters( 'leadkit/visits_per_write', LEADKIT_VISITS_PER_WRITE ) );
	$todo = array();
	foreach ( array_reverse( $details ) as $d ) {
		$key    = leadkit_visit_key( $visitor, $d['first'] );
		$values = leadkit_visit_values( $visitor, $row, $key, $d, $cols, (array) ( $st['brief'] ?? array() ) );
		$hash   = md5( (string) wp_json_encode( $values ) );
		if ( ( $st['visits'][ $key ]['hash'] ?? '' ) === $hash ) {
			continue;
		}
		$todo[ $key ] = array( 'values' => $values, 'hash' => $hash, 'name' => leadkit_visit_name( $d ) );
		if ( count( $todo ) >= $max ) {
			break;
		}
	}
	if ( ! $todo ) {
		return 0;
	}

	$unknown = array_values( array_filter( array_keys( $todo ), fn( $k ) => empty( $st['visits'][ $k ]['row'] ) ) );
	if ( $unknown ) {
		$found = leadkit_monday(
			'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 100, columns: [{column_id: $c, column_values: $v}]) { items { id column_values(ids: $r) { text } } } }',
			array( 'b' => (string) $board, 'c' => $cols['key'], 'v' => $unknown, 'r' => array( $cols['key'] ) )
		);
		if ( null === $found ) {
			return 0; // Could not ask: creating now could double rows.
		}
		foreach ( (array) ( $found['items_page_by_column_values']['items'] ?? array() ) as $it ) {
			$k = (string) ( $it['column_values'][0]['text'] ?? '' );
			if ( isset( $todo[ $k ] ) ) {
				$st['visits'][ $k ] = array( 'row' => (string) $it['id'], 'hash' => '' );
			}
		}
	}

	$written = 0;
	foreach ( array_chunk( $todo, LEADKIT_VISITS_PER_REQUEST, true ) as $chunk ) {
		$decl  = array( '$b: ID!' );
		$body  = array();
		$vars  = array( 'b' => (string) $board );
		$alias = array();
		$i     = 0;
		foreach ( $chunk as $key => $w ) {
			$decl[]          = '$v' . $i . ': JSON!';
			$vars[ 'v' . $i ] = wp_json_encode( $w['values'] );
			$known           = (string) ( $st['visits'][ $key ]['row'] ?? '' );
			if ( $known ) {
				$decl[]           = '$i' . $i . ': ID!';
				$vars[ 'i' . $i ] = $known;
				$body[]           = 'u' . $i . ': change_multiple_column_values(item_id: $i' . $i . ', board_id: $b, column_values: $v' . $i . ') { id }';
				$alias[ $key ]    = 'u' . $i;
			} else {
				$decl[]           = '$n' . $i . ': String!';
				$vars[ 'n' . $i ] = $w['name'];
				$body[]           = 'c' . $i . ': create_item(board_id: $b, item_name: $n' . $i . ', column_values: $v' . $i . ') { id }';
				$alias[ $key ]    = 'c' . $i;
			}
			++$i;
		}
		$data = leadkit_monday( 'mutation (' . implode( ', ', $decl ) . ') { ' . implode( ' ', $body ) . ' }', $vars, true );
		if ( null === $data ) {
			break; // monday.com unreachable: the rest wait for the next post.
		}
		foreach ( $alias as $key => $a ) {
			$id = (string) ( $data[ $a ]['id'] ?? '' );
			if ( $id ) {
				$st['visits'][ $key ] = array( 'row' => $id, 'hash' => $chunk[ $key ]['hash'] );
				++$written;
			} elseif ( 'u' === $a[0] ) {
				// The row is gone (deleted on monday.com): found or made again next time.
				unset( $st['visits'][ $key ] );
			}
		}
	}
	if ( count( $st['visits'] ) > LEADKIT_VISITOR_VIEWS ) {
		$st['visits'] = array_slice( $st['visits'], -LEADKIT_VISITOR_VIEWS, null, true );
	}
	return $written;
}
