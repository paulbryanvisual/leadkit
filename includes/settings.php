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


/**
 * Resolve a write-only secret field on save.
 *
 * @param string $submitted What the form sent.
 * @param string $key       Option key.
 * @return string
 */
function leadkit_keep_secret( $submitted, $key ) {
	$submitted = trim( sanitize_text_field( (string) $submitted ) );
	$existing  = (string) ( get_option( 'leadkit_options', array() )[ $key ] ?? '' );

	if ( '-' === $submitted ) {
		return '';
	}

	return '' === $submitted ? $existing : $submitted;
}

/**
 * Resolve the Visits Worker URL on save: https, with no query or fragment.
 *
 * Anything else is refused with a notice and the URL saved before is kept,
 * so a typo cannot quietly stop every visit being kept. An empty box turns
 * it off.
 *
 * @param string $submitted What the form sent.
 * @return string
 */
function leadkit_clean_visits_url( $submitted ) {
	$url = trim( (string) $submitted );
	if ( '' === $url ) {
		return '';
	}
	if ( preg_match( '#^https://[^/\s"\'<>?\#][^\s"\'<>?\#]*$#', $url ) ) {
		return esc_url_raw( $url, array( 'https' ) );
	}
	add_settings_error(
		'leadkit_options',
		'leadkit_visits_url',
		__( 'Visits Worker URL not saved: it must start with https:// and have no ? or #, e.g. https://visits.example.org. The URL it had is kept; the other settings were saved.', 'leadkit' )
	);
	return (string) ( get_option( 'leadkit_options', array() )['visits_url'] ?? '' );
}


/**
 * Sanitise a comma-separated list of recipients.
 *
 * Invalid entries are dropped rather than saved, so a typo cannot quietly break
 * delivery for everyone on the line — wp_mail() refuses the whole message if any
 * recipient is malformed, which would turn one wrong character into no
 * notifications at all.
 *
 * @param string $raw Comma-separated addresses.
 * @return string
 */
