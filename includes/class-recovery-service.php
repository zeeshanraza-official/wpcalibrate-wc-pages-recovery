<?php
/**
 * Safe Recovery Engine and Mutation Orchestration.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class RecoveryService
 *
 * Core recovery service enforcing the safe recovery algorithm, atomic locking,
 * ambiguity halts, pre-trash verification, and truthful persistence recording.
 */
class RecoveryService {

	/**
	 * Recursion guard flag to prevent self-triggered recovery loops.
	 *
	 * @var bool
	 */
	public static bool $is_recovering = false;

	/**
	 * Run recovery across one or all roles.
	 *
	 * @param string      $trigger Trigger source ('cron', 'manual', 'event', 'admin_action').
	 * @param string|null $target_role Optional specific role to recover, or null for all.
	 * @return array<string, mixed> Recovery outcome report.
	 */
	public static function recover( string $trigger = 'system', ?string $target_role = null ): array {
		if ( ! Lifecycle::is_woocommerce_ready() ) {
			return [
				'success' => false,
				'status'  => 'error',
				'message' => __( 'WooCommerce is not available or inactive.', 'wpcalibrate-wc-pages-recovery' ),
			];
		}

		// Acquire atomic concurrency lock.
		$lock_token = Lock::acquire( 120 );
		if ( null === $lock_token ) {
			self::log( 'warning', 'Recovery lock acquisition failed. Another worker holds the lock.' );
			return [
				'success' => false,
				'status'  => 'locked',
				'message' => __( 'A recovery operation is already in progress. Please try again shortly.', 'wpcalibrate-wc-pages-recovery' ),
			];
		}

		self::$is_recovering = true;
		$results             = [];

		try {
			// Clear cached diagnostics and fetch fresh state under lock.
			PageInspector::clear_cache();
			$diagnostics = PageInspector::inspect_all_roles( true );

			$roles_to_process = ( null !== $target_role ) ? [ $target_role ] : PageInspector::get_supported_roles();

			foreach ( $roles_to_process as $role ) {
				$role_result = self::recover_single_role( $role, $diagnostics, $trigger );
				$results[ $role ] = $role_result;
			}

			// Clear cache again and record fresh state after mutations.
			PageInspector::clear_cache();
			$fresh_diagnostics = PageInspector::inspect_all_roles( true );
			History::record_last_check( $fresh_diagnostics );

		} finally {
			self::$is_recovering = false;
			Lock::release( $lock_token );
		}

		return [
			'success' => true,
			'status'  => 'completed',
			'results' => $results,
		];
	}

