<?php
/**
 * Email clicks: which officer email a donor clicked.
 *
 * An officer's draft script puts the email's own id on its link as utm_term,
 * "e" and when the draft was made (e20261026-104312), and saves the same id on
 * the "Emailed" subitem it adds under the donor on the People board once the
 * email is sent. A browser that arrives through such a link carries the id on
 * its campaign touch; LeadKit finds the subitem whose "Email ID" is that id and
 * sets its "Clicked" date, the first time only. Where two subitems share an id
 * (two drafts made in the same second), the one under the donor the browser's
 * donor key names wins.
 *
 * The subitem may not be there yet: the script notices a sent draft within
 * about five minutes. An id that finds nothing waits in the
 * leadkit_email_clicks option and is tried again at each background run, for
 * three days.
 *
 * Columns are found by title on the People board's subitems board and never
 * created; the leadkit/email_click_columns filter changes the titles. What
 * happened is noted with the donor keys' notes.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_EMAIL_CLICK_COLUMNS = array(
	'id'      => 'Email ID', // The email's id, as on its link.
	'clicked' => 'Clicked',  // Set to the day of the first click.
);
const LEADKIT_EMAIL_ID_RE      = '/^e\d{8}-\d{6}$/';
const LEADKIT_EMAIL_CLICKS     = 'leadkit_email_clicks';
const LEADKIT_EMAIL_CLICKS_MAX = 200;
const LEADKIT_EMAIL_CLICKS_RUN = 25;

/**
 * The subitem column titles email clicks use.
 *
 * @return array { id, clicked }
 */
function leadkit_email_click_columns() {
	/**
	 * The People subitem columns email clicks use, by title: 'id' holds the
	 * email's id, 'clicked' the date set on the first click.
	 *
	 * @param array $titles { id, clicked }.
	 */
	return (array) apply_filters( 'leadkit/email_click_columns', LEADKIT_EMAIL_CLICK_COLUMNS ) + LEADKIT_EMAIL_CLICK_COLUMNS;
}

/**
 * Queue the email ids on a browser's campaign touches that are new to it.
 *
 * @param string $visitor Visitor id.
 * @param array  $st      Visitor state (by reference: eids).
 * @return int Ids queued.
 */
function leadkit_email_clicks_queue( $visitor, &$st ) {
	$seen = (array) ( $st['eids'] ?? array() );
	$new  = array();
	foreach ( (array) ( $st['touches'] ?? array() ) as $time => $t ) {
		$id = strtolower( trim( (string) ( $t['term'] ?? '' ) ) );
		if ( preg_match( LEADKIT_EMAIL_ID_RE, $id ) && ! in_array( $id, $seen, true ) && ! isset( $new[ $id ] ) ) {
			$new[ $id ] = (string) $time;
		}
	}
	if ( ! $new ) {
		return 0;
	}
	$all = get_option( LEADKIT_EMAIL_CLICKS, array() );
	$all = is_array( $all ) ? $all : array();
	foreach ( $new as $id => $time ) {
		$all[ $visitor . ' ' . $id ] = array(
			'visitor' => $visitor,
			'id'      => $id,
			'day'     => leadkit_vdate( 'Y-m-d', strtotime( $time ) ?: time() ), // The click's day, where the donor is.
			'person'  => (string) ( $st['dk_person'] ?? '' ),
			'at'      => time(),
		);
		$seen[] = $id;
	}
	$st['eids'] = array_slice( $seen, -50 );
	update_option( LEADKIT_EMAIL_CLICKS, array_slice( $all, -LEADKIT_EMAIL_CLICKS_MAX, null, true ), false );
	return count( $new );
}

/**
 * Set Clicked on the subitems the waiting ids name.
 *
 * @return int Subitems marked.
 */
