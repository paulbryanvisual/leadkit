<?php
/**
 * A monday.com form, rendered as plain HTML on the site.
 *
 *   [leadkit_monday_form form="<form token>" board="<board id>" name="volunteer"]
 *
 * A monday.com WorkForm embedded in an iframe is another site's page: the
 * page's CSS cannot reach it, it can only take hidden values from its URL, and
 * the site cannot tell when it has been submitted. This renders the same form
 * as ordinary markup instead — no styles of its own, a class on every element —
 * and submits it here, to leadkit/v1/monday, which creates the item on the
 * board through the monday.com API.
 *
 * The questions, labels, required flags and choices are read from the monday
 * form itself (cached ten minutes), so it is still edited in monday. Each answer
 * goes to the column the question belongs to. Every tracking value LeadKit has
 * (the same set it sends to embedded forms) goes to the board column of the
 * same title — utm_source, campaign_history, page_detail, visitor_id… — which
 * is exactly what the form's tags created, so one board serves both.
 *
 * Stored first, sent second: every submission is saved as a LeadKit lead before
 * monday.com is called, and a failed call is recorded on that lead ("NOT SENT"
 * in the Leads list) rather than losing the submission.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'leadkit_monday_form', 'leadkit_monday_form_shortcode' );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			LEADKIT_REST_NS,
			'/monday',
			array(
				'methods'             => 'POST',
				'callback'            => 'leadkit_handle_monday_form',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * The form's questions and the board's columns, cached.
 *
 * @param string $token Form token.
 * @param string $board Board id.
 * @param bool   $fresh Skip the cache.
 * @return array|null { title, description, done_title, done_text, group, questions[], columns{id:{title,type}} }
 */
