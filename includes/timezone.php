<?php
/**
 * Times in the visitor's own time zone.
 *
 * The tracker sends the browser's IANA zone (America/Denver, Europe/London…).
 * While LeadKit handles one visitor — a form submission or a return-visit
 * sync — monday.com names, journeys and logs are written in that zone, so
 * "arrived 9:14 am" means 9:14 where they were. Without a zone (older
 * tracker, unusual browser) the site's zone is used, as before.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A valid time zone name, or ''.
 *
 * @param mixed $tz Candidate, e.g. "America/Chicago".
 * @return string
 */
function leadkit_clean_tz( $tz ) {
	$tz = trim( (string) $tz );
	if ( '' === $tz || strlen( $tz ) > 64 || ! preg_match( '#^[A-Za-z][A-Za-z0-9_+\-]*(/[A-Za-z0-9_+\-]+)*$#', $tz ) ) {
		return '';
	}
	try {
		new DateTimeZone( $tz );
	} catch ( Exception $e ) {
		return '';
	}
	return $tz;
}

/**
 * Set (or with '' clear) the zone for the visitor being handled.
 *
 * @param string|null $tz Zone name.
 * @return DateTimeZone|null The zone now in use, null for the site's.
 */
function leadkit_use_visitor_tz( $tz = null ) {
	static $zone = null;
	if ( null !== $tz ) {
		$tz   = leadkit_clean_tz( $tz );
		$zone = $tz ? new DateTimeZone( $tz ) : null;
	}
	return $zone;
}

/**
 * The visitor's zone if known, else the site's.
 *
 * @return DateTimeZone
 */
function leadkit_vtz() {
	return leadkit_use_visitor_tz() ?: wp_timezone();
}

/**
 * wp_date() in the visitor's zone.
 *
 * @param string   $format Date format.
 * @param int|null $ts     Unix time; now if null.
 * @return string
 */
function leadkit_vdate( $format, $ts = null ) {
	return (string) wp_date( $format, null === $ts ? time() : (int) $ts, leadkit_vtz() );
}

/**
 * "CDT, their time zone (America/Chicago)" or "CDT, the site's time zone".
 *
 * @return string
 */
function leadkit_vtz_note() {
	$abbr = leadkit_vdate( 'T' );
	return leadkit_use_visitor_tz()
		/* translators: 1: zone abbreviation, 2: zone name. */
		? sprintf( __( '%1$s, their time zone (%2$s)', 'leadkit' ), $abbr, leadkit_vtz()->getName() )
		/* translators: %s: zone abbreviation. */
		: sprintf( __( '%s, the site\'s time zone', 'leadkit' ), $abbr );
}
