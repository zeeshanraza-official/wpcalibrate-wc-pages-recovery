# WooCommerce Core Pages Auto-Recovery

[![WordPress](https://img.shields.io/badge/WordPress-7.0%20to%207.1.2-blue.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-11.1%20to%2011.1.2-purple.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%20|%208.3-777bb4.svg)](https://www.php.net)
[![HPOS](https://img.shields.io/badge/HPOS-Compatible-success.svg)](https://woocommerce.com)
[![Blocks](https://img.shields.io/badge/Cart%20%26%20Checkout%20Blocks-Compatible-success.svg)](https://woocommerce.com)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

> **Enterprise-grade, non-destructive automatic detection, protection, and self-healing for critical WooCommerce store pages (Cart, Checkout, and My Account).**

Developed with precision by [WPCalibrate](https://wpcalibrate.com).

---

## 🚀 Overview

When essential WooCommerce pages (Cart, Checkout, My Account) are accidentally deleted, trashed, unassigned, or conflicted by redesigns or client edits, customer checkout flows break and revenue stops instantly.

**WooCommerce Core Pages Auto-Recovery** constantly monitors your store pages and immediately repairs assignments and canonical templates without data loss, race conditions, or human error.

---

## ✨ Key Features

- **Non-Destructive Custom Builder Coexistence:**
  Stores using Elementor, Divi, Beaver Builder, or custom Gutenberg templates are preserved. Healthy custom pages are never overwritten.
- **Safe Pre-Trash Verification:**
  Restores trashed pages **only** if their pre-trash state was confirmed as `'publish'`. Never inadvertently exposes private or draft content.
- **Ambiguity Halts:**
  If multiple candidate pages exist for a role, automatic recovery pauses and prompts the administrator to choose the intended page rather than generating duplicate store pages.
- **Atomic Concurrency Control (CAS):**
  Uses atomic Compare-And-Swap token locks with stale-lock resolution (120s TTL) to eliminate race conditions under concurrent worker traffic.
- **Action Scheduler with WP-Cron Fallback:**
  Reconciliation runs in the background. Event listeners debounce successive edits (15s delay) with recursion suppression.
- **Native WordPress Dashboard Auto-Updates:**
  Seamlessly receives update notifications and one-click upgrades directly within your WordPress dashboard via GitHub Releases.
- **Background-Adaptive Branding Icons:**
  Official WPCalibrate branding automatically switches between `icon-white.png` (for dark WordPress menus) and `icon-dark.png` (for light dashboards).

---

## 📦 Requirements

- **PHP:** 8.2 or 8.3
- **WordPress:** 7.0 to 7.1.2+
- **WooCommerce:** 11.1 to 11.1.2+
- **Database:** MySQL 5.7+ / MariaDB 10.4+

---

## 🛠️ Installation

### Option A: Direct WordPress Upload
1. Download `wpcalibrate-wc-pages-recovery.zip` from the [Latest Release](https://github.com/zeeshanraza-official/wpcalibrate-wc-pages-recovery/releases/latest).
2. Go to **WordPress Admin > Plugins > Add New > Upload Plugin**.
3. Choose the ZIP file and click **Install Now**, then **Activate**.

### Option B: Automatic Dashboard Updates
Once installed, future versions will automatically show standard WordPress update notifications in **Dashboard > Updates** and **Plugins**. Click **Update Now** to upgrade in one click.

---

## 🔧 Developer & Architecture Reference

### Extensibility Hooks

```php
// Inspect or modify diagnostic evaluation before recovery
add_filter( 'wpcalibrate_wcpr_pre_recovery', function( $diagnostics, $trigger ) {
    return $diagnostics;
}, 10, 2 );

// Post-recovery notifications or external logging
add_action( 'wpcalibrate_wcpr_post_recovery', function( $summary ) {
    // $summary contains execution results, restored pages, and logs
} );

// Candidate page approval override
add_filter( 'wpcalibrate_wcpr_candidate_approved', function( $is_approved, $candidate, $role ) {
    return $is_approved;
}, 10, 3 );
```

---

## 🛡️ License

This plugin is licensed under the [GNU General Public License v2.0 or later](LICENSE).

## 💬 Support & Contact

- **Website:** [https://wpcalibrate.com](https://wpcalibrate.com)
- **Plugin Marketplace:** [https://marketplace.wpcalibrate.com](https://marketplace.wpcalibrate.com)
- **Email:** [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
- **Phone:** +44 7474 795976