function leadkit_monday_form_def( $token, $board, $fresh = false ) {
	$key = 'leadkit_mform_' . md5( $token . '|' . $board );
	if ( ! $fresh ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$data = leadkit_monday(
		'query ($t: String!, $b: [ID!]) { form(formToken: $t) { title description active questions { id type title description required visible options { label value visible active } settings { display prefixPredefined { enabled prefix } } } features { afterSubmissionView { title description } monday { itemGroupId } } } boards(ids: $b) { columns { id title type } } }',
		array( 't' => $token, 'b' => array( $board ) )
	);
	if ( empty( $data['form'] ) || empty( $data['boards'][0]['columns'] ) ) {
		return null;
	}

	$columns = array();
	foreach ( $data['boards'][0]['columns'] as $c ) {
		$columns[ $c['id'] ] = array( 'title' => (string) $c['title'], 'type' => (string) $c['type'] );
	}

	$def = array(
		'title'       => (string) $data['form']['title'],
		'description' => (string) $data['form']['description'],
		'active'      => ! empty( $data['form']['active'] ),
		'done_title'  => (string) ( $data['form']['features']['afterSubmissionView']['title'] ?? '' ),
		'done_text'   => (string) ( $data['form']['features']['afterSubmissionView']['description'] ?? '' ),
		'group'       => (string) ( $data['form']['features']['monday']['itemGroupId'] ?? '' ),
		'questions'   => array(),
		'columns'     => $columns,
	);
	foreach ( (array) $data['form']['questions'] as $q ) {
		if ( empty( $q['visible'] ) || 'PAGE_BLOCK' === $q['type'] ) {
			continue;
		}
		$opts = array();
		foreach ( (array) ( $q['options'] ?? array() ) as $o ) {
			if ( ( $o['visible'] ?? true ) && ( $o['active'] ?? true ) ) {
				$opts[] = (string) $o['label'];
			}
		}
		$def['questions'][] = array(
			'id'          => (string) $q['id'],
			'type'        => (string) $q['type'],
			'title'       => (string) $q['title'],
			'description' => (string) ( $q['description'] ?? '' ),
			'required'    => ! empty( $q['required'] ),
			'options'     => $opts,
			'prefix'      => (string) ( $q['settings']['prefixPredefined']['prefix'] ?? '' ),
		);
	}

	set_transient( $key, $def, 10 * MINUTE_IN_SECONDS );
	return $def;
}

/**
 * The browser's autocomplete hint for a question, from its type and title.
 *
 * @param array $q Question.
 * @return string
 */
function leadkit_mform_autocomplete( $q ) {
	$map = array( 'Name' => 'name', 'Email' => 'email', 'Phone' => 'tel', 'Link' => 'url' );
	if ( isset( $map[ $q['type'] ] ) ) {
		return $map[ $q['type'] ];
	}
	$t = strtolower( $q['title'] );
	foreach ( array(
		'street'   => 'street-address',
		'address'  => 'street-address',
		'city'     => 'address-level2',
		'zip'      => 'postal-code',
		'postal'   => 'postal-code',
		'state'    => 'address-level1',
		'province' => 'address-level1',
		'first'    => 'given-name',
		'last'     => 'family-name',
		'company'  => 'organization',
	) as $needle => $hint ) {
		if ( false !== strpos( $t, $needle ) ) {
			return $hint;
		}
	}
	return '';
}

/**
 * Render the form.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function leadkit_monday_form_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'form'         => '',
			'board'        => '',
			'name'         => 'monday-form',
			'class_prefix' => 'lk-form',
			'submit_text'  => __( 'Submit', 'leadkit' ),
			'show_title'   => '',
			'success'      => '',
		),
		$atts,
		'leadkit_monday_form'
	);

	$token = preg_replace( '/[^a-f0-9]/', '', strtolower( $atts['form'] ) );
	$board = preg_replace( '/\D/', '', $atts['board'] );
	$name  = sanitize_key( $atts['name'] ) ?: 'monday-form';
	$p     = preg_replace( '/[^a-zA-Z0-9_-]/', '', $atts['class_prefix'] ) ?: 'lk-form';

	if ( ! $token || ! $board ) {
		return current_user_can( 'edit_posts' ) ? '<p>' . esc_html__( 'LeadKit: the monday form needs form="" and board="".', 'leadkit' ) . '</p>' : '';
	}
	if ( ! leadkit_monday_token() ) {
		return current_user_can( 'edit_posts' ) ? '<p>' . esc_html__( 'LeadKit: add a monday.com API token under Settings → LeadKit.', 'leadkit' ) . '</p>' : '';
	}

	$def = leadkit_monday_form_def( $token, $board );
	if ( ! $def ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		$why = (string) get_option( 'leadkit_last_monday_error', '' );
		return '<p>' . esc_html__( 'LeadKit: could not load that monday.com form. Check the form token, the board id and the API token.', 'leadkit' )
			. ( $why ? '<br><strong>' . esc_html__( 'monday.com said:', 'leadkit' ) . '</strong> ' . esc_html( $why ) : '' )
			. '<br><small>' . esc_html__( '(Only editors see this.)', 'leadkit' ) . '</small></p>';
	}

	// After the redirect back from leadkit/v1/monday.
	$status = isset( $_GET['leadkit_form'], $_GET['leadkit'] ) && sanitize_key( wp_unslash( $_GET['leadkit_form'] ) ) === $name // phpcs:ignore WordPress.Security.NonceVerification
		? sanitize_key( wp_unslash( $_GET['leadkit'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
		: '';

	$id   = $p . '-' . $name;
	$opts = leadkit_options();
	$out  = '<form class="' . esc_attr( $p ) . '" id="' . esc_attr( $id ) . '" action="' . esc_url( rest_url( LEADKIT_REST_NS . '/monday' ) ) . '" method="post" enctype="multipart/form-data" data-leadkit data-leadkit-form="' . esc_attr( $name ) . '" data-leadkit-monday>';

	if ( 'sent' === $status ) {
		$title = $atts['success'] ?: ( $def['done_title'] ?: __( 'Thank you — we received your response.', 'leadkit' ) );
		$out  .= '<div class="' . esc_attr( $p ) . '__message ' . esc_attr( $p ) . '__message--sent" id="leadkit-message" role="status">'
			. '<p class="' . esc_attr( $p ) . '__message-title">' . esc_html( $title ) . '</p>'
			. ( $def['done_text'] ? '<p class="' . esc_attr( $p ) . '__message-text">' . esc_html( $def['done_text'] ) . '</p>' : '' )
			. '</div></form>';
		return $out;
	}

	if ( $atts['show_title'] ) {
		$out .= '<h2 class="' . esc_attr( $p ) . '__title">' . esc_html( $def['title'] ) . '</h2>';
		if ( $def['description'] ) {
			$out .= '<div class="' . esc_attr( $p ) . '__intro">' . wpautop( esc_html( $def['description'] ) ) . '</div>';
		}
	}

	$out .= '<input type="hidden" name="leadkit_form" value="' . esc_attr( $name ) . '">'
		. '<input type="hidden" name="form_token" value="' . esc_attr( $token ) . '">'
		. '<input type="hidden" name="board" value="' . esc_attr( $board ) . '">'
		. '<div class="' . esc_attr( $p ) . '__hp" aria-hidden="true" style="position:absolute;left:-9999px"><input type="text" name="leadkit_hp" tabindex="-1" autocomplete="off"></div>';

	$req  = '<span class="' . esc_attr( $p ) . '__required" aria-hidden="true">*</span>';
	$cls  = function ( $s ) use ( $p ) {
		return esc_attr( $p . $s );
	};
	foreach ( $def['questions'] as $q ) {
		$fid   = $id . '-' . $q['id'];
		$fname = 'q[' . $q['id'] . ']';
		$r     = $q['required'] ? ' required' : '';
		$label = esc_html( $q['title'] ) . ( $q['required'] ? ' ' . $req : '' );
		$help  = $q['description'] ? '<p class="' . $cls( '__help' ) . '">' . esc_html( $q['description'] ) . '</p>' : '';
		$ac    = leadkit_mform_autocomplete( $q );
		$ac    = $ac ? ' autocomplete="' . esc_attr( $ac ) . '"' : '';
		$slug  = sanitize_html_class( strtolower( $q['type'] ) );

		switch ( $q['type'] ) {
			case 'Name':
			case 'ShortText':
			case 'Email':
			case 'Phone':
			case 'Number':
			case 'Link':
			case 'Date':
			case 'Country':
				$types = array( 'Email' => 'email', 'Phone' => 'tel', 'Number' => 'number', 'Link' => 'url', 'Date' => 'date' );
				$type  = $types[ $q['type'] ] ?? 'text';
				$max   = 'Name' === $q['type'] ? ' maxlength="255"' : '';
				$out  .= '<div class="' . $cls( '__field' ) . ' ' . $cls( '__field--' . $slug ) . '">'
					. '<label class="' . $cls( '__label' ) . '" for="' . esc_attr( $fid ) . '">' . $label . '</label>'
					. '<input class="' . $cls( '__input' ) . '" type="' . $type . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $fname ) . '"' . $ac . $max . $r . '>'
					. $help . '</div>';
				break;

			case 'LongText':
				$out .= '<div class="' . $cls( '__field' ) . ' ' . $cls( '__field--longtext' ) . '">'
					. '<label class="' . $cls( '__label' ) . '" for="' . esc_attr( $fid ) . '">' . $label . '</label>'
					. '<textarea class="' . $cls( '__input' ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $fname ) . '" rows="4"' . $r . '></textarea>'
					. $help . '</div>';
				break;

			case 'SingleSelect':
			case 'MultiSelect':
				$multi = 'MultiSelect' === $q['type'];
				$out  .= '<fieldset class="' . $cls( '__field' ) . ' ' . $cls( '__field--choices' ) . '"' . ( $q['required'] ? ' data-required' : '' ) . '>'
					. '<legend class="' . $cls( '__label' ) . '">' . $label . '</legend>'
					. '<div class="' . $cls( '__options' ) . '">';
				foreach ( $q['options'] as $i => $o ) {
					// A single choice can carry `required` natively; a set of checkboxes cannot.
					$out .= '<label class="' . $cls( '__option' ) . '"><input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $fname . ( $multi ? '[]' : '' ) ) . '" value="' . esc_attr( $o ) . '"' . ( ! $multi && $q['required'] && 0 === $i ? ' required' : '' ) . '> <span>' . esc_html( $o ) . '</span></label>';
				}
				$out .= '</div>' . $help . '</fieldset>';
				break;

			case 'Boolean':
				$out .= '<div class="' . $cls( '__field' ) . ' ' . $cls( '__field--checkbox' ) . '">'
					. '<label class="' . $cls( '__option' ) . '"><input type="checkbox" name="' . esc_attr( $fname ) . '" value="1"' . $r . '> <span>' . $label . '</span></label>'
					. $help . '</div>';
				break;

			case 'File':
				$out .= '<div class="' . $cls( '__field' ) . ' ' . $cls( '__field--file' ) . '">'
					. '<label class="' . $cls( '__label' ) . '" for="' . esc_attr( $fid ) . '">' . $label . '</label>'
					. '<input class="' . $cls( '__input' ) . '" type="file" id="' . esc_attr( $fid ) . '" name="' . esc_attr( 'q_' . $q['id'] ) . '" accept=".pdf,.doc,.docx,.rtf,.txt,.odt,.jpg,.jpeg,.png"' . $r . '>'
					. $help . '</div>';
				break;

			case 'DISPLAY_TEXT':
				$out .= '<div class="' . $cls( '__text' ) . '">' . wpautop( esc_html( $q['title'] . ( $q['description'] ? "\n\n" . $q['description'] : '' ) ) ) . '</div>';
				break;
		}
	}

	if ( $opts['turnstile_sitekey'] ) {
		$out .= '<div class="cf-turnstile ' . $cls( '__turnstile' ) . '" data-sitekey="' . esc_attr( $opts['turnstile_sitekey'] ) . '" data-action="leadkit-form"></div>';
		leadkit_form_was_rendered( true );
	}

	if ( 'error' === $status ) {
		$out .= '<div class="' . $cls( '__message' ) . ' ' . $cls( '__message--error' ) . '" id="leadkit-message" role="alert"><p>'
			. esc_html__( 'That did not go through — please fill in every required field and try again.', 'leadkit' ) . '</p></div>';
	}

	$out .= '<div class="' . $cls( '__actions' ) . '"><button class="' . $cls( '__submit' ) . '" type="submit">' . esc_html( $atts['submit_text'] ) . '</button></div>'
		. '</form>';

	return $out;
}

/**
 * Turn one answer into the value monday.com expects for that column.
 *
 * @param array  $col    { title, type }.
 * @param array  $q      Question.
 * @param mixed  $answer Raw answer.
 * @return mixed|null Null when there is nothing to send.
 */
function leadkit_mform_value( $col, $q, $answer ) {
	if ( is_array( $answer ) ) {
		$answer = array_values( array_filter( array_map( fn( $a ) => sanitize_text_field( wp_unslash( (string) $a ) ), $answer ), 'strlen' ) );
		if ( ! $answer ) {
			return null;
		}
	} else {
		$answer = 'long_text' === $col['type'] ? sanitize_textarea_field( wp_unslash( (string) $answer ) ) : sanitize_text_field( wp_unslash( (string) $answer ) );
		if ( '' === $answer ) {
			return null;
		}
	}

	switch ( $col['type'] ) {
		case 'text':
			return mb_substr( is_array( $answer ) ? implode( ', ', $answer ) : $answer, 0, 2000 );
		case 'long_text':
			return array( 'text' => mb_substr( is_array( $answer ) ? implode( "\n", $answer ) : $answer, 0, 20000 ) );
		case 'email':
			return is_email( $answer ) ? array( 'email' => $answer, 'text' => $answer ) : null;
		case 'phone':
			$digits  = preg_replace( '/[^\d+]/', '', $answer );
			$country = strtoupper( $q['prefix'] ?: 'US' );
			if ( 'US' === $country && preg_match( '/^\d{10}$/', $digits ) ) {
				$digits = '+1' . $digits;
			}
			return array( 'phone' => $digits, 'countryShortName' => $country );
		case 'dropdown':
			$labels = array_values( array_intersect( (array) $answer, $q['options'] ) );
			return $labels ? array( 'labels' => $labels ) : null;
		case 'status':
			$label = is_array( $answer ) ? $answer[0] : $answer;
			return in_array( $label, $q['options'], true ) ? array( 'label' => $label ) : null;
		case 'checkbox':
			return array( 'checked' => 'true' );
		case 'numbers':
			return is_numeric( $answer ) ? (string) $answer : null;
		case 'date':
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $answer ) ? array( 'date' => $answer ) : null;
		case 'link':
			return esc_url_raw( $answer ) ? array( 'url' => esc_url_raw( $answer ), 'text' => $answer ) : null;
	}
	return null;
}

