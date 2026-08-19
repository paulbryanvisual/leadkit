<?php
/**
 * Plugin Name:       LeadKit — Lead Form & Visitor Tracking
 * Plugin URI:        https://github.com/paulbryanvisual/leadkit
 * Description:       The lead-capture form and first-party visitor tracker, packaged to travel between projects. Renders the form anywhere (template tag or shortcode), lazy-mounts Cloudflare Turnstile, and ships the analytics tracker that attaches behavioural context to every lead.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Paul Bryan Visual
 * License:           GPL-2.0-or-later
 * Text Domain:       leadkit
 * Update URI:        https://github.com/paulbryanvisual/leadkit
 *
 * @package LeadKit
 *
 * The server side IS in this plugin as of 1.1.0: submissions go to the REST
 * route leadkit/v1/submit, which validates, stores the lead as a `leadkit_lead`
 * post and emails it on. Leaving it out was a real failure — the form pointed at
 * a Cloudflare Pages Function, the site moved hosts, /api/submit became a 404,
 * and every enquiry was lost with no error anywhere.
 *
 * `submit_url` remains configurable for sites that genuinely have their own
 * endpoint; left empty it uses the built-in route, which is the default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADKIT_VERSION', '1.2.0' );
define( 'LEADKIT_DIR', __DIR__ );
define( 'LEADKIT_URL', plugin_dir_url( __FILE__ ) );

require_once LEADKIT_DIR . '/includes/form.php';
require_once LEADKIT_DIR . '/includes/settings.php';
require_once LEADKIT_DIR . '/includes/mailer.php';
require_once LEADKIT_DIR . '/includes/journey.php';
require_once LEADKIT_DIR . '/includes/updater.php';
require_once LEADKIT_DIR . '/includes/leads.php';
require_once LEADKIT_DIR . '/includes/submit.php';

/**
 * One option, one array — portable and easy to export between installs.
 *
 * @return array{submit_url:string,sync_url:string,track_url:string,turnstile_sitekey:string,storage_prefix:string}
 */
function leadkit_options() {
	$defaults = array(
		// Empty means "use the route this plugin registers" — resolved below,
		// so the default is correct on any host without anyone configuring it.
		'submit_url'        => '',
		'sync_url'          => '/api/sync-lead',
		'track_url'         => '/api/track-interaction',
		'turnstile_sitekey' => '',
		'turnstile_secret'  => '',
		'resend_api_key'    => '',
		'turnstile_actions'   => '',
		'turnstile_hostnames' => '',
		'turnstile_fail_open' => '',
		'notify_email'      => '',
		'github_token'      => '',
		'from_email'        => '',
		'from_name'         => '',
		'storage_prefix'    => 'leadkit',
	);

	$opts = get_option( 'leadkit_options', array() );
	$opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

	/*
	 * `/api/submit` is the Cloudflare-era value. It is a 404 on any other host,
	 * and it is what silently broke the form on migration — so treat it as
	 * unset rather than honouring a URL that cannot work here.
	 */
	if ( '' === $opts['submit_url'] || '/api/submit' === $opts['submit_url'] ) {
		$opts['submit_url'] = leadkit_submit_endpoint();
	}

	return $opts;
}