function leadkit_clean_email_list( $raw ) {
	$out = array();

	foreach ( explode( ',', (string) $raw ) as $candidate ) {
		$email = sanitize_email( trim( $candidate ) );
		if ( $email && is_email( $email ) ) {
			$out[] = $email;
		}
	}

	return implode( ', ', array_unique( $out ) );
}

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
						/*
						 * Write-only fields. The form never renders the stored
						 * value, so an empty box means "unchanged" — treating it
						 * as "clear" would wipe the key every time anyone saved
						 * an unrelated setting.
						 *
						 * Clearing is still possible: type a single "-".
						 */
						'turnstile_secret'    => leadkit_keep_secret( $raw['turnstile_secret'] ?? '', 'turnstile_secret' ),
						'resend_api_key'      => leadkit_keep_secret( $raw['resend_api_key'] ?? '', 'resend_api_key' ),
						'turnstile_actions'   => sanitize_text_field( $raw['turnstile_actions'] ?? '' ),
						'turnstile_hostnames' => sanitize_text_field( $raw['turnstile_hostnames'] ?? '' ),
						'turnstile_fail_open' => empty( $raw['turnstile_fail_open'] ) ? '' : '1',
						'notify_email'      => leadkit_clean_email_list( $raw['notify_email'] ?? '' ),
						'monday_token'      => leadkit_keep_secret( $raw['monday_token'] ?? '', 'monday_token' ),
						'monday_boards'     => implode( ', ', array_unique( preg_match_all( '/\d{5,}/', (string) ( $raw['monday_boards'] ?? '' ), $mb ) ? $mb[0] : array() ) ),
						'notify_returns'    => empty( $raw['notify_returns'] ) ? '' : '1',
						'monday_people_board' => preg_match( '/\d{5,}/', (string) ( $raw['monday_people_board'] ?? '' ), $pb ) ? $pb[0] : '',
						'monday_visitors_board' => preg_match( '/\d{5,}/', (string) ( $raw['monday_visitors_board'] ?? '' ), $vb ) ? $vb[0] : '',
						'monday_visits_board' => preg_match( '/\d{5,}/', (string) ( $raw['monday_visits_board'] ?? '' ), $vs ) ? $vs[0] : '',
						'monday_donations_board' => preg_match( '/\d{5,}/', (string) ( $raw['monday_donations_board'] ?? '' ), $db ) ? $db[0] : '',
						'visits_url'        => leadkit_clean_visits_url( $raw['visits_url'] ?? '' ),
						'visits_secret'     => leadkit_keep_secret( $raw['visits_secret'] ?? '', 'visits_secret' ),
						// Paths, each with slashes both ends: "/help/". A lone "/" is everything.
						'untracked_paths'   => implode( ', ', array_unique( array_filter( array_map( function ( $p ) {
							$p = preg_replace( '#[^a-z0-9/._~%-]#', '', (string) wp_parse_url( strtolower( trim( (string) $p ) ), PHP_URL_PATH ) );
							if ( '' === trim( $p, '/' ) ) {
								return '' === $p ? '' : '/';
							}
							return '/' . trim( $p, '/' ) . '/';
						}, preg_split( '/[\s,]+/', (string) ( $raw['untracked_paths'] ?? '' ) ) ) ) ) ),
						'from_email'        => sanitize_email( $raw['from_email'] ?? '' ),
						'from_name'         => sanitize_text_field( $raw['from_name'] ?? '' ),
						'storage_prefix'    => preg_replace( '/[^a-zA-Z0-9_]/', '', $raw['storage_prefix'] ?? 'leadkit' ) ?: 'leadkit',
						'donation_hosts'    => implode( ', ', array_filter( array_map( function ( $h ) {
							$h = strtolower( trim( (string) $h ) );
							$h = preg_replace( '#^https?://#', '', $h );
							return preg_replace( '/[^a-z0-9.-]/', '', strtok( $h, '/' ) ?: '' );
						}, explode( ',', (string) ( $raw['donation_hosts'] ?? '' ) ) ) ) ),
						'form_hosts'        => implode( ', ', array_filter( array_map( function ( $h ) {
							$h = strtolower( trim( (string) $h ) );
							$h = preg_replace( '#^https?://#', '', $h );
							return preg_replace( '/[^a-z0-9.-]/', '', strtok( $h, '/' ) ?: '' );
						}, explode( ',', (string) ( $raw['form_hosts'] ?? '' ) ) ) ) ),
						'history_param'     => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $raw['history_param'] ?? 'campaign_history' ) ),
						'attribution_days'  => (string) max( 0, min( 3650, (int) ( $raw['attribution_days'] ?? 0 ) ) ),
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
		'resend_api_key'    => array( __( 'Resend API key', 'leadkit' ), __( 'Paste the key from resend.com/api-keys. Without it, mail goes out through the host’s own transport, which on shared hosting usually means the spam folder. Never shown again once saved; leave empty to keep the current one, or type a single - to clear it.', 'leadkit' ), 'secret' ),
		'from_email'        => array( __( 'Send FROM this address', 'leadkit' ), __( 'Must be on a domain verified with your mail provider — not necessarily this site’s domain. An address on a domain with no SPF is what spam looks like, and it will be filtered.', 'leadkit' ) ),
		'from_name'         => array( __( 'Send FROM this name', 'leadkit' ), __( 'The sender name recipients see. Defaults to the site title.', 'leadkit' ) ),
		'notify_email'      => array( __( 'Send leads to', 'leadkit' ), __( 'One address, or several separated by commas — everyone listed gets a copy. Empty uses the site admin address. Anything that is not a valid address is dropped when you save, rather than saved and silently breaking delivery for the rest. Every lead is saved under Leads either way, so a mail problem never loses one.', 'leadkit' ) ),
		'submit_url'        => array( __( 'Form submit endpoint', 'leadkit' ), __( 'Leave EMPTY to use this plugin’s own endpoint, which is what almost every site wants. Only set this if you have your own service handling submissions.', 'leadkit' ) ),
		'sync_url'          => array( __( 'Tracker sync endpoint', 'leadkit' ), __( 'Receives background analytics for known leads (JSON).', 'leadkit' ) ),
		'track_url'         => array( __( 'Interaction endpoint', 'leadkit' ), __( 'Receives the first phone/email click with full session context (JSON).', 'leadkit' ) ),
		'turnstile_sitekey' => array( __( 'Turnstile site key', 'leadkit' ), __( 'Leave empty to render the form without bot protection. Cloudflare’s testing key 1x00000000000000000000AA renders a dummy widget on any domain and ALWAYS passes — it protects nothing.', 'leadkit' ) ),
		'turnstile_secret'  => array( __( 'Turnstile secret key', 'leadkit' ), __( 'Required for the check to mean anything — the site key renders the widget, the secret is what verifies it server-side. Never shown again once saved; leave empty to keep the current one, or type a single - to clear it.', 'leadkit' ), 'secret' ),
		'turnstile_actions'   => array( __( 'Accepted Turnstile actions', 'leadkit' ), __( 'Comma-separated. Must match the data-action on your widgets. A token minted for a different action is refused. Empty falls back to leadkit-form.', 'leadkit' ) ),
		'turnstile_hostnames' => array( __( 'Extra Turnstile hostnames', 'leadkit' ), __( 'Comma-separated, ADDED to this site’s own hostname, which is always accepted. Only add hosts you serve the form from. Never add localhost here on a production site — a token solved anywhere would then be accepted, which is the check gone.', 'leadkit' ) ),
		'turnstile_fail_open' => array( __( 'Accept if Cloudflare is unreachable', 'leadkit' ), __( 'Off (recommended): a submission is refused when Turnstile cannot be reached. On: it is accepted unverified — fewer lost enquiries during a Cloudflare outage, but an attacker who can block that call turns the check off.', 'leadkit' ), 'checkbox' ),
		'donation_hosts'    => array( __( 'Donation hosts', 'leadkit' ), __( 'Comma-separated domains whose links get the visitor’s campaign added, packed into utm_source as labeled pairs with the visitor id: medium.email__source.mailchimp__campaign.spring-appeal__content.email-1__visitorid.… Subdomains match, so givelively.org covers secure.givelively.org. Add data-leadkit-keep to a link to leave it alone. Empty turns stamping off.', 'leadkit' ) ),
		'form_hosts'        => array( __( 'Form hosts', 'leadkit' ), __( 'Comma-separated domains of outside forms that take hidden fields (default forms.monday.com, wkf.ms). Links and embeds to them get utm_source, utm_medium, utm_campaign, utm_content and utm_term for the campaign with the credit, plus the full campaign history. On a monday.com form, add a form tag with each of those names to capture them as columns. Empty turns it off.', 'leadkit' ) ),
		'history_param'     => array( __( 'History parameter', 'leadkit' ), __( 'Name of the parameter carrying every campaign the visitor arrived from, oldest first, e.g. email__mailchimp__spring-appeal__email-1 > social__instagram__spring-appeal__post-3. Default campaign_history. Empty leaves it off.', 'leadkit' ) ),
		'attribution_days'  => array( __( 'Campaign credit (days)', 'leadkit' ), __( 'How long the latest campaign link a visitor arrived from keeps the credit. 0 (the default) means for as long as their browser keeps the data; a newer campaign always replaces it. Every campaign touch is kept either way.', 'leadkit' ) ),
		'notify_returns'    => array( __( 'Email me when a lead comes back', 'leadkit' ), __( 'Once a day at most per lead: when someone who already sent the form (or tapped the phone number) visits again, email the pages they looked at. The visits are saved on the lead either way.', 'leadkit' ), 'checkbox' ),
		'monday_token'      => array( __( 'monday.com API token', 'leadkit' ), __( 'From monday.com: your avatar → Developers → My access tokens. Used only on the server, to add return visits to the matching monday.com item. Never shown again once saved; leave empty to keep it, a single - clears it. Or define LEADKIT_MONDAY_TOKEN in wp-config.php.', 'leadkit' ), 'secret' ),
		'monday_boards'     => array( __( 'monday.com boards', 'leadkit' ), __( 'Board ids (the number in the board URL), comma-separated. Each needs a visitor_id column, which a form tag named visitor_id creates. When a returning visitor matches an item, LeadKit fills "Return visits log", "Last seen" and "Return visits" columns (creating them the first time) and posts an update on the item once a day. Set the form to redirect after submitting to a page on this site with ?leadkit_lead=<form name> so LeadKit knows they submitted.', 'leadkit' ) ),
		'monday_people_board' => array( __( 'monday.com People board', 'leadkit' ), __( 'Optional. A board id for one row per person, matched by email. Every submission is connected to its person, the person\'s columns are rolled up (visitor ids, submissions, engagement, first and latest source, first and last seen), and return visits are added to the person instead of each submission. Columns are created the first time, so an empty board is fine.', 'leadkit' ) ),
		'monday_visitors_board' => array( __( 'monday.com Visitors board', 'leadkit' ), __( 'Optional. A board id for one row per browser, keyed by a visitorid column — the same id the forms send and every donation link carries. Each Donate click and form submission updates the row: First and Last seen, Visits, First and Latest source, First and Latest referrer, Donate clicks and the Journey log, and connects it to the submission (Form submissions) and the person (Person, with a People board). Missing columns are created. Gifts connect to the row from the Donations board, by an automation matching visitorid.', 'leadkit' ) ),
		'monday_visits_board' => array( __( 'monday.com Visits board', 'leadkit' ), __( 'Optional, with a Visitors board. A board id for one row per visit — a run of pages with no gap over 30 minutes — connected to its row on the Visitors board: Started, Referrer (the site that sent the visit), Source, Landing page, Pages, Seconds on site, Donate clicks, Forms and the Journey. Written with the Visitors row, earlier visits included. Missing columns are created.', 'leadkit' ) ),
		'monday_donations_board' => array( __( 'monday.com Donations board', 'leadkit' ), __( 'Optional, with a People board. The board gifts arrive on (from Zapier). Every 15 minutes LeadKit links the newest gifts the way monday.com automations cannot: it finds or creates the donor on People by Donor email when nothing connected them, gives the gift\'s browser on the Visitors board the same person, and fills the Open person links. Its Person column must be a two-way connection for People\'s Total given to count gifts.', 'leadkit' ) ),
		'visits_url'        => array( __( 'Visits Worker URL', 'leadkit' ), __( 'Optional, with a Visitors board. The Cloudflare Worker that keeps every browser\'s visits in D1, e.g. https://visits.example.org. The tracker posts every visit straight to it, anonymous visitors included, so none of that traffic or data touches WordPress. A browser\'s visits stay there until it reaches a goal: by default a Donate click or a form (the Worker\'s WARM_RULES setting can add returning visitors or campaign arrivals). Every 15 minutes LeadKit fetches those browsers and writes them to the Visitors and Visits boards with their whole history, earlier anonymous visits included. Empty turns it off. Make sure the privacy policy says the site keeps visit history per browser.', 'leadkit' ) ),
		'visits_secret'     => array( __( 'Visits Worker secret', 'leadkit' ), __( 'The Worker\'s SYNC_SECRET. Better in wp-config.php as LEADKIT_VISITS_SECRET, which takes precedence. Never shown again once saved; leave empty to keep it, a single - clears it.', 'leadkit' ), 'secret' ),
		'untracked_paths'   => array( __( 'Untracked pages', 'leadkit' ), __( 'Paths where nothing is tracked and nothing is sent — pages someone at risk might use, like /help/. Comma-separated; each covers the pages under it (/help/ covers /help/hotline/ but not /helpful/). The tracker is not loaded there at all, and those pages are dropped from anything a browser recorded there before.', 'leadkit' ) ),
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
	$leadkit_have_from      = '' !== trim( (string) $opts['from_email'] );
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

			<h3><?= esc_html__( 'What to fill in', 'leadkit' ) ?></h3>
			<table class="widefat striped" style="margin:.5rem 0">
				<tbody>
					<tr>
						<td style="width:12rem"><strong><?= esc_html__( 'Resend API key', 'leadkit' ) ?></strong></td>
						<td style="width:6rem"><?= $leadkit_have_api ? $leadkit_yes : $leadkit_no // phpcs:ignore WordPress.Security.EscapeOutput — escaped above. ?></td>
						<td>
							<a href="https://resend.com/api-keys" target="_blank" rel="noopener"><?= esc_html__( 'resend.com/api-keys', 'leadkit' ) ?></a>
							— <?= esc_html__( 'Create API Key, permission “Sending access”. Shown once; if you miss it, make another.', 'leadkit' ) ?>
						</td>
					</tr>
					<tr>
						<td><strong><?= esc_html__( 'Turnstile keys', 'leadkit' ) ?></strong></td>
						<td><?= $leadkit_have_turnstile ? $leadkit_yes : $leadkit_no // phpcs:ignore WordPress.Security.EscapeOutput — escaped above. ?></td>
						<td>
							<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener"><?= esc_html__( 'Cloudflare dashboard → Turnstile', 'leadkit' ) ?></a>
							— <?= esc_html__( 'open your widget, Settings. The site key is public; reveal the secret key beside it. Both go in the fields below.', 'leadkit' ) ?>
						</td>
					</tr>
					<tr>
						<td><strong><?= esc_html__( 'Send FROM', 'leadkit' ) ?></strong></td>
						<td><?= $leadkit_have_from ? $leadkit_yes : $leadkit_no // phpcs:ignore WordPress.Security.EscapeOutput — escaped above. ?></td>
						<td>
							<?= esc_html__( 'An address on a domain verified at', 'leadkit' ) ?>
							<a href="https://resend.com/domains" target="_blank" rel="noopener">resend.com/domains</a>.
							<?= esc_html__( 'Not necessarily this site’s domain — sending as a domain with no SPF record is what spam looks like. The visitor’s own address is set as Reply-To, so replying reaches them.', 'leadkit' ) ?>
						</td>
					</tr>
					<tr>
						<td><strong><?= esc_html__( 'Submit endpoint', 'leadkit' ) ?></strong></td>
						<td><?= esc_html__( 'leave empty', 'leadkit' ) ?></td>
						<td>
							<?= esc_html__( 'Empty means this plugin handles submissions itself, which is what almost every site wants. Currently:', 'leadkit' ) ?>
							<code><?= esc_html( leadkit_submit_endpoint() ) ?></code>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?= esc_html__( 'The two key fields are write-only: once saved the value is never shown again. Leave a box empty to keep what is already there; type a single - to clear it.', 'leadkit' ) ?>
			</p>

			<h3><?= esc_html__( 'Optional: keep the keys out of database exports', 'leadkit' ) ?></h3>
			<p class="description">
				<?= esc_html__( 'A key saved here travels in every database export and sits in every backup. If that matters — a site whose database moves between hosts, say — put either key in wp-config.php instead, above the line that reads', 'leadkit' ) ?>
				<code>/* That&rsquo;s all, stop editing! */</code>.
				<?= esc_html__( 'A constant takes precedence over the matching field, and the field can then be left empty.', 'leadkit' ) ?>
			</p>
