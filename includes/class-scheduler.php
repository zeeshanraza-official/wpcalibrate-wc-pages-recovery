<?php
/**
 * Background Automation, Event Hooks, and Scheduling.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Scheduler
 *
 * Coordinates Action Scheduler with WP-Cron fallback, debounced event triggers,
 * retry backoff, and recursion suppression.
 */
class Scheduler {

	public const ACTION_HOOK    = 'wpcalibrate_wcpr_run_recovery';
	public const ACTION_GROUP   = 'wpcalibrate-wc-pages-recovery';
	public const DEBOUNCE_DELAY = 15; // 15 seconds.
	public const MAX_RETRIES    = 3;
	public const OPTION_RETRIES = 'wpcalibrate_wcpr_retry_count';

	/**
	 * Register background hooks and event listeners.
	 */
	public static function init(): void {
		// Hook worker action to RecoveryService.
		add_action( self::ACTION_HOOK, [ self::class, 'execute_scheduled_job' ] );

		// Event listeners for page lifecycle and content changes.
		add_action( 'wp_trash_post', [ self::class, 'on_post_trashed' ], 10, 1 );
		add_action( 'untrashed_post', [ self::class, 'on_post_untrashed' ], 10, 1 );
		add_action( 'before_delete_post', [ self::class, 'on_before_post_deleted' ], 10, 1 );
		add_action( 'deleted_post', [ self::class, 'on_post_deleted' ], 10, 1 );
		add_action( 'transition_post_status', [ self::class, 'on_post_status_transition' ], 10, 3 );
		add_action( 'post_updated', [ self::class, 'on_post_updated' ], 10, 3 );

		// Event listeners for WooCommerce page option changes.
		add_action( 'update_option_woocommerce_cart_page_id', [ self::class, 'on_role_option_updated' ], 10, 3 );
		add_action( 'update_option_woocommerce_checkout_page_id', [ self::class, 'on_role_option_updated' ], 10, 3 );
		add_action( 'update_option_woocommerce_myaccount_page_id', [ self::class, 'on_role_option_updated' ], 10, 3 );
	}

	/**
	 * Worker execution callback.
	 */
	public static function execute_scheduled_job(): void {
		// If auto-recovery is globally disabled, skip execution.
		if ( ! Settings::is_auto_recovery_enabled() ) {
			return;
		}

		$outcome = RecoveryService::recover( 'scheduled_job' );

		// Evaluate results to manage retries for transient errors.
		self::handle_retry_logic( $outcome );
	}

	/**
	 * Handle retry backoff for transient failures (bounded to 3 attempts).
	 *
	 * @param array<string, mixed> $outcome
	 */
	private static function handle_retry_logic( array $outcome ): void {
		$has_transient_error = false;

		if ( isset( $outcome['status'] ) && in_array( $outcome['status'], [ 'locked', 'error' ], true ) ) {
			$has_transient_error = true;
		}

		if ( ! empty( $outcome['results'] ) && is_array( $outcome['results'] ) ) {
			foreach ( $outcome['results'] as $res ) {
				if ( isset( $res['status'] ) && in_array( $res['status'], [ 'partial_failure', 'error' ], true ) ) {
					$has_transient_error = true;
					break;
				}
			}
		}

		$retries = absint( get_option( self::OPTION_RETRIES, 0 ) );

		if ( $has_transient_error && $retries < self::MAX_RETRIES ) {
			$retries++;
			update_option( self::OPTION_RETRIES, $retries, false );

			// Exponential backoff: 2 min, 4 min, 8 min.
			$delay = (int) pow( 2, $retries ) * 60;
			self::schedule_single( time() + $delay );

			RecoveryService::log( 'warning', sprintf( 'Transient failure detected. Scheduling retry %d of %d in %d seconds.', $retries, self::MAX_RETRIES, $delay ) );
		} else {
			// Reset retry count on success or when no transient error occurred (e.g. healthy, policy blocked, or ambiguous halt).
			if ( $retries > 0 ) {
				update_option( self::OPTION_RETRIES, 0, false );
			}
		}
	}

	/**
	 * Schedule a debounced check if not already enqueued.
	 */
	public static function schedule_debounced_check(): void {
		// Suppress during plugin's own active recovery mutations.
		if ( RecoveryService::$is_recovering ) {
			return;
		}

		if ( ! Settings::is_auto_recovery_enabled() ) {
			return;
		}

		$debounce_transient = 'wpcalibrate_wcpr_debounce';
		if ( get_transient( $debounce_transient ) ) {
			return; // Already debounced.
		}

		set_transient( $debounce_transient, 1, self::DEBOUNCE_DELAY );
		self::schedule_single( time() + self::DEBOUNCE_DELAY );
	}

	/**
	 * Schedule initial check after activation.
	 */
	public static function schedule_initial_check(): void {
		self::schedule_single( time() + 5 );
		self::reschedule_reconciliation();
	}

