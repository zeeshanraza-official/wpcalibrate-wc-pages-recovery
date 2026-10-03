<?php
/**
 * GitHub Dashboard Auto-Updater Service for WooCommerce Core Pages Auto-Recovery.
 *
 * Enables seamless WordPress Dashboard updates directly from GitHub Releases.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Handles WordPress Dashboard plugin update checks, metadata modals, and installations via GitHub.
 */
final class GitHubUpdater {

	/**
	 * GitHub Repository in format 'owner/repo'.
	 */
	public const GITHUB_REPO = 'zeeshanraza-official/wpcalibrate-wc-pages-recovery';

	/**
	 * Transient key for caching remote GitHub release metadata.
	 */
	public const TRANSIENT_KEY = 'wpcalibrate_wcpr_gh_release';

	/**
	 * Cache TTL in seconds (12 hours).
	 */
	public const CACHE_TTL = 43200;

	/**
	 * Register updater hooks.
	 */
	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'filter_update_plugins' ] );
		add_filter( 'plugins_api', [ self::class, 'filter_plugins_api' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ self::class, 'filter_post_install' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ self::class, 'on_upgrade_complete' ], 10, 2 );
	}

	/**
	 * Get plugin basename (e.g. 'wpcalibrate-wc-pages-recovery/wpcalibrate-wc-pages-recovery.php').
	 */
	public static function get_plugin_basename(): string {
		return plugin_basename( WPCALIBRATE_WCPR_PLUGIN_FILE );
	}

	/**
	 * Check GitHub for latest release and inject update payload into WordPress update transient.
	 *
	 * @param mixed $transient Site transient object.
	 * @return mixed
	 */
	public static function filter_update_plugins( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::get_latest_release();
		if ( empty( $release ) || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$latest_version  = ltrim( $release['tag_name'], 'vV' );
		$current_version = WPCALIBRATE_WCPR_VERSION;
		$plugin_file     = self::get_plugin_basename();

		$update_item = (object) [
			'id'           => 'wpcalibrate-wc-pages-recovery',
			'slug'         => 'wpcalibrate-wc-pages-recovery',
			'plugin'       => $plugin_file,
			'new_version'  => $latest_version,
			'url'          => 'https://github.com/' . self::GITHUB_REPO,
			'package'      => self::get_release_package_url( $release ),
			'icons'        => [
				'2x'      => WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png',
				'1x'      => WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png',
				'default' => WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png',
			],
			'banners'      => [],
			'tested'       => '7.1.2',
			'requires_php' => '8.2',
			'requires'     => '7.0',
		];

		if ( version_compare( $latest_version, $current_version, '>' ) ) {
			$transient->response[ $plugin_file ] = $update_item;
			unset( $transient->no_update[ $plugin_file ] );
		} else {
			$transient->no_update[ $plugin_file ] = $update_item;
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * Provide modal details when administrator clicks "View version x.x.x details".
	 *
	 * @param mixed  $result Current result.
	 * @param string $action Requested action.
	 * @param mixed  $args   Arguments object.
	 * @return mixed
	 */
	public static function filter_plugins_api( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'wpcalibrate-wc-pages-recovery' !== $args->slug ) {
			return $result;
		}

		$release = self::get_latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		$latest_version = ltrim( $release['tag_name'] ?? '1.0.0', 'vV' );
		$changelog      = ! empty( $release['body'] ) ? $release['body'] : __( 'Maintenance and stability improvements.', 'wpcalibrate-wc-pages-recovery' );

		$info = (object) [
			'name'          => __( 'WooCommerce Core Pages Auto-Recovery', 'wpcalibrate-wc-pages-recovery' ),
			'slug'          => 'wpcalibrate-wc-pages-recovery',
			'version'       => $latest_version,
			'author'        => '<a href="https://wpcalibrate.com">WPCalibrate</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'requires'      => '7.0',
			'tested'        => '7.1.2',
			'requires_php'  => '8.2',
			'download_link' => self::get_release_package_url( $release ),
			'sections'      => [
				'description'  => __( 'Safely monitor, protect, and automatically restore WooCommerce Cart, Checkout, and My Account pages without data loss or duplicate page proliferation.', 'wpcalibrate-wc-pages-recovery' ),
				'changelog'    => wp_kses_post( nl2br( esc_html( $changelog ) ) ),
				'installation' => __( 'Automatic update directly via WordPress Plugins screen or upload the distribution ZIP.', 'wpcalibrate-wc-pages-recovery' ),
			],
			'banners'       => [],
			'icons'         => [
				'2x'      => WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png',
				'1x'      => WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png',
				'default' => WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png',
			],
		];

		return $info;
	}

	/**
	 * Normalize destination directory name after WordPress unzips release archive.
	 *
	 * @param bool  $response   Installation status.
	 * @param array $hook_extra Extra hook metadata.
	 * @param array $result     Upgrader result array.
	 * @return array
	 */
	public static function filter_post_install( bool $response, array $hook_extra, array $result ): array {
		if ( ! $response || empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::get_plugin_basename() ) {
			return $result;
		}

		global $wp_filesystem;
		if ( ! isset( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
			return $result;
		}

		$target_dir = WP_PLUGIN_DIR . '/wpcalibrate-wc-pages-recovery';
		$source_dir = $result['destination'] ?? '';

		if ( ! empty( $source_dir ) && untrailingslashit( $source_dir ) !== untrailingslashit( $target_dir ) ) {
			$wp_filesystem->move( $source_dir, $target_dir, true );
			$result['destination'] = $target_dir;
		}

		return $result;
	}

	/**
	 * Clear cached release transient upon successful upgrade.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param array $options  Upgrade options.
	 */
	public static function on_upgrade_complete( mixed $upgrader, array $options ): void {
		if (
			isset( $options['action'], $options['type'], $options['plugins'] ) &&
			'update' === $options['action'] &&
			'plugin' === $options['type'] &&
			in_array( self::get_plugin_basename(), (array) $options['plugins'], true )
		) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * Retrieve latest release details from GitHub API with caching.
	 *
	 * @param bool $force_refresh Whether to bypass transient cache.
	 * @return array<string, mixed>|null
	 */
	public static function get_latest_release( bool $force_refresh = false ): ?array {
		if ( ! $force_refresh && ! isset( $_GET['force-check'] ) ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$api_url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$args    = [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . ( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '7.1' ) . '; ' . ( function_exists( 'home_url' ) ? home_url() : 'https://wpcalibrate.com' ),
			],
		];

		$response = wp_remote_get( $api_url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		set_transient( self::TRANSIENT_KEY, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Extract direct ZIP asset URL from release payload, or fallback to zipball_url.
	 *
	 * @param array<string, mixed> $release
	 * @return string
	 */
	public static function get_release_package_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if (
					isset( $asset['name'], $asset['browser_download_url'] ) &&
					str_ends_with( strtolower( (string) $asset['name'] ), '.zip' )
				) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return (string) ( $release['zipball_url'] ?? '' );
	}
}
