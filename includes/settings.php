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
						'turnstile_secret'    => sanitize_text_field( $raw['turnstile_secret'] ?? '' ),
						'turnstile_actions'   => sanitize_text_field( $raw['turnstile_actions'] ?? '' ),
						'turnstile_hostnames' => sanitize_text_field( $raw['turnstile_hostnames'] ?? '' ),
						'turnstile_fail_open' => empty( $raw['turnstile_fail_open'] ) ? '' : '1',
						'notify_email'      => sanitize_email( $raw['notify_email'] ?? '' ),
						'from_email'        => sanitize_email( $raw['from_email'] ?? '' ),
						'from_name'         => sanitize_text_field( $raw['from_name'] ?? '' ),
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
		'from_email'        => array( __( 'Send FROM this address', 'leadkit' ), __( 'Must be on a domain verified with your mail provider — not necessarily this site’s domain. An address on a domain with no SPF is what spam looks like, and it will be filtered.', 'leadkit' ) ),
		'from_name'         => array( __( 'Send FROM this name', 'leadkit' ), __( 'The sender name recipients see. Defaults to the site title.', 'leadkit' ) ),
		'notify_email'      => array( __( 'Send leads to', 'leadkit' ), __( 'Where each enquiry is emailed. Empty uses the site admin address. Every lead is saved under Leads either way, so a mail problem never loses one.', 'leadkit' ) ),
		'submit_url'        => array( __( 'Form submit endpoint', 'leadkit' ), __( 'Leave EMPTY to use this plugin’s own endpoint, which is what almost every site wants. Only set this if you have your own service handling submissions.', 'leadkit' ) ),
		'sync_url'          => array( __( 'Tracker sync endpoint', 'leadkit' ), __( 'Receives background analytics for known leads (JSON).', 'leadkit' ) ),
		'track_url'         => array( __( 'Interaction endpoint', 'leadkit' ), __( 'Receives the first phone/email click with full session context (JSON).', 'leadkit' ) ),
		'turnstile_sitekey' => array( __( 'Turnstile site key', 'leadkit' ), __( 'Leave empty to render the form without bot protection. Cloudflare’s testing key 1x00000000000000000000AA renders a dummy widget on any domain and ALWAYS passes — it protects nothing.', 'leadkit' ) ),
		'turnstile_secret'  => array( __( 'Turnstile secret key', 'leadkit' ), __( 'Required for the check to mean anything. Without it the widget is decorative: the site key renders it, the secret is what verifies it server-side.', 'leadkit' ) ),
		'turnstile_actions'   => array( __( 'Accepted Turnstile actions', 'leadkit' ), __( 'Comma-separated. Must match the data-action on your widgets. A token minted for a different action is refused. Empty falls back to leadkit-form.', 'leadkit' ) ),
		'turnstile_hostnames' => array( __( 'Extra Turnstile hostnames', 'leadkit' ), __( 'Comma-separated, ADDED to this site’s own hostname, which is always accepted. Only add hosts you serve the form from. Never add localhost here on a production site — a token solved anywhere would then be accepted, which is the check gone.', 'leadkit' ) ),
		'turnstile_fail_open' => array( __( 'Accept if Cloudflare is unreachable', 'leadkit' ), __( 'Off (recommended): a submission is refused when Turnstile cannot be reached. On: it is accepted unverified — fewer lost enquiries during a Cloudflare outage, but an attacker who can block that call turns the check off.', 'leadkit' ), 'checkbox' ),
		'storage_prefix'    => array( __( 'Storage prefix', 'leadkit' ), __( 'Prefix for the tracker’s localStorage keys. Change per project if endpoints are shared.', 'leadkit' ) ),
	);
	?>
	<?php
	/*
	 * Setup guidance ON the screen, not in a readme nobody opens.
	 *
	 * The two credentials do not live here — they belong in wp-config.php, so
	 * they stay out of database exports — which means the settings screen is
	 * exactly where someone will look for them and not find them. So it says
	 * where they go, and it REPORTS whether they arrived: a live readout beats
	 * an instruction, because it answers "did my edit work" without needing a
	 * test submission to find out.
	 */
	$leadkit_have_api       = '' !== leadkit_api_key();
	$leadkit_have_turnstile = '' !== leadkit_turnstile_secret();
	$leadkit_yes            = '<span style="color:#00794b;font-weight:600">' . esc_html__( 'detected', 'leadkit' ) . '</span>';
	$leadkit_no             = '<span style="color:#b32d2e;font-weight:600">' . esc_html__( 'not set', 'leadkit' ) . '</span>';
	?>
	<div class="wrap">
		<h1><?= esc_html__( 'LeadKit', 'leadkit' ) ?></h1>

		<div class="card" style="max-width:46rem;padding:1rem 1.25rem">
			<h2 style="margin-top:0"><?= esc_html__( 'Setup', 'leadkit' ) ?></h2>

			<p>
				<strong><?= esc_html__( 'Where enquiries go.', 'leadkit' ) ?></strong>
				<?= esc_html__( 'Every submission is saved under Leads before any email is attempted, so a mail problem shows as a red flag next to an enquiry you still have — never as one you lost.', 'leadkit' ) ?>
			</p>

			<h3><?= esc_html__( 'Two secrets go in wp-config.php, not on this page', 'leadkit' ) ?></h3>
			<p class="description">
				<?= esc_html__( 'A credential saved here travels in every database export and sits in every backup. In wp-config.php it stays in one file that is never exported. Both constants take precedence over the matching field below.', 'leadkit' ) ?>
			</p>