function leadkit_email_clicks_run() {
	$all = get_option( LEADKIT_EMAIL_CLICKS, array() );
	if ( ! is_array( $all ) || ! $all ) {
		return 0;
	}
	$sub = leadkit_people_subitems_board();
	if ( ! $sub ) {
		return 0; // None, or monday.com could not be asked: next run.
	}
	$cols = leadkit_monday_board_columns( $sub );
	if ( null === $cols ) {
		return 0;
	}
	$titles  = leadkit_email_click_columns();
	$id_col  = $cols[ strtolower( $titles['id'] ) ]['id'] ?? '';
	$clicked = $cols[ strtolower( $titles['clicked'] ) ] ?? null;
	$ck_col  = $clicked && 'date' === $clicked['type'] ? $clicked['id'] : '';

	$done = 0;
	$gone = array();
	foreach ( array_slice( $all, 0, LEADKIT_EMAIL_CLICKS_RUN, true ) as $k => $p ) {
		$old = time() - (int) ( $p['at'] ?? 0 ) > 3 * DAY_IN_SECONDS;
		if ( ! $id_col || ! $ck_col ) {
			if ( $old ) {
				leadkit_dk_note( (string) $p['visitor'], (string) $p['id'], sprintf( 'email click not recorded: no "%s" and "%s" date columns on the People subitems', $titles['id'], $titles['clicked'] ) );
				$gone[] = $k;
			}
			continue;
		}
		$data = leadkit_monday(
			'query ($b: ID!, $c: String!, $v: [String]!, $r: [String!]) { items_page_by_column_values(board_id: $b, limit: 10, columns: [{column_id: $c, column_values: $v}]) { items { id parent_item { id } column_values(ids: $r) { id text } } } }',
			array( 'b' => (string) $sub, 'c' => $id_col, 'v' => array( (string) $p['id'] ), 'r' => array( $id_col, $ck_col ) )
		);
		if ( null === $data ) {
			continue;
		}
		$text = function ( $it, $col ) {
			foreach ( (array) ( $it['column_values'] ?? array() ) as $cv ) {
				if ( (string) ( $cv['id'] ?? '' ) === $col ) {
					return trim( (string) ( $cv['text'] ?? '' ) );
				}
			}
			return '';
		};
		$hits = array_values( array_filter( (array) ( $data['items_page_by_column_values']['items'] ?? array() ), fn( $it ) => strtolower( $text( $it, $id_col ) ) === $p['id'] ) );
		if ( count( $hits ) > 1 && ! empty( $p['person'] ) ) {
			$mine = array_values( array_filter( $hits, fn( $it ) => (string) ( $it['parent_item']['id'] ?? '' ) === (string) $p['person'] ) );
			$hits = $mine ?: $hits;
		}
		if ( ! $hits ) {
			if ( $old ) {
				leadkit_dk_note( (string) $p['visitor'], (string) $p['id'], 'email click not recorded: no Emailed subitem has this email id after three days' );
				$gone[] = $k;
			}
			continue; // The script may not have seen the send yet.
		}
		if ( count( $hits ) > 1 ) {
			leadkit_dk_note( (string) $p['visitor'], (string) $p['id'], sprintf( 'email click not recorded: %d Emailed subitems have this email id', count( $hits ) ) );
			$gone[] = $k;
			continue;
		}
		$hit = $hits[0];
		if ( '' === $text( $hit, $ck_col ) ) {
			if ( ! leadkit_visitor_set( $sub, (string) $hit['id'], array( $ck_col => array( 'date' => (string) $p['day'] ) ) ) ) {
				continue;
			}
			leadkit_dk_note( (string) $p['visitor'], (string) $p['id'], sprintf( '"%s" set on Emailed subitem %s', $titles['clicked'], $hit['id'] ) );
			++$done;
		}
		$gone[] = $k;
	}
	if ( $gone ) {
		// Read again: an id may have been queued meanwhile.
		$now = get_option( LEADKIT_EMAIL_CLICKS, array() );
		$now = is_array( $now ) ? $now : array();
		foreach ( $gone as $k ) {
			unset( $now[ $k ] );
		}
		update_option( LEADKIT_EMAIL_CLICKS, $now, false );
	}
	return $done;
}

/**
 * The People board's subitems board, from its Subitems column. Cached a day.
 *
 * @return string|null Board id; '' when there is none; null when monday.com could not be asked.
 */
function leadkit_people_subitems_board() {
	$people = leadkit_monday_people_board();
	if ( ! $people ) {
		return '';
	}
	$key    = leadkit_monday_columns_key( 'subitems', $people );
	$cached = get_transient( $key );
	if ( is_string( $cached ) ) {
		return $cached;
	}
	$data = leadkit_monday( 'query ($b: [ID!]) { boards(ids: $b) { columns(types: [subtasks]) { settings_str } } }', array( 'b' => array( $people ) ) );
	if ( null === $data ) {
		return null;
	}
	$settings = json_decode( (string) ( $data['boards'][0]['columns'][0]['settings_str'] ?? '' ), true );
	$id       = preg_replace( '/\D/', '', (string) ( $settings['boardIds'][0] ?? '' ) );
	set_transient( $key, $id, DAY_IN_SECONDS );
	return $id;
}