	/**
	 * Recover a single core role following the safe recovery algorithm.
	 *
	 * @param string                                $role Role to evaluate and recover.
	 * @param array<string, array<string, mixed>>   $all_diagnostics Diagnostics map for all roles.
	 * @param string                                $trigger Trigger source.
	 * @return array<string, mixed>
	 */
	private static function recover_single_role( string $role, array $all_diagnostics, string $trigger ): array {
		$diag = $all_diagnostics[ $role ] ?? null;
		if ( ! is_array( $diag ) ) {
			return [ 'status' => 'error', 'message' => 'Diagnostic missing' ];
		}

		// Check policy toggle: if auto-recovery or role is disabled, and this is not a direct manual trigger.
		$is_manual = in_array( $trigger, [ 'manual', 'admin_action' ], true );
		if ( ! $is_manual && ! Settings::is_role_protected( $role ) ) {
			return [
				'status'      => 'policy_skipped',
				'result_code' => 'role_protection_disabled',
				'message'     => sprintf( __( 'Automatic recovery for %s is disabled in settings.', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ) ),
			];
		}

		/**
		 * Action hook before executing recovery on a role.
		 *
		 * @param string               $role Target role.
		 * @param array<string, mixed> $diag Current diagnostic state.
		 */
		do_action( 'wpcalibrate_wcpr_pre_recovery', $role, $diag );

		// 1. Leave healthy assignments unchanged.
		if ( $diag['is_healthy'] ) {
			return [
				'status'      => 'healthy',
				'result_code' => 'already_healthy',
				'page_id'     => $diag['page_id'],
				'message'     => sprintf( __( '%s is healthy and was left unchanged.', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ) ),
			];
		}

		$status_code = $diag['status_code'];
		$old_page_id = (int) $diag['page_id'];
		$option_name = PageInspector::get_role_option_name( $role );

		// 2. Handle trashed page.
		if ( 'trashed' === $status_code ) {
			$restored_id = self::handle_trashed_page( $role, $old_page_id, $trigger );
			if ( $restored_id > 0 ) {
				$outcome = [
					'status'      => 'success',
					'action'      => 'restore',
					'result_code' => 'restored_trashed_page',
					'old_page_id' => $old_page_id,
					'new_page_id' => $restored_id,
					'message'     => sprintf( __( 'Restored previously published %s page (#%d) from trash.', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ), $restored_id ),
				];
				History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
				do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
				return $outcome;
			}

			// Restoration could not proceed safely (e.g. pre-trash status was not published).
			$outcome = [
				'status'      => 'blocked',
				'action'      => 'none',
				'result_code' => 'trash_pre_status_unsafe',
				'old_page_id' => $old_page_id,
				'new_page_id' => 0,
				'message'     => sprintf( __( 'Trashed %s page (#%d) was not published prior to trashing. Manual review is required.', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ), $old_page_id ),
			];
			History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
			do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
			return $outcome;
		}

		// 3. Unpublished, password-protected, wrong post type, duplicate assignment:
		// Require manual administrator attention; do not silently overwrite.
		if ( in_array( $status_code, [ 'unpublished', 'password_protected', 'wrong_post_type', 'duplicate_assignment' ], true ) ) {
			$outcome = [
				'status'      => 'blocked',
				'action'      => 'none',
				'result_code' => 'requires_manual_attention',
				'old_page_id' => $old_page_id,
				'new_page_id' => $old_page_id,
				'message'     => sprintf(
					/* translators: 1: role label, 2: diagnostic message */
					__( '%1$s requires manual attention: %2$s', 'wpcalibrate-wc-pages-recovery' ),
					PageInspector::get_role_label( $role ),
					$diag['message']
				),
			];
			History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
			do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
			return $outcome;
		}

		// 4. Missing assignments or permanently deleted pages:
		// Collect IDs currently in use by other roles to avoid conflicts.
		$in_use_ids = [];
		foreach ( $all_diagnostics as $other_role => $other_diag ) {
			if ( $other_role !== $role && ! empty( $other_diag['page_id'] ) ) {
				$in_use_ids[] = absint( $other_diag['page_id'] );
			}
		}

		$candidates = PageInspector::find_candidates( $role, $in_use_ids );

		// A. Check for previously tracked page first.
		if ( $candidates['tracked'] instanceof \WP_Post ) {
			$candidate = $candidates['tracked'];
			/**
			 * Filter to approve candidate page before assignment.
			 *
			 * @param bool     $approved Initial approval flag.
			 * @param \WP_Post $candidate Candidate post.
			 * @param string   $role Target role.
			 */
			$approved = (bool) apply_filters( 'wpcalibrate_wcpr_candidate_approved', true, $candidate, $role );
			if ( $approved ) {
				update_option( $option_name, $candidate->ID );
				if ( absint( get_option( $option_name, 0 ) ) === $candidate->ID ) {
					$outcome = [
						'status'      => 'success',
						'action'      => 'reassign',
						'result_code' => 'reassigned_tracked_page',
						'old_page_id' => $old_page_id,
						'new_page_id' => $candidate->ID,
						'message'     => sprintf( __( 'Reassigned previously tracked %s page "%s" (#%d).', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ), $candidate->post_title, $candidate->ID ),
					];
					History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
					do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
					return $outcome;
				}
			}
		}

		// B. Check for unique published candidate containing role content.
		if ( 1 === count( $candidates['matching'] ) ) {
			$candidate = $candidates['matching'][0];
			$approved  = (bool) apply_filters( 'wpcalibrate_wcpr_candidate_approved', true, $candidate, $role );
			if ( $approved ) {
				update_option( $option_name, $candidate->ID );
				update_post_meta( $candidate->ID, PageInspector::META_TRACKED_ROLE, $role );

				if ( absint( get_option( $option_name, 0 ) ) === $candidate->ID ) {
					$outcome = [
						'status'      => 'success',
						'action'      => 'reassign',
						'result_code' => 'reassigned_matching_page',
						'old_page_id' => $old_page_id,
						'new_page_id' => $candidate->ID,
						'message'     => sprintf( __( 'Discovered and assigned existing %s page "%s" (#%d).', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ), $candidate->post_title, $candidate->ID ),
					];
					History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
					do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
					return $outcome;
				}
			}
		}

		// C. Ambiguous candidates: multiple matching pages exist.
		// Stop recovery for this role and request administrator selection!
		if ( count( $candidates['matching'] ) > 1 ) {
			$outcome = [
				'status'      => 'blocked',
				'action'      => 'ambiguous_candidates_halt',
				'result_code' => 'ambiguous_candidates_detected',
				'old_page_id' => $old_page_id,
				'new_page_id' => 0,
				'message'     => sprintf(
					/* translators: 1: role label, 2: count */
					__( 'Multiple published candidate pages (%2$d) found matching %1$s content. Recovery halted to prevent unintended assignments; please select a page manually.', 'wpcalibrate-wc-pages-recovery' ),
					PageInspector::get_role_label( $role ),
					count( $candidates['matching'] )
				),
			];
			History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
			do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
			return $outcome;
		}

		// D. No suitable candidates exist: create exactly one published page.
		$creation_result = self::create_core_page( $role );
		if ( $creation_result['created_page_id'] > 0 ) {
			$new_page_id = $creation_result['created_page_id'];

			// Assign option.
			update_option( $option_name, $new_page_id );

			// Verify option persistence immediately.
			$persisted_id = absint( get_option( $option_name, 0 ) );
			if ( $persisted_id === $new_page_id ) {
				$outcome = [
					'status'      => 'success',
					'action'      => 'create',
					'result_code' => 'created_new_page',
					'old_page_id' => $old_page_id,
					'new_page_id' => $new_page_id,
					'message'     => sprintf( __( 'Created and assigned a new %s page (#%d).', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ), $new_page_id ),
				];
				History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
				do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
				return $outcome;
			}

			// Partial failure: page was created in database, but option write did not persist.
			// Ownership metadata is already attached to $new_page_id, allowing next retry to rediscover it.
			$outcome = [
				'status'      => 'partial_failure',
				'action'      => 'create',
				'result_code' => 'option_persistence_failed',
				'old_page_id' => $old_page_id,
				'new_page_id' => $new_page_id,
				'message'     => sprintf( __( 'Created %s page (#%d), but setting the WooCommerce page option failed. Retry will attempt re-assignment.', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ), $new_page_id ),
			];
			History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
			do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
			return $outcome;
		}

		// Page insertion failure.
		$outcome = [
			'status'      => 'error',
			'action'      => 'create_failed',
			'result_code' => 'page_insert_failed',
			'old_page_id' => $old_page_id,
			'new_page_id' => 0,
			'message'     => sprintf( __( 'Failed to create new %s page in database.', 'wpcalibrate-wc-pages-recovery' ), PageInspector::get_role_label( $role ) ),
		];
		History::add( array_merge( $outcome, [ 'trigger' => $trigger, 'role' => $role ] ) );
		do_action( 'wpcalibrate_wcpr_post_recovery', $role, $outcome );
		return $outcome;
	}

