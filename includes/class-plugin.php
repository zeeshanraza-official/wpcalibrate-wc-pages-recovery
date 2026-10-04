<?php
/**
 * Main Plugin Coordinator.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Singleton coordinator registering all plugin services, lifecycle events,
 * admin menus, and action links.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Initialize plugin services and hooks.
	 */
	public function init(): void {
		// Run idempotent version check & upgrade migration if needed.
		Lifecycle::check_upgrade();

		// Register Settings API.
		add_action( 'admin_init', [ Settings::class, 'register' ] );

		// Register Admin UI and action handlers.
		Admin::init();

		// Add Settings action link on Plugins listing.
		add_filter( 'plugin_action_links_' . plugin_basename( WPCALIBRATE_WCPR_PLUGIN_FILE ), [ $this, 'add_plugin_action_links' ] );

		// Initialize Background Automation & Event Listeners only if WooCommerce is ready.
		if ( Lifecycle::is_woocommerce_ready() ) {
			Scheduler::init();
		}
	}

	/**
	 * Add Settings shortcut link to the Plugins page.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function add_plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'wpcalibrate-wc-pages-recovery' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}
}
