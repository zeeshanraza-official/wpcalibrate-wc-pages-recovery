# Implementation Progress Checklist: WooCommerce Core Pages Auto-Recovery

- [x] **1. Workspace Inspection & Compatibility Verification**
  - [x] Inspect workspace and environment (PHP 8.3 CLI, Git, Composer available).
  - [x] Verify official release versions: WordPress 7.1.2 (Released Sep 22, 2026), WooCommerce 11.1.2 (Released Sep 22, 2026).
  - [x] Set target baselines: Minimum WP 7.0, Minimum WC 11.1; Tested up to WP 7.1.2, WC 11.1.2; Requires PHP: 8.2 (supports PHP 8.2 and 8.3).
  - [x] Verify WooCommerce page generation APIs, canonical blocks vs shortcode structures, and declare HPOS / Blocks compatibility.

- [x] **2. Plugin Structure, Dependency Guards, Lifecycle & Shared Menu**
  - [x] Main plugin file `wpcalibrate-wc-pages-recovery.php` with official headers, license, dependency guards, and HPOS/Blocks compatibility declaration.
  - [x] Main plugin class `includes/class-plugin.php` with singleton/service registration and hook orchestration.
  - [x] Lifecycle handler `includes/class-lifecycle.php` (activation defaults, upgrades, deactivation cleanup, retention checks).
  - [x] Shared WPCalibrate top-level menu coordination (`wpcalibrate`) with submenu (`wpcalibrate-wc-pages-recovery`) preventing duplicates across load orders.

- [x] **3. Page Health & Diagnostic Engine**
  - [x] Implement `includes/class-page-inspector.php`.
  - [x] Option inspection for `woocommerce_cart_page_id`, `woocommerce_checkout_page_id`, `woocommerce_myaccount_page_id`.
  - [x] Diagnostics: unassigned, deleted, trashed, wrong post type, unpublished, password-protected, duplicate role assignment, recognized content, unrecognized/custom content.
  - [x] Block recognition: Cart block (`woocommerce/cart`), Checkout block (`woocommerce/checkout`), My Account rendering, shortcodes (`[woocommerce_cart]`, `[woocommerce_checkout]`, `[woocommerce_my_account]`).
  - [x] Reusable / synced blocks (`wp_block`) inspection with recursion bounds (max depth 5) and cycle protection.
  - [x] Preserving valid custom-builder pages with non-destructive warnings.
  - [x] Never infer role identity from title or slug alone.

- [x] **4. Safe Recovery Service & Atomic Concurrency**
  - [x] Implement `includes/class-lock.php` with atomic token, expiry, safe stale-lock handling, and owner-only release.
  - [x] Implement `includes/class-recovery-service.php` with pre-mutation state checks.
  - [x] Candidate resolution: tracked page preference, unique suitable published page check, ambiguity halt.
  - [x] Safe restoration of trashed pages (only if pre-trash status was published and suitable).
  - [x] Creation of missing page: exactly one page, canonical core block structure or classic shortcode, unique slug, metadata tracking.
  - [x] Partial failure handling and rediscovery on retry.
  - [x] Extensible hooks: `wpcalibrate_wcpr_pre_recovery`, `wpcalibrate_wcpr_post_recovery`, `wpcalibrate_wcpr_candidate_approved`.

- [x] **5. Automation, Scheduling & Event Listeners**
  - [x] Implement `includes/class-scheduler.php`.
  - [x] Action Scheduler integration with graceful WP-Cron fallback.
  - [x] Event listeners for post mutations (`wp_trash_post`, `untrashed_post`, `before_delete_post`, `deleted_post`, `transition_post_status`, `post_updated`).
  - [x] Event listeners for option changes (`update_option_woocommerce_*_page_id`).
  - [x] Debounced scheduling, deduplication, and recursion suppression during plugin-initiated updates.
  - [x] Exponential backoff retry logic (up to 3 retries for transient issues, zero retries for ambiguous/policy-blocked states).
  - [x] Initial check on activation after dependencies are confirmed; hourly reconciliation check.

