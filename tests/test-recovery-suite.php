<?php
/**
 * Comprehensive Automated Test Suite for WooCommerce Core Pages Auto-Recovery.
 *
 * Verifies all acceptance criteria, recovery policies, concurrency safeguards,
 * failure handling, and lifecycle behaviors.
 *
 * @package WPCalibrate\WooPagesRecovery\Tests
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery\Tests;

use WPCalibrate\WooPagesRecovery\Admin;
use WPCalibrate\WooPagesRecovery\GitHubUpdater;
use WPCalibrate\WooPagesRecovery\History;
use WPCalibrate\WooPagesRecovery\Lifecycle;
use WPCalibrate\WooPagesRecovery\Lock;
use WPCalibrate\WooPagesRecovery\PageInspector;
use WPCalibrate\WooPagesRecovery\RecoveryService;
use WPCalibrate\WooPagesRecovery\Scheduler;
use WPCalibrate\WooPagesRecovery\Settings;

require_once __DIR__ . '/bootstrap.php';

class TestRunner {
	private int $passed = 0;
	private int $failed = 0;
	private array $errors = [];

	public function run(): void {
		echo "============================================================\n";
		echo " WPCalibrate WooCommerce Core Pages Auto-Recovery Test Suite \n";
		echo " Running on PHP " . PHP_VERSION . "\n";
		echo "============================================================\n\n";

		$tests = [
			'test_fresh_recovery_creates_three_distinct_pages',
			'test_repeated_recovery_idempotency',
			'test_concurrency_and_atomic_lock',
			'test_restore_trashed_page_with_published_pre_status',
			'test_trashed_page_with_draft_pre_status_requires_manual_attention',
			'test_password_protected_and_unpublished_produce_actionable_blocks',
			'test_duplicate_role_assignment_detection',
			'test_ambiguous_candidates_halts_recovery',
			'test_preserves_custom_builder_content',
			'test_partial_failure_and_rediscovery',
			'test_scheduler_event_debouncing_and_recursion_guard',
			'test_policy_and_role_toggles',
			'test_security_capabilities_and_nonces',
			'test_lifecycle_deactivation_and_uninstall_retention',
			'test_shared_wpcalibrate_menu_coordination',
			'test_blocks_vs_shortcodes_creation_format',
			'test_github_updater_workflow',
		];

		foreach ( $tests as $test ) {
			\WCPR_Test_State::reset();
			try {
				$this->$test();
				$this->passed++;
				echo " [PASS] {$test}\n";
			} catch ( \Throwable $e ) {
				$this->failed++;
				$this->errors[] = [ 'test' => $test, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString() ];
				echo " [FAIL] {$test}: " . $e->getMessage() . "\n";
			}
		}

		echo "\n------------------------------------------------------------\n";
		echo " Results: {$this->passed} Passed, {$this->failed} Failed\n";
		echo "------------------------------------------------------------\n";

		if ( $this->failed > 0 ) {
			echo "\nFailures details:\n";
			foreach ( $this->errors as $err ) {
				echo "Test: " . $err['test'] . "\n";
				echo "Error: " . $err['message'] . "\n";
				echo $err['trace'] . "\n\n";
			}
			exit( 1 );
		}

		echo "All automated tests passed successfully!\n";
		exit( 0 );
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

	/* ================= TEST CASES ================= */

	/**
	 * Acceptance Test 1:
	 * Starting with all three assignments missing and no candidates,
	 * one successful recovery creates and assigns exactly three distinct suitable published pages.
	 */
	public function test_fresh_recovery_creates_three_distinct_pages(): void {
		$result = RecoveryService::recover( 'manual' );
		$this->assert( $result['success'], 'Recovery should report success' );

		$cart_id     = absint( get_option( 'woocommerce_cart_page_id', 0 ) );
		$checkout_id = absint( get_option( 'woocommerce_checkout_page_id', 0 ) );
		$myaccount_id= absint( get_option( 'woocommerce_myaccount_page_id', 0 ) );

		$this->assert( $cart_id > 0, 'Cart page should be assigned' );
		$this->assert( $checkout_id > 0, 'Checkout page should be assigned' );
		$this->assert( $myaccount_id > 0, 'My Account page should be assigned' );

		// Verify pages are distinct.
		$this->assert( $cart_id !== $checkout_id, 'Cart and Checkout must be distinct' );
		$this->assert( $cart_id !== $myaccount_id, 'Cart and My Account must be distinct' );
		$this->assert( $checkout_id !== $myaccount_id, 'Checkout and My Account must be distinct' );

		// Verify pages exist, are published, and have tracking meta.
		$cart_post = get_post( $cart_id );
		$this->assertEquals( 'publish', $cart_post->post_status, 'Cart must be published' );
		$this->assertEquals( 'cart', get_post_meta( $cart_id, PageInspector::META_TRACKED_ROLE, true ) );

		$checkout_post = get_post( $checkout_id );
		$this->assertEquals( 'publish', $checkout_post->post_status, 'Checkout must be published' );
		$this->assertEquals( 'checkout', get_post_meta( $checkout_id, PageInspector::META_TRACKED_ROLE, true ) );

		$myaccount_post = get_post( $myaccount_id );
		$this->assertEquals( 'publish', $myaccount_post->post_status, 'My Account must be published' );
		$this->assertEquals( 'myaccount', get_post_meta( $myaccount_id, PageInspector::META_TRACKED_ROLE, true ) );

		// Diagnostics must now confirm all 3 are healthy.
		$diagnostics = PageInspector::inspect_all_roles( true );
		$this->assert( $diagnostics['cart']['is_healthy'], 'Cart should be healthy' );
		$this->assert( $diagnostics['checkout']['is_healthy'], 'Checkout should be healthy' );
		$this->assert( $diagnostics['myaccount']['is_healthy'], 'My Account should be healthy' );
	}

	/**
	 * Acceptance Test 2:
	 * Ten repeated repair runs produce no extra pages or changes to healthy assignments.
	 */
	public function test_repeated_recovery_idempotency(): void {
		// Run initial recovery.
		RecoveryService::recover( 'manual' );

		$cart_id      = absint( get_option( 'woocommerce_cart_page_id' ) );
		$checkout_id  = absint( get_option( 'woocommerce_checkout_page_id' ) );
		$myaccount_id = absint( get_option( 'woocommerce_myaccount_page_id' ) );
		$initial_page_count = count( \WCPR_Test_State::$posts );

		// Run 10 times consecutively.
		for ( $i = 0; $i < 10; $i++ ) {
			$run = RecoveryService::recover( 'cron' );
			$this->assert( $run['success'], "Run {$i} must succeed" );
			$this->assertEquals( 'already_healthy', $run['results']['cart']['result_code'] );
			$this->assertEquals( 'already_healthy', $run['results']['checkout']['result_code'] );
			$this->assertEquals( 'already_healthy', $run['results']['myaccount']['result_code'] );
		}

		$this->assertEquals( $cart_id, absint( get_option( 'woocommerce_cart_page_id' ) ), 'Cart ID must remain unchanged' );
		$this->assertEquals( $checkout_id, absint( get_option( 'woocommerce_checkout_page_id' ) ), 'Checkout ID must remain unchanged' );
		$this->assertEquals( $myaccount_id, absint( get_option( 'woocommerce_myaccount_page_id' ) ), 'My Account ID must remain unchanged' );
		$this->assertEquals( $initial_page_count, count( \WCPR_Test_State::$posts ), 'No new pages should be created' );
	}

	/**
	 * Acceptance Test 3:
	 * Concurrency & atomic lock: two simultaneous repair requests create no duplicate pages,
	 * safely serialize or report an existing operation.
	 */
	public function test_concurrency_and_atomic_lock(): void {
		// Worker 1 acquires lock.
		$token1 = Lock::acquire( 60 );
		$this->assert( ! empty( $token1 ), 'Worker 1 should acquire lock' );
		$this->assert( Lock::is_locked(), 'Lock should be active' );

		// Worker 2 attempts to acquire lock: must fail.
		$token2 = Lock::acquire( 60 );
		$this->assert( null === $token2, 'Worker 2 should not acquire active lock' );

		// Attempting recovery while lock is held must return status 'locked'.
		$recovery_attempt = RecoveryService::recover( 'cron' );
		$this->assertEquals( 'locked', $recovery_attempt['status'], 'Recovery must abort with locked status' );

		// Worker 2 attempts to release with invalid token: must fail.
		$bad_release = Lock::release( 'wrong-token' );
		$this->assert( ! $bad_release, 'Release with wrong token must fail' );
		$this->assert( Lock::is_locked(), 'Lock must remain active after bad release' );

		// Worker 1 releases with valid token: must succeed.
		$good_release = Lock::release( $token1 );
		$this->assert( $good_release, 'Worker 1 release must succeed' );
		$this->assert( ! Lock::is_locked(), 'Lock must now be free' );

		// Worker 2 can now acquire lock.
		$token2_after = Lock::acquire( 60 );
		$this->assert( ! empty( $token2_after ), 'Worker 2 can acquire lock after release' );
		Lock::release( $token2_after );
	}

	/**
	 * Acceptance Test 4:
	 * A previously published suitable trashed page is restored with its original ID and content.
	 */
	public function test_restore_trashed_page_with_published_pre_status(): void {
		// Setup an assigned published cart page.
		$page_id = wp_insert_post( [
			'post_title'   => 'Original Cart',
			'post_name'    => 'cart',
			'post_content' => '<!-- wp:woocommerce/cart -->',
			'post_status'  => 'publish',
		] );
		update_option( 'woocommerce_cart_page_id', $page_id );

		// Trash the page.
		wp_trash_post( $page_id );
		$this->assertEquals( 'trash', get_post( $page_id )->post_status );

		// Run recovery.
		$res = RecoveryService::recover( 'event' );
		$this->assert( $res['success'], 'Recovery should succeed' );
		$this->assertEquals( 'restore', $res['results']['cart']['action'] );
		$this->assertEquals( $page_id, $res['results']['cart']['new_page_id'], 'Must restore original ID' );

		// Verify page status in database is publish.
		$restored_post = get_post( $page_id );
		$this->assertEquals( 'publish', $restored_post->post_status );
		$this->assertEquals( '<!-- wp:woocommerce/cart -->', $restored_post->post_content, 'Content must be preserved unchanged' );
	}

	/**
	 * Acceptance Test 5:
	 * Draft/private pages, password protection, duplicate assignments, ambiguous candidates:
	 * produce actionable results without unsafe changes.
	 */
	public function test_trashed_page_with_draft_pre_status_requires_manual_attention(): void {
		$page_id = wp_insert_post( [
			'post_title'   => 'Draft Cart',
			'post_name'    => 'cart',
			'post_content' => '<!-- wp:woocommerce/cart -->',
			'post_status'  => 'draft',
		] );
		update_option( 'woocommerce_cart_page_id', $page_id );
		wp_trash_post( $page_id );

		// Pre-trash status is 'draft'.
		$res = RecoveryService::recover( 'manual' );
		$this->assertEquals( 'blocked', $res['results']['cart']['status'] );
		$this->assertEquals( 'trash_pre_status_unsafe', $res['results']['cart']['result_code'] );

		// Page must remain in trash and not be published.
		$this->assertEquals( 'trash', get_post( $page_id )->post_status );
	}

	public function test_password_protected_and_unpublished_produce_actionable_blocks(): void {
		// 1. Password protected page.
		$page_id = wp_insert_post( [
			'post_title'    => 'Protected Checkout',
			'post_content'  => '<!-- wp:woocommerce/checkout -->',
			'post_status'   => 'publish',
			'post_password' => 'secret123',
		] );
		update_option( 'woocommerce_checkout_page_id', $page_id );

		$diag = PageInspector::inspect_role( 'checkout', [ 'checkout' => $page_id ] );
		$this->assertEquals( 'password_protected', $diag['status_code'] );
		$this->assert( ! $diag['is_healthy'] );

		$res = RecoveryService::recover( 'manual' );
		$this->assertEquals( 'blocked', $res['results']['checkout']['status'] );
		$this->assertEquals( $page_id, absint( get_option( 'woocommerce_checkout_page_id' ) ), 'Must not overwrite assignment' );

		// 2. Draft page.
		$draft_id = wp_insert_post( [
			'post_title'   => 'Draft Cart',
			'post_content' => '<!-- wp:woocommerce/cart -->',
			'post_status'  => 'draft',
		] );
		update_option( 'woocommerce_cart_page_id', $draft_id );
		$diag_draft = PageInspector::inspect_role( 'cart', [ 'cart' => $draft_id ] );
		$this->assertEquals( 'unpublished', $diag_draft['status_code'] );

		$res_draft = RecoveryService::recover( 'manual' );
		$this->assertEquals( 'blocked', $res_draft['results']['cart']['status'] );
		$this->assertEquals( 'draft', get_post( $draft_id )->post_status, 'Must not publish draft' );
	}

	public function test_duplicate_role_assignment_detection(): void {
		$shared_page_id = wp_insert_post( [
			'post_title'   => 'Shared Page',
			'post_content' => '<!-- wp:woocommerce/cart -->',
			'post_status'  => 'publish',
		] );
		update_option( 'woocommerce_cart_page_id', $shared_page_id );
		update_option( 'woocommerce_checkout_page_id', $shared_page_id );

		$diagnostics = PageInspector::inspect_all_roles( true );
		$this->assertEquals( 'duplicate_assignment', $diagnostics['cart']['status_code'] );
		$this->assertEquals( 'duplicate_assignment', $diagnostics['checkout']['status_code'] );

		$res = RecoveryService::recover( 'manual' );
		$this->assertEquals( 'blocked', $res['results']['cart']['status'] );
		$this->assertEquals( 'blocked', $res['results']['checkout']['status'] );
	}

	public function test_ambiguous_candidates_halts_recovery(): void {
		// Delete cart option.
		delete_option( 'woocommerce_cart_page_id' );

		// Create two candidate published pages with Cart shortcode.
		$p1 = wp_insert_post( [
			'post_title'   => 'Cart Option A',
			'post_content' => '[woocommerce_cart]',
			'post_status'  => 'publish',
		] );
		$p2 = wp_insert_post( [
			'post_title'   => 'Cart Option B',
			'post_content' => '[woocommerce_cart]',
			'post_status'  => 'publish',
		] );

		$res = RecoveryService::recover( 'manual', 'cart' );
		$this->assertEquals( 'blocked', $res['results']['cart']['status'] );
		$this->assertEquals( 'ambiguous_candidates_detected', $res['results']['cart']['result_code'] );
		$this->assertEquals( 0, absint( get_option( 'woocommerce_cart_page_id', 0 ) ), 'Must not assign arbitrarily' );
	}

	/**
	 * Acceptance Test 6:
	 * Existing custom templates, shortcodes, and blocks remain unchanged.
	 */
	public function test_preserves_custom_builder_content(): void {
		$builder_page = wp_insert_post( [
			'post_title'   => 'Custom Elementor Cart',
			'post_content' => '<div class="elementor-custom-cart-widget">Custom layout</div>',
			'post_status'  => 'publish',
		] );
		update_option( 'woocommerce_cart_page_id', $builder_page );

		$diag = PageInspector::inspect_role( 'cart', [ 'cart' => $builder_page ] );
		$this->assert( $diag['is_healthy'], 'Should be treated as healthy to preserve custom builder' );
		$this->assertEquals( 'healthy_custom', $diag['status_code'] );
		$this->assert( $diag['has_custom_warn'] );

		// Recovery must leave it unchanged.
		$res = RecoveryService::recover( 'manual', 'cart' );
		$this->assertEquals( 'already_healthy', $res['results']['cart']['result_code'] );
		$this->assertEquals( '<div class="elementor-custom-cart-widget">Custom layout</div>', get_post( $builder_page )->post_content );
	}

	/**
	 * Acceptance Test 7:
	 * Simulated page-insert and option-write failures produce truthful results;
	 * retries rediscover prior creations.
	 */
	public function test_partial_failure_and_rediscovery(): void {
		delete_option( 'woocommerce_cart_page_id' );

		// Simulate option-write failure.
		\WCPR_Test_State::$simulate_option_write_failure = true;
		$res = RecoveryService::recover( 'manual', 'cart' );

		$this->assertEquals( 'partial_failure', $res['results']['cart']['status'] );
		$this->assertEquals( 'option_persistence_failed', $res['results']['cart']['result_code'] );
		$created_id = $res['results']['cart']['new_page_id'];
		$this->assert( $created_id > 0, 'Page was created in database' );

		// Stop simulating failure for retry.
		\WCPR_Test_State::$simulate_option_write_failure = false;

		// Subsequent recovery should rediscover the tracked page instead of creating a duplicate!
		$page_count_before = count( \WCPR_Test_State::$posts );
		$retry_res = RecoveryService::recover( 'manual', 'cart' );

		$this->assertEquals( 'success', $retry_res['results']['cart']['status'] );
		$this->assertEquals( 'reassigned_tracked_page', $retry_res['results']['cart']['result_code'] );
		$this->assertEquals( $created_id, $retry_res['results']['cart']['new_page_id'], 'Must reuse previously created page' );
		$this->assertEquals( $page_count_before, count( \WCPR_Test_State::$posts ), 'No duplicate page created' );
		$this->assertEquals( $created_id, absint( get_option( 'woocommerce_cart_page_id' ) ) );
	}

	/**
	 * Acceptance Test 8:
	 * Scheduled debouncing, coalescing, and recursion prevention.
	 */
	public function test_scheduler_event_debouncing_and_recursion_guard(): void {
		$page_id = wp_insert_post( [
			'post_title'   => 'Cart',
			'post_content' => '<!-- wp:woocommerce/cart -->',
			'post_status'  => 'publish',
		] );
		update_option( 'woocommerce_cart_page_id', $page_id );

		// Fire 5 rapid debounced check triggers.
		Scheduler::schedule_debounced_check();
		Scheduler::schedule_debounced_check();
		Scheduler::schedule_debounced_check();

		// Action Scheduler should have exactly one action enqueued.
		$this->assert( Scheduler::has_action_scheduler() );
		$this->assert( as_has_scheduled_action( Scheduler::ACTION_HOOK ) );

		// Recursion guard: during active recovery, schedule_debounced_check must do nothing.
		RecoveryService::$is_recovering = true;
		delete_transient( 'wpcalibrate_wcpr_debounce' );
		Scheduler::schedule_debounced_check();
		$this->assert( ! get_transient( 'wpcalibrate_wcpr_debounce' ), 'Debounce must not set during active recovery' );
		RecoveryService::$is_recovering = false;
	}

	/**
	 * Acceptance Test 9:
	 * Disabling automation or a role prevents corresponding automatic mutations;
	 * manual repair remains available to authorized administrators.
	 */
	public function test_policy_and_role_toggles(): void {
		delete_option( 'woocommerce_cart_page_id' );
		delete_option( 'woocommerce_checkout_page_id' );

		// Disable Checkout protection in settings.
		update_option( Settings::OPTION_NAME, [
			'auto_recovery_enabled' => true,
			'protect_cart'          => true,
			'protect_checkout'      => false,
			'protect_myaccount'     => true,
		] );

		// Background / cron recovery.
		$auto_run = RecoveryService::recover( 'cron' );
		$this->assertEquals( 'success', $auto_run['results']['cart']['status'], 'Cart should recover' );
		$this->assertEquals( 'policy_skipped', $auto_run['results']['checkout']['status'], 'Checkout should be skipped due to policy' );
		$this->assertEquals( 0, absint( get_option( 'woocommerce_checkout_page_id', 0 ) ) );

		// Direct manual repair for Checkout by administrator must still work.
		$manual_run = RecoveryService::recover( 'manual', 'checkout' );
		$this->assertEquals( 'success', $manual_run['results']['checkout']['status'], 'Manual repair must override toggle' );
		$this->assert( absint( get_option( 'woocommerce_checkout_page_id', 0 ) ) > 0 );
	}

	/**
	 * Acceptance Test 10:
	 * Capability checks and security guards.
	 */
	public function test_security_capabilities_and_nonces(): void {
		// Non-administrator user without manage_options.
		\WCPR_Test_State::$current_user_caps = [ 'manage_options' => false ];

		$threw = false;
		try {
			Admin::handle_check_now();
		} catch ( \RuntimeException $e ) {
			$threw = true;
		}
		$this->assert( $threw, 'Unauthorized user must be denied' );

		\WCPR_Test_State::$current_user_caps = [ 'manage_options' => true ];
	}

	/**
	 * Acceptance Test 11:
	 * Deactivation and uninstall retention policies.
	 */
	public function test_lifecycle_deactivation_and_uninstall_retention(): void {
		// Create and assign pages.
		RecoveryService::recover( 'manual' );
		$cart_id = absint( get_option( 'woocommerce_cart_page_id' ) );
		$this->assert( $cart_id > 0 );

		// Test deactivation: stops jobs, releases locks.
		Lock::acquire( 120 );
		$this->assert( Lock::is_locked() );
		Lifecycle::deactivate();
		$this->assert( ! Lock::is_locked(), 'Deactivation must release lock' );
		$this->assert( ! as_has_scheduled_action( Scheduler::ACTION_HOOK ), 'Deactivation must cancel jobs' );
		$this->assertEquals( $cart_id, absint( get_option( 'woocommerce_cart_page_id' ) ), 'Deactivation must preserve assignments' );

		// Test uninstall with cleanup enabled.
		update_option( Settings::OPTION_NAME, [ 'uninstall_cleanup' => true ] );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require WPCALIBRATE_WCPR_PLUGIN_DIR . 'uninstall.php';

		// Plugin options should be deleted.
		$this->assert( false === get_option( Settings::OPTION_NAME, false ), 'Plugin options must be removed' );

		// WooCommerce pages and assignments must STILL exist!
		$this->assertEquals( $cart_id, absint( get_option( 'woocommerce_cart_page_id' ) ), 'Core page assignment MUST survive uninstall' );
		$this->assert( get_post( $cart_id ) instanceof \WP_Post, 'Core page MUST survive uninstall' );
	}

	/**
	 * Acceptance Test 12:
	 * Shared WPCalibrate menu coordination across plugin load orders and branding icon.
	 */
	public function test_shared_wpcalibrate_menu_coordination(): void {
		// Verify branding icon files exist and are valid.
		$icon_white_path = WPCALIBRATE_WCPR_PLUGIN_DIR . 'branding/icon-white.png';
		$icon_dark_path  = WPCALIBRATE_WCPR_PLUGIN_DIR . 'branding/icon-dark.png';
		$this->assert( file_exists( $icon_white_path ), 'branding/icon-white.png must exist' );
		$this->assert( filesize( $icon_white_path ) > 0, 'branding/icon-white.png must not be empty' );
		$this->assert( file_exists( $icon_dark_path ), 'branding/icon-dark.png must exist' );
		$this->assert( filesize( $icon_dark_path ) > 0, 'branding/icon-dark.png must not be empty' );

		// Scenario A: When our plugin loads first, it registers parent using branding icon-white.png for dark admin menu.
		\WCPR_Test_State::reset();
		Admin::register_menu();
		$this->assert( isset( \WCPR_Test_State::$menu['wpcalibrate'] ), 'Parent registered by our plugin' );
		$this->assert(
			str_ends_with( \WCPR_Test_State::$menu['wpcalibrate']['icon'], 'branding/icon-white.png' ),
			'Parent menu icon must use branding/icon-white.png for dark admin menu background'
		);
		$this->assert( isset( \WCPR_Test_State::$submenu['wpcalibrate']['wpcalibrate-wc-pages-recovery'] ) );

		// Scenario B: Simulate another WPCalibrate plugin already registered parent menu.
		\WCPR_Test_State::reset();
		\WCPR_Test_State::$admin_page_hooks['wpcalibrate'] = true;
		Admin::register_menu();

		// Parent should not be re-registered; submenu must be registered.
		$this->assert( isset( \WCPR_Test_State::$submenu['wpcalibrate']['wpcalibrate-wc-pages-recovery'] ) );
	}

	/**
	 * Acceptance Test 13:
	 * Blocks vs Shortcodes creation format.
	 */
	public function test_blocks_vs_shortcodes_creation_format(): void {
		// 1. Test shortcodes format.
		update_option( Settings::OPTION_NAME, [ 'new_page_format' => 'shortcodes' ] );
		$sc_cart = RecoveryService::create_core_page( 'cart' );
		$sc_post = get_post( $sc_cart['created_page_id'] );
		$this->assert( str_contains( $sc_post->post_content, '[woocommerce_cart]' ), 'Shortcode format must use [woocommerce_cart]' );

		// 2. Test blocks format.
		update_option( Settings::OPTION_NAME, [ 'new_page_format' => 'blocks' ] );
		$bl_cart = RecoveryService::create_core_page( 'cart' );
		$bl_post = get_post( $bl_cart['created_page_id'] );
		$this->assert( str_contains( $bl_post->post_content, 'wp:woocommerce/cart' ), 'Blocks format must use wp:woocommerce/cart' );
	}

	/**
	 * Acceptance Test 14:
	 * GitHub Dashboard Auto-Updater Workflow.
	 */
	public function test_github_updater_workflow(): void {
		\WCPR_Test_State::reset();
		$plugin_file = GitHubUpdater::get_plugin_basename();
		$this->assertEquals( 'wpcalibrate-wc-pages-recovery/wpcalibrate-wc-pages-recovery.php', $plugin_file );

		// Simulate GitHub API endpoint returning a newer version v1.2.0 with a ZIP asset.
		$api_url = 'https://api.github.com/repos/' . GitHubUpdater::GITHUB_REPO . '/releases/latest';
		\WCPR_Test_State::$http_responses[ $api_url ] = [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'tag_name'    => 'v1.2.0',
				'body'        => '### Changes in 1.2.0\n- Enhanced page recovery engine\n- Performance optimizations',
				'zipball_url' => 'https://api.github.com/repos/zeeshanraza-official/wpcalibrate-wc-pages-recovery/zipball/v1.2.0',
				'assets'      => [
					[
						'name'                 => 'wpcalibrate-wc-pages-recovery.zip',
						'browser_download_url' => 'https://github.com/zeeshanraza-official/wpcalibrate-wc-pages-recovery/releases/download/v1.2.0/wpcalibrate-wc-pages-recovery.zip',
					],
				],
			] ),
		];

		// Force refresh to bypass any cached transient.
		delete_transient( GitHubUpdater::TRANSIENT_KEY );

		// 1. Verify update transient population for new version.
		$transient = (object) [ 'response' => [], 'no_update' => [] ];
		$filtered  = GitHubUpdater::filter_update_plugins( $transient );

		$this->assert( isset( $filtered->response[ $plugin_file ] ), 'New version must be placed in response array' );
		$update = $filtered->response[ $plugin_file ];
		$this->assertEquals( '1.2.0', $update->new_version );
		$this->assertEquals( 'https://github.com/zeeshanraza-official/wpcalibrate-wc-pages-recovery/releases/download/v1.2.0/wpcalibrate-wc-pages-recovery.zip', $update->package );

		// 2. Verify plugins_api modal details.
		$args = (object) [ 'slug' => 'wpcalibrate-wc-pages-recovery' ];
		$info = GitHubUpdater::filter_plugins_api( false, 'plugin_information', $args );

		$this->assert( is_object( $info ), 'plugins_api must return object' );
		$this->assertEquals( '1.2.0', $info->version );
		$this->assert( str_contains( $info->sections['changelog'], 'Enhanced page recovery engine' ), 'Changelog must include release notes' );

		// 3. Verify when GitHub has same/lower version, it populates no_update.
		\WCPR_Test_State::$http_responses[ $api_url ] = [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'tag_name'    => 'v1.0.0',
				'zipball_url' => 'https://example.com/download.zip',
				'assets'      => [],
			] ),
		];
		delete_transient( GitHubUpdater::TRANSIENT_KEY );

		$transient2 = (object) [ 'response' => [], 'no_update' => [] ];
		$filtered2  = GitHubUpdater::filter_update_plugins( $transient2 );
		$this->assert( isset( $filtered2->no_update[ $plugin_file ] ), 'Current version must be placed in no_update array' );
		$this->assert( ! isset( $filtered2->response[ $plugin_file ] ), 'Current version must not be in response array' );
	}
}

// Execute the test suite!
$runner = new TestRunner();
$runner->run();
