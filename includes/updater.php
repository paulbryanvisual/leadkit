<?php
/**
 * Update this plugin from its own GitHub repository.
 *
 * WordPress only offers updates for things on wordpress.org, so a plugin you
 * host yourself is invisible to it — every release means building a zip and
 * uploading it to each site by hand, which is how sites end up two years behind.
 *
 * Git Updater solves this generally and well, but its licence gates
 * AUTHENTICATED API requests, and a private repository needs authentication for
 * every single call. So for a private repo the free version stops working after
 * the trial. This is the same job in one file, for one plugin.
 *
 * It hooks the update machinery WordPress already has, so an update appears
 * under Dashboard → Updates and installs with the normal button. Nothing about
 * the experience is special.
 *
 * TWO THINGS make a GitHub zip different from a wordpress.org one, and both are
 * why this is more than a version check:
 *
 *   1. A private repo's zipball needs an Authorization header. WordPress
 *      downloads packages with download_url(), which sends none — so the
 *      download is intercepted and performed here instead.
 *
 *   2. GitHub names the folder inside the zip `owner-repo-a1b2c3d`. Installed
 *      as-is, WordPress would treat it as a DIFFERENT plugin, leaving the old
 *      copy active and the new one dormant beside it. The folder is renamed to
 *      the real slug before install.
 *
 * The token: define LEADKIT_GITHUB_TOKEN in wp-config.php, or paste it into
 * Settings → LeadKit. A public repo needs no token at all.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LEADKIT_GH_OWNER = 'paulbryanvisual';
const LEADKIT_GH_REPO  = 'leadkit';

/**
 * The GitHub token, or '' when the repo is public.
 *
 * @return string
 */
function leadkit_github_token() {
	/*
	 * Constant only — there is no setting for this any more.
	 *
	 * The repository is public, so updates need no authentication at all, and a
	 * credential field nobody needs is somewhere a credential ends up for no
	 * reason. If the repository is ever made private again, define this in
	 * wp-config.php and everything works as before:
	 *
	 *     define( 'LEADKIT_GITHUB_TOKEN', 'github_pat_…' );
	 */
	if ( defined( 'LEADKIT_GITHUB_TOKEN' ) && LEADKIT_GITHUB_TOKEN ) {
		return (string) LEADKIT_GITHUB_TOKEN;
	}

	return '';
}

/**
 * Forget a token stored by an earlier version.
 *
 * 1.2.0 offered a settings field for this, and sites that used it have the
 * token sitting in `wp_options` — travelling in every database export and
 * backup for a credential that is no longer read. Removing the field would
 * leave it there invisibly, so it is deleted rather than orphaned.
 *
 * The token itself should still be revoked on GitHub; nothing here can do that.
 */
add_action(
	'admin_init',
	function () {
		$opts = get_option( 'leadkit_options', array() );

		if ( is_array( $opts ) && array_key_exists( 'github_token', $opts ) ) {
			unset( $opts['github_token'] );
			update_option( 'leadkit_options', $opts );
		}
	}
);

/**
 * Request headers for the GitHub API.
 *
 * @param string $accept Accept header.
 * @return array
 */
function leadkit_github_headers( $accept = 'application/vnd.github+json' ) {
	$headers = array(
		'Accept'               => $accept,
		'User-Agent'           => 'LeadKit/' . LEADKIT_VERSION,
		'X-GitHub-Api-Version' => '2022-11-28',
	);

	$token = leadkit_github_token();
	if ( $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	return $headers;
}

/**
 * The newest released version on GitHub, cached.
 *
 * Cached for twelve hours because this runs on admin page loads, and an
 * unauthenticated GitHub is 60 requests an hour per IP — shared hosting means
 * sharing that with strangers. The cache is cleared when someone explicitly
 * checks for updates.
 *
 * @param bool $force Skip the cache.
 * @return array{version:string,zip:string,url:string,notes:string,published:string}|null
 */
function leadkit_github_latest( $force = false ) {
	$key = 'leadkit_latest_release';

	if ( ! $force ) {
		$cached = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$res = wp_remote_get(
		sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', LEADKIT_GH_OWNER, LEADKIT_GH_REPO ),
		array(
			'timeout' => 12,
			'headers' => leadkit_github_headers(),
		)
	);

	$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$tag  = is_array( $body ) ? ( $body['tag_name'] ?? '' ) : '';

	/*
	 * Fall back to tags. A repository can be tagged without anyone creating a
	 * GitHub "release", and a tag is what the version actually lives in — so
	 * treating a missing release as "no update" would hide real ones.
	 */
	if ( '' === $tag ) {
		$res  = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/tags', LEADKIT_GH_OWNER, LEADKIT_GH_REPO ),
			array(
				'timeout' => 12,
				'headers' => leadkit_github_headers(),
			)
		);
		$tags = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $tags ) || ! $tags ) {
			// Cache the miss briefly so a broken token does not retry every page load.
			set_site_transient( $key, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}
		usort( $tags, fn( $a, $b ) => version_compare( ltrim( $b['name'] ?? '', 'v' ), ltrim( $a['name'] ?? '', 'v' ) ) );
		$tag  = (string) ( $tags[0]['name'] ?? '' );
		$body = array( 'body' => '', 'published_at' => '' );
	}

	if ( '' === $tag ) {
		return null;
	}

	$info = array(
		'version'   => ltrim( $tag, 'vV' ),
		// The API zipball, not the browser download URL: this one honours the
		// Authorization header, which is what a private repo requires.
		'zip'       => sprintf( 'https://api.github.com/repos/%s/%s/zipball/%s', LEADKIT_GH_OWNER, LEADKIT_GH_REPO, $tag ),
		'url'       => sprintf( 'https://github.com/%s/%s', LEADKIT_GH_OWNER, LEADKIT_GH_REPO ),
		'notes'     => (string) ( $body['body'] ?? '' ),
		'published' => (string) ( $body['published_at'] ?? '' ),
	);

	set_site_transient( $key, $info, 12 * HOUR_IN_SECONDS );

	return $info;
}

