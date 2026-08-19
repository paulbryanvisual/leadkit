<?php
/**
 * The visitor's journey, rendered.
 *
 * The tracker has always collected this — every page, how long they were
 * actually reading it, how far down they got, every photograph they opened,
 * every phone number they tapped. Until now it went into the lead as a blob of
 * JSON nobody reads, and the email did not mention it at all.
 *
 * That is the difference between "someone enquired" and "someone spent four
 * minutes on the bathrooms page, opened three photographs of the same wet room,
 * and tapped the phone number before filling the form". The second one tells
 * you what to say when you call them back.
 *
 * ONE renderer for both surfaces. The admin screen and the email must show the
 * same journey, and two renderers guarantee they eventually will not.
 *
 * Table markup and inline styles throughout, because half the audience is an
 * email client: Gmail strips <style> blocks, Outlook renders through Word, and
 * neither has flexbox. What survives that also renders fine in wp-admin.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human-readable duration from milliseconds.
 *
 * @param int $ms Milliseconds.
 * @return string
 */
function leadkit_duration( $ms ) {
	$s = (int) round( $ms / 1000 );

	if ( $s < 60 ) {
		return $s . 's';
	}

	$m = intdiv( $s, 60 );
	$r = $s % 60;

	return $r ? sprintf( '%dm %ds', $m, $r ) : sprintf( '%dm', $m );
}

/**
 * How engaged a page view was, as a colour.
 *
 * Reading time is the signal that matters and the eye should find it without
 * reading numbers: a page someone sat on for two minutes should not look like
 * one they bounced off in three seconds.
 *
 * @param int $ms Active milliseconds.
 * @return string Hex colour.
 */
function leadkit_heat( $ms ) {
	$s = $ms / 1000;

	if ( $s >= 60 ) {
		return '#0b6b3a';
	}
	if ( $s >= 20 ) {
		return '#1f7a4d';
	}
	if ( $s >= 8 ) {
		return '#8a6d1f';
	}

	return '#8a8a8a';
}

/**
 * Make a stored image src absolute.
 *
 * The tracker records whatever was in the `src` attribute, which is usually
 * root-relative. That resolves fine in a browser and not at all in an email
 * client, which is why the previous system's notification showed a row of
 * broken-image icons where the photographs should have been.
 *
 * @param string $src Stored src.
 * @return string
 */
function leadkit_absolute_src( $src ) {
	$src = trim( (string) $src );

	if ( '' === $src || preg_match( '#^https?://#i', $src ) ) {
		return $src;
	}

	return home_url( '/' . ltrim( $src, '/' ) );
}


/**
 * A readable name for a path.
 *
 * `/work/rockwall-minimalist-bath/` means nothing at a glance; "Cash Texas
 * Accessible Bath" means everything, and it is what the page is actually
 * called — a slug and a title diverge more often than you would think.
 *
 * Falls back to prettifying the slug when nothing resolves, so a page that has
 * since been deleted still reads as words rather than a dead URL.
 *
 * @param string $path Path recorded by the tracker.
 * @return string
 */
