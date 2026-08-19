<?php
/**
 * Where a lead goes the moment it arrives.
 *
 * A custom post type rather than a table, for one reason: a lead that lands in
 * `wp_posts` is visible in wp-admin the second it is stored, searchable, and
 * survives the plugin being deactivated. A bespoke table is invisible to
 * everything WordPress already gives you, and the first thing anyone asks for —
 * "can I see the ones from last week?" — needs building by hand.
 *
 * The post is stored BEFORE the notification is attempted (see submit.php).
 * Mail is the part that fails; the record must not depend on it.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_CPT = 'leadkit_lead';

/**
 * Register the lead post type.
 *
 * Not public: a lead has no front-end URL, no archive, and must never appear in
 * a search result or a sitemap. `show_ui` without `public` is exactly that —
 * an admin-only record.
 */
add_action(
	'init',
	function () {
		register_post_type(
			LEADKIT_CPT,
			array(
				'labels'          => array(
					'name'               => __( 'Leads', 'leadkit' ),
					'singular_name'      => __( 'Lead', 'leadkit' ),
					'menu_name'          => __( 'Leads', 'leadkit' ),
					'all_items'          => __( 'All Leads', 'leadkit' ),
					'search_items'       => __( 'Search Leads', 'leadkit' ),
					'not_found'          => __( 'No leads yet.', 'leadkit' ),
					'not_found_in_trash' => __( 'No leads in the trash.', 'leadkit' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-email-alt',
				'menu_position'   => 26,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					// A lead is a record of something that happened. Nobody
					// authors one by hand, so the "Add New" button is a way to
					// create confusing data and nothing else.
					'create_posts' => 'do_not_allow',
				),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
	}
);

/**
 * The list table, showing what you actually need to act on a lead.
 *
 * The default columns are a title and a date, which for a lead means the name
 * and nothing else — you would have to open every one to find a phone number.
 */
add_filter(
	'manage_' . LEADKIT_CPT . '_posts_columns',
	function () {
		return array(
			'cb'            => '<input type="checkbox" />',
			'title'         => __( 'Name', 'leadkit' ),
			'leadkit_email' => __( 'Email', 'leadkit' ),
			'leadkit_phone' => __( 'Phone', 'leadkit' ),
			'leadkit_type'  => __( 'Project', 'leadkit' ),
			'leadkit_msg'   => __( 'Message', 'leadkit' ),
			'leadkit_sent'  => __( 'Notified', 'leadkit' ),
			'date'          => __( 'Received', 'leadkit' ),
		);
	}
);

add_action(
	'manage_' . LEADKIT_CPT . '_posts_custom_column',
	function ( $col, $post_id ) {
		switch ( $col ) {
			case 'leadkit_email':
				$v = (string) get_post_meta( $post_id, '_leadkit_email', true );
				echo $v ? '<a href="mailto:' . esc_attr( $v ) . '">' . esc_html( $v ) . '</a>' : '—';
				break;
			case 'leadkit_phone':
				$v = (string) get_post_meta( $post_id, '_leadkit_phone', true );
				echo $v ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $v ) ) . '">' . esc_html( $v ) . '</a>' : '—';
				break;
			case 'leadkit_type':
				echo esc_html( (string) get_post_meta( $post_id, '_leadkit_project_type', true ) ?: '—' );
				break;
			case 'leadkit_msg':
				$m = (string) get_post_meta( $post_id, '_leadkit_message', true );
				echo esc_html( mb_strimwidth( $m, 0, 90, '…' ) );
				break;
			case 'leadkit_sent':
				/*
				 * The single most useful column here. A lead stored but never
				 * emailed looks identical to a healthy one until someone
				 * notices the inbox is empty, which is usually weeks later.
				 */
				$sent = get_post_meta( $post_id, '_leadkit_notified', true );
				if ( '1' === $sent ) {
					echo '<span style="color:#00794b">' . esc_html__( 'sent', 'leadkit' ) . '</span>';
				} else {
					$err = (string) get_post_meta( $post_id, '_leadkit_notify_error', true );
					echo '<span style="color:#b32d2e;font-weight:600">' . esc_html__( 'NOT SENT', 'leadkit' ) . '</span>';
					if ( $err ) {
						echo '<br><small>' . esc_html( mb_strimwidth( $err, 0, 60, '…' ) ) . '</small>';
					}
				}
				break;
		}
	},
	10,
	2
);

/**
 * The whole lead on the edit screen, including the analytics the tracker
 * attached — which is the reason that tracker exists.
 */
add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'leadkit_detail',
			__( 'Lead', 'leadkit' ),
			function ( $post ) {
				$rows = array(
					__( 'Name', 'leadkit' )    => get_post_meta( $post->ID, '_leadkit_name', true ),
					__( 'Email', 'leadkit' )   => get_post_meta( $post->ID, '_leadkit_email', true ),
					__( 'Phone', 'leadkit' )   => get_post_meta( $post->ID, '_leadkit_phone', true ),
					__( 'Project', 'leadkit' ) => get_post_meta( $post->ID, '_leadkit_project_type', true ),
					__( 'Page', 'leadkit' )    => get_post_meta( $post->ID, '_leadkit_source_url', true ),
					__( 'IP', 'leadkit' )      => get_post_meta( $post->ID, '_leadkit_ip', true ),
				);
				echo '<table class="widefat striped"><tbody>';
				foreach ( $rows as $label => $value ) {
					echo '<tr><th style="width:9rem">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ?: '—' ) . '</td></tr>';
				}
				echo '</tbody></table>';

				echo '<p><strong>' . esc_html__( 'Message', 'leadkit' ) . '</strong></p>';
				echo '<div style="white-space:pre-wrap;background:#f6f7f7;padding:1rem;border:1px solid #dcdcde">'
					. esc_html( (string) get_post_meta( $post->ID, '_leadkit_message', true ) ) . '</div>';

				$analytics = (string) get_post_meta( $post->ID, '_leadkit_analytics', true );
				if ( $analytics ) {
					echo '<p><strong>' . esc_html__( 'Session', 'leadkit' ) . '</strong></p>';
					echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:1rem;border:1px solid #dcdcde;max-height:22em;overflow:auto">'
						. esc_html( $analytics ) . '</pre>';
				}
			},
			LEADKIT_CPT,
			'normal',
			'high'
		);
	}
);

/** Newest first — the only order that makes sense for an inbox. */
add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() && $q->is_main_query() && LEADKIT_CPT === $q->get( 'post_type' ) && ! $q->get( 'orderby' ) ) {
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'DESC' );
		}
	}
);

/**
 * A count bubble on the menu, for leads nobody has opened yet.
 *
 * Unread is tracked as the ABSENCE of a meta key rather than its presence, so
 * every lead that has ever arrived counts as unread until someone opens it —
 * including the ones stored before this was written.
 */
add_action(
	'load-post.php',
	function () {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id && LEADKIT_CPT === get_post_type( $id ) ) {
			update_post_meta( $id, '_leadkit_read', '1' );
		}
	}
);

add_filter(
	'add_menu_classes',
	function ( $menu ) {
		$unread = get_posts(
			array(
				'post_type'   => LEADKIT_CPT,
				'post_status' => 'publish',
				'numberposts' => 100,
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_leadkit_read',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$n = count( $unread );
		if ( ! $n ) {
			return $menu;
		}
		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . LEADKIT_CPT === $item[2] ) {
				$menu[ $i ][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $n . '</span></span>';
				break;
			}
		}
		return $menu;
	}
);
