<?php
/**
 * A readable summary of a submission's tracking, for monday.com.
 *
 * The raw tracking columns are for reports; nobody reads
 * "/volunteer/ 184s 0% > /volunteer/ 48s 29% > …" in a table cell. So each
 * item created by [leadkit_monday_form] also gets:
 *
 *   - an update on the item, "How they got here": the campaign, how engaged
 *     they were, and each page once, by name, with its time and how far they
 *     read;
 *   - columns that sort and filter: Engagement (Hot / Warm / Browsing),
 *     Source, Minutes on site, Pages — and Journey, the same page list one per
 *     line. Created on the board the first time they are needed.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "path 12s 40% > path 3s 0%" back into rows.
 *
 * @param string $detail page_detail.
 * @return array[] { path, secs, scroll }
 */
function leadkit_parse_page_detail( $detail ) {
	$rows = array();
	foreach ( explode( ' > ', (string) $detail ) as $part ) {
		// page_log entries lead with the arrival time; page_detail ones do not.
		if ( preg_match( '#^(?:(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ)\s+)?(\S+)\s+(\d+)s\s+(\d+)%$#', trim( $part ), $m ) ) {
			$rows[] = array(
				'at'     => $m[1] ? strtotime( $m[1] ) : 0,
				'path'   => $m[2],
				'secs'   => (int) $m[3],
				'scroll' => (int) $m[4],
			);
		}
	}
	return $rows;
}

/**
 * "4m 12s", "38s", "1h 5m".
 *
 * @param int $secs Seconds.
 * @return string
 */
function leadkit_secs_label( $secs ) {
	$secs = max( 0, (int) $secs );
	if ( $secs < 60 ) {
		return $secs . 's';
	}
	if ( $secs < 3600 ) {
		return intdiv( $secs, 60 ) . 'm' . ( $secs % 60 ? ' ' . ( $secs % 60 ) . 's' : '' );
	}
	return intdiv( $secs, 3600 ) . 'h ' . intdiv( $secs % 3600, 60 ) . 'm';
}

/**
 * "Email · light-the-path · email-1" from the UTM fields, or where they came from.
 *
 * @param array $t Tracking values.
 * @return string
 */
function leadkit_source_label( $t ) {
	$media = array(
		'email'       => 'Email',
		'social'      => 'Social',
		'paid-social' => 'Paid social',
		'cpc'         => 'Paid search',
		'print'       => 'Print',
		'referral'    => 'Referral',
		'organic'     => 'Organic search',
	);
	$medium = strtolower( (string) ( $t['utm_medium'] ?? '' ) );
	if ( $medium || ! empty( $t['utm_campaign'] ) || ! empty( $t['utm_source'] ) ) {
		$bits = array_filter(
			array(
				$media[ $medium ] ?? ( $medium ? ucfirst( $medium ) : '' ),
				$t['utm_source'] ?? '',
				$t['utm_campaign'] ?? '',
				$t['utm_content'] ?? '',
			),
			'strlen'
		);
		// Source is noise next to "Email" when it is the email tool itself.
		if ( 'email' === $medium ) {
			$bits = array_values( array_diff( $bits, array( 'mailchimp' ) ) );
		}
		return implode( ' · ', $bits );
	}
	$ref = (string) ( $t['referrer'] ?? '' );
	if ( $ref ) {
		$host   = preg_replace( '/^www\./', '', $ref );
		$search = (bool) preg_match( '/(^|\.)(google|bing|duckduckgo|yahoo|ecosia|baidu|yandex)\./', $host . '.' );
		return ( $search ? 'Organic search · ' : 'Referral · ' ) . $host;
	}
	return 'Direct';
}

/**
 * A packed campaign ("email__mailchimp__light-the-path__email-1") as words.
 *
 * @param string $packed Packed touch.
 * @return string
 */
function leadkit_packed_label( $packed ) {
	$p = explode( '__', (string) $packed );
	return leadkit_source_label(
		array(
			'utm_medium'   => 'none' === ( $p[0] ?? '' ) ? '' : ( $p[0] ?? '' ),
			'utm_source'   => 'none' === ( $p[1] ?? '' ) ? '' : ( $p[1] ?? '' ),
			'utm_campaign' => 'none' === ( $p[2] ?? '' ) ? '' : ( $p[2] ?? '' ),
			'utm_content'  => 'none' === ( $p[3] ?? '' ) ? '' : ( $p[3] ?? '' ),
		)
	);
}

