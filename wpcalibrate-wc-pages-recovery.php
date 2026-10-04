<?php
/**
 * Plugin Name: WPCalibrate Core Pages Auto-Recovery for WooCommerce
 * Plugin URI: https://github.com/zeeshanraza-official/wpcalibrate-wc-pages-recovery
 * Description: Automatically detects and safely repairs missing WooCommerce Cart, Checkout, and My Account pages and page assignments.
 * Version: 1.0.0
 * Author: WPCalibrate
 * Author URI: https://wpcalibrate.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpcalibrate-wc-pages-recovery
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Tested up to: 7.1.2
 * WC requires at least: 11.1
 * WC tested up to: 11.1.2
 * Requires Plugins: woocommerce
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

// Plugin version and directory constants.
define( 'WPCALIBRATE_WCPR_VERSION', '1.0.0' );
define( 'WPCALIBRATE_WCPR_PLUGIN_FILE', __FILE__ );
define( 'WPCALIBRATE_WCPR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCALIBRATE_WCPR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCALIBRATE_WCPR_MIN_PHP', '8.2.0' );
define( 'WPCALIBRATE_WCPR_MIN_WP', '7.0' );
define( 'WPCALIBRATE_WCPR_MIN_WC', '11.1.0' );

/**
 * Autoloader for plugin classes under WPCalibrate\WooPagesRecovery namespace.
 *
 * @param string $class Fully qualified class name.
 */
spl_autoload_register( function( string $class ): void {
	$prefix = 'WPCalibrate\\WooPagesRecovery\\';
	if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );
	$slug           = strtolower( (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', $relative_class ) );
	$slug           = str_replace( 'git-hub', 'github', $slug );
	$file_name      = 'class-' . $slug . '.php';
	$file_path      = WPCALIBRATE_WCPR_PLUGIN_DIR . 'includes/' . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
} );

/**
 * Declare compatibility with WooCommerce features (HPOS and Cart/Checkout Blocks).
 */
add_action( 'before_woocommerce_init', function(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPCALIBRATE_WCPR_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPCALIBRATE_WCPR_PLUGIN_FILE, true );
	}
} );

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( WPCALIBRATE_WCPR_PLUGIN_FILE, [ Lifecycle::class, 'activate' ] );
register_deactivation_hook( WPCALIBRATE_WCPR_PLUGIN_FILE, [ Lifecycle::class, 'deactivate' ] );

/**
 * Initialize the plugin after plugins are loaded.
 */
add_action( 'plugins_loaded', function(): void {
	load_plugin_textdomain(
		'wpcalibrate-wc-pages-recovery',
		false,
		dirname( plugin_basename( WPCALIBRATE_WCPR_PLUGIN_FILE ) ) . '/languages'
	);

	// Guard against unsupported PHP version.
	if ( version_compare( PHP_VERSION, WPCALIBRATE_WCPR_MIN_PHP, '<' ) ) {
		add_action( 'admin_notices', function(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						esc_html__( 'WPCalibrate Core Pages Auto-Recovery for WooCommerce requires PHP %1$s or higher. Your server is running PHP %2$s. The plugin has been disabled.', 'wpcalibrate-wc-pages-recovery' ),
						esc_html( WPCALIBRATE_WCPR_MIN_PHP ),
						esc_html( PHP_VERSION )
					);
					?>
				</p>
			</div>
			<?php
		} );
		return;
	}

	// Bootstrap the main plugin coordinator.
	Plugin::get_instance()->init();
} );
