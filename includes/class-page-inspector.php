<?php
/**
 * Page Health and Diagnostic Engine.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class PageInspector
 *
 * Analyzes WooCommerce core pages, performs deep block and shortcode inspection,
 * discovers valid candidates, and establishes structural page health.
 */
class PageInspector {

	public const ROLE_CART      = 'cart';
	public const ROLE_CHECKOUT  = 'checkout';
	public const ROLE_MYACCOUNT = 'myaccount';

	public const META_TRACKED_ROLE = '_wpcalibrate_wcpr_tracked_role';
	public const CACHE_GROUP       = 'wpcalibrate_wcpr_diagnostics';
	public const CACHE_KEY         = 'all_roles_health';
	public const CACHE_TTL         = 60; // 60 seconds.

	/**
	 * Supported roles list.
	 *
	 * @return array<int, string>
	 */
	public static function get_supported_roles(): array {
		return [
			self::ROLE_CART,
			self::ROLE_CHECKOUT,
			self::ROLE_MYACCOUNT,
		];
	}

	/**
	 * Map role to WooCommerce option name.
	 *
	 * @param string $role Core role name.
	 * @return string Option name or empty string if invalid.
	 */
	public static function get_role_option_name( string $role ): string {
		return match ( $role ) {
			self::ROLE_CART      => 'woocommerce_cart_page_id',
			self::ROLE_CHECKOUT  => 'woocommerce_checkout_page_id',
			self::ROLE_MYACCOUNT => 'woocommerce_myaccount_page_id',
			default              => '',
		};
	}

	/**
	 * Map role to human-readable label.
	 *
	 * @param string $role Core role name.
	 * @return string
	 */
	public static function get_role_label( string $role ): string {
		return match ( $role ) {
			self::ROLE_CART      => __( 'Cart', 'wpcalibrate-wc-pages-recovery' ),
			self::ROLE_CHECKOUT  => __( 'Checkout', 'wpcalibrate-wc-pages-recovery' ),
			self::ROLE_MYACCOUNT => __( 'My Account', 'wpcalibrate-wc-pages-recovery' ),
			default              => ucfirst( $role ),
		};
	}

	/**
	 * Inspect all core roles and return their diagnostic states.
	 *
	 * @param bool $force_refresh Whether to bypass transient cache.
	 * @return array<string, array<string, mixed>>
	 */
	public static function inspect_all_roles( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// Retrieve all current option values to identify duplicate assignments.
		$assignments = [];
		foreach ( self::get_supported_roles() as $role ) {
			$option_name         = self::get_role_option_name( $role );
			$assignments[ $role ] = absint( get_option( $option_name, 0 ) );
		}

		$results = [];
		foreach ( self::get_supported_roles() as $role ) {
			$results[ $role ] = self::inspect_role( $role, $assignments );
		}

		set_transient( self::CACHE_KEY, $results, self::CACHE_TTL );
		return $results;
	}

	/**
	 * Invalidate diagnostics cache.
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Inspect a specific role given the current assignment map.
	 *
	 * @param string               $role Role to inspect.
	 * @param array<string, int>   $assignments Map of role => assigned_page_id.
	 * @return array<string, mixed> Diagnostic details.
	 */
	public static function inspect_role( string $role, array $assignments ): array {
		$page_id = $assignments[ $role ] ?? 0;

		$diagnostic = [
			'role'             => $role,
			'role_label'       => self::get_role_label( $role ),
			'option_name'      => self::get_role_option_name( $role ),
			'page_id'          => $page_id,
			'page_title'       => '',
			'edit_url'         => '',
			'view_url'         => '',
			'post_status'      => '',
			'is_healthy'       => false,
			'status_code'      => 'unassigned',
			'rendering_format' => 'none',
			'message'          => '',
			'has_custom_warn'  => false,
		];

		// Check if unassigned.
		if ( $page_id <= 0 ) {
			$diagnostic['status_code'] = 'unassigned';
			$diagnostic['message']     = __( 'Page is not assigned in WooCommerce settings.', 'wpcalibrate-wc-pages-recovery' );
			return $diagnostic;
		}

		// Check if post exists.
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post ) {
			$diagnostic['status_code'] = 'deleted';
			$diagnostic['message']     = sprintf(
				/* translators: %d: page ID */
				__( 'Assigned page ID #%d no longer exists.', 'wpcalibrate-wc-pages-recovery' ),
				$page_id
			);
			return $diagnostic;
		}

		$diagnostic['page_title']  = ! empty( $post->post_title ) ? $post->post_title : sprintf( __( '(Page #%d)', 'wpcalibrate-wc-pages-recovery' ), $page_id );
		$diagnostic['post_status'] = $post->post_status;
		$diagnostic['edit_url']    = get_edit_post_link( $page_id ) ?: '';
		$diagnostic['view_url']    = get_permalink( $page_id ) ?: '';

