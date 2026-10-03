# Recovery Policy and Decision Matrix: WooCommerce Core Pages Auto-Recovery

## 1. Diagnostic Health States and Automated Actions

The table below describes how each page status is evaluated and what action the automated recovery engine takes:

| Diagnostic State | Condition | Automated Action | Safe Guarantee |
|---|---|---|---|
| **Healthy** | Assigned page exists, `publish` status, no password, recognized WooCommerce block or shortcode. | **No action.** Healthy assignments are left untouched. | Zero overhead, zero changes to valid pages. |
| **Healthy with Custom Content** | Assigned page exists, `publish` status, no password, but utilizes custom builder (Elementor, Divi, custom HTML). | **No mutation.** Issues warning notice in admin dashboard. | **Preserves custom layouts.** Never overwrites shortcodes or custom templates. |
| **Trashed (Pre-Status Published)** | Assigned page is in trash, and WordPress `_wp_trash_meta_status` confirms it was published prior to deletion. | **Safely untrashes page.** Verifies final status is `publish` and restores assignment. | Restores original page ID and URL slug without data loss. |
| **Trashed (Pre-Status Draft/Private)** | Assigned page is in trash, but was not published prior to trashing (or status unknown). | **Halt & flag for manual review.** Does NOT automatically publish. | Protects store administrator intent; prevents private/draft content exposure. |
| **Unassigned or Deleted Post** | WooCommerce option is `0` or points to a non-existent post ID. | 1. Reassign previously tracked page if found.<br>2. Reassign unique published candidate matching role content.<br>3. If >1 candidates exist: **Halt for admin selection.**<br>4. If 0 candidates exist: **Create exactly one published page.** | Eliminates duplicates; requests human approval when ambiguous. |
| **Unpublished (Draft / Pending / Private)** | Assigned page exists, but status is not `publish`. | **Halt & flag for manual attention.** Does not force-publish drafts. | Administrator must explicitly publish or reassign. |
| **Password Protected** | Assigned page exists and is published, but has a password set (`post_password`). | **Halt & flag for manual attention.** Does not strip passwords. | Public checkout cannot be locked behind a WordPress password. |
| **Wrong Post Type** | Assigned ID belongs to an attachment, product, revision, or custom post type. | **Halt & flag for manual attention.** | Requires valid `page` post type. |
| **Duplicate Role Assignment** | Single page ID is assigned to multiple core roles (e.g. Cart AND Checkout). | **Halt & flag for manual attention.** | Single page cannot serve multiple core WooCommerce roles. |

---

## 2. Page Creation Specifications

When creating a new page for an unassigned role without candidates:
- **Title:** Localized standard title (`Cart`, `Checkout`, `My Account`).
- **Slug:** Localized safe unique slug (`cart`, `checkout`, `my-account`) using WordPress slug collision avoidance without renaming existing pages.
- **Rendering Format:**
  - **WooCommerce Blocks (Default):**
    - Cart: `<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart is-loading"></div><!-- /wp:woocommerce/cart -->`
    - Checkout: `<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->`
    - My Account: `<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->`
  - **Classic Shortcodes:**
    - Cart: `[woocommerce_cart]`
    - Checkout: `[woocommerce_checkout]`
    - My Account: `[woocommerce_my_account]`
- **Ownership Metadata:**
  - `_wpcalibrate_wcpr_tracked_role`: stores role name (`cart`, `checkout`, `myaccount`).
  - `_wpcalibrate_wcpr_created_by`: `wpcalibrate-wc-pages-recovery`.
  - `_wpcalibrate_wcpr_created_at`: unix timestamp.

---

## 3. How to Intentionally Delete or Replace a Core Page

Because the plugin automatically monitors trash and deletion events, follow these steps when intentionally restructuring store pages:

1. In WordPress Admin, navigate to **WPCalibrate > Core Pages Recovery > Recovery Settings**.
2. Uncheck **Global Automatic Recovery** (or uncheck the specific role you wish to modify).
3. Click **Save Settings**.
4. You may now delete, rename, or trash the page without automatic recovery re-creating or restoring it.
5. Once your new page is published, either assign it via **WPCalibrate > Core Pages Recovery > Overview > Assign...** or through standard **WooCommerce > Settings > Advanced**.
6. Re-enable automatic recovery in **Recovery Settings**.
