=== WPCalibrate Core Pages Auto-Recovery for WooCommerce ===
Contributors: wpcalibrate
Tags: woocommerce, cart, checkout, auto-recovery, recovery
Requires at least: 7.0
Tested up to: 7.1.2
Requires PHP: 8.2
WC requires at least: 11.1
WC tested up to: 11.1.2
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically detect and safely repair missing WooCommerce Cart, Checkout, and My Account pages and page assignments.

== Description ==

**WPCalibrate Core Pages Auto-Recovery for WooCommerce** by WPCalibrate is a rock-solid, production-grade utility designed to prevent store downtime and lost checkout conversions. Accidental page trashing, deletion, unassignment, or broken shortcode blocks can take down your cart and checkout without warning.

This plugin continuously monitors your essential store pages and safely repairs or restores them according to verified architectural policies.

### Key Capabilities

* **Automated Background Recovery:** Automatically detects missing, deleted, or unassigned Cart, Checkout, and My Account pages and repairs them without manual intervention.
* **Non-Destructive Restoration:** Existing custom builder templates (Elementor, Divi, Gutenberg, Beaver Builder) and shortcodes are never overwritten or replaced.
* **Safe Trash Handling:** Trashed store pages are restored only if their pre-trash status was verified as published. Drafts and private pages are never published automatically.
* **Ambiguity Prevention:** If multiple matching candidate pages exist, recovery halts and prompts an administrator rather than creating confusing duplicates.
* **Canonical Core Blocks & Classic Shortcodes:** Choose between modern WooCommerce Core Blocks (`wp:woocommerce/cart`, `wp:woocommerce/checkout`) or Classic Shortcodes (`[woocommerce_cart]`, `[woocommerce_checkout]`, `[woocommerce_my_account]`) for newly generated pages.
* **Atomic Concurrency:** Site-scoped lock with unique worker tokens and expiration prevents race conditions between simultaneous cron tasks or manual administrator actions.
* **Action Scheduler Integration:** Debounced event-driven checks coalesced with an hourly reconciliation schedule and automated retry backoff (up to 3 attempts for transient issues).
* **Detailed Audit History:** Bounded operational log (up to 100 entries) recording every diagnostic check, page transition, action, and result code.
* **Shared WPCalibrate Dashboard:** Clean administration under the unified WPCalibrate parent menu with full accessibility, screen-reader support, and RTL compliance.

== Installation ==

1. Upload the `wpcalibrate-wc-pages-recovery` directory to the `/wp-content/plugins/` directory, or install the ZIP directly via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure WooCommerce 11.1 or higher is installed and active.
4. Navigate to **WPCalibrate > Core Pages Recovery** to review diagnostic health and customize recovery policies.

== Frequently Asked Questions ==

= Does this plugin work with page builders like Elementor or Divi? =
Yes. The diagnostic engine recognizes valid custom content. It will never overwrite, alter, or replace your custom builder templates.

= What happens if I want to intentionally delete or redesign a core page? =
If you need to intentionally delete or replace a core page, navigate to **WPCalibrate > Core Pages Recovery > Recovery Settings** and disable automatic recovery first.

= What happens on plugin uninstall? =
By default, all plugin configuration and history are retained. If you enable the "Data Retention on Uninstall" cleanup option in settings, plugin options and transients are removed upon deletion. Your WooCommerce pages, page content, and page assignments are NEVER deleted or altered.

== Screenshots ==

1. Core Pages Diagnostics Overview displaying page health and assignment status.
2. Recovery Settings panel with granular role toggles and format selectors.
3. Operational Recovery History audit trail.
4. WPCalibrate Support and direct contact channels.

== Changelog ==

= 1.0.0 =
* Initial official production release.
* Automated recovery for Cart, Checkout, and My Account pages.
* Atomic token locking and Action Scheduler debounced background reconciliation.
* Support for WooCommerce Blocks and Classic Shortcodes.
* Comprehensive audit history log and shared WPCalibrate administrative interface.