<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:.85rem;overflow-x:auto;font-size:12px">define( 'LEADKIT_RESEND_API_KEY',   're_…' );
define( 'LEADKIT_TURNSTILE_SECRET', '0x4AAA…' );</pre>
		</div>

		<form action="options.php" method="post">
			<?php settings_fields( 'leadkit' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $meta ) : ?>
				<tr>
					<th scope="row"><label for="leadkit-<?= esc_attr( $key ) ?>"><?= esc_html( $meta[0] ) ?></label></th>
					<td>
						<?php if ( 'secret' === ( $meta[2] ?? 'text' ) ) : ?>
						<input name="leadkit_options[<?= esc_attr( $key ) ?>]" type="password" id="leadkit-<?= esc_attr( $key ) ?>" value="" class="regular-text" autocomplete="new-password"
							placeholder="<?= esc_attr( $opts[ $key ] ? __( '•••••••••••• saved — leave empty to keep it', 'leadkit' ) : __( 'not set', 'leadkit' ) ) ?>">
						<?php elseif ( 'checkbox' === ( $meta[2] ?? 'text' ) ) : ?>
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
		<?php
		$leadkit_counts = leadkit_browsers_enabled() && leadkit_visits_secret() ? leadkit_browsers_counts() : null;
		if ( $leadkit_counts ) :
			?>
		<p class="description">
			<?= esc_html(
				sprintf(
					/* translators: 1: browsers kept, 2: warm, 3: sent to monday.com, 4: waiting to be sent. */
					__( 'Visits Worker: %1$d browsers kept, %2$d warm, %3$d sent to monday.com, %4$d waiting for the next run.', 'leadkit' ),
					$leadkit_counts['browsers'],
					$leadkit_counts['warm'],
					$leadkit_counts['sent'],
					$leadkit_counts['pending']
				)
			) ?>
		</p>
		<?php endif; ?>
		<p>
			<?= esc_html__( 'Render the form with the [leadkit_form] shortcode, or from a theme with leadkit_form( ["class_prefix" => "your-prefix"] ). The endpoint payload contract is documented in the plugin’s readme.txt.', 'leadkit' ) ?>
		</p>
	</div>
	<?php
}
