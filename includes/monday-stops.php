<?php
/**
 * Each page of a journey as its own subitem, for monday.com.
 *
 * The update and the Journey column read well, but they are text: nothing in
 * monday can add up "time on the Volunteer page" across everyone. So each stop
 * also becomes a subitem of the submission, in order, with columns that sort,
 * filter and chart:
 *
 *   Step · Page (path) · Arrived (date and time) · Seconds on page ·
 *   Time on page ("4m 12s") · Read % · Loads · Screenshot
 *
 * The Screenshot is the page itself — captured once per page and reused for
 * every visitor — with a gauge beside it filled to how far this person read.
 *
 * All the subitems for a submission go in one or two API requests (aliased
 * mutations), and the work runs after the visitor has been answered, so the
 * form never waits on it.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_STOPS_PER_REQUEST = 20;
const LEADKIT_STOPS_MAX         = 60;
const LEADKIT_SHOTS_MAX         = 20;
const LEADKIT_SHOT_TTL          = 7 * DAY_IN_SECONDS;

/**
 * Queue the subitems for after the response is sent.
 *
 * @param string $item_id Parent item.
 * @param string $board   Parent board.
 * @param array  $summary From leadkit_journey_summary().
 */
function leadkit_monday_queue_stops( $item_id, $board, $summary ) {
	/**
	 * Whether submissions get a subitem per page. Default true.
	 *
	 * @param bool   $on    On.
	 * @param string $board Board id.
	 */
	if ( ! $item_id || empty( $summary['list'] ) || ! apply_filters( 'leadkit/monday_stops', true, $board ) ) {
		return;
	}
	$stops = array_slice( $summary['list'], 0, LEADKIT_STOPS_MAX );

	add_action(
		'shutdown',
		function () use ( $item_id, $board, $stops ) {
			// Answer the visitor first; the rest happens on its own time.
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			leadkit_monday_add_stops( $item_id, $board, $stops );
		},
		100
	);
}

/**
 * The subitems board under a board, from its Subitems column. Cached a day.
 *
 * @param string $board Parent board.
 * @return string '' when the board has no subitems yet.
 */
function leadkit_monday_subitems_board( $board ) {
	$key    = 'leadkit_msub_' . $board;
	$cached = get_transient( $key );
	if ( $cached ) {
		return (string) $cached;
	}
	$data = leadkit_monday( 'query ($b: [ID!]) { boards(ids: $b) { columns(types: [subtasks]) { settings } } }', array( 'b' => array( (string) $board ) ) );
	foreach ( (array) ( $data['boards'][0]['columns'] ?? array() ) as $c ) {
		$s   = is_array( $c['settings'] ?? null ) ? $c['settings'] : json_decode( (string) ( $c['settings'] ?? '' ), true );
		$sub = (string) ( $s['boardIds'][0] ?? '' );
		if ( $sub ) {
			set_transient( $key, $sub, DAY_IN_SECONDS );
			return $sub;
		}
	}
	return '';
}

/**
 * The stop columns on the subitems board, created the first time.
 *
 * @param string $sub Subitems board.
 * @return array|null
 */
function leadkit_monday_stop_columns( $sub ) {
	return leadkit_monday_ensure_columns(
		$sub,
		array(
			'step'    => array( 'Step', 'numbers' ),
			'path'    => array( 'Page', 'text' ),
			'arrived' => array( 'Arrived', 'date' ),
			'secs'    => array( 'Seconds on page', 'numbers' ),
			'time'    => array( 'Time on page', 'text' ),
			'scroll'  => array( 'Read %', 'numbers' ),
			'loads'   => array( 'Loads', 'numbers' ),
			'shot'    => array( 'Screenshot', 'file' ),
		),
		'stops'
	);
}

/**
 * "3 · Volunteer Inquiry"
 *
 * @param int   $n Step number.
 * @param array $p Stop.
 * @return string
 */
function leadkit_stop_name( $n, $p ) {
	return mb_substr( $n . ' · ' . $p['label'], 0, 255 );
}

/**
 * Column values for one stop.
 *
 * @param int   $n    Step number.
 * @param array $p    Stop.
 * @param array $cols Column ids.
 * @return array
 */
function leadkit_stop_values( $n, $p, $cols ) {
	$v = array(
		$cols['step']   => (string) $n,
		$cols['path']   => mb_substr( (string) $p['path'], 0, 2000 ),
		$cols['secs']   => (string) (int) $p['secs'],
		$cols['time']   => leadkit_secs_label( $p['secs'] ),
		$cols['scroll'] => (string) (int) $p['scroll'],
		$cols['loads']  => (string) (int) $p['visits'],
	);
	if ( ! empty( $p['at'] ) ) {
		// monday stores date columns in UTC and shows them in each viewer's zone.
		$v[ $cols['arrived'] ] = array(
			'date' => gmdate( 'Y-m-d', $p['at'] ),
			'time' => gmdate( 'H:i:s', $p['at'] ),
		);
	}
	return $v;
}