/** The plugin's own basename, e.g. leadkit/leadkit.php. */
function leadkit_basename() {
	return plugin_basename( LEADKIT_DIR . '/leadkit.php' );
}

/**
 * Offer the update to WordPress.
 */
add_filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$latest = leadkit_github_latest();
		if ( ! $latest || empty( $latest['version'] ) ) {
			return $transient;
		}

		$basename = leadkit_basename();

		if ( version_compare( $latest['version'], LEADKIT_VERSION, '>' ) ) {
			$transient->response[ $basename ] = (object) array(
				'slug'        => dirname( $basename ),
				'plugin'      => $basename,
				'new_version' => $latest['version'],
				'url'         => $latest['url'],
				'package'     => $latest['zip'],
				'tested'      => get_bloginfo( 'version' ),
			);
			unset( $transient->no_update[ $basename ] );
		} else {
			// Telling WordPress it is current is what puts an honest "you have
			// the latest version" in the plugin row rather than silence.
			$transient->no_update[ $basename ] = (object) array(
				'slug'        => dirname( $basename ),
				'plugin'      => $basename,
				'new_version' => LEADKIT_VERSION,
				'url'         => $latest['url'],
				'package'     => '',
			);
		}

		return $transient;
	}
);

/**
 * The "View details" panel, so the update is not an unexplained version bump.
 */
add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( leadkit_basename() ) !== $args->slug ) {
			return $result;
		}

		$latest = leadkit_github_latest();
		if ( ! $latest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'LeadKit',
			'slug'          => $args->slug,
			'version'       => $latest['version'],
			'author'        => '<a href="https://github.com/' . LEADKIT_GH_OWNER . '">Paul Bryan Visual</a>',
			'homepage'      => $latest['url'],
			'download_link' => $latest['zip'],
			'last_updated'  => $latest['published'],
			'sections'      => array(
				'description' => __( 'The lead-capture form, visitor tracker and submission endpoint.', 'leadkit' ),
				'changelog'   => $latest['notes'] ? wpautop( wp_kses_post( $latest['notes'] ) ) : __( 'See the repository for release notes.', 'leadkit' ),
			),
		);
	},
	10,
	3
);

/**
 * Download the package ourselves, with the token attached.
 *
 * `download_url()` sends no Authorization header, so a private repository
 * answers WordPress's request with a 404 — which surfaces as the deeply
 * unhelpful "download failed. Not Found".
 */
add_filter(
	'upgrader_pre_download',
	function ( $reply, $package, $upgrader ) {
		if ( false === strpos( (string) $package, 'api.github.com/repos/' . LEADKIT_GH_OWNER . '/' . LEADKIT_GH_REPO ) ) {
			return $reply;
		}

		$token = leadkit_github_token();
		if ( ! $token ) {
			// A public repo needs nothing special; let WordPress do it.
			return $reply;
		}

		$tmp = wp_tempnam( 'leadkit-update.zip' );
		if ( ! $tmp ) {
			return new WP_Error( 'leadkit_no_tempfile', __( 'Could not create a temporary file for the download.', 'leadkit' ) );
		}

		$res = wp_remote_get(
			$package,
			array(
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => leadkit_github_headers( 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $res ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'leadkit_download_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GitHub refused the download (HTTP %d). Check the token in Settings → LeadKit.', 'leadkit' ),
					$code
				)
			);
		}

		return $tmp;
	},
	10,
	3
);

/**
 * Rename GitHub's folder to the plugin's real slug before installing.
 *
 * A GitHub zipball contains `owner-repo-a1b2c3d/`. Installed under that name
 * WordPress treats it as a different plugin: the old copy stays active, the new
 * one sits beside it deactivated, and the update appears to have done nothing.
 */
add_filter(
	'upgrader_source_selection',
	function ( $source, $remote_source, $upgrader, $args = array() ) {
		$basename = leadkit_basename();
		$slug     = dirname( $basename );

		if ( empty( $args['plugin'] ) || $args['plugin'] !== $basename ) {
			return $source;
		}
		if ( basename( $source ) === $slug ) {
			return $source;
		}

		global $wp_filesystem;
		$corrected = trailingslashit( $remote_source ) . $slug;

		if ( $wp_filesystem && $wp_filesystem->move( $source, $corrected, true ) ) {
			return trailingslashit( $corrected );
		}

		return new WP_Error( 'leadkit_rename_failed', __( 'Could not rename the downloaded folder to the plugin slug.', 'leadkit' ) );
	},
	10,
	4
);

/** A fresh check when someone explicitly asks for one. */
add_action(
	'upgrader_process_complete',
	function () {
		delete_site_transient( 'leadkit_latest_release' );
	}
);