/**
 * Everything the summary needs, worked out once.
 *
 * @param array $t         Tracking values (as sent with the form).
 * @param array $analytics Decoded analyticsData, if any.
 * @return array|null Null when there is no journey to describe.
 */
function leadkit_journey_summary( $t, $analytics = array() ) {
	$rows = leadkit_parse_page_detail( ! empty( $t['page_log'] ) ? $t['page_log'] : ( $t['page_detail'] ?? '' ) );
	if ( ! $rows ) {
		return null;
	}

	/*
	 * In the order they happened, each with the time they arrived. A page
	 * reloaded straight after itself is one stop (first arrival, times added),
	 * so a refresh does not read as eight visits; going back to it later is a
	 * new stop, because when they came back is the point.
	 */
	$pages = array();
	foreach ( $rows as $r ) {
		$k    = '/' . trim( $r['path'], '/' ) . '/';
		$last = count( $pages ) - 1;
		if ( $last >= 0 && $pages[ $last ]['key'] === $k ) {
			$pages[ $last ]['secs']  += $r['secs'];
			$pages[ $last ]['scroll'] = max( $pages[ $last ]['scroll'], $r['scroll'] );
			++$pages[ $last ]['visits'];
			continue;
		}
		$pages[] = array(
			'key'    => $k,
			'path'   => $r['path'],
			'label'  => leadkit_page_label( $r['path'] ),
			'at'     => $r['at'],
			'secs'   => $r['secs'],
			'scroll' => $r['scroll'],
			'visits' => 1,
		);
	}
	$total    = array_sum( array_column( $pages, 'secs' ) );
	$distinct = count( array_unique( array_column( $pages, 'key' ) ) );

	$tapped = false;
	foreach ( (array) ( $analytics['events'] ?? array() ) as $e ) {
		if ( in_array( $e['type'] ?? '', array( 'phone_click', 'email_click' ), true ) ) {
			$tapped = true;
		}
	}
	$temp  = leadkit_temperature(
		array(
			'total_ms' => $total * 1000,
			'photos'   => (int) ( $t['photos_viewed'] ?? 0 ),
			'tapped'   => $tapped,
			'returned' => false,
			'pages'    => $distinct,
		)
	);
	$level = array( 'Hot lead' => 'Hot', 'Warm' => 'Warm' )[ $temp['label'] ] ?? 'Browsing';

	$source = leadkit_source_label( $t );
	$first  = ! empty( $t['first_touch'] ) ? leadkit_packed_label( $t['first_touch'] ) : '';

	$lines = array();
	foreach ( $pages as $p ) {
		$lines[] = ( $p['at'] ? wp_date( 'M j, g:i:s a T', $p['at'] ) . ' — ' : '' ) . leadkit_stop_label( $p );
	}

	return array(
		'level'   => $level,
		'source'  => $source,
		'first'   => $first && $first !== $source ? $first : '',
		'minutes' => round( $total / 60, 1 ),
		'pages'   => $distinct,
		'device'  => (string) ( $t['device'] ?? '' ),
		'photos'  => (int) ( $t['photos_viewed'] ?? 0 ),
		'list'    => $pages,
		'text'    => implode( "\n", $lines ),
		'total'   => $total,
	);
}

/**
 * "Donate (/donate/) — 23s, read 100%"
 *
 * @param array $p One stop.
 * @return string
 */
function leadkit_stop_label( $p ) {
	return $p['label'] . ' (' . $p['path'] . ') — ' . leadkit_secs_label( $p['secs'] )
		. ( $p['visits'] > 1 ? ' over ' . $p['visits'] . ' loads' : '' )
		. ', read ' . ( $p['visits'] > 1 ? 'up to ' : '' ) . $p['scroll'] . '%';
}

/**
 * The update posted on the item.
 *
 * @param array $s From leadkit_journey_summary().
 * @return string HTML (monday updates take simple markup).
 */
