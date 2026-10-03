<?php
/**
 * Plugin Settings Management.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * Handles options registration, defaults, sanitization, and configuration access.
 */
class Settings {

	public const OPTION_NAME = 'wpcalibrate_wcpr_settings';

	/**
	 * Default settings array.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'auto_recovery_enabled' => true,
			'protect_cart'          => true,
			'protect_checkout'      => true,
			'protect_myaccount'     => true,
			'new_page_format'       => 'blocks', // 'blocks' or 'shortcodes'.
			'logging_enabled'       => false,
			'uninstall_cleanup'     => false,
		];
	}

	/**
	 * Retrieve all settings with defaults applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		$saved = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Optional fallback value.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::get_all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Check if global auto-recovery is enabled.
	 *
	 * @return bool
	 */
	public static function is_auto_recovery_enabled(): bool {
		return (bool) self::get( 'auto_recovery_enabled', true );
	}

	/**
	 * Check if a specific role is protected.
	 *
	 * @param string $role 'cart', 'checkout', or 'myaccount'.
	 * @return bool
	 */
	public static function is_role_protected( string $role ): bool {
		if ( ! self::is_auto_recovery_enabled() ) {
			return false;
		}

		return match ( $role ) {
			'cart'      => (bool) self::get( 'protect_cart', true ),
			'checkout'  => (bool) self::get( 'protect_checkout', true ),
			'myaccount' => (bool) self::get( 'protect_myaccount', true ),
			default     => false,
		};
	}

	/**
	 * Retrieve rendering format for new pages ('blocks' or 'shortcodes').
	 *
	 * @return string
	 */
	public static function get_new_page_format(): string {
		$format = (string) self::get( 'new_page_format', 'blocks' );
		return in_array( $format, [ 'blocks', 'shortcodes' ], true ) ? $format : 'blocks';
	}

	/**
	 * Check if diagnostic logging to WooCommerce logger is enabled.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled(): bool {
		return (bool) self::get( 'logging_enabled', false );
	}

	/**
	 * Check if uninstall cleanup is enabled.
	 *
	 * @return bool
	 */
	public static function is_uninstall_cleanup_enabled(): bool {
		return (bool) self::get( 'uninstall_cleanup', false );
	}

	/**
	 * Register settings with WordPress Settings API.
	 */
	public static function register(): void {
		register_setting(
			'wpcalibrate_wcpr_settings_group',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'description'       => __( 'WooCommerce Core Pages Auto-Recovery Configuration', 'wpcalibrate-wc-pages-recovery' ),
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
				'default'           => self::get_defaults(),
			]
		);
	}

	/**
	 * Sanitize raw input from settings form.
	 *
	 * @param mixed $input Raw input array.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_defaults();
		}

		$output = [];

		$output['auto_recovery_enabled'] = ! empty( $input['auto_recovery_enabled'] );
		$output['protect_cart']          = ! empty( $input['protect_cart'] );
		$output['protect_checkout']      = ! empty( $input['protect_checkout'] );
		$output['protect_myaccount']     = ! empty( $input['protect_myaccount'] );

		$format = sanitize_key( $input['new_page_format'] ?? 'blocks' );
		$output['new_page_format'] = in_array( $format, [ 'blocks', 'shortcodes' ], true ) ? $format : 'blocks';

		$output['logging_enabled']   = ! empty( $input['logging_enabled'] );
		$output['uninstall_cleanup'] = ! empty( $input['uninstall_cleanup'] );

		return $output;
	}
}