	/**
	 * Safely restore a trashed page if and only if its pre-trash status was 'publish'.
	 *
	 * @param string $role Core role.
	 * @param int    $page_id Trashed page ID.
	 * @param string $trigger Trigger source.
	 * @return int Restored page ID if successfully restored and published, 0 otherwise.
	 */
	public static function handle_trashed_page( string $role, int $page_id, string $trigger ): int {
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post || 'trash' !== $post->post_status ) {
			return 0;
		}

		// Check WordPress pre-trash status meta.
		$pre_trash_status = get_post_meta( $page_id, '_wp_trash_meta_status', true );

		// If pre-trash status is explicitly 'publish', it is safe to untrash.
		if ( 'publish' === $pre_trash_status ) {
			wp_untrash_post( $page_id );

			// Verify post is now published.
			clean_post_cache( $page_id );
			$restored_post = get_post( $page_id );
			if ( $restored_post instanceof \WP_Post && 'publish' === $restored_post->post_status ) {
				update_post_meta( $page_id, PageInspector::META_TRACKED_ROLE, $role );
				return $page_id;
			}
		}

		return 0;
	}

	/**
	 * Create exactly one published WooCommerce core page with canonical content and tracking metadata.
	 *
	 * @param string $role Core role name ('cart', 'checkout', 'myaccount').
	 * @return array{created_page_id: int, format: string}
	 */
	public static function create_core_page( string $role ): array {
		$format = Settings::get_new_page_format();

		$page_data = self::get_canonical_page_specification( $role, $format );

		$post_id = wp_insert_post( [
			'post_title'     => $page_data['title'],
			'post_name'      => sanitize_title( $page_data['slug'] ),
			'post_content'   => $page_data['content'],
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		], true );

		if ( is_wp_error( $post_id ) || empty( $post_id ) || ! is_numeric( $post_id ) ) {
			self::log( 'error', sprintf( 'wp_insert_post failed for role %s: %s', $role, is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Unknown error' ) );
			return [ 'created_page_id' => 0, 'format' => $format ];
		}

		$created_id = (int) $post_id;

		// Attach ownership and role tracking metadata.
		update_post_meta( $created_id, PageInspector::META_TRACKED_ROLE, $role );
		update_post_meta( $created_id, '_wpcalibrate_wcpr_created_by', 'wpcalibrate-wc-pages-recovery' );
		update_post_meta( $created_id, '_wpcalibrate_wcpr_created_at', time() );

		return [
			'created_page_id' => $created_id,
			'format'          => $format,
		];
	}

	/**
	 * Canonical page structure definitions.
	 *
	 * @param string $role Core role name.
	 * @param string $format 'blocks' or 'shortcodes'.
	 * @return array{title: string, slug: string, content: string}
	 */
	public static function get_canonical_page_specification( string $role, string $format ): array {
		return match ( $role ) {
			PageInspector::ROLE_CART      => [
				'title'   => _x( 'Cart', 'Page title', 'woocommerce' ),
				'slug'    => 'cart',
				'content' => 'blocks' === $format
					? "<!-- wp:woocommerce/cart -->\n<div class=\"wp-block-woocommerce-cart is-loading\"></div>\n<!-- /wp:woocommerce/cart -->"
					: '[woocommerce_cart]',
			],
			PageInspector::ROLE_CHECKOUT  => [
				'title'   => _x( 'Checkout', 'Page title', 'woocommerce' ),
				'slug'    => 'checkout',
				'content' => 'blocks' === $format
					? "<!-- wp:woocommerce/checkout -->\n<div class=\"wp-block-woocommerce-checkout is-loading\"></div>\n<!-- /wp:woocommerce/checkout -->"
					: '[woocommerce_checkout]',
			],
			PageInspector::ROLE_MYACCOUNT => [
				'title'   => _x( 'My Account', 'Page title', 'woocommerce' ),
				'slug'    => 'my-account',
				'content' => 'blocks' === $format
					? "<!-- wp:shortcode -->\n[woocommerce_my_account]\n<!-- /wp:shortcode -->"
					: '[woocommerce_my_account]',
			],
			default                       => [
				'title'   => ucfirst( $role ),
				'slug'    => sanitize_title( $role ),
				'content' => '',
			],
		};
	}

	/**
	 * Safely manually assign an existing published page to a role.
	 *
	 * Performs strict server-side validation:
	 * - Page must exist and be post_type === 'page'
	 * - Page must be published and not password-protected
	 * - Page must not be assigned to another protected role
	 *
	 * @param string $role Target role.
	 * @param int    $page_id Target page ID.
	 * @param bool   $confirm_custom Whether administrator explicitly confirmed custom content.
	 * @return array{success: bool, message: string}
	 */
	public static function manually_assign_page( string $role, int $page_id, bool $confirm_custom = false ): array {
		if ( ! in_array( $role, PageInspector::get_supported_roles(), true ) ) {
			return [ 'success' => false, 'message' => __( 'Invalid WooCommerce role specified.', 'wpcalibrate-wc-pages-recovery' ) ];
		}

		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return [ 'success' => false, 'message' => __( 'Specified item is not a valid WordPress page.', 'wpcalibrate-wc-pages-recovery' ) ];
		}

		if ( 'publish' !== $post->post_status ) {
			return [ 'success' => false, 'message' => __( 'Only published pages can be assigned as core WooCommerce pages.', 'wpcalibrate-wc-pages-recovery' ) ];
		}

		if ( ! empty( $post->post_password ) ) {
			return [ 'success' => false, 'message' => __( 'Password-protected pages cannot be assigned as core WooCommerce pages.', 'wpcalibrate-wc-pages-recovery' ) ];
		}

		// Check for conflicts with other roles.
		foreach ( PageInspector::get_supported_roles() as $other_role ) {
			if ( $other_role !== $role ) {
				$assigned_other = absint( get_option( PageInspector::get_role_option_name( $other_role ), 0 ) );
				if ( $assigned_other === $page_id ) {
					return [
						'success' => false,
						'message' => sprintf(
							/* translators: %s: other role name */
							__( 'Conflict: Page #%1$d is already assigned to %2$s.', 'wpcalibrate-wc-pages-recovery' ),
							$page_id,
							PageInspector::get_role_label( $other_role )
						),
					];
				}
			}
		}

		// Check content suitability.
		$analysis = PageInspector::analyze_content( $post, $role );
		if ( ! $analysis['recognized'] && ! $confirm_custom ) {
			return [
				'success' => false,
				'requires_confirmation' => true,
				'message' => __( 'This page does not contain standard WooCommerce blocks or shortcodes. Please confirm if you wish to use this custom layout.', 'wpcalibrate-wc-pages-recovery' ),
			];
		}

		// Proceed with assignment.
		$option_name = PageInspector::get_role_option_name( $role );
		$old_page_id = absint( get_option( $option_name, 0 ) );

		update_option( $option_name, $page_id );
		update_post_meta( $page_id, PageInspector::META_TRACKED_ROLE, $role );

		PageInspector::clear_cache();

		History::add( [
			'trigger'     => 'manual_assignment',
			'role'        => $role,
			'action'      => 'manual_assign',
			'old_page_id' => $old_page_id,
			'new_page_id' => $page_id,
			'status'      => 'success',
			'result_code' => 'manual_assigned',
			'message'     => sprintf( __( 'Manually assigned page "%s" (#%d) to %s.', 'wpcalibrate-wc-pages-recovery' ), $post->post_title, $page_id, PageInspector::get_role_label( $role ) ),
		] );

		return [
			'success' => true,
			'message' => sprintf( __( 'Successfully assigned page "%s" (#%d) to %s.', 'wpcalibrate-wc-pages-recovery' ), $post->post_title, $page_id, PageInspector::get_role_label( $role ) ),
		];
	}

	/**
	 * Log a message to WooCommerce logger if enabled.
	 *
	 * @param string $level Log level ('info', 'warning', 'error', 'debug').
	 * @param string $message Log message.
	 */
	public static function log( string $level, string $message ): void {
		if ( ! Settings::is_logging_enabled() ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log( $level, sanitize_text_field( $message ), [ 'source' => 'wpcalibrate-wc-pages-recovery' ] );
		}
	}
}