		// Check post type.
		if ( 'page' !== $post->post_type ) {
			$diagnostic['status_code'] = 'wrong_post_type';
			$diagnostic['message']     = sprintf(
				/* translators: %s: post type name */
				__( 'Assigned post has invalid post type "%s"; must be a standard page.', 'wpcalibrate-wc-pages-recovery' ),
				$post->post_type
			);
			return $diagnostic;
		}

		// Check if trashed.
		if ( 'trash' === $post->post_status ) {
			$diagnostic['status_code'] = 'trashed';
			$diagnostic['message']     = __( 'Assigned page is currently in the trash.', 'wpcalibrate-wc-pages-recovery' );
			return $diagnostic;
		}

		// Check if unpublished.
		if ( 'publish' !== $post->post_status ) {
			$diagnostic['status_code'] = 'unpublished';
			$diagnostic['message']     = sprintf(
				/* translators: %s: status name */
				__( 'Assigned page is not published (current status: %s). Requires administrator attention.', 'wpcalibrate-wc-pages-recovery' ),
				$post->post_status
			);
			return $diagnostic;
		}

		// Check if password protected.
		if ( ! empty( $post->post_password ) ) {
			$diagnostic['status_code'] = 'password_protected';
			$diagnostic['message']     = __( 'Assigned page is password-protected. Core WooCommerce pages must be publicly accessible.', 'wpcalibrate-wc-pages-recovery' );
			return $diagnostic;
		}

		// Check for duplicate role assignment.
		$duplicate_roles = [];
		foreach ( $assignments as $other_role => $other_id ) {
			if ( $other_role !== $role && $other_id === $page_id ) {
				$duplicate_roles[] = self::get_role_label( $other_role );
			}
		}

		if ( ! empty( $duplicate_roles ) ) {
			$diagnostic['status_code'] = 'duplicate_assignment';
			$diagnostic['message']     = sprintf(
				/* translators: %s: list of conflicting roles */
				__( 'This page is simultaneously assigned to %s. A single page cannot serve multiple roles.', 'wpcalibrate-wc-pages-recovery' ),
				implode( ', ', $duplicate_roles )
			);
			return $diagnostic;
		}

		// Inspect page content for recognized blocks and shortcodes.
		$content_analysis = self::analyze_content( $post, $role );
		$diagnostic['rendering_format'] = $content_analysis['format'];

		if ( $content_analysis['recognized'] ) {
			$diagnostic['is_healthy']  = true;
			$diagnostic['status_code'] = 'healthy';
			$diagnostic['message']     = sprintf(
				/* translators: %s: format description */
				__( 'Healthy. Recognized %s rendering is active.', 'wpcalibrate-wc-pages-recovery' ),
				$content_analysis['label']
			);
			return $diagnostic;
		}

		// Published, unprotected page with unrecognized/custom content:
		// Preserve valid assignment and warn without breaking custom builders.
		$diagnostic['is_healthy']       = true;
		$diagnostic['has_custom_warn']  = true;
		$diagnostic['status_code']      = 'healthy_custom';
		$diagnostic['rendering_format'] = 'custom';
		$diagnostic['message']          = __( 'Healthy with custom content. Standard WooCommerce blocks/shortcodes were not detected; existing builder or custom template layout is preserved.', 'wpcalibrate-wc-pages-recovery' );

