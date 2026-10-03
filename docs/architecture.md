# Technical Architecture: WooCommerce Core Pages Auto-Recovery

## 1. Overview and Core Philosophy

**WooCommerce Core Pages Auto-Recovery** (`wpcalibrate-wc-pages-recovery`) is an enterprise-grade WordPress and WooCommerce plugin engineered to ensure zero downtime for essential commerce flows (Cart, Checkout, and My Account).

The plugin is designed with strict non-destructive safety principles:
- **Never mutate healthy configurations:** Valid page assignments and content are never touched.
- **Never overwrite custom builders:** Pages built with Elementor, Divi, Beaver Builder, or custom block templates are preserved.
- **Never guess in ambiguous situations:** If multiple matching pages exist, automatic recovery halts and prompts the administrator.
- **Atomic Concurrency:** Site-scoped locking with unique owner tokens ensures background jobs and manual administrator requests never collide or produce duplicate pages.

---

## 2. Component Structure and Class Hierarchy

```
wpcalibrate-wc-pages-recovery/
├── wpcalibrate-wc-pages-recovery.php # Bootstrap, headers, dependency checks, HPOS declarations
├── uninstall.php                     # Retention-compliant uninstaller
├── readme.txt                        # Standard WordPress plugin directory readme
├── LICENSE                           # GPL-2.0-or-later license
├── includes/
│   ├── class-plugin.php             # Main plugin coordinator and service locator
│   ├── class-lifecycle.php          # Activation, deactivation, and idempotent migrations
│   ├── class-settings.php           # Settings API options registration and sanitization
│   ├── class-lock.php               # Site-scoped atomic concurrency lock
│   ├── class-history.php            # Bounded operational audit log (max 100 entries)
│   ├── class-page-inspector.php     # Deep page health diagnostics and candidate detection
│   ├── class-recovery-service.php   # Safe recovery algorithm and mutation orchestration
│   ├── class-scheduler.php          # Action Scheduler / WP-Cron debounced automation
│   └── class-admin.php              # UI dashboard, shared WPCalibrate menu, POST handlers
├── assets/
│   ├── css/admin.css                # Accessible, responsive, RTL CSS logical properties
│   └── js/admin.js                  # Accessible drawers, confirmation dialogs, keyboard UX
├── languages/
│   └── wpcalibrate-wc-pages-recovery.pot # Localization template
├── tests/
│   ├── bootstrap.php                # High-fidelity WordPress and WooCommerce test doubles
│   ├── test-recovery-suite.php      # Automated acceptance criteria test suite (16 tests)
│   └── test-rendering-and-e2e.php   # Rendering, HPOS, and lifecycle test suite (5 tests)
└── docs/
    ├── progress-checklist.md        # Ordered milestone tracking checklist
    ├── architecture.md              # Technical architecture and data flow
    ├── recovery-policy.md           # Recovery rules and administrative guide
    └── verification-results.md      # Executed tests, environments, and compatibility audit
```

---

## 3. Concurrency Lock: Atomic Compare-And-Swap (CAS)

To guarantee that multiple background workers or simultaneous administrator requests do not create duplicate pages or overwrite options concurrently, `Lock` implements an atomic Compare-And-Swap (CAS) mechanism:

1. **Lock Storage (`wpcalibrate_wcpr_lock`):**
   Stores a JSON-encoded payload containing `token` (32-character random string), `expires` (unix timestamp), and `created`.
2. **Atomic Acquisition:**
   - Reads the existing lock.
   - If not present: Executes an atomic `INSERT` query.
   - If present but expired: Executes an atomic `UPDATE ... WHERE option_value = <previous_raw_value>` (CAS).
   - If present and active (`expires > now`): Aborts acquisition and returns `null`.
3. **Owner-Only Release:**
   - The lock can only be deleted if the stored token matches the worker's token (`DELETE ... WHERE option_value LIKE '%"token":"<worker_token>"%'`).
   - If a worker's lock expired and another worker acquired it, the first worker's release query safely affects 0 rows, preventing accidental lock revocation.

---

## 4. Deep Page Inspector & Content Recognition

`PageInspector` evaluates page health across all three core roles:
- `cart` (`woocommerce_cart_page_id`)
- `checkout` (`woocommerce_checkout_page_id`)
- `myaccount` (`woocommerce_myaccount_page_id`)

### Content Recognition Hierarchy:
1. **Shortcodes:** Direct detection of `[woocommerce_cart]`, `[woocommerce_checkout]`, and `[woocommerce_my_account]`.
2. **Blocks:** Direct detection of `woocommerce/cart`, `woocommerce/checkout`, `woocommerce/customer-account`.
3. **Nested & Synced Blocks (`wp_block`):**
   - Parses block nodes via `parse_blocks()`.
   - Inspects `core/block` reusable block references.
   - **Recursion Guard:** Bounded to a maximum depth of 5.
   - **Cycle Protection:** Tracks visited reusable block IDs (`$visited_refs`) to prevent infinite loops.
4. **Custom Builder Coexistence:**
   - If a page is published, not password-protected, and has custom builder markup (e.g., Elementor or Divi widgets), it is diagnosed as `healthy_custom`.
   - The plugin issues an informative warning in the dashboard, preserving the existing assignment without mutation.

---

## 5. Safe Recovery Algorithm

When `RecoveryService::recover()` executes under lock:
1. **Pre-Mutation State Check:** Refreshes and inspects all page states.
2. **Policy Evaluation:** If global auto-recovery or the specific role is disabled, skips automatic recovery.
3. **Healthy Assignment Preservation:** If already healthy, leaves unchanged.
4. **Trashed Page Handling:**
   - Checks WordPress pre-trash status meta (`_wp_trash_meta_status`).
   - If pre-trash status was `'publish'`, calls `wp_untrash_post()`, verifies post status is now published, re-tracks metadata, and records restoration.
   - If pre-trash status was `'draft'`, `'private'`, or unknown, halts and flags `requires_manual_attention`.
5. **Candidate Discovery & Ambiguity Prevention:**
   - Checks for a previously tracked page (`_wpcalibrate_wcpr_tracked_role`).
   - Checks for a unique published candidate page matching role content.
   - **Ambiguity Halt:** If 2 or more published pages match role content, recovery halts immediately for that role to prevent misassignment.
6. **Canonical Page Creation:**
   - If no candidate exists and no conflict is present, creates exactly one page with canonical WooCommerce Block structure or Classic Shortcodes.
   - Attaches ownership meta (`_wpcalibrate_wcpr_tracked_role`, `_wpcalibrate_wcpr_created_by`).
   - Verifies option persistence. If option persistence fails, records a `partial_failure` so subsequent retries rediscover the created page via tracked meta rather than duplicating it.

---

## 6. Background Automation & Event Listeners

`Scheduler` coordinates background checks using:
- **Action Scheduler:** Preferred background engine (WC standard).
- **WP-Cron Fallback:** Guarded fallback when Action Scheduler is not initialized.
- **Debounced Scheduling:** Page trashed, untrashed, deleted, or option update events trigger a coalesced check scheduled in 15 seconds. Rapid subsequent events are suppressed via a 15-second debounce transient.
- **Recursion Suppression:** `RecoveryService::$is_recovering` static flag prevents the plugin's own page creations or option updates from triggering endless background loops.
- **Exponential Backoff:** Up to 3 retries (2 min, 4 min, 8 min) for transient errors (e.g. temporary database lock). Zero retries for policy-blocked or ambiguous states.