/**
 * Create the subitems, then their screenshots.
 *
 * @param string $item_id Parent item.
 * @param string $board   Parent board.
 * @param array  $stops   Stops, in order.
 * @return string[] Subitem ids by stop index.
 */
function leadkit_monday_add_stops( $item_id, $board, $stops ) {
	$ids   = array();
	$first = 0;
	$sub   = leadkit_monday_subitems_board( $board );

	// A board with no subitems yet gets its subitems board from the first one.
	if ( ! $sub ) {
		$made = leadkit_monday(
			'mutation ($p: ID!, $n: String!) { create_subitem(parent_item_id: $p, item_name: $n) { id board { id } } }',
			array( 'p' => (string) $item_id, 'n' => leadkit_stop_name( 1, $stops[0] ) )
		);
		$sub = (string) ( $made['create_subitem']['board']['id'] ?? '' );
		if ( ! $sub ) {
			return array();
		}
		set_transient( 'leadkit_msub_' . $board, $sub, DAY_IN_SECONDS );
		$ids[0] = (string) $made['create_subitem']['id'];
		$first  = 1;
	}

	$cols = leadkit_monday_stop_columns( $sub );
	if ( ! $cols ) {
		return $ids;
	}
	if ( isset( $ids[0] ) ) {
		leadkit_monday(
			'mutation ($i: ID!, $b: ID!, $v: JSON!) { change_multiple_column_values(item_id: $i, board_id: $b, column_values: $v) { id } }',
			array( 'i' => $ids[0], 'b' => $sub, 'v' => wp_json_encode( leadkit_stop_values( 1, $stops[0], $cols ) ) )
		);
	}

	// The rest in batches: one request creates up to LEADKIT_STOPS_PER_REQUEST.
	$rest = array_slice( $stops, $first, null, true );
	foreach ( array_chunk( $rest, LEADKIT_STOPS_PER_REQUEST, true ) as $chunk ) {
		$decl  = array( '$p: ID!' );
		$body  = array();
		$vars  = array( 'p' => (string) $item_id );
		foreach ( $chunk as $i => $p ) {
			$decl[]          = '$n' . $i . ': String!';
			$decl[]          = '$v' . $i . ': JSON';
			$body[]          = 's' . $i . ': create_subitem(parent_item_id: $p, item_name: $n' . $i . ', column_values: $v' . $i . ', create_labels_if_missing: false) { id }';
			$vars[ 'n' . $i ] = leadkit_stop_name( $i + 1, $p );
			$vars[ 'v' . $i ] = wp_json_encode( leadkit_stop_values( $i + 1, $p, $cols ) );
		}
		$data = leadkit_monday( 'mutation (' . implode( ', ', $decl ) . ') { ' . implode( ' ', $body ) . ' }', $vars );
		foreach ( array_keys( $chunk ) as $i ) {
			if ( ! empty( $data[ 's' . $i ]['id'] ) ) {
				$ids[ $i ] = (string) $data[ 's' . $i ]['id'];
			}
		}
	}
	ksort( $ids );

	/**
	 * Whether stops get a screenshot. Default true.
	 *
	 * @param bool $on On.
	 */
	if ( apply_filters( 'leadkit/monday_stop_screenshots', true ) && function_exists( 'imagecreatefromstring' ) ) {
		$done  = 0;
		$until = time() + 90; // A first capture of many new pages can be slow; stop, the next submission fills the cache.
		foreach ( $ids as $i => $sid ) {
			if ( $done >= LEADKIT_SHOTS_MAX || time() > $until ) {
				break;
			}
			$file = leadkit_stop_screenshot( $stops[ $i ] );
			if ( $file ) {
				leadkit_monday_upload_path( $sid, $cols['shot'], $file, sanitize_file_name( ( $i + 1 ) . '-' . trim( $stops[ $i ]['path'], '/' ) ) . '.png' );
				wp_delete_file( $file );
				++$done;
			}
		}
	}

	return $ids;
}

/**
 * Where page screenshots are cached.
 *
 * @return string Directory, created if needed; '' if it cannot be.
 */
function leadkit_shot_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'leadkit-shots';
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $dir;
}

/**
 * The capture service's URL for a page.
 *
 * WordPress.com's public mShots service by default: free, no key, and it only
 * ever sees public page addresses. Swap it with the filter.
 *
 * @param string $url Page.
 * @return string
 */
function leadkit_shot_source( $url ) {
	$src = 'https://s0.wp.com/mshots/v1/' . rawurlencode( $url ) . '?w=640&h=800&vpw=1280&vph=1600';
	/**
	 * The screenshot service URL for a page. Must return a PNG or JPEG.
	 *
	 * @param string $src Service URL.
	 * @param string $url Page URL.
	 */
	return (string) apply_filters( 'leadkit/screenshot_source', $src, $url );
}

/**
 * A page's screenshot, from the cache or captured now.
 *
 * mShots answers with a placeholder GIF while it works, so a miss waits and
 * asks again a few times; a page still not ready is tried by the next
 * submission instead.
 *
 * @param string $path Site path.
 * @return string Cached image file, or ''.
 */