/**
 * First activation on a site that already carried the theme-era options picks
 * them up, so "move the form into a plugin" does not mean re-typing settings.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( get_option( 'leadkit_options' ) ) {
			return;
		}

		$seed = array();
		foreach ( array(
			'submit_url'        => 'roger_form_endpoint',
			'turnstile_sitekey' => 'roger_turnstile_sitekey',
		) as $ours => $theirs ) {
			$val = get_option( $theirs, '' );
			if ( $val ) {
				$seed[ $ours ] = $val;
			}
		}
		if ( defined( 'ROGER_TURNSTILE_SITEKEY' ) && ROGER_TURNSTILE_SITEKEY ) {
			$seed['turnstile_sitekey'] = ROGER_TURNSTILE_SITEKEY;
		}

		if ( $seed ) {
			update_option( 'leadkit_options', $seed );
		}
	}
);

/**
 * The visitor tracker, on every front-end page.
 *
 * Deferred: it only binds listeners, so it has no business on the critical
 * path. The config the script needs travels as JSON printed before it.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_admin() ) {
			return;
		}

		$opts = leadkit_options();

		wp_enqueue_script(
			'leadkit-tracker',
			LEADKIT_URL . 'assets/tracker.js',
			array(),
			LEADKIT_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_add_inline_script(
			'leadkit-tracker',
			'window.LeadKitCfg = ' . wp_json_encode(
				array(
					'submitAction'  => $opts['submit_url'],
					'syncUrl'       => $opts['sync_url'],
					'trackUrl'      => $opts['track_url'],
					'storagePrefix' => $opts['storage_prefix'],
				)
			) . ';',
			'before'
		);
	}
);

/**
 * Turnstile, mounted on first interaction with the form.
 *
 * The eager widget measured ~600 KB on every page of the original build — a
 * challenge iframe, api.js, a challenge script and a 471 KB XHR — on pages with
 * no intention of submitting anything. Deferring the mount to the first focus
 * or pointer over the form keeps the protection and removes the cost from every
 * page where the form is never touched. Tiny and inlined: no request.
 */
add_action(
	'wp_footer',
	function () {
		$opts = leadkit_options();

		if ( ! $opts['turnstile_sitekey'] || ! leadkit_form_was_rendered() ) {
			return;
		}
		?>
<script>
(function(){
	/*
	 * EVERY form on the page, not the first one.
	 *
	 * This used to be querySelector — singular — so on a page carrying both the
	 * content form and the footer form, only the first ever got the mount
	 * listeners. The other never loaded api.js, so its widget never rendered,
	 * so it could never produce a token. With a secret configured that form
	 * would be rejected on every submission, silently, while the other worked.
	 */
	var forms = document.querySelectorAll('form[data-leadkit]');
	if (!forms.length) return;

	var loading = false;
	function mount() {
		if (loading) return;
		loading = true;
		var s = document.createElement('script');
		s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
		s.async = true;
		s.defer = true;
		document.head.appendChild(s);
	}

	Array.prototype.forEach.call(forms, function (form) {
		if (!form.querySelector('.cf-turnstile')) return;

		['focusin','pointerdown'].forEach(function (e) {
			form.addEventListener(e, mount, {once:true, passive:true});
		});

		/*
		 * A token is single-use. Reset THIS form's widget, by passing its own
		 * container — bare reset() acts on the first widget on the page, which
		 * with two forms is the wrong one.
		 */
		form.addEventListener('submit', function () {
			var box = form.querySelector('.cf-turnstile');
			setTimeout(function () {
				if (window.turnstile && box) { window.turnstile.reset(box); }
			}, 0);
		});
	});
})();
</script>
		<?php
	},
	20
);

/**
 * Default styles — ONLY when the host theme has not claimed the markup with
 * its own class prefix. A theme that renders leadkit_form() under its own
 * prefix (this project uses main-footer) already styles every element, and
 * shipping CSS on top of that would be the drift this plugin exists to avoid.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( apply_filters( 'leadkit/load_default_styles', true ) ) {
			wp_register_style( 'leadkit-form', LEADKIT_URL . 'assets/leadkit.css', array(), LEADKIT_VERSION );
		}
	},
	11
);


/**
 * The block, so the form can be placed from the editor.
 *
 * Registered from block.json — one definition read by both PHP and the editor,
 * rather than a PHP registration and a JS one that drift apart.
 */
add_action(
	'init',
	function () {
		if ( function_exists( 'register_block_type' ) && is_dir( LEADKIT_DIR . '/blocks/form' ) ) {
			register_block_type( LEADKIT_DIR . '/blocks/form' );
		}
	}
);

add_shortcode(
	'leadkit_form',
	function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'class_prefix' => 'leadkit-form',
			),
			$atts,
			'leadkit_form'
		);

		return leadkit_form( $atts, false );
	}
);
