<?php
/**
 * Plugin Lifecycle Handler.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lifecycle
 *
 * Handles activation, deactivation, and version upgrades safely.
 */
class Lifecycle {

	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		// Initialize settings defaults without overwriting existing configuration.
		$current_settings = get_option( Settings::OPTION_NAME, null );
		if ( null === $current_settings || ! is_array( $current_settings ) ) {
			update_option( Settings::OPTION_NAME, Settings::get_defaults(), false );
		} else {
			// Merge any newly introduced settings fields.
			$merged = array_merge( Settings::get_defaults(), $current_settings );
			update_option( Settings::OPTION_NAME, $merged, false );
		}

		// Set or update data version.
		update_option( 'wpcalibrate_wcpr_version', WPCALIBRATE_WCPR_VERSION, false );

		// Register an initial check if dependencies are available.
		if ( self::is_woocommerce_ready() ) {
			Scheduler::schedule_initial_check();
		}
	}

	/**
	 * Run deactivation tasks.
	 */
	public static function deactivate(): void {
		// Cancel all scheduled actions and cron events owned by the plugin.
		Scheduler::cancel_all_jobs();

		// Release any stale locks owned by the plugin.
		Lock::force_release();
	}

	/**
	 * Check if version upgrade migration is needed and run it idempotently.
	 */
	public static function check_upgrade(): void {
		$installed_version = get_option( 'wpcalibrate_wcpr_version', '0.0.0' );

		if ( version_compare( (string) $installed_version, WPCALIBRATE_WCPR_VERSION, '<' ) ) {
			self::run_upgrade( (string) $installed_version );
			update_option( 'wpcalibrate_wcpr_version', WPCALIBRATE_WCPR_VERSION, false );
		}
	}

	/**
	 * Idempotent migration logic between versions.
	 *
	 * @param string $from_version Previous installed version.
	 */
	private static function run_upgrade( string $from_version ): void {
		// Ensure settings contain all required keys.
		$settings = get_option( Settings::OPTION_NAME, [] );
		if ( is_array( $settings ) ) {
			$defaults = Settings::get_defaults();
			$updated  = false;
			foreach ( $defaults as $key => $default_val ) {
				if ( ! array_key_exists( $key, $settings ) ) {
					$settings[ $key ] = $default_val;
					$updated          = true;
				}
			}
			if ( $updated ) {
				update_option( Settings::OPTION_NAME, $settings, false );
			}
		}

		// Deduplicate and re-register scheduled jobs.
		if ( self::is_woocommerce_ready() ) {
			Scheduler::reschedule_reconciliation();
		}
	}

	/**
	 * Check whether WooCommerce is active and ready.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_ready(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}
}