	/**
	 * Ensure hourly reconciliation job is registered.
	 */
	public static function reschedule_reconciliation(): void {
		// Prefer Action Scheduler if available.
		if ( self::has_action_scheduler() ) {
			// Clear legacy wp-cron if switching.
			wp_clear_scheduled_hook( self::ACTION_HOOK );

			if ( ! as_has_scheduled_action( self::ACTION_HOOK, [], self::ACTION_GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::ACTION_HOOK, [], self::ACTION_GROUP );
			}
		} else {
			// Fallback to WP-Cron.
			if ( ! wp_next_scheduled( self::ACTION_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::ACTION_HOOK );
			}
		}
	}

	/**
	 * Schedule a single execution at a specific timestamp.
	 *
	 * @param int $timestamp Future execution unix timestamp.
	 */
	public static function schedule_single( int $timestamp ): void {
		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::ACTION_HOOK, [], self::ACTION_GROUP ) ) {
				as_schedule_single_action( $timestamp, self::ACTION_HOOK, [], self::ACTION_GROUP );
			}
		} else {
			if ( ! wp_next_scheduled( self::ACTION_HOOK ) ) {
				wp_schedule_single_event( $timestamp, self::ACTION_HOOK );
			}
		}
	}

	/**
	 * Cancel all registered jobs across both backends.
	 */
	public static function cancel_all_jobs(): void {
		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( self::ACTION_HOOK, [], self::ACTION_GROUP );
		}

		wp_clear_scheduled_hook( self::ACTION_HOOK );
		delete_transient( 'wpcalibrate_wcpr_debounce' );
		delete_option( self::OPTION_RETRIES );
	}

	/**
	 * Get the timestamp of the next scheduled execution.
	 *
	 * @return int|null
	 */
	public static function get_next_scheduled_time(): ?int {
		if ( self::has_action_scheduler() ) {
			$action_id = as_next_scheduled_action( self::ACTION_HOOK, [], self::ACTION_GROUP );
			if ( $action_id ) {
				$action = \ActionScheduler::store()->fetch_action( $action_id );
				if ( $action && method_exists( $action, 'get_schedule' ) ) {
					$date = $action->get_schedule()->get_date();
					return $date ? $date->getTimestamp() : null;
				}
			}
		}

		$wp_cron_time = wp_next_scheduled( self::ACTION_HOOK );
		return $wp_cron_time ? (int) $wp_cron_time : null;
	}

	/**
	 * Detect if Action Scheduler is available and initialized.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Check if a post ID belongs to any currently assigned or tracked WooCommerce core page.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_assigned_or_tracked_page( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		foreach ( PageInspector::get_supported_roles() as $role ) {
			$assigned_id = absint( get_option( PageInspector::get_role_option_name( $role ), 0 ) );
			if ( $assigned_id === $post_id ) {
				return true;
			}
		}

		$tracked_role = get_post_meta( $post_id, PageInspector::META_TRACKED_ROLE, true );
		return ! empty( $tracked_role );
	}

	/* ================= EVENT HOOK HANDLERS ================= */

	/**
	 * Post trashed handler.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_post_trashed( int $post_id ): void {
		if ( self::is_assigned_or_tracked_page( $post_id ) ) {
			self::schedule_debounced_check();
		}
	}

	/**
	 * Post untrashed handler.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_post_untrashed( int $post_id ): void {
		if ( self::is_assigned_or_tracked_page( $post_id ) ) {
			self::schedule_debounced_check();
		}
	}

	/**
	 * Snapshot before permanent deletion if it affects a role.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_before_post_deleted( int $post_id ): void {
		if ( self::is_assigned_or_tracked_page( $post_id ) ) {
			// Tag temporary transient so post-delete handler triggers recovery.
			set_transient( 'wpcalibrate_wcpr_del_' . $post_id, 1, 60 );
		}
	}

	/**
	 * Post permanently deleted handler.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_post_deleted( int $post_id ): void {
		if ( get_transient( 'wpcalibrate_wcpr_del_' . $post_id ) ) {
			delete_transient( 'wpcalibrate_wcpr_del_' . $post_id );
			self::schedule_debounced_check();
		}
	}

	/**
	 * Post status transition handler (e.g. published -> draft).
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public static function on_post_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'page' === $post->post_type && $new_status !== $old_status ) {
			if ( self::is_assigned_or_tracked_page( $post->ID ) ) {
				self::schedule_debounced_check();
			}
		}
	}

	/**
	 * Post content updated handler.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post_after
	 * @param \WP_Post $post_before
	 */
	public static function on_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( 'page' === $post_after->post_type ) {
			if ( $post_after->post_content !== $post_before->post_content ) {
				if ( self::is_assigned_or_tracked_page( $post_id ) ) {
					self::schedule_debounced_check();
				}
			}
		}
	}

	/**
	 * Option change handler for WooCommerce page IDs.
	 *
	 * @param mixed  $old_value
	 * @param mixed  $new_value
	 * @param string $option
	 */
	public static function on_role_option_updated( mixed $old_value, mixed $new_value, string $option ): void {
		if ( $old_value !== $new_value ) {
			self::schedule_debounced_check();
		}
	}
}