		return $diagnostic;
	}

	/**
	 * Analyze page content for role-specific blocks or shortcodes.
	 *
	 * @param \WP_Post $post Target post.
	 * @param string   $role Target role.
	 * @return array{recognized: bool, format: string, label: string}
	 */
	public static function analyze_content( \WP_Post $post, string $role ): array {
		$content = $post->post_content;

		// 1. Direct shortcode detection.
		$shortcode = match ( $role ) {
			self::ROLE_CART      => 'woocommerce_cart',
			self::ROLE_CHECKOUT  => 'woocommerce_checkout',
			self::ROLE_MYACCOUNT => 'woocommerce_my_account',
			default              => '',
		};

		if ( ! empty( $shortcode ) && function_exists( 'has_shortcode' ) && has_shortcode( $content, $shortcode ) ) {
			return [
				'recognized' => true,
				'format'     => 'shortcode',
				'label'      => sprintf( '[%s]', $shortcode ),
			];
		}

		// 2. Direct block or nested/synced block detection.
		$visited_refs = [ $post->ID => true ];
		$block_match  = self::inspect_blocks_for_role( $content, $role, 0, $visited_refs );

		if ( $block_match['found'] ) {
			return [
				'recognized' => true,
				'format'     => 'block',
				'label'      => $block_match['block_name'],
			];
		}

		// 3. Fallback string check for raw shortcodes without wp shortcode parser.
		if ( ! empty( $shortcode ) && false !== stripos( $content, '[' . $shortcode ) ) {
			return [
				'recognized' => true,
				'format'     => 'shortcode',
				'label'      => sprintf( '[%s]', $shortcode ),
			];
		}

		return [
			'recognized' => false,
			'format'     => 'none',
			'label'      => __( 'None', 'wpcalibrate-wc-pages-recovery' ),
		];
	}

	/**
	 * Recursively inspect parsed blocks for role suitability with cycle & recursion protection.
	 *
	 * @param string             $raw_content Raw content containing block markup.
	 * @param string             $role Target role.
	 * @param int                $depth Current recursion depth.
	 * @param array<int, bool>   $visited_refs Visited reusable/synced block IDs to prevent cycles.
	 * @return array{found: bool, block_name: string}
	 */
	private static function inspect_blocks_for_role( string $raw_content, string $role, int $depth, array &$visited_refs ): array {
		if ( $depth > 5 || empty( $raw_content ) ) {
			return [ 'found' => false, 'block_name' => '' ];
		}

		$target_blocks = match ( $role ) {
			self::ROLE_CART      => [ 'woocommerce/cart', 'woocommerce/cart-order-summary-block' ],
			self::ROLE_CHECKOUT  => [ 'woocommerce/checkout', 'woocommerce/checkout-order-summary-block' ],
			self::ROLE_MYACCOUNT => [ 'woocommerce/customer-account', 'woocommerce/my-account' ],
			default              => [],
		};

		// Check raw string signature first for rapid matching.
		foreach ( $target_blocks as $tb ) {
			if ( false !== strpos( $raw_content, '<!-- wp:' . $tb ) ) {
				return [ 'found' => true, 'block_name' => $tb ];
			}
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return [ 'found' => false, 'block_name' => '' ];
		}

		$blocks = parse_blocks( $raw_content );
		return self::scan_block_nodes( $blocks, $role, $target_blocks, $depth, $visited_refs );
	}

	/**
	 * Scan an array of parsed block structures.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $role Target role.
	 * @param array<int, string>               $target_blocks Allowed block names.
	 * @param int                              $depth Recursion depth.
	 * @param array<int, bool>                 $visited_refs Visited block IDs.
	 * @return array{found: bool, block_name: string}
	 */
	private static function scan_block_nodes( array $blocks, string $role, array $target_blocks, int $depth, array &$visited_refs ): array {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			if ( in_array( $block_name, $target_blocks, true ) ) {
				return [ 'found' => true, 'block_name' => (string) $block_name ];
			}

			// Check reusable / synced block (core/block).
			if ( 'core/block' === $block_name && ! empty( $block['attrs']['ref'] ) ) {
				$ref_id = absint( $block['attrs']['ref'] );
				if ( ! empty( $ref_id ) && empty( $visited_refs[ $ref_id ] ) ) {
					$visited_refs[ $ref_id ] = true;
					$ref_post = get_post( $ref_id );
					if ( $ref_post instanceof \WP_Post && 'wp_block' === $ref_post->post_type ) {
						$res = self::inspect_blocks_for_role( $ref_post->post_content, $role, $depth + 1, $visited_refs );
						if ( $res['found'] ) {
							return $res;
						}
					}
				}
			}

			// Check inner blocks.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner_res = self::scan_block_nodes( $block['innerBlocks'], $role, $target_blocks, $depth + 1, $visited_refs );
				if ( $inner_res['found'] ) {
					return $inner_res;
				}
			}
		}

		return [ 'found' => false, 'block_name' => '' ];
	}

	/**
	 * Find candidate published pages for a missing role.
	 *
	 * Returns:
	 * - 'tracked': Exactly one previously tracked page if suitable and unambiguous.
	 * - 'matching': Array of published pages containing role-specific content.
	 *
	 * @param string             $role Core role to find candidates for.
	 * @param array<int, int>    $exclude_page_ids Page IDs currently assigned or in use.
	 * @return array{tracked: ?\WP_Post, matching: array<int, \WP_Post>}
	 */
	public static function find_candidates( string $role, array $exclude_page_ids = [] ): array {
		$tracked_match = null;
		$matching      = [];

		// Query published pages with bounding limit to maintain performance.
		$args = [
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => 50,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'post__not_in'           => array_filter( array_map( 'absint', $exclude_page_ids ) ),
			'suppress_filters'       => false,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		];

		$pages = get_posts( $args );
		if ( ! is_array( $pages ) ) {
			return [ 'tracked' => null, 'matching' => [] ];
		}

		foreach ( $pages as $page ) {
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}

			// Exclude password-protected pages.
			if ( ! empty( $page->post_password ) ) {
				continue;
			}

			// Check tracked role meta.
			$tracked_role = get_post_meta( $page->ID, self::META_TRACKED_ROLE, true );
			if ( $tracked_role === $role ) {
				if ( null === $tracked_match ) {
					$tracked_match = $page;
				}
			}

			// Inspect content for role recognition.
			$analysis = self::analyze_content( $page, $role );
			if ( $analysis['recognized'] ) {
				$matching[] = $page;
			}
		}

		return [
			'tracked'  => $tracked_match,
			'matching' => $matching,
		];
	}
}