function leadkit_page_label( $path ) {
	static $cache = array();

	$path = '/' . trim( (string) $path, '/' ) . '/';
	if ( '//' === $path ) {
		return __( 'Homepage', 'leadkit' );
	}
	if ( isset( $cache[ $path ] ) ) {
		return $cache[ $path ];
	}

	$id    = url_to_postid( home_url( $path ) );
	$label = $id ? get_the_title( $id ) : '';

	if ( '' === trim( $label ) ) {
		$slug  = basename( rtrim( $path, '/' ) );
		$label = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	// "contact" beside "Premium Bathroom Remodels" reads as a bug rather than a
	// title. Only touched when the title carries no capitals of its own.
	if ( $label === strtolower( $label ) ) {
		$label = ucwords( $label );
	}

	$cache[ $path ] = $label;

	return $label;
}

/**
 * How warm this enquiry is, from behaviour rather than from what they typed.
 *
 * Everyone writes "interested in a bathroom". Not everyone reads for four
 * minutes, opens six photographs and taps the phone number — and the person
 * ringing them back should be able to tell those apart before they dial.
 *
 * @param array $signals Counted signals.
 * @return array{label:string,colour:string,background:string}
 */
function leadkit_temperature( $signals ) {
	$score = 0;
	$score += min( 3, intdiv( (int) $signals['total_ms'], 60000 ) );   // a point a minute, to three
	$score += min( 3, (int) $signals['photos'] );
	$score += $signals['tapped'] ? 3 : 0;
	$score += $signals['returned'] ? 2 : 0;
	$score += min( 2, max( 0, (int) $signals['pages'] - 2 ) );

	if ( $score >= 7 ) {
		return array(
			'label'      => __( 'Hot lead', 'leadkit' ),
			'colour'     => '#0b6b3a',
			'background' => '#e6f4ea',
		);
	}
	if ( $score >= 4 ) {
		return array(
			'label'      => __( 'Warm', 'leadkit' ),
			'colour'     => '#8a6d1f',
			'background' => '#fcf7e6',
		);
	}

	return array(
		'label'      => __( 'Browsing', 'leadkit' ),
		'colour'     => '#646970',
		'background' => '#f0f0f1',
	);
}

/**
 * The device, in the two words that change how you answer.
 *
 * Whether they wrote this one-handed on a phone at a job site or sat at a desk
 * with the photographs open is the difference between "call them" and "send the
 * detailed quote".
 *
 * @param array $device Device context from the tracker.
 * @return string
 */
function leadkit_device_label( $device ) {
	$ua    = (string) ( $device['userAgent'] ?? '' );
	$width = (int) ( $device['width'] ?? 0 );

	if ( preg_match( '/iPhone|Android.*Mobile/i', $ua ) ) {
		$kind = __( 'Phone', 'leadkit' );
	} elseif ( preg_match( '/iPad|Tablet|Android/i', $ua ) ) {
		$kind = __( 'Tablet', 'leadkit' );
	} elseif ( '' !== $ua ) {
		$kind = __( 'Desktop', 'leadkit' );
	} else {
		return '';
	}

	return $width ? sprintf( '%s · %dpx wide', $kind, $width ) : $kind;
}

/**
 * Render the journey as self-contained HTML.
 *
 * @param array|string $analytics Decoded payload or its JSON.
 * @return string HTML, or '' when there is nothing worth showing.
 */
function leadkit_render_journey( $analytics ) {
	if ( is_string( $analytics ) ) {
		$analytics = json_decode( $analytics, true );
	}
	if ( ! is_array( $analytics ) ) {
		return '';
	}

	$views  = isset( $analytics['page_views'] ) && is_array( $analytics['page_views'] ) ? $analytics['page_views'] : array();
	$events = isset( $analytics['events'] ) && is_array( $analytics['events'] ) ? $analytics['events'] : array();

	if ( ! $views && ! $events ) {
		return '';
	}

	$total = 0;
	foreach ( $views as $v ) {
		$total += (int) ( $v['active_time_ms'] ?? 0 );
	}

	$photos = array();
	$taps   = array();
	$rage   = 0;
	foreach ( $events as $e ) {
		$type = (string) ( $e['type'] ?? '' );
		if ( 'photo_click' === $type ) {
			$photos[] = $e;
		} elseif ( 'rage_click' === $type ) {
			++$rage;
		} elseif ( in_array( $type, array( 'phone_click', 'email_click' ), true ) ) {
			$taps[] = $e;
		}
	}

	// How long the whole visit spanned, which is not the same as reading time.
	$stamps = array();
	foreach ( array_merge( $views, $events ) as $r ) {
		$t = isset( $r['time'] ) ? strtotime( (string) $r['time'] ) : 0;
		if ( $t ) {
			$stamps[] = $t;
		}
	}
	$started_at = isset( $analytics['started_at'] ) ? strtotime( (string) $analytics['started_at'] ) : 0;
	if ( $started_at ) {
		$stamps[] = $started_at;
	}
	$span = $stamps ? ( max( $stamps ) - min( $stamps ) ) : 0;

	/*
	 * A visit spread over hours is somebody who left, thought about it, and came
	 * back — which is a stronger signal than the same minutes spent in one sitting.
	 */
	$returned = $span > ( 2 * HOUR_IN_SECONDS );

	$temp = leadkit_temperature(
		array(
			'total_ms' => $total,
			'photos'   => count( $photos ),
			'tapped'   => (bool) $taps,
			'returned' => $returned,
			'pages'    => count( $views ),
		)
	);

	// The single busiest page — where their attention actually went.
	$best = null;
	foreach ( $views as $v ) {
		if ( ! $best || (int) ( $v['active_time_ms'] ?? 0 ) > (int) ( $best['active_time_ms'] ?? 0 ) ) {
			$best = $v;
		}
	}

	$ink   = '#1d2327';
	$muted = '#646970';
	$line  = '#dcdcde';
	$out   = '';

	$out .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:' . $ink . '">';

	// ---- the headline numbers -------------------------------------------
	$started = isset( $analytics['started_at'] ) ? strtotime( (string) $analytics['started_at'] ) : 0;
	$stats   = array(
		array( __( 'Pages seen', 'leadkit' ), (string) count( $views ) ),
		array( __( 'Time reading', 'leadkit' ), leadkit_duration( $total ) ),
		array( __( 'Photos opened', 'leadkit' ), (string) count( $photos ) ),
	);
	if ( $started ) {
		/*
		 * wp_date(), not date_i18n() with a hand-added offset. `strtotime` on the
		 * tracker's ISO string already gives a UTC timestamp, and date_i18n
		 * applies the site's offset itself — so adding it again shifted every
		 * arrival time by the offset twice and put a session from fifteen
		 * minutes ago in the small hours.
		 */
		$stats[] = array( __( 'First arrived', 'leadkit' ), wp_date( 'j M, g:ia', $started ) );
	}

	/*
	 * The badge and the sentence come first, because this is what gets read on
	 * a phone in a van between jobs. Everything below it is the evidence.
	 */
	$sentence = array();
	if ( $views ) {
		/* translators: 1: reading time, 2: number of pages. */
		$sentence[] = sprintf( __( 'Read for %1$s across %2$d pages', 'leadkit' ), leadkit_duration( $total ), count( $views ) );
	}
	if ( $best && (int) ( $best['active_time_ms'] ?? 0 ) > 0 ) {
		/* translators: %s: page name. */
		$sentence[] = sprintf( __( 'mostly on %s', 'leadkit' ), leadkit_page_label( (string) ( $best['path'] ?? '' ) ) );
	}
	if ( $photos ) {
		/* translators: %d: number of photographs. */
		$sentence[] = sprintf( _n( 'opened %d photograph', 'opened %d photographs', count( $photos ), 'leadkit' ), count( $photos ) );
	}
	if ( $taps ) {
		$sentence[] = __( 'and tapped the phone number', 'leadkit' );
	}
	if ( $returned ) {
		/* translators: %s: human-readable duration. */
		$sentence[] = sprintf( __( 'returning over %s', 'leadkit' ), human_time_diff( 0, $span ) );
	}

	$out .= '<tr><td style="padding:0 0 12px">'
		. '<span style="display:inline-block;background:' . $temp['background'] . ';color:' . $temp['colour']
		. ';font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:4px 9px;border-radius:2px">'
		. esc_html( $temp['label'] ) . '</span>';

	if ( $sentence ) {
		$out .= '<div style="font-size:15px;line-height:1.5;color:' . $ink . ';padding:9px 0 0">'
			. esc_html( implode( ', ', $sentence ) ) . '.</div>';
	}
	$out .= '</td></tr>';

	/*
	 * Two per row, not four. Four abreast needs 420px of label before anything
	 * else on the page, which is what made the whole email 476px wide on a
	 * 400px phone — and an email that scrolls sideways is an email nobody
	 * finishes reading.
	 */
	$out .= '<tr><td style="padding:0 0 14px"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse"><tr>';
	foreach ( $stats as $i => $s ) {
		if ( $i > 0 && 0 === $i % 2 ) {
			$out .= '</tr><tr>';
		}
		$out .= '<td width="50%" style="padding:0 12px 10px 0;vertical-align:top">'
			. '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . $muted . '">' . esc_html( $s[0] ) . '</div>'
			. '<div style="font-size:19px;font-weight:700;line-height:1.3">' . esc_html( $s[1] ) . '</div>'
			. '</td>';
	}
	$out .= '</tr></table></td></tr>';

	// ---- high-intent actions, first because they change the phone call ---
	if ( $taps ) {
		$out .= '<tr><td style="padding:0 0 14px">';
		foreach ( $taps as $t ) {
			$is_phone = 'phone_click' === ( $t['type'] ?? '' );
			$out     .= '<div style="background:#e6f4ea;border-left:4px solid #0b6b3a;padding:9px 12px;margin:0 0 6px;font-size:14px">'
				. '<strong>' . esc_html( $is_phone ? __( 'Tapped the phone number', 'leadkit' ) : __( 'Tapped the email address', 'leadkit' ) ) . '</strong> '
				. '<span style="color:' . $muted . '">' . esc_html( (string) ( $t['value'] ?? '' ) ) . '</span>'
				. '</div>';
		}
		$out .= '</td></tr>';
	}

	if ( $rage ) {
		$out .= '<tr><td style="padding:0 0 14px">'
			. '<div style="background:#fcf0ef;border-left:4px solid #b32d2e;padding:9px 12px;font-size:14px">'
			/* translators: %d: number of rapid repeated clicks. */
			. esc_html( sprintf( _n( '%d rapid repeated click — something did not respond as they expected.', '%d rapid repeated clicks — something did not respond as they expected.', $rage, 'leadkit' ), $rage ) )
			. '</div></td></tr>';
	}

	// ---- the path through the site --------------------------------------
	if ( $views ) {
		$out .= '<tr><td style="padding:0 0 6px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . $muted . '">'
			. esc_html__( 'Their path through the site', 'leadkit' ) . '</td></tr>';
		$out .= '<tr><td style="padding:0 0 14px"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse">';

		$last = count( $views ) - 1;
		foreach ( $views as $i => $v ) {
			$path   = (string) ( $v['path'] ?? '/' );
			$label  = leadkit_page_label( $path );
			$ms     = (int) ( $v['active_time_ms'] ?? 0 );
			$scroll = max( 0, min( 100, (int) ( $v['max_scroll_percent'] ?? 0 ) ) );
			$heat   = leadkit_heat( $ms );

			$out .= '<tr>'
				// The step marker, and the rule joining it to the next step.
				. '<td width="26" style="vertical-align:top;padding:0">'
					. '<div style="width:10px;height:10px;border-radius:5px;background:' . $heat . ';margin:6px 0 0 3px"></div>'
					. ( $i < $last ? '<div style="width:2px;height:26px;background:' . $line . ';margin:2px 0 0 7px"></div>' : '' )
				. '</td>'
				. '<td style="padding:0 0 6px;vertical-align:top">'
					// Linked, so "which page was that" is one click rather than a hunt.
					. '<div style="font-size:14px;font-weight:600;line-height:1.5">'
						. '<a href="' . esc_url( home_url( $path ) ) . '" style="color:' . $ink . ';text-decoration:none">' . esc_html( $label ) . '</a>'
						. ' <span style="font-weight:400;font-size:12px;color:' . $muted . '">' . esc_html( $path ) . '</span>'
					. '</div>'
					. '<div style="font-size:12px;color:' . $muted . ';line-height:1.5">'
						. '<span style="color:' . $heat . ';font-weight:600">' . esc_html( leadkit_duration( $ms ) ) . '</span>'
						. esc_html__( ' reading · scrolled ', 'leadkit' ) . esc_html( $scroll ) . '%'
					. '</div>'
					// A bar, because "9%" and "80%" should not look alike at a glance.
					. '<div style="background:' . $line . ';height:4px;max-width:180px;margin:4px 0 0">'
						. '<div style="background:' . $heat . ';height:4px;width:' . esc_attr( max( 2, $scroll ) ) . '%"></div>'
					. '</div>'
				. '</td>'
			. '</tr>';
		}
		$out .= '</table></td></tr>';
	}

	// ---- the photographs they chose to look at ---------------------------
	if ( $photos ) {
		$out .= '<tr><td style="padding:0 0 6px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . $muted . '">'
			. esc_html__( 'Photographs they opened', 'leadkit' ) . '</td></tr>';
		$out .= '<tr><td style="padding:0 0 8px"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse"><tr>';

		$shown = 0;
		foreach ( $photos as $p ) {
			if ( $shown >= 6 ) {
				break;
			}
			$src = leadkit_absolute_src( (string) ( $p['value'] ?? '' ) );
			if ( '' === $src ) {
				continue;
			}
			++$shown;
			$from = (string) ( $p['path'] ?? '' );
			$out .= '<td width="50%" style="padding:0 8px 10px 0;vertical-align:top">'
				. '<a href="' . esc_url( $from ? home_url( $from ) : $src ) . '" style="text-decoration:none">'
					. '<img src="' . esc_url( $src ) . '" alt="" style="display:block;width:100%;max-width:150px;height:112px;object-fit:cover;border:1px solid ' . $line . ';border-radius:3px">'
				. '</a>'
				. '<div style="font-size:11px;color:' . $muted . ';padding:4px 0 0;max-width:150px;line-height:1.4">' . esc_html( $from ? leadkit_page_label( $from ) : '' ) . '</div>'
				. '</td>';
			if ( 0 === $shown % 2 ) {
				$out .= '</tr><tr>';
			}
		}
		$out .= '</tr></table>';
		if ( count( $photos ) > $shown ) {
			$out .= '<div style="font-size:12px;color:' . $muted . '">'
				/* translators: %d: number of further photographs. */
				. esc_html( sprintf( _n( 'and %d more', 'and %d more', count( $photos ) - $shown, 'leadkit' ), count( $photos ) - $shown ) )
				. '</div>';
		}
		$out .= '</td></tr>';
	}

	// ---- where they came from --------------------------------------------
	$device = isset( $analytics['device'] ) && is_array( $analytics['device'] ) ? leadkit_device_label( $analytics['device'] ) : '';
	if ( $device ) {
		$out .= '<tr><td style="padding:8px 0 0;font-size:12px;color:' . $muted . '">'
			. esc_html__( 'Device — ', 'leadkit' ) . esc_html( $device )
			. ( $span > 60 ? esc_html( sprintf( __( ' · visit spanned %s', 'leadkit' ), human_time_diff( 0, $span ) ) ) : '' )
			. '</td></tr>';
	}

	$utm = isset( $analytics['utm'] ) && is_array( $analytics['utm'] ) ? array_filter( $analytics['utm'] ) : array();
	if ( $utm ) {
		$bits = array();
		foreach ( $utm as $k => $v ) {
			$bits[] = esc_html( $k . ': ' . $v );
		}
		$out .= '<tr><td style="padding:6px 0 0;font-size:12px;color:' . $muted . '">'
			. esc_html__( 'Campaign — ', 'leadkit' ) . implode( ' · ', $bits ) . '</td></tr>';
	}

	$out .= '</table>';

	return $out;
}
