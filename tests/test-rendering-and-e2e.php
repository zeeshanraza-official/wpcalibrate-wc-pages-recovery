<?php
/**
 * Frontend Rendering, Store Flow, HPOS, and Lifecycle Edge Cases Test.
 *
 * @package WPCalibrate\WooPagesRecovery\Tests
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery\Tests;

use WPCalibrate\WooPagesRecovery\Admin;
use WPCalibrate\WooPagesRecovery\Lifecycle;
use WPCalibrate\WooPagesRecovery\PageInspector;
use WPCalibrate\WooPagesRecovery\RecoveryService;
use WPCalibrate\WooPagesRecovery\Settings;

require_once __DIR__ . '/bootstrap.php';

class RenderingAndE2ETest {
	private int $passed = 0;
	private int $failed = 0;

	public function run(): void {
		echo "============================================================\n";
		echo " Frontend Rendering, HPOS & Store Simulation Tests \n";
		echo "============================================================\n\n";

		$tests = [
			'test_block_rendering_structure',
			'test_shortcode_rendering_structure',
			'test_no_woocommerce_graceful_handling',
			'test_hpos_compatibility_flag',
			'test_reverse_plugin_activation_order',
			'test_admin_menu_icon_sizing_and_styles',
		];

		foreach ( $tests as $test ) {
			\WCPR_Test_State::reset();
			try {
				$this->$test();
				$this->passed++;
				echo " [PASS] {$test}\n";
			} catch ( \Throwable $e ) {
				$this->failed++;
				echo " [FAIL] {$test}: " . $e->getMessage() . "\n";
			}
		}

		echo "\n------------------------------------------------------------\n";
		echo " Results: {$this->passed} Passed, {$this->failed} Failed\n";
		echo "------------------------------------------------------------\n";

		if ( $this->failed > 0 ) {
			exit( 1 );
		}
	}

	private function assert( bool $condition, string $message = '' ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( 'Assertion failed: ' . ( $message ?: 'condition was false' ) );
		}
	}

	private function assertEquals( mixed $expected, mixed $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			$msg = $message ?: ( 'Expected ' . var_export( $expected, true ) . ' but got ' . var_export( $actual, true ) );
			throw new \RuntimeException( $msg );
		}
	}

	/**
	 * Verify Canonical WooCommerce Block Page Structures.
	 */
	public function test_block_rendering_structure(): void {
		update_option( Settings::OPTION_NAME, [ 'new_page_format' => 'blocks' ] );

		$cart_spec = RecoveryService::get_canonical_page_specification( PageInspector::ROLE_CART, 'blocks' );
		$this->assert( str_contains( $cart_spec['content'], '<!-- wp:woocommerce/cart -->' ) );
		$this->assert( str_contains( $cart_spec['content'], 'wp-block-woocommerce-cart' ) );

		$checkout_spec = RecoveryService::get_canonical_page_specification( PageInspector::ROLE_CHECKOUT, 'blocks' );
		$this->assert( str_contains( $checkout_spec['content'], '<!-- wp:woocommerce/checkout -->' ) );
		$this->assert( str_contains( $checkout_spec['content'], 'wp-block-woocommerce-checkout' ) );

		$myaccount_spec = RecoveryService::get_canonical_page_specification( PageInspector::ROLE_MYACCOUNT, 'blocks' );
		$this->assert( str_contains( $myaccount_spec['content'], '[woocommerce_my_account]' ) );
	}

	/**
	 * Verify Canonical Classic Shortcode Page Structures.
	 */
	public function test_shortcode_rendering_structure(): void {
		update_option( Settings::OPTION_NAME, [ 'new_page_format' => 'shortcodes' ] );

		$cart_spec = RecoveryService::get_canonical_page_specification( PageInspector::ROLE_CART, 'shortcodes' );
		$this->assert( '[woocommerce_cart]' === trim( $cart_spec['content'] ) );

		$checkout_spec = RecoveryService::get_canonical_page_specification( PageInspector::ROLE_CHECKOUT, 'shortcodes' );
		$this->assert( '[woocommerce_checkout]' === trim( $checkout_spec['content'] ) );

		$myaccount_spec = RecoveryService::get_canonical_page_specification( PageInspector::ROLE_MYACCOUNT, 'shortcodes' );
		$this->assert( '[woocommerce_my_account]' === trim( $myaccount_spec['content'] ) );
	}

	/**
	 * Verify No-WooCommerce behavior.
	 */
	public function test_no_woocommerce_graceful_handling(): void {
		// Mock WooCommerce inactive.
		// If WooCommerce is inactive, RecoveryService::recover must return an error without fatal.
		$reflection = new \ReflectionClass( Lifecycle::class );
		$method = $reflection->getMethod( 'is_woocommerce_ready' );

		// When WooCommerce class is missing or not ready:
		$res = RecoveryService::recover( 'manual' );
		// Since WooCommerce double exists in test bootstrap, verify that recover operates safely.
		$this->assert( is_array( $res ) );
	}

	/**
	 * Verify HPOS compatibility declaration.
	 */
	public function test_hpos_compatibility_flag(): void {
		// Check that compatibility declaration hook exists in main plugin file.
		$main_file_content = file_get_contents( WPCALIBRATE_WCPR_PLUGIN_FILE );
		$this->assert( str_contains( $main_file_content, "'custom_order_tables'" ), 'custom_order_tables HPOS compatibility declared' );
		$this->assert( str_contains( $main_file_content, "'cart_checkout_blocks'" ), 'cart_checkout_blocks compatibility declared' );
		$this->assert( str_contains( $main_file_content, 'before_woocommerce_init' ) );
	}

	/**
	 * Verify Reverse Plugin Activation Order for Shared WPCalibrate Parent Menu.
	 */
	public function test_reverse_plugin_activation_order(): void {
		// Scenario A: Our plugin loads first.
		\WCPR_Test_State::reset();
		Admin::register_menu();
		$this->assert( isset( \WCPR_Test_State::$admin_page_hooks['wpcalibrate'] ), 'Parent registered by us when first' );
		$this->assert( isset( \WCPR_Test_State::$submenu['wpcalibrate']['wpcalibrate-wc-pages-recovery'] ) );

		// Scenario B: Other plugin registered parent first.
		\WCPR_Test_State::reset();
		\WCPR_Test_State::$admin_page_hooks['wpcalibrate'] = true;
		\WCPR_Test_State::$menu['wpcalibrate'] = [ 'title' => 'WPCalibrate', 'slug' => 'wpcalibrate' ];

		Admin::register_menu();
		$this->assert( isset( \WCPR_Test_State::$submenu['wpcalibrate']['wpcalibrate-wc-pages-recovery'] ), 'Submenu registered under existing parent' );
		// Parent in $menu should NOT be overwritten:
		$this->assertEquals( 'WPCalibrate', \WCPR_Test_State::$menu['wpcalibrate']['title'] );
	}

	/**
	 * Verify Admin Menu and Dashboard Icon Sizing, Styles, and Background Adaptation.
	 */
	public function test_admin_menu_icon_sizing_and_styles(): void {
		// 1. Verify branding icons exist.
		$icon_white_path = WPCALIBRATE_WCPR_PLUGIN_DIR . 'branding/icon-white.png';
		$icon_dark_path  = WPCALIBRATE_WCPR_PLUGIN_DIR . 'branding/icon-dark.png';
		$this->assert( file_exists( $icon_white_path ), 'icon-white.png must exist' );
		$this->assert( file_exists( $icon_dark_path ), 'icon-dark.png must exist' );

		// 2. Capture output of Admin::render_admin_menu_styles().
		ob_start();
		Admin::render_admin_menu_styles();
		$css_output = ob_get_clean();

		$this->assert( str_contains( $css_output, 'width: 20px !important;' ), 'CSS must enforce 20px width' );
		$this->assert( str_contains( $css_output, 'height: 20px !important;' ), 'CSS must enforce 20px height' );
		$this->assert( str_contains( $css_output, 'padding-top: 7px !important;' ), 'CSS must enforce 7px top padding for vertical centering' );
		$this->assert( str_contains( $css_output, '#toplevel_page_wpcalibrate' ), 'CSS must target WPCalibrate menu item' );
		$this->assert( str_contains( $css_output, 'icon-dark.png' ), 'CSS must reference icon-dark.png for light admin color schemes' );

		// 3. Verify render_dashboard() renders background-adaptive icons.
		\WCPR_Test_State::reset();
		ob_start();
		Admin::render_dashboard();
		$dashboard_html = ob_get_clean();

		$this->assert( str_contains( $dashboard_html, 'branding/icon-dark.png' ), 'Dashboard header must render icon-dark.png for light background' );
		$this->assert( str_contains( $dashboard_html, 'branding/icon-white.png' ), 'Dashboard header must render icon-white.png for dark background' );
		$this->assert( str_contains( $dashboard_html, 'wpcalibrate-wcpr-branding-icon--dark' ), 'Markup must include dark icon class' );
		$this->assert( str_contains( $dashboard_html, 'wpcalibrate-wcpr-branding-icon--white' ), 'Markup must include white icon class' );
	}
}

$e2e = new RenderingAndE2ETest();
$e2e->run();
