<?php
/**
 * Work that must not keep a visitor waiting, run in a request of its own.
 *
 * LeadKit used to answer the visitor and carry on in the same request
 * (fastcgi_finish_request on shutdown). Some hosts do not let that answer go
 * until PHP has finished — on 3sgf.org (SiteGround) a volunteer waited 20 to 30
 * seconds after pressing Submit while their person, their browser's Visitors
 * row and the page screenshots were written. So that work is handed over:
 *
 *   1. the job is kept in a transient under a random 32-character key;
 *   2. a loopback request to leadkit/v1/job with that key is sent without
 *      waiting for it (it runs the job on its own time);
 *   3. a WP-Cron event two minutes later runs it too, in case the loopback
 *      did not get through (a host that blocks loopbacks, say).
 *
 * Whichever comes first takes the job and deletes it, so it runs once. The key
 * is the only thing the route accepts, and it cannot be guessed.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_JOB_HOOK = 'leadkit_run_job';

add_action( LEADKIT_JOB_HOOK, 'leadkit_run_job' );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			'/job',
			array(
				'methods'             => 'POST',
				'callback'            => function ( $req ) {
					ignore_user_abort( true );
					leadkit_run_job( (string) $req->get_param( 'key' ) );
					return new WP_REST_Response( null, 204 );
				},
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Hand a job to a request of its own.
 *
 * @param string $job  Job name: form_person, visitor or stops.
 * @param array  $data What the job needs.
 * @return void
 */
function leadkit_defer( $job, $data ) {
	$key = wp_generate_password( 32, false, false );
	$ref = wp_get_referer();
	set_transient(
		'leadkit_job_' . $key,
		array(
			'job'     => (string) $job,
			'data'    => $data,
			'tz'      => ( $tz = leadkit_use_visitor_tz() ) ? $tz->getName() : '',
			'referer' => $ref ? (string) $ref : '',
		),
		DAY_IN_SECONDS
	);
	wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, LEADKIT_JOB_HOOK, array( $key ) );
	wp_remote_post(
		rest_url( LEADKIT_REST_NS . '/job' ),
		array(
			'blocking'  => false,
			'timeout'   => 0.5,
			'body'      => array( 'key' => $key ),
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		)
	);
}

/**
 * Run a deferred job once, whichever of the loopback and WP-Cron gets it first.
 *
 * @param string $key Job key.
 * @return void
 */
function leadkit_run_job( $key ) {
	$key = (string) $key;
	if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $key ) ) {
		return;
	}
	$job = get_transient( 'leadkit_job_' . $key );
	if ( ! is_array( $job ) ) {
		return; // Already run, or never was.
	}
	delete_transient( 'leadkit_job_' . $key );
	wp_clear_scheduled_hook( LEADKIT_JOB_HOOK, array( $key ) );

	ignore_user_abort( true );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	leadkit_use_visitor_tz( (string) ( $job['tz'] ?? '' ) );
	if ( ! empty( $job['referer'] ) ) {
		// The page the form was on, for what reads wp_get_referer().
		$_REQUEST['_wp_http_referer'] = $job['referer']; // phpcs:ignore WordPress.Security.NonceVerification
	}
	$d = (array) ( $job['data'] ?? array() );

	switch ( $job['job'] ?? '' ) {
		case 'form_person':
			$person_id = leadkit_person_upsert( (array) $d['person'] );
			if ( ! empty( $d['visitor'] ) && is_array( $d['analytics'] ?? null ) ) {
				leadkit_queue_visitor( $d['visitor'], $d['analytics'], array( 'item' => $d['item'], 'person' => $person_id, 'form' => $d['form'] ) );
			}
			break;
		case 'visitor':
			leadkit_queue_visitor( $d['visitor'], $d['analytics'], (array) $d['link'] );
			break;
		case 'stops':
			leadkit_monday_add_stops( $d['item'], $d['board'], (array) $d['stops'] );
			break;
	}
}
