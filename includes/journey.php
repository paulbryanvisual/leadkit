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

	$out .= '<tr><td style="padding:0 0 14px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>';
	foreach ( $stats as $s ) {
		$out .= '<td style="padding:0 22px 0 0;vertical-align:top">'
			. '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . $muted . '">' . esc_html( $s[0] ) . '</div>'
			. '<div style="font-size:20px;font-weight:700;line-height:1.3">' . esc_html( $s[1] ) . '</div>'
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
			$label  = '/' === $path ? __( 'Homepage', 'leadkit' ) : $path;
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
					. '<div style="font-size:14px;font-weight:600;line-height:1.5">' . esc_html( $label ) . '</div>'
					. '<div style="font-size:12px;color:' . $muted . ';line-height:1.5">'
						. '<span style="color:' . $heat . ';font-weight:600">' . esc_html( leadkit_duration( $ms ) ) . '</span>'
						. esc_html__( ' reading · scrolled ', 'leadkit' ) . esc_html( $scroll ) . '%'
					. '</div>'
					// A bar, because "9%" and "80%" should not look alike at a glance.
					. '<div style="background:' . $line . ';height:4px;width:180px;margin:4px 0 0">'
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
		$out .= '<tr><td style="padding:0 0 8px"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse"><tr>';

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
			$out .= '<td style="padding:0 8px 8px 0;vertical-align:top">'
				. '<img src="' . esc_url( $src ) . '" width="132" alt="" style="display:block;width:132px;height:99px;object-fit:cover;border:1px solid ' . $line . ';border-radius:3px">'
				. '<div style="font-size:11px;color:' . $muted . ';padding:4px 0 0;max-width:132px">' . esc_html( (string) ( $p['path'] ?? '' ) ) . '</div>'
				. '</td>';
			if ( 0 === $shown % 3 ) {
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
