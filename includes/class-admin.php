<?php
/**
 * Admin Dashboard, Menus, UI Screens, and Action Handlers.
 *
 * @package WPCalibrate\WooPagesRecovery
 */

declare(strict_types=1);

namespace WPCalibrate\WooPagesRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 *
 * Manages WordPress admin menu integration under shared WPCalibrate parent,
 * screen-specific asset enqueuing, POST mutation handlers, and accessible UI rendering.
 */
class Admin {

	public const PARENT_SLUG = 'wpcalibrate';
	public const PAGE_SLUG   = 'wpcalibrate-wc-pages-recovery';

	/**
	 * Register hooks for admin interface.
	 */
	public static function init(): void {
		// Register late to coordinate shared WPCalibrate parent menu with other plugins.
		add_action( 'admin_menu', [ self::class, 'register_menu' ], 99 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ self::class, 'render_admin_notices' ] );
		add_action( 'admin_head', [ self::class, 'render_admin_menu_styles' ] );

		// POST action endpoints (following PRG pattern with strict nonce & capability checks).
		add_action( 'admin_post_wpcalibrate_wcpr_check_now', [ self::class, 'handle_check_now' ] );
		add_action( 'admin_post_wpcalibrate_wcpr_repair_all', [ self::class, 'handle_repair_all' ] );
		add_action( 'admin_post_wpcalibrate_wcpr_repair_single', [ self::class, 'handle_repair_single' ] );
		add_action( 'admin_post_wpcalibrate_wcpr_manual_assign', [ self::class, 'handle_manual_assign' ] );
		add_action( 'admin_post_wpcalibrate_wcpr_save_settings', [ self::class, 'handle_save_settings' ] );
		add_action( 'admin_post_wpcalibrate_wcpr_clear_history', [ self::class, 'handle_clear_history' ] );
		add_action( 'admin_post_wpcalibrate_wcpr_dismiss_notice', [ self::class, 'handle_dismiss_notice' ] );
	}

	/**
	 * Register shared top-level WPCalibrate menu and dedicated submenu.
	 */
	public static function register_menu(): void {
		global $admin_page_hooks;

		// Check if another WPCalibrate plugin already registered the top-level parent.
		if ( ! isset( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
			add_menu_page(
				'WPCalibrate',
				'WPCalibrate',
				'manage_options',
				self::PARENT_SLUG,
				[ self::class, 'render_shared_parent_screen' ],
				WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-white.png',
				59
			);
		}

		// Register unique submenu for Core Pages Auto-Recovery.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'WooCommerce Core Pages Auto-Recovery', 'wpcalibrate-wc-pages-recovery' ),
			__( 'Core Pages Recovery', 'wpcalibrate-wc-pages-recovery' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_dashboard' ]
		);
	}

	/**
	 * Shared parent screen fallback if clicked directly.
	 */
	public static function render_shared_parent_screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'wpcalibrate-wc-pages-recovery' ) );
		}
		// Redirect gracefully to this plugin's settings screen.
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Enqueue assets only on this plugin's admin screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// Only enqueue on our specific submenu screen.
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'wpcalibrate-wcpr-admin',
			WPCALIBRATE_WCPR_PLUGIN_URL . 'assets/css/admin.css',
			[],
			WPCALIBRATE_WCPR_VERSION
		);

		wp_enqueue_script(
			'wpcalibrate-wcpr-admin',
			WPCALIBRATE_WCPR_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WPCALIBRATE_WCPR_VERSION,
			true
		);

		wp_localize_script(
			'wpcalibrate-wcpr-admin',
			'wpcalibrateWcprData',
			[
				'confirmRepairAll'    => __( 'Are you sure you want to run automatic repair on missing core pages?', 'wpcalibrate-wc-pages-recovery' ),
				'confirmClearHistory' => __( 'Are you sure you want to clear the entire recovery history log?', 'wpcalibrate-wc-pages-recovery' ),
			]
		);
	}

	/**
	 * Output admin-wide styles for the WPCalibrate sidebar menu icon.
	 *
	 * Ensures the menu icon is strictly constrained to 20x20px across all admin screens
	 * (e.g. plugins.php, dashboard, etc.) matching native WordPress dashicons and other plugins.
	 */
	public static function render_admin_menu_styles(): void {
		$icon_dark_url = esc_url( WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png' );
		?>
		<style id="wpcalibrate-wcpr-menu-icon-style">
			#adminmenu #toplevel_page_wpcalibrate .wp-menu-image img,
			#adminmenu a.toplevel_page_wpcalibrate .wp-menu-image img,
			#adminmenu a[href*="page=wpcalibrate"] .wp-menu-image img {
				width: 20px !important;
				height: 20px !important;
				max-width: 20px !important;
				max-height: 20px !important;
				padding-top: 7px !important;
				object-fit: contain !important;
				box-sizing: content-box !important;
				opacity: 0.85;
				transition: opacity 0.15s ease-in-out;
			}
			#adminmenu #toplevel_page_wpcalibrate:hover .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate.wp-has-current-submenu .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate.current .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate.wp-menu-open .wp-menu-image img {
				opacity: 1 !important;
			}
			/* Light admin color scheme support: switch to icon-dark on light background */
			body.admin-color-light #adminmenu #toplevel_page_wpcalibrate .wp-menu-image img,
			body.admin-color-light #adminmenu a.toplevel_page_wpcalibrate .wp-menu-image img,
			body.admin-color-light #adminmenu a[href*="page=wpcalibrate"] .wp-menu-image img {
				content: url('<?php echo $icon_dark_url; ?>') !important;
			}
		</style>
		<?php
	}

	/**
	 * Persistent and dismissible admin notices.
	 */
	public static function render_admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check WooCommerce dependency.
		if ( ! Lifecycle::is_woocommerce_ready() ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'WooCommerce Core Pages Auto-Recovery:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
					<?php esc_html_e( 'WooCommerce is not active or not installed. Automatic recovery is currently paused.', 'wpcalibrate-wc-pages-recovery' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Don't show persistent alert on our own settings screen to avoid clutter.
		$current_screen = get_current_screen();
		if ( $current_screen && str_contains( $current_screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$diagnostics = PageInspector::inspect_all_roles();
		$unresolved  = [];

		foreach ( $diagnostics as $role => $diag ) {
			if ( ! $diag['is_healthy'] ) {
				$unresolved[ $role ] = $diag['status_code'];
			}
		}

		if ( empty( $unresolved ) ) {
			return;
		}

		// State-based hash to avoid repeatedly showing dismissed notice for the exact same state.
		$state_hash     = md5( (string) wp_json_encode( $unresolved ) );
		$dismissed_hash = get_option( 'wpcalibrate_wcpr_dismissed_hash', '' );

		if ( $state_hash === $dismissed_hash ) {
			return;
		}

		$dashboard_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$dismiss_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpcalibrate_wcpr_dismiss_notice&hash=' . $state_hash ),
			'wpcalibrate_wcpr_dismiss_notice_nonce'
		);
		?>
		<div class="notice notice-error is-dismissible" style="border-left-color: #d63638;">
			<p>
				<strong><?php esc_html_e( 'WooCommerce Core Pages Issue Detected:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
				<?php
				printf(
					/* translators: 1: count of broken pages, 2: link to dashboard */
					esc_html__( '%1$d core store page(s) require attention. Please review and restore them to maintain normal checkout and cart operations.', 'wpcalibrate-wc-pages-recovery' ),
					count( $unresolved )
				);
				?>
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-secondary" style="margin-left: 10px;">
					<?php esc_html_e( 'Review & Repair', 'wpcalibrate-wc-pages-recovery' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the full settings and dashboard interface.
	 */
	public static function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		$valid_tabs   = [ 'overview', 'settings', 'history', 'support' ];
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'overview';
		}

		$diagnostics      = PageInspector::inspect_all_roles();
		$last_check_info  = History::get_last_check();
		$next_sched_time  = Scheduler::get_next_scheduled_time();
		$wc_ready         = Lifecycle::is_woocommerce_ready();
		$settings         = Settings::get_all();
		$history_entries  = History::get_all();
		?>
		<div class="wrap wpcalibrate-wcpr-wrap">
			<header class="wpcalibrate-wcpr-header">
				<div class="wpcalibrate-wcpr-header-title">
					<span class="wpcalibrate-wcpr-branding-badge">
						<img src="<?php echo esc_url( WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-dark.png' ); ?>" alt="WPCalibrate" class="wpcalibrate-wcpr-branding-icon wpcalibrate-wcpr-branding-icon--dark" width="32" height="32" />
						<img src="<?php echo esc_url( WPCALIBRATE_WCPR_PLUGIN_URL . 'branding/icon-white.png' ); ?>" alt="WPCalibrate" class="wpcalibrate-wcpr-branding-icon wpcalibrate-wcpr-branding-icon--white" width="32" height="32" />
					</span>
					<h1><?php esc_html_e( 'WooCommerce Core Pages Auto-Recovery', 'wpcalibrate-wc-pages-recovery' ); ?></h1>
					<span class="wpcalibrate-wcpr-badge"><?php echo esc_html( 'v' . WPCALIBRATE_WCPR_VERSION ); ?></span>
				</div>
				<p class="wpcalibrate-wcpr-subtitle">
					<?php esc_html_e( 'Safely monitor, protect, and automatically restore WooCommerce Cart, Checkout, and My Account pages.', 'wpcalibrate-wc-pages-recovery' ); ?>
				</p>
			</header>

			<?php self::render_query_feedback_notices(); ?>

			<nav class="nav-tab-wrapper wpcalibrate-wcpr-nav-tabs" aria-label="<?php esc_attr_e( 'Plugin Sections', 'wpcalibrate-wc-pages-recovery' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Overview', 'wpcalibrate-wc-pages-recovery' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Recovery Settings', 'wpcalibrate-wc-pages-recovery' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=history' ) ); ?>" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Recovery History', 'wpcalibrate-wc-pages-recovery' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=support' ) ); ?>" class="nav-tab <?php echo 'support' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-sos"></span> <?php esc_html_e( 'WPCalibrate Support', 'wpcalibrate-wc-pages-recovery' ); ?>
				</a>
			</nav>

			<div class="wpcalibrate-wcpr-tab-content">
				<?php
				match ( $active_tab ) {
					'overview' => self::render_tab_overview( $diagnostics, $last_check_info, $next_sched_time, $wc_ready ),
					'settings' => self::render_tab_settings( $settings ),
					'history'  => self::render_tab_history( $history_entries ),
					'support'  => self::render_tab_support(),
					default    => null,
				};
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render query feedback messages after PRG redirects.
	 */
	private static function render_query_feedback_notices(): void {
		if ( ! isset( $_GET['wcpr_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( $_GET['wcpr_notice'] );
		$msg    = isset( $_GET['wcpr_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['wcpr_msg'] ) ) : '';

		switch ( $notice ) {
			case 'checked':
				?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'Diagnostic scan completed. Fresh page states retrieved.', 'wpcalibrate-wc-pages-recovery' ); ?></p>
				</div>
				<?php
				break;
			case 'repaired':
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( ! empty( $msg ) ? $msg : __( 'Recovery operation completed successfully.', 'wpcalibrate-wc-pages-recovery' ) ); ?></p>
				</div>
				<?php
				break;
			case 'assigned':
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( ! empty( $msg ) ? $msg : __( 'Page assigned successfully.', 'wpcalibrate-wc-pages-recovery' ) ); ?></p>
				</div>
				<?php
				break;
			case 'settings_saved':
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'wpcalibrate-wc-pages-recovery' ); ?></p>
				</div>
				<?php
				break;
			case 'history_cleared':
				?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'Operational recovery history cleared.', 'wpcalibrate-wc-pages-recovery' ); ?></p>
				</div>
				<?php
				break;
			case 'error':
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( ! empty( $msg ) ? $msg : __( 'An error occurred while processing the request.', 'wpcalibrate-wc-pages-recovery' ) ); ?></p>
				</div>
				<?php
				break;
			case 'locked':
				?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'Another recovery task is currently active. Please wait a moment and try again.', 'wpcalibrate-wc-pages-recovery' ); ?></p>
				</div>
				<?php
				break;
		}
	}

	/**
	 * Tab: Overview.
	 *
	 * @param array<string, array<string, mixed>> $diagnostics
	 * @param array<string, mixed>|null           $last_check
	 * @param int|null                            $next_sched
	 * @param bool                                $wc_ready
	 */
	private static function render_tab_overview( array $diagnostics, ?array $last_check, ?int $next_sched, bool $wc_ready ): void {
		$all_healthy = true;
		foreach ( $diagnostics as $diag ) {
			if ( ! $diag['is_healthy'] ) {
				$all_healthy = false;
				break;
			}
		}
		?>
		<div class="wpcalibrate-wcpr-status-grid">
			<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-card-metric">
				<h3><?php esc_html_e( 'Store Pages Status', 'wpcalibrate-wc-pages-recovery' ); ?></h3>
				<div class="wpcalibrate-wcpr-metric-value <?php echo $all_healthy ? 'is-healthy' : 'is-unhealthy'; ?>">
					<span class="dashicons <?php echo $all_healthy ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
					<?php echo $all_healthy ? esc_html__( 'All Pages Healthy', 'wpcalibrate-wc-pages-recovery' ) : esc_html__( 'Attention Required', 'wpcalibrate-wc-pages-recovery' ); ?>
				</div>
				<p class="description">
					<?php esc_html_e( 'Structural health verification of Cart, Checkout, and My Account pages.', 'wpcalibrate-wc-pages-recovery' ); ?>
				</p>
			</div>

			<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-card-metric">
				<h3><?php esc_html_e( 'Last Completed Check', 'wpcalibrate-wc-pages-recovery' ); ?></h3>
				<div class="wpcalibrate-wcpr-metric-value">
					<span class="dashicons dashicons-clock"></span>
					<?php
					if ( ! empty( $last_check['timestamp'] ) ) {
						echo esc_html( wp_date( 'M j, Y H:i:s', (int) $last_check['timestamp'] ) );
					} else {
						esc_html_e( 'No check recorded yet', 'wpcalibrate-wc-pages-recovery' );
					}
					?>
				</div>
				<p class="description">
					<?php esc_html_e( 'Time of last comprehensive page and assignment scan.', 'wpcalibrate-wc-pages-recovery' ); ?>
				</p>
			</div>

			<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-card-metric">
				<h3><?php esc_html_e( 'Next Scheduled Check', 'wpcalibrate-wc-pages-recovery' ); ?></h3>
				<div class="wpcalibrate-wcpr-metric-value">
					<span class="dashicons dashicons-calendar-alt"></span>
					<?php
					if ( ! empty( $next_sched ) ) {
						echo esc_html( wp_date( 'M j, Y H:i:s', $next_sched ) );
					} else {
						esc_html_e( 'Reconciliation pending', 'wpcalibrate-wc-pages-recovery' );
					}
					?>
				</div>
				<p class="description">
					<?php
					if ( Scheduler::has_action_scheduler() ) {
						esc_html_e( 'Managed by Action Scheduler (hourly reconciliation).', 'wpcalibrate-wc-pages-recovery' );
					} else {
						esc_html_e( 'Managed by WP-Cron fallback. Depends on site traffic or server cron.', 'wpcalibrate-wc-pages-recovery' );
					}
					?>
				</p>
			</div>

			<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-card-metric">
				<h3><?php esc_html_e( 'WooCommerce Dependency', 'wpcalibrate-wc-pages-recovery' ); ?></h3>
				<div class="wpcalibrate-wcpr-metric-value <?php echo $wc_ready ? 'is-healthy' : 'is-unhealthy'; ?>">
					<span class="dashicons <?php echo $wc_ready ? 'dashicons-yes' : 'dashicons-no-alt'; ?>"></span>
					<?php echo $wc_ready ? esc_html__( 'Active & Supported', 'wpcalibrate-wc-pages-recovery' ) : esc_html__( 'Inactive / Missing', 'wpcalibrate-wc-pages-recovery' ); ?>
				</div>
				<p class="description">
					<?php
					if ( defined( 'WC_VERSION' ) ) {
						/* translators: %s: WooCommerce version */
						printf( esc_html__( 'Detected WooCommerce version %s.', 'wpcalibrate-wc-pages-recovery' ), esc_html( WC_VERSION ) );
					} else {
						esc_html_e( 'WooCommerce core plugin must be active for recovery.', 'wpcalibrate-wc-pages-recovery' );
					}
					?>
				</p>
			</div>
		</div>

		<!-- Action Bar -->
		<div class="wpcalibrate-wcpr-action-bar">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-wcpr-inline-form">
				<input type="hidden" name="action" value="wpcalibrate_wcpr_check_now" />
				<?php wp_nonce_field( 'wpcalibrate_wcpr_check_now_action', 'wpcalibrate_wcpr_nonce' ); ?>
				<button type="submit" class="button button-secondary">
					<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Check Now (Read-Only)', 'wpcalibrate-wc-pages-recovery' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-wcpr-inline-form wpcalibrate-wcpr-repair-all-form">
				<input type="hidden" name="action" value="wpcalibrate_wcpr_repair_all" />
				<?php wp_nonce_field( 'wpcalibrate_wcpr_repair_all_action', 'wpcalibrate_wcpr_nonce' ); ?>
				<button type="submit" class="button button-primary" <?php echo ! $wc_ready ? 'disabled' : ''; ?>>
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Check and Repair Missing/Broken Pages', 'wpcalibrate-wc-pages-recovery' ); ?>
				</button>
			</form>
		</div>

		<!-- Diagnostics Table -->
		<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-table-card">
			<h2><?php esc_html_e( 'Core Pages Diagnostics', 'wpcalibrate-wc-pages-recovery' ); ?></h2>
			<table class="wp-list-table widefat fixed striped wpcalibrate-wcpr-table">
				<thead>
					<tr>
						<th scope="col" style="width: 140px;"><?php esc_html_e( 'Core Role', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Assigned Page', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 160px;"><?php esc_html_e( 'Diagnostic Status', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 130px;"><?php esc_html_e( 'Format', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Health & Policy Notes', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 220px;"><?php esc_html_e( 'Actions', 'wpcalibrate-wc-pages-recovery' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $diagnostics as $role => $diag ) : ?>
						<tr>
							<td class="wpcalibrate-wcpr-col-role">
								<strong><?php echo esc_html( $diag['role_label'] ); ?></strong>
								<code class="wpcalibrate-wcpr-code"><?php echo esc_html( $diag['option_name'] ); ?></code>
							</td>
							<td>
								<?php if ( $diag['page_id'] > 0 && ! empty( $diag['page_title'] ) ) : ?>
									<strong><?php echo esc_html( $diag['page_title'] ); ?></strong>
									<span class="wpcalibrate-wcpr-page-id">(#<?php echo esc_html( (string) $diag['page_id'] ); ?>)</span>
									<div class="row-actions">
										<?php if ( ! empty( $diag['edit_url'] ) ) : ?>
											<span><a href="<?php echo esc_url( $diag['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit Page', 'wpcalibrate-wc-pages-recovery' ); ?></a> | </span>
										<?php endif; ?>
										<?php if ( ! empty( $diag['view_url'] ) ) : ?>
											<span><a href="<?php echo esc_url( $diag['view_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Page', 'wpcalibrate-wc-pages-recovery' ); ?></a></span>
										<?php endif; ?>
									</div>
								<?php else : ?>
									<em class="wpcalibrate-wcpr-text-muted"><?php esc_html_e( 'No page assigned', 'wpcalibrate-wc-pages-recovery' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php self::render_status_badge( $diag['status_code'], $diag['is_healthy'], $diag['has_custom_warn'] ); ?>
							</td>
							<td>
								<span class="wpcalibrate-wcpr-format-badge format-<?php echo esc_attr( $diag['rendering_format'] ); ?>">
									<?php echo esc_html( ucfirst( $diag['rendering_format'] ) ); ?>
								</span>
							</td>
							<td>
								<p class="wpcalibrate-wcpr-diag-message"><?php echo esc_html( $diag['message'] ); ?></p>
							</td>
							<td class="wpcalibrate-wcpr-col-actions">
								<?php if ( ! $diag['is_healthy'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-wcpr-inline-form">
										<input type="hidden" name="action" value="wpcalibrate_wcpr_repair_single" />
										<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>" />
										<?php wp_nonce_field( 'wpcalibrate_wcpr_repair_single_action', 'wpcalibrate_wcpr_nonce' ); ?>
										<button type="submit" class="button button-small button-primary">
											<?php esc_html_e( 'Repair', 'wpcalibrate-wc-pages-recovery' ); ?>
										</button>
									</form>
								<?php endif; ?>

								<button type="button" class="button button-small button-secondary wpcalibrate-wcpr-assign-toggle" data-role="<?php echo esc_attr( $role ); ?>">
									<?php esc_html_e( 'Assign...', 'wpcalibrate-wc-pages-recovery' ); ?>
								</button>
							</td>
						</tr>

						<!-- Manual Assignment Form Row -->
						<tr class="wpcalibrate-wcpr-assign-row" id="wpcalibrate-wcpr-assign-row-<?php echo esc_attr( $role ); ?>" style="display: none;">
							<td colspan="6" class="wpcalibrate-wcpr-assign-cell">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-wcpr-assign-form">
									<input type="hidden" name="action" value="wpcalibrate_wcpr_manual_assign" />
									<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>" />
									<?php wp_nonce_field( 'wpcalibrate_wcpr_manual_assign_action', 'wpcalibrate_wcpr_nonce' ); ?>

									<fieldset class="wpcalibrate-wcpr-fieldset">
										<legend class="screen-reader-text"><?php printf( esc_html__( 'Manually assign page for %s', 'wpcalibrate-wc-pages-recovery' ), esc_html( $diag['role_label'] ) ); ?></legend>
										<label for="wpcalibrate-wcpr-select-<?php echo esc_attr( $role ); ?>">
											<strong><?php printf( esc_html__( 'Select published page to assign to %s:', 'wpcalibrate-wc-pages-recovery' ), esc_html( $diag['role_label'] ) ); ?></strong>
										</label>

										<select name="page_id" id="wpcalibrate-wcpr-select-<?php echo esc_attr( $role ); ?>" required>
											<option value=""><?php esc_html_e( '-- Choose a published page --', 'wpcalibrate-wc-pages-recovery' ); ?></option>
											<?php
											$pages = get_posts( [
												'post_type'      => 'page',
												'post_status'    => 'publish',
												'posts_per_page' => 100,
												'orderby'        => 'title',
												'order'          => 'ASC',
											] );
											foreach ( $pages as $p ) {
												$selected = ( $p->ID === (int) $diag['page_id'] ) ? 'selected' : '';
												printf(
													'<option value="%d" %s>%s (#%d)</option>',
													esc_attr( (string) $p->ID ),
													esc_attr( $selected ),
													esc_html( $p->post_title ?: __( '(Untitled)', 'wpcalibrate-wc-pages-recovery' ) ),
													esc_html( (string) $p->ID )
												);
											}
											?>
										</select>

										<label class="wpcalibrate-wcpr-checkbox-inline">
											<input type="checkbox" name="confirm_custom" value="1" />
											<?php esc_html_e( 'Explicitly confirm custom layout if WooCommerce blocks/shortcodes are absent.', 'wpcalibrate-wc-pages-recovery' ); ?>
										</label>

										<button type="submit" class="button button-primary button-small">
											<?php esc_html_e( 'Save Assignment', 'wpcalibrate-wc-pages-recovery' ); ?>
										</button>
										<button type="button" class="button button-secondary button-small wpcalibrate-wcpr-assign-cancel" data-role="<?php echo esc_attr( $role ); ?>">
											<?php esc_html_e( 'Cancel', 'wpcalibrate-wc-pages-recovery' ); ?>
										</button>
									</fieldset>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render status badges with accessible text and icons.
	 *
	 * @param string $status_code
	 * @param bool   $is_healthy
	 * @param bool   $has_custom_warn
	 */
	private static function render_status_badge( string $status_code, bool $is_healthy, bool $has_custom_warn ): void {
		if ( $is_healthy && ! $has_custom_warn ) {
			echo '<span class="wpcalibrate-wcpr-badge badge-healthy"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Healthy', 'wpcalibrate-wc-pages-recovery' ) . '</span>';
		} elseif ( $is_healthy && $has_custom_warn ) {
			echo '<span class="wpcalibrate-wcpr-badge badge-warning"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html__( 'Custom Content', 'wpcalibrate-wc-pages-recovery' ) . '</span>';
		} else {
			$label = match ( $status_code ) {
				'unassigned'          => __( 'Unassigned', 'wpcalibrate-wc-pages-recovery' ),
				'deleted'             => __( 'Deleted Post', 'wpcalibrate-wc-pages-recovery' ),
				'trashed'             => __( 'In Trash', 'wpcalibrate-wc-pages-recovery' ),
				'unpublished'         => __( 'Unpublished', 'wpcalibrate-wc-pages-recovery' ),
				'password_protected'  => __( 'Password Protected', 'wpcalibrate-wc-pages-recovery' ),
				'wrong_post_type'     => __( 'Wrong Post Type', 'wpcalibrate-wc-pages-recovery' ),
				'duplicate_assignment'=> __( 'Duplicate Role', 'wpcalibrate-wc-pages-recovery' ),
				default               => __( 'Issue Detected', 'wpcalibrate-wc-pages-recovery' ),
			};
			echo '<span class="wpcalibrate-wcpr-badge badge-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( $label ) . '</span>';
		}
	}

	/**
	 * Tab: Recovery Settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	private static function render_tab_settings( array $settings ): void {
		?>
		<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-form-card">
			<h2><?php esc_html_e( 'Recovery Configuration', 'wpcalibrate-wc-pages-recovery' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpcalibrate_wcpr_save_settings" />
				<?php wp_nonce_field( 'wpcalibrate_wcpr_save_settings_action', 'wpcalibrate_wcpr_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Global Automatic Recovery', 'wpcalibrate-wc-pages-recovery' ); ?></th>
							<td>
								<label for="wpcalibrate-wcpr-auto-recovery">
									<input type="checkbox" name="auto_recovery_enabled" id="wpcalibrate-wcpr-auto-recovery" value="1" <?php checked( ! empty( $settings['auto_recovery_enabled'] ) ); ?> />
									<?php esc_html_e( 'Enable automatic background detection and recovery for WooCommerce core pages.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, the plugin automatically restores or recreates missing core pages when pages are trashed, deleted, or unassigned. If you intend to permanently delete or restructure store pages, disable this setting first.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Protected Core Roles', 'wpcalibrate-wc-pages-recovery' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Protected Core Roles', 'wpcalibrate-wc-pages-recovery' ); ?></legend>
									<label style="display: block; margin-bottom: 6px;">
										<input type="checkbox" name="protect_cart" value="1" <?php checked( ! empty( $settings['protect_cart'] ) ); ?> />
										<?php esc_html_e( 'Protect Cart page (woocommerce_cart_page_id)', 'wpcalibrate-wc-pages-recovery' ); ?>
									</label>
									<label style="display: block; margin-bottom: 6px;">
										<input type="checkbox" name="protect_checkout" value="1" <?php checked( ! empty( $settings['protect_checkout'] ) ); ?> />
										<?php esc_html_e( 'Protect Checkout page (woocommerce_checkout_page_id)', 'wpcalibrate-wc-pages-recovery' ); ?>
									</label>
									<label style="display: block; margin-bottom: 6px;">
										<input type="checkbox" name="protect_myaccount" value="1" <?php checked( ! empty( $settings['protect_myaccount'] ) ); ?> />
										<?php esc_html_e( 'Protect My Account page (woocommerce_myaccount_page_id)', 'wpcalibrate-wc-pages-recovery' ); ?>
									</label>
								</fieldset>
								<p class="description">
									<?php esc_html_e( 'Select which core roles are monitored and automatically recovered. Manual recovery controls remain available regardless of these toggles.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'New Page Creation Format', 'wpcalibrate-wc-pages-recovery' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'New Page Creation Format', 'wpcalibrate-wc-pages-recovery' ); ?></legend>
									<label style="display: block; margin-bottom: 8px;">
										<input type="radio" name="new_page_format" value="blocks" <?php checked( 'blocks' === ( $settings['new_page_format'] ?? 'blocks' ) ); ?> />
										<strong><?php esc_html_e( 'WooCommerce Core Blocks (Recommended)', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
										<span class="description" style="display: block; margin-left: 24px;">
											<?php esc_html_e( 'Creates new Cart and Checkout pages using modern WooCommerce Blocks (wp:woocommerce/cart and wp:woocommerce/checkout).', 'wpcalibrate-wc-pages-recovery' ); ?>
										</span>
									</label>
									<label style="display: block; margin-bottom: 8px;">
										<input type="radio" name="new_page_format" value="shortcodes" <?php checked( 'shortcodes' === ( $settings['new_page_format'] ?? 'blocks' ) ); ?> />
										<strong><?php esc_html_e( 'Classic Shortcodes', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
										<span class="description" style="display: block; margin-left: 24px;">
											<?php esc_html_e( 'Creates new pages using classic shortcodes: [woocommerce_cart], [woocommerce_checkout], and [woocommerce_my_account].', 'wpcalibrate-wc-pages-recovery' ); ?>
										</span>
									</label>
								</fieldset>
								<p class="description">
									<?php esc_html_e( 'Changing this setting affects newly created pages only. Existing pages, custom builder templates, and restored pages are never modified.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Diagnostic Logging', 'wpcalibrate-wc-pages-recovery' ); ?></th>
							<td>
								<label for="wpcalibrate-wcpr-logging">
									<input type="checkbox" name="logging_enabled" id="wpcalibrate-wcpr-logging" value="1" <?php checked( ! empty( $settings['logging_enabled'] ) ); ?> />
									<?php esc_html_e( 'Record recovery activities to WooCommerce Logger (Source: wpcalibrate-wc-pages-recovery).', 'wpcalibrate-wc-pages-recovery' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Logs can be inspected in WooCommerce > Status > Logs. No credentials or customer personal data are recorded.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Data Retention on Uninstall', 'wpcalibrate-wc-pages-recovery' ); ?></th>
							<td>
								<label for="wpcalibrate-wcpr-uninstall-cleanup">
									<input type="checkbox" name="uninstall_cleanup" id="wpcalibrate-wcpr-uninstall-cleanup" value="1" <?php checked( ! empty( $settings['uninstall_cleanup'] ) ); ?> />
									<?php esc_html_e( 'Clean up plugin configuration, logs, and tracking metadata upon plugin deletion.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'By default, plugin settings and history are preserved. When checked, deleting the plugin will remove plugin-specific options, transients, and role postmeta. Store pages, page content, and WooCommerce page assignments are ALWAYS preserved.', 'wpcalibrate-wc-pages-recovery' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'wpcalibrate-wc-pages-recovery' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab: History.
	 *
	 * @param array<int, array<string, mixed>> $history
	 */
	private static function render_tab_history( array $history ): void {
		?>
		<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-table-card">
			<div class="wpcalibrate-wcpr-history-header">
				<div>
					<h2><?php esc_html_e( 'Operational Recovery History', 'wpcalibrate-wc-pages-recovery' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %d: count */
							esc_html__( 'Retaining the most recent %d operational records.', 'wpcalibrate-wc-pages-recovery' ),
							History::MAX_ENTRIES
						);
						?>
					</p>
				</div>
				<?php if ( ! empty( $history ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcalibrate-wcpr-clear-history-form">
						<input type="hidden" name="action" value="wpcalibrate_wcpr_clear_history" />
						<?php wp_nonce_field( 'wpcalibrate_wcpr_clear_history_action', 'wpcalibrate_wcpr_nonce' ); ?>
						<button type="submit" class="button button-secondary">
							<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear History', 'wpcalibrate-wc-pages-recovery' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<table class="wp-list-table widefat fixed striped wpcalibrate-wcpr-history-table">
				<thead>
					<tr>
						<th scope="col" style="width: 160px;"><?php esc_html_e( 'Timestamp', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 110px;"><?php esc_html_e( 'Trigger', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 100px;"><?php esc_html_e( 'Role', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 110px;"><?php esc_html_e( 'Action', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 140px;"><?php esc_html_e( 'Page Transition', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col" style="width: 110px;"><?php esc_html_e( 'Status', 'wpcalibrate-wc-pages-recovery' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Operational Outcome Details', 'wpcalibrate-wc-pages-recovery' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $history ) ) : ?>
						<tr>
							<td colspan="7" class="wpcalibrate-wcpr-empty">
								<?php esc_html_e( 'No recovery operations recorded yet.', 'wpcalibrate-wc-pages-recovery' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $history as $item ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $item['timestamp'] ?? 0 ) ) ); ?></td>
								<td><code><?php echo esc_html( $item['trigger'] ?? 'system' ); ?></code></td>
								<td><strong><?php echo esc_html( ucfirst( $item['role'] ?? 'all' ) ); ?></strong></td>
								<td><?php echo esc_html( $item['action'] ?? 'check' ); ?></td>
								<td>
									<?php
									$old_id = (int) ( $item['old_page_id'] ?? 0 );
									$new_id = (int) ( $item['new_page_id'] ?? 0 );
									if ( $old_id > 0 && $new_id > 0 && $old_id !== $new_id ) {
										echo esc_html( "#{$old_id} → #{$new_id}" );
									} elseif ( $new_id > 0 ) {
										echo esc_html( "#{$new_id}" );
									} elseif ( $old_id > 0 ) {
										echo esc_html( "#{$old_id}" );
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<span class="wpcalibrate-wcpr-status-tag status-<?php echo esc_attr( $item['status'] ?? 'info' ); ?>">
										<?php echo esc_html( ucfirst( $item['status'] ?? 'info' ) ); ?>
									</span>
								</td>
								<td>
									<?php echo esc_html( $item['message'] ?? '' ); ?>
									<?php if ( ! empty( $item['result_code'] ) ) : ?>
										<small style="display: block; color: #646970;"><code><?php echo esc_html( $item['result_code'] ); ?></code></small>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab: Support & WPCalibrate Credentials.
	 */
	private static function render_tab_support(): void {
		?>
		<div class="wpcalibrate-wcpr-support-grid">
			<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-support-info">
				<h2><?php esc_html_e( 'WPCalibrate Support & Inquiries', 'wpcalibrate-wc-pages-recovery' ); ?></h2>
				<p>
					<?php esc_html_e( 'Need help, custom enhancements, or architectural assistance with your WooCommerce store infrastructure? Get in touch with our engineering team.', 'wpcalibrate-wc-pages-recovery' ); ?>
				</p>

				<ul class="wpcalibrate-wcpr-contact-list">
					<li>
						<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Email:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
						<a href="mailto:support@wpcalibrate.com">support@wpcalibrate.com</a>
					</li>
					<li>
						<span class="dashicons dashicons-admin-site" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Official Website:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
						<a href="https://wpcalibrate.com" target="_blank" rel="noopener noreferrer">https://wpcalibrate.com</a>
					</li>
					<li>
						<span class="dashicons dashicons-store" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Plugin Marketplace:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
						<a href="https://marketplace.wpcalibrate.com/" target="_blank" rel="noopener noreferrer">https://marketplace.wpcalibrate.com/</a>
					</li>
					<li>
						<span class="dashicons dashicons-phone" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Telephone:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
						<a href="tel:+447474795976">+44 7474 795976</a>
					</li>
					<li>
						<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'WhatsApp Direct:', 'wpcalibrate-wc-pages-recovery' ); ?></strong>
						<a href="https://wa.me/447474795976" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Chat on WhatsApp (+44 7474 795976)', 'wpcalibrate-wc-pages-recovery' ); ?></a>
					</li>
				</ul>
			</div>

			<div class="wpcalibrate-wcpr-card wpcalibrate-wcpr-support-policy">
				<h2><?php esc_html_e( 'Safe Recovery Guarantee', 'wpcalibrate-wc-pages-recovery' ); ?></h2>
				<p>
					<?php esc_html_e( 'WooCommerce Core Pages Auto-Recovery is engineered with strict non-destructive safety principles:', 'wpcalibrate-wc-pages-recovery' ); ?>
				</p>
				<ul class="wpcalibrate-wcpr-guarantee-list">
					<li><strong><?php esc_html_e( 'Zero Data Overwrite:', 'wpcalibrate-wc-pages-recovery' ); ?></strong> <?php esc_html_e( 'Existing custom page content, shortcodes, and builder layouts are never overwritten or modified.', 'wpcalibrate-wc-pages-recovery' ); ?></li>
					<li><strong><?php esc_html_e( 'Safe Trashed Page Restoration:', 'wpcalibrate-wc-pages-recovery' ); ?></strong> <?php esc_html_e( 'Pages in trash are restored only if their pre-trash status was verified as published.', 'wpcalibrate-wc-pages-recovery' ); ?></li>
					<li><strong><?php esc_html_e( 'Ambiguity Halts:', 'wpcalibrate-wc-pages-recovery' ); ?></strong> <?php esc_html_e( 'If multiple candidate pages exist, recovery halts and awaits administrator selection rather than creating duplicate pages.', 'wpcalibrate-wc-pages-recovery' ); ?></li>
					<li><strong><?php esc_html_e( 'Atomic Concurrency:', 'wpcalibrate-wc-pages-recovery' ); ?></strong> <?php esc_html_e( 'Background jobs and manual triggers use site-scoped atomic token locks to prevent race conditions.', 'wpcalibrate-wc-pages-recovery' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/* ================= POST MUTATION ACTION HANDLERS ================= */

	/**
	 * Handle Read-Only "Check Now" action.
	 */
	public static function handle_check_now(): void {
		check_admin_referer( 'wpcalibrate_wcpr_check_now_action', 'wpcalibrate_wcpr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		PageInspector::clear_cache();
		$diagnostics = PageInspector::inspect_all_roles( true );
		History::record_last_check( $diagnostics );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'overview', 'wcpr_notice' => 'checked' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle "Check and Repair" action across all missing/broken roles.
	 */
	public static function handle_repair_all(): void {
		check_admin_referer( 'wpcalibrate_wcpr_repair_all_action', 'wpcalibrate_wcpr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		$outcome = RecoveryService::recover( 'manual' );
		$notice  = 'repaired';
		$msg     = '';

		if ( 'locked' === ( $outcome['status'] ?? '' ) ) {
			$notice = 'locked';
		} elseif ( empty( $outcome['success'] ) ) {
			$notice = 'error';
			$msg    = $outcome['message'] ?? '';
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'overview', 'wcpr_notice' => $notice, 'wcpr_msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle per-role repair action.
	 */
	public static function handle_repair_single(): void {
		check_admin_referer( 'wpcalibrate_wcpr_repair_single_action', 'wpcalibrate_wcpr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		$role = isset( $_POST['role'] ) ? sanitize_key( $_POST['role'] ) : '';
		if ( ! in_array( $role, PageInspector::get_supported_roles(), true ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'overview', 'wcpr_notice' => 'error', 'wcpr_msg' => rawurlencode( __( 'Invalid role specified.', 'wpcalibrate-wc-pages-recovery' ) ) ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$outcome = RecoveryService::recover( 'manual', $role );
		$notice  = 'repaired';
		$msg     = '';

		if ( 'locked' === ( $outcome['status'] ?? '' ) ) {
			$notice = 'locked';
		} elseif ( empty( $outcome['success'] ) ) {
			$notice = 'error';
			$msg    = $outcome['message'] ?? '';
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'overview', 'wcpr_notice' => $notice, 'wcpr_msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle manual page assignment.
	 */
	public static function handle_manual_assign(): void {
		check_admin_referer( 'wpcalibrate_wcpr_manual_assign_action', 'wpcalibrate_wcpr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		$role           = isset( $_POST['role'] ) ? sanitize_key( $_POST['role'] ) : '';
		$page_id        = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$confirm_custom = ! empty( $_POST['confirm_custom'] );

		$result = RecoveryService::manually_assign_page( $role, $page_id, $confirm_custom );

		if ( $result['success'] ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'overview', 'wcpr_notice' => 'assigned', 'wcpr_msg' => rawurlencode( $result['message'] ) ], admin_url( 'admin.php' ) ) );
		} else {
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'overview', 'wcpr_notice' => 'error', 'wcpr_msg' => rawurlencode( $result['message'] ) ], admin_url( 'admin.php' ) ) );
		}
		exit;
	}

	/**
	 * Handle Settings form save.
	 */
	public static function handle_save_settings(): void {
		check_admin_referer( 'wpcalibrate_wcpr_save_settings_action', 'wpcalibrate_wcpr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		$sanitized = Settings::sanitize( $_POST );
		update_option( Settings::OPTION_NAME, $sanitized, false );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'settings', 'wcpr_notice' => 'settings_saved' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle Clear History action.
	 */
	public static function handle_clear_history(): void {
		check_admin_referer( 'wpcalibrate_wcpr_clear_history_action', 'wpcalibrate_wcpr_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		History::clear();

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'history', 'wcpr_notice' => 'history_cleared' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle dismiss notice action.
	 */
	public static function handle_dismiss_notice(): void {
		check_admin_referer( 'wpcalibrate_wcpr_dismiss_notice_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'wpcalibrate-wc-pages-recovery' ) );
		}

		$hash = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
		if ( ! empty( $hash ) ) {
			update_option( 'wpcalibrate_wcpr_dismissed_hash', $hash, false );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