- [x] **6. Admin Interface, Settings, Manual Controls & History**
  - [x] Implement `includes/class-settings.php` and `includes/class-admin.php`.
  - [x] Overview screen: Role statuses, page IDs, titles, rendering format, diagnostic reason, view/edit links.
  - [x] Manual controls: "Check Now" (read-only), "Check and Repair" (protected mutation), per-role repair buttons.
  - [x] Manual page assigner: searchable published page picker with strict server-side suitability and conflict validation.
  - [x] Settings: Global auto-recovery toggle, per-role protection toggles, creation format (Core Blocks vs Classic Shortcodes), diagnostic logging toggle, uninstall data cleanup toggle.
  - [x] Actionable notices: persistent alert for broken pages, dismissible transient notices with PRG pattern.
  - [x] Implement `includes/class-history.php`: bounded history log (max 100 entries), viewable log table, clear history action.
  - [x] WPCalibrate support card: email, website, marketplace, phone, and WhatsApp links.
  - [x] Admin assets (`assets/css/admin.css`, `assets/js/admin.js`): responsive, keyboard accessible, ARIA labels, RTL logical CSS.

- [x] **7. Comprehensive Verification & Testing**
  - [x] PHP syntax linting (`php -l`) across all plugin files (11/11 passed).
  - [x] Automated test suite covering inspector diagnostics, recovery workflows, concurrency/locking, failure recoveries, scheduler debouncing, and capability/nonce guards (16/16 passed).
  - [x] Automated rendering, HPOS, and reverse activation suite (5/5 passed).
  - [x] Verification of uninstall cleanup vs data retention (pages and assignments always survive).
  - [x] Verification of multisite per-site activation and single-site installation.

- [x] **8. Packaging & Deliverables**
  - [x] Generate clean distribution ZIP `wpcalibrate-wc-pages-recovery.zip`.
  - [x] Write `readme.txt` with official WordPress plugin directory standards.
  - [x] Write `uninstall.php` respecting retention settings.
  - [x] Write `LICENSE` (GPL-2.0-or-later).
  - [x] Deliver complete technical documentation (`docs/architecture.md`, `docs/recovery-policy.md`, `docs/verification-results.md`).

- [x] **9. Automated FTP Remote Deployment & Continuous Sync**
  - [x] Store credentials in `ftp-config.json` with passive FTP mode.
  - [x] Implement multi-mode deployer `ftp-sync.py` and PowerShell wrapper `deploy-ftp.ps1`.
  - [x] Support one-shot sync, `--force`, `--status` verification, and `--watch` real-time auto-uploader.
  - [x] Synchronize plugin files to `/wp-content/plugins/wpcalibrate-wc-pages-recovery/` on remote test server.

- [x] **10. Background-Adaptive Branding Icons (`icon-white.png` & `icon-dark.png`)**
  - [x] Integrate official `branding/icon-white.png` for dark backgrounds (WordPress admin sidebar menu `#adminmenu`, dark admin schemes, dark mode).
  - [x] Integrate official `branding/icon-dark.png` for light backgrounds (plugin dashboard header, white card containers, `body.admin-color-light`).
  - [x] Enforce contextual width placement rules (20px in WordPress admin menu, 32px in dashboard header).
  - [x] Pass all automated test assertions (22/22 tests passing).
  - [x] Deploy updated assets and code to remote server via FTP and verify live HTTP 200 accessibility.

- [x] **11. GitHub Public Repository & Dashboard Auto-Updates**
  - [x] Create and publish public repository `https://github.com/zeeshanraza-official/wpcalibrate-wc-pages-recovery`.
  - [x] Build `GitHubUpdater` service handling `site_transient_update_plugins`, `plugins_api` modal details, and `upgrader_post_install` directory normalization.
  - [x] Publish official release `v1.0.0` with `wpcalibrate-wc-pages-recovery.zip` attached.
  - [x] Pass automated test suite with updater scenario (23/23 tests passing).
  - [x] Synchronize updated codebase to remote testing server.