function leadkit_journey_update_html( $s ) {
	$html  = '<p><strong>How they got here</strong> — ' . esc_html( $s['source'] )
		. ( $s['first'] ? ' <em>(first came from ' . esc_html( $s['first'] ) . ')</em>' : '' ) . '</p>';
	$html .= '<p><strong>Engagement: ' . esc_html( $s['level'] ) . '</strong> — '
		. esc_html( leadkit_secs_label( $s['total'] ) ) . ' on the site, '
		/* translators: %d: number of pages. */
		. esc_html( sprintf( _n( '%d page', '%d pages', $s['pages'], 'leadkit' ), $s['pages'] ) )
		. ( $s['photos'] ? ', ' . (int) $s['photos'] . ' photos opened' : '' )
		. ( $s['device'] ? ', ' . esc_html( $s['device'] ) : '' ) . '</p>';

	// Grouped by day, each stop with the time they arrived.
	$day = '';
	$open = false;
	foreach ( $s['list'] as $p ) {
		$d = $p['at'] ? wp_date( 'l, M j, Y', $p['at'] ) : __( 'Pages, in order', 'leadkit' );
		if ( $d !== $day ) {
			$html .= ( $open ? '</ul>' : '' ) . '<p><strong>' . esc_html( $d ) . '</strong></p><ul>';
			$day   = $d;
			$open  = true;
		}
		$html .= '<li>' . ( $p['at'] ? esc_html( wp_date( 'g:i:s a', $p['at'] ) ) . ' — ' : '' )
			. '<strong>' . esc_html( $p['label'] ) . '</strong> ' . esc_html( $p['path'] ) . ' — '
			. esc_html( leadkit_secs_label( $p['secs'] ) )
			. ( $p['visits'] > 1 ? ' over ' . (int) $p['visits'] . ' loads' : '' )
			. ', read ' . ( $p['visits'] > 1 ? 'up to ' : '' ) . (int) $p['scroll'] . '%</li>';
	}
	$timed = (bool) array_filter( array_column( $s['list'], 'at' ) );
	return $html . ( $open ? '</ul>' : '' ) . '<p><em>' . ( $timed ? 'Times are ' . esc_html( wp_date( 'T' ) ) . ', the site\'s time zone. ' : '' ) . '— LeadKit</em></p>';
}

/**
 * The summary columns on a board, created the first time. Cached a day.
 *
 * @param string $board Board id.
 * @return array|null { level, source, minutes, pages, journey } => column id.
 */
function leadkit_monday_summary_columns( $board ) {
	$key    = 'leadkit_monday_scols_' . $board;
	$cached = get_transient( $key );
	if ( is_array( $cached ) && count( $cached ) === 5 ) {
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
		'level'   => array( 'Engagement', 'status' ),
		'source'  => array( 'Source', 'text' ),
		'minutes' => array( 'Minutes on site', 'numbers' ),
		'pages'   => array( 'Pages', 'numbers' ),
		'journey' => array( 'Journey', 'long_text' ),
	);
	$cols = array();
	foreach ( $want as $slot => list( $title, $type ) ) {
		$id = $by_title[ strtolower( $title ) ] ?? '';
		if ( ! $id ) {
			$vars = array( 'b' => (string) $board, 't' => $title );
			$args = '';
			if ( 'status' === $type ) {
				$args         = ', defaults: $d';
				$vars['d']    = wp_json_encode(
					array(
						'labels' => array(
							array( 'label' => 'Hot', 'color' => 'done_green', 'index' => 0 ),
							array( 'label' => 'Warm', 'color' => 'egg_yolk', 'index' => 1 ),
							array( 'label' => 'Browsing', 'color' => 'american_gray', 'index' => 2 ),
						),
					)
				);
			}
			$made = leadkit_monday(
				'mutation ($b: ID!, $t: String!' . ( $args ? ', $d: JSON' : '' ) . ') { create_column(board_id: $b, title: $t, column_type: ' . $type . $args . ') { id } }',
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
 * Column values for the summary columns.
 *
 * @param array $s    Summary.
 * @param array $cols Column ids.
 * @return array
 */
function leadkit_summary_values( $s, $cols ) {
	return array(
		$cols['level']   => array( 'label' => $s['level'] ),
		$cols['source']  => mb_substr( $s['source'], 0, 2000 ),
		$cols['minutes'] => (string) $s['minutes'],
		$cols['pages']   => (string) $s['pages'],
		$cols['journey'] => array( 'text' => $s['text'] ),
	);
}