/**
 * Handle a submission: validate, store as a LeadKit lead, create the monday item.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response
 */
function leadkit_handle_monday_form( $req ) {
	$referer = wp_get_referer() ?: home_url( '/' );
	$token   = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $req->get_param( 'form_token' ) ) );
	$board   = preg_replace( '/\D/', '', (string) $req->get_param( 'board' ) );
	$thanks  = __( 'Thank you — we received your response.', 'leadkit' );

	if ( '' !== trim( (string) $req->get_param( 'leadkit_hp' ) ) ) {
		return leadkit_answer( $req, true, $thanks, $referer );
	}

	/*
	 * Only boards listed in settings. The form token and board arrive from the
	 * browser, so without this anyone could use this site's API token to write
	 * items onto any board it can reach.
	 */
	if ( ! $token || ! $board || ! in_array( $board, leadkit_monday_boards(), true ) ) {
		return leadkit_answer( $req, false, __( 'This form is not set up to accept responses.', 'leadkit' ), $referer );
	}

	$def = leadkit_monday_form_def( $token, $board );
	if ( ! $def || ! $def['active'] ) {
		return leadkit_answer( $req, false, __( 'This form is not accepting responses right now.', 'leadkit' ), $referer );
	}

	$ip = leadkit_client_ip();
	if ( $ip && get_transient( 'leadkit_mrl_' . md5( $ip ) ) ) {
		return leadkit_answer( $req, false, __( 'That has already been sent — give us a moment.', 'leadkit' ), $referer );
	}

	$secret = leadkit_turnstile_secret();
	if ( '' !== $secret && ! leadkit_verify_turnstile( (string) $req->get_param( 'cf-turnstile-response' ), $secret, $ip ) ) {
		return leadkit_answer( $req, false, __( 'Could not verify that you are human. Please try again.', 'leadkit' ), $referer );
	}

	$answers = (array) $req->get_param( 'q' );
	$files   = $req->get_file_params();
	$values  = array();
	$item    = '';
	$summary = array();
	$uploads = array();

	foreach ( $def['questions'] as $q ) {
		$raw = $answers[ $q['id'] ] ?? null;

		if ( 'File' === $q['type'] ) {
			$f = $files[ 'q_' . $q['id'] ] ?? null;
			$ok = $f && UPLOAD_ERR_OK === (int) $f['error'] && $f['size'] > 0 && $f['size'] <= 10 * MB_IN_BYTES
				&& preg_match( '/\.(pdf|docx?|rtf|txt|odt|jpe?g|png)$/i', (string) $f['name'] );
			if ( $ok ) {
				$uploads[ $q['id'] ] = $f;
				$summary[ $q['title'] ] = sanitize_file_name( $f['name'] );
			} elseif ( $q['required'] ) {
				return leadkit_answer( $req, false, '', $referer );
			}
			continue;
		}

		if ( 'Name' === $q['type'] ) {
			$item = mb_substr( sanitize_text_field( wp_unslash( (string) $raw ) ), 0, 255 );
			if ( '' === $item && $q['required'] ) {
				return leadkit_answer( $req, false, '', $referer );
			}
			$summary[ $q['title'] ] = $item;
			continue;
		}

		$col = $def['columns'][ $q['id'] ] ?? null;
		$val = $col ? leadkit_mform_value( $col, $q, $raw ) : null;
		if ( null === $val ) {
			if ( $q['required'] ) {
				return leadkit_answer( $req, false, '', $referer );
			}
			continue;
		}
		$values[ $q['id'] ]     = $val;
		$summary[ $q['title'] ] = is_array( $raw ) ? implode( ', ', array_map( 'sanitize_text_field', $raw ) ) : sanitize_text_field( wp_unslash( (string) $raw ) );
	}

	if ( $ip ) {
		set_transient( 'leadkit_mrl_' . md5( $ip ), 1, 20 );
	}

	// Tracking: each value to the text column titled the same, if the board has one.
	$tracking = json_decode( (string) $req->get_param( 'tracking' ), true );
	$by_title = array();
	foreach ( $def['columns'] as $cid => $c ) {
		if ( in_array( $c['type'], array( 'text', 'long_text' ), true ) ) {
			$by_title[ strtolower( $c['title'] ) ] = $cid;
		}
	}
	if ( is_array( $tracking ) ) {
		foreach ( $tracking as $k => $v ) {
			$cid = $by_title[ strtolower( (string) $k ) ] ?? '';
			if ( $cid && ! isset( $values[ $cid ] ) && is_scalar( $v ) && '' !== (string) $v ) {
				$v               = sanitize_text_field( (string) $v );
				$values[ $cid ] = 'long_text' === $def['columns'][ $cid ]['type'] ? array( 'text' => $v ) : mb_substr( $v, 0, 2000 );
			}
		}
	}

	$item = $item ?: __( 'New response', 'leadkit' );

	// ---- store first ----------------------------------------------------------
	$analytics = (string) $req->get_param( 'analyticsData' );
	$decoded   = json_decode( $analytics, true );
	$visitor   = is_array( $decoded ) ? leadkit_clean_visitor_id( $decoded['id'] ?? '' ) : '';
	$email     = '';
	foreach ( $values as $v ) {
		if ( is_array( $v ) && isset( $v['email'] ) ) {
			$email = $v['email'];
			break;
		}
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => LEADKIT_CPT,
			'post_status' => 'publish',
			'post_title'  => $item . ' — ' . $def['title'],
		),
		true
	);
	if ( ! is_wp_error( $post_id ) ) {
		update_post_meta( $post_id, '_leadkit_name', $item );
		update_post_meta( $post_id, '_leadkit_email', $email );
		update_post_meta( $post_id, '_leadkit_project_type', $def['title'] );
		update_post_meta(
			$post_id,
			'_leadkit_message',
			implode( "\n", array_map( fn( $k, $v ) => $k . ': ' . $v, array_keys( $summary ), $summary ) )
		);
		update_post_meta( $post_id, '_leadkit_source_url', esc_url_raw( $referer ) );
		update_post_meta( $post_id, '_leadkit_ip', $ip );
		if ( $visitor ) {
			update_post_meta( $post_id, '_leadkit_visitor_id', $visitor );
		}
		if ( $analytics ) {
			update_post_meta( $post_id, '_leadkit_analytics', wp_json_encode( $decoded ?: $analytics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			leadkit_store_campaign( $post_id, is_array( $decoded ) ? $decoded : array() );
		}
		update_post_meta( $post_id, '_leadkit_monday_board', $board );
	}

	// ---- then monday.com ----------------------------------------------------------
	$group = $def['group'] ? ', group_id: $g' : '';
	$vars  = array( 'b' => $board, 'n' => $item, 'v' => wp_json_encode( (object) $values ) );
	if ( $def['group'] ) {
		$vars['g'] = $def['group'];
	}
	$made    = leadkit_monday(
		'mutation ($b: ID!, $n: String!, $v: JSON' . ( $def['group'] ? ', $g: String' : '' ) . ') { create_item(board_id: $b, item_name: $n, column_values: $v' . $group . ', create_labels_if_missing: false) { id } }',
		$vars
	);
	$item_id = (string) ( $made['create_item']['id'] ?? '' );

	if ( $item_id ) {
		foreach ( $uploads as $col => $f ) {
			leadkit_monday_upload( $item_id, $col, $f );
		}
		if ( $visitor ) {
			// Prime the return-visit lookup so it does not have to search.
			$key   = leadkit_visitor_key( $visitor );
			$state = get_option( $key, array() );
			$state = is_array( $state ) ? $state : array();
			$state['monday'][ $board ] = array( 'item' => $item_id, 'checked' => time() );
			update_option( $key, $state, false );
		}
	}

	if ( ! is_wp_error( $post_id ) ) {
		update_post_meta( $post_id, '_leadkit_notified', $item_id ? '1' : '0' );
		if ( $item_id ) {
			update_post_meta( $post_id, '_leadkit_monday_item', $item_id );
		} else {
			update_post_meta( $post_id, '_leadkit_notify_error', 'monday.com: ' . (string) get_option( 'leadkit_last_monday_error', 'no response' ) );
		}
		/** This action is documented in includes/submit.php */
		do_action( 'leadkit_lead_received', $post_id, array( 'name' => $item, 'email' => $email, 'monday_item' => $item_id ) );
	}

	// The lead is saved either way, so the visitor is told it worked.
	return leadkit_answer( $req, true, $thanks, $referer );
}

/**
 * Upload a file to a file column (multipart, the API's /v2/file endpoint).
 *
 * @param string $item_id Item.
 * @param string $column  Column id.
 * @param array  $file    PHP upload array.
 * @return bool
 */
function leadkit_monday_upload( $item_id, $column, $file ) {
	$token = leadkit_monday_token();
	if ( ! $token || ! is_readable( $file['tmp_name'] ) ) {
		return false;
	}
	$boundary = 'leadkit' . wp_generate_password( 16, false );
	$name     = sanitize_file_name( $file['name'] );
	$query    = 'mutation ($file: File!) { add_file_to_column(item_id: ' . (int) $item_id . ', column_id: "' . preg_replace( '/[^a-z0-9_]/i', '', $column ) . '", file: $file) { id } }';
	$body     = "--$boundary\r\nContent-Disposition: form-data; name=\"query\"\r\n\r\n$query\r\n"
		. "--$boundary\r\nContent-Disposition: form-data; name=\"variables[file]\"; filename=\"$name\"\r\n"
		. "Content-Type: application/octet-stream\r\n\r\n" . file_get_contents( $file['tmp_name'] ) . "\r\n" // phpcs:ignore WordPress.WP.AlternativeFunctions
		. "--$boundary--\r\n";

	$res = wp_remote_post(
		apply_filters( 'leadkit/monday_file_url', LEADKIT_MONDAY_API . '/file' ),
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => $token,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			) + leadkit_monday_version_header(),
			'body'    => $body,
		)
	);
	$json = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return ! empty( $json['data']['add_file_to_column']['id'] );
}