function leadkit_page_shot( $path ) {
	$dir = leadkit_shot_dir();
	if ( ! $dir ) {
		return '';
	}
	$path = '/' . ltrim( (string) $path, '/' );
	$file = $dir . '/' . md5( $path ) . '.img';
	if ( is_readable( $file ) && filemtime( $file ) > time() - LEADKIT_SHOT_TTL ) {
		return $file;
	}

	/*
	 * A page that no longer exists would be captured as the theme's 404
	 * screen, which reads as if the visitor saw something real. Check first;
	 * a miss is remembered for a day.
	 */
	$gone = 'leadkit_shot_gone_' . md5( $path );
	if ( get_transient( $gone ) ) {
		return '';
	}
	$head = wp_remote_head( home_url( $path ), array( 'timeout' => 10, 'redirection' => 3 ) );
	$code = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );
	if ( $code >= 400 ) {
		set_transient( $gone, 1, DAY_IN_SECONDS );
		return '';
	}

	$src = leadkit_shot_source( home_url( $path ) );
	for ( $try = 0; $try < 4; $try++ ) {
		$res  = wp_remote_get( $src, array( 'timeout' => 20, 'redirection' => 3 ) );
		$type = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'content-type' );
		$body = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( $body && preg_match( '#image/(png|jpe?g|webp)#', $type ) ) {
			file_put_contents( $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $file;
		}
		if ( $try < 3 ) {
			sleep( 4 );
		}
	}
	// A stale copy beats none.
	return is_readable( $file ) ? $file : '';
}

/**
 * The page's screenshot with a gauge beside it, filled to how far they read.
 *
 * @param array $p Stop.
 * @return string Temporary PNG, or ''.
 */
function leadkit_stop_screenshot( $p ) {
	$base = leadkit_page_shot( $p['path'] );
	if ( ! $base ) {
		return '';
	}
	$src = @imagecreatefromstring( (string) file_get_contents( $base ) ); // phpcs:ignore
	if ( ! $src ) {
		return '';
	}

	// Page thumbnail 400 × 500, top of the page.
	$tw  = 400;
	$th  = 500;
	$sw  = imagesx( $src );
	$sh  = min( imagesy( $src ), (int) round( $sw * $th / $tw ) );
	$gw  = 56;  // Gauge strip.
	$pad = 12;
	$w   = $tw + $gw;
	$img = imagecreatetruecolor( $w, $th );

	$bg     = imagecolorallocate( $img, 246, 247, 251 );
	$track  = imagecolorallocate( $img, 222, 225, 233 );
	$fill   = imagecolorallocate( $img, 0, 115, 234 );   // monday blue.
	$ink    = imagecolorallocate( $img, 50, 51, 56 );
	$white  = imagecolorallocate( $img, 255, 255, 255 );
	$border = imagecolorallocate( $img, 208, 212, 228 );

	imagefilledrectangle( $img, 0, 0, $w, $th, $bg );
	imagecopyresampled( $img, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh );
	imagerectangle( $img, 0, 0, $tw - 1, $th - 1, $border );
	imagedestroy( $src );

	// The gauge: the page from top to bottom, filled as far as they read.
	$read = max( 0, min( 100, (int) $p['scroll'] ) );
	$gx1  = $tw + $pad;
	$gx2  = $w - $pad;
	$gy1  = $pad;
	$gy2  = $th - 44;
	imagefilledrectangle( $img, $gx1, $gy1, $gx2, $gy2, $track );
	if ( $read > 0 ) {
		imagefilledrectangle( $img, $gx1, $gy1, $gx2, $gy1 + (int) round( ( $gy2 - $gy1 ) * $read / 100 ), $fill );
	}

	// "73%" under the gauge, "read" under that.
	$label = $read . '%';
	$f     = 5;
	imagestring( $img, $f, (int) ( $tw + ( $gw - imagefontwidth( $f ) * strlen( $label ) ) / 2 ), $th - 38, $label, $ink );
	imagestring( $img, 3, (int) ( $tw + ( $gw - imagefontwidth( 3 ) * 4 ) / 2 ), $th - 20, 'read', $ink );

	// Time on page, as a tag on the screenshot.
	$t  = leadkit_secs_label( $p['secs'] ) . ' on page';
	$bw = imagefontwidth( $f ) * strlen( $t ) + 16;
	imagefilledrectangle( $img, 8, $th - 32, 8 + $bw, $th - 8, $ink );
	imagestring( $img, $f, 16, $th - 28, $t, $white );

	$out = wp_tempnam( 'leadkit-stop' );
	imagepng( $img, $out, 6 );
	imagedestroy( $img );
	return $out;
}

/**
 * Upload a file on disk to a file column.
 *
 * @param string $item_id Item (or subitem).
 * @param string $column  Column id.
 * @param string $path    File.
 * @param string $name    File name shown in monday.
 * @return bool
 */
function leadkit_monday_upload_path( $item_id, $column, $path, $name ) {
	return leadkit_monday_upload(
		$item_id,
		$column,
		array(
			'tmp_name' => $path,
			'name'     => $name,
		)
	);
}