<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:.85rem;overflow-x:auto">define( 'LEADKIT_RESEND_API_KEY',   're_…' );
define( 'LEADKIT_TURNSTILE_SECRET', '0x4AAA…' );</pre>
			<p>
				<?= esc_html__( 'Paste them immediately above the line that reads', 'leadkit' ) ?>
				<code>/* That&rsquo;s all, stop editing! */</code><?= esc_html__( '. Below that line they are read too late to work.', 'leadkit' ) ?>
			</p>

			<table class="widefat striped" style="margin:.75rem 0">
				<tbody>
					<tr>
						<td style="width:16rem"><code>LEADKIT_RESEND_API_KEY</code></td>
						<td><?= $leadkit_have_api ? $leadkit_yes : $leadkit_no // phpcs:ignore WordPress.Security.EscapeOutput — escaped above. ?></td>
						<td><a href="https://resend.com/api-keys" target="_blank" rel="noopener"><?= esc_html__( 'resend.com/api-keys', 'leadkit' ) ?></a> — <?= esc_html__( 'Create API Key, permission “Sending access”. It is shown once; if you miss it, make another.', 'leadkit' ) ?></td>
					</tr>
					<tr>
						<td><code>LEADKIT_TURNSTILE_SECRET</code></td>
						<td><?= $leadkit_have_turnstile ? $leadkit_yes : $leadkit_no // phpcs:ignore WordPress.Security.EscapeOutput — escaped above. ?></td>
						<td><a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener"><?= esc_html__( 'Cloudflare dashboard → Turnstile', 'leadkit' ) ?></a> — <?= esc_html__( 'open your widget, Settings, reveal Secret key. The site key beside it is public and goes in the field below.', 'leadkit' ) ?></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?= esc_html__( 'If one says “not set” after you have edited wp-config.php: the line is below the “stop editing” marker, or misspelled, or the file was not saved.', 'leadkit' ) ?>
			</p>

			<h3><?= esc_html__( 'The From address must be a domain your provider has verified', 'leadkit' ) ?></h3>
			<p class="description">
				<?= esc_html__( 'Not necessarily this site’s domain. Sending as a domain with no SPF record is what spam looks like, and it will be filtered. Verify a domain at resend.com/domains, then use an address on it. The visitor’s own address is set as Reply-To automatically, so replying reaches them.', 'leadkit' ) ?>
			</p>

			<h3><?= esc_html__( 'Leave “Form submit endpoint” empty', 'leadkit' ) ?></h3>
			<p class="description">
				<?= esc_html__( 'Empty means this plugin handles submissions itself, which is what almost every site wants. Only fill it in if a separate service receives your forms.', 'leadkit' ) ?>
				<?= esc_html__( 'Current endpoint:', 'leadkit' ) ?> <code><?= esc_html( leadkit_submit_endpoint() ) ?></code>
			</p>
		</div>

		<form action="options.php" method="post">
			<?php settings_fields( 'leadkit' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $meta ) : ?>
				<tr>
					<th scope="row"><label for="leadkit-<?= esc_attr( $key ) ?>"><?= esc_html( $meta[0] ) ?></label></th>
					<td>
						<?php if ( 'checkbox' === ( $meta[2] ?? 'text' ) ) : ?>
						<label>
							<input name="leadkit_options[<?= esc_attr( $key ) ?>]" type="checkbox" id="leadkit-<?= esc_attr( $key ) ?>" value="1" <?php checked( '1', $opts[ $key ] ); ?>>
							<?= esc_html__( 'Enabled', 'leadkit' ) ?>
						</label>
						<?php else : ?>
						<input name="leadkit_options[<?= esc_attr( $key ) ?>]" type="text" id="leadkit-<?= esc_attr( $key ) ?>" value="<?= esc_attr( $opts[ $key ] ) ?>" class="regular-text">
						<?php endif; ?>
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
