<?php
/**
 * Settings → LeadKit.
 *
 * Everything a new project needs to type in one screen: where submissions go,
 * where the tracker syncs, and the Turnstile site key.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'LeadKit', 'leadkit' ),
			__( 'LeadKit', 'leadkit' ),
			'manage_options',
			'leadkit',
			'leadkit_render_settings_page'
		);
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'leadkit',
			'leadkit_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $raw ) {
					$raw = is_array( $raw ) ? $raw : array();
					return array(
						'submit_url'        => esc_url_raw( $raw['submit_url'] ?? '', array( 'http', 'https' ) ) ?: sanitize_text_field( $raw['submit_url'] ?? '' ),
						'sync_url'          => sanitize_text_field( $raw['sync_url'] ?? '' ),
						'track_url'         => sanitize_text_field( $raw['track_url'] ?? '' ),
						'turnstile_sitekey' => sanitize_text_field( $raw['turnstile_sitekey'] ?? '' ),
						'storage_prefix'    => preg_replace( '/[^a-zA-Z0-9_]/', '', $raw['storage_prefix'] ?? 'leadkit' ) ?: 'leadkit',
					);
				},
			)
		);
	}
);

/**
 * The settings screen.
 */
function leadkit_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$opts   = leadkit_options();
	$fields = array(
		'submit_url'        => array( __( 'Form submit endpoint', 'leadkit' ), __( 'The URL the lead form POSTs to. Relative (/api/submit) or absolute.', 'leadkit' ) ),
		'sync_url'          => array( __( 'Tracker sync endpoint', 'leadkit' ), __( 'Receives background analytics for known leads (JSON).', 'leadkit' ) ),
		'track_url'         => array( __( 'Interaction endpoint', 'leadkit' ), __( 'Receives the first phone/email click with full session context (JSON).', 'leadkit' ) ),
		'turnstile_sitekey' => array( __( 'Turnstile site key', 'leadkit' ), __( 'Leave empty to render the form without bot protection. Cloudflare’s testing key 1x00000000000000000000AA renders a dummy widget on any domain.', 'leadkit' ) ),
		'storage_prefix'    => array( __( 'Storage prefix', 'leadkit' ), __( 'Prefix for the tracker’s localStorage keys. Change per project if endpoints are shared.', 'leadkit' ) ),
	);
	?>
	<div class="wrap">
		<h1><?= esc_html__( 'LeadKit', 'leadkit' ) ?></h1>
		<form action="options.php" method="post">
			<?php settings_fields( 'leadkit' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $meta ) : ?>
				<tr>
					<th scope="row"><label for="leadkit-<?= esc_attr( $key ) ?>"><?= esc_html( $meta[0] ) ?></label></th>
					<td>
						<input name="leadkit_options[<?= esc_attr( $key ) ?>]" type="text" id="leadkit-<?= esc_attr( $key ) ?>" value="<?= esc_attr( $opts[ $key ] ) ?>" class="regular-text">
						<p class="description"><?= esc_html( $meta[1] ) ?></p>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<p>
			<?= esc_html__( 'Render the form with the [leadkit_form] shortcode, or from a theme with leadkit_form( ["class_prefix" => "your-prefix"] ). The endpoint payload contract is documented in the plugin’s readme.txt.', 'leadkit' ) ?>
		</p>
	</div>
	<?php
}
