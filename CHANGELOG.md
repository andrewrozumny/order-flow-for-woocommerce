# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-10-13

Hardening release before the WordPress.org listing. No new features; existing settings and orders carry over.

### Security
- The configuration (statuses, transition rules, documents, import and export, deleting a status) needs the `manage_options` capability by default (filter `ofwc_manage_capability`). Shop managers can no longer rewrite the rules that limit them.
- Transition rules are enforced for every status change a person makes: the Order Flow box, the status dropdown, all bulk actions including the WooCommerce ones, the order list and preview buttons, new orders and the REST API with the login cookie. Gateways, cron, WP-CLI, API keys and full refunds are not restricted.
- A required comment applies to every person, administrators included. A status that does not exist is refused.
- The comment of a status change reaches the customer email only when "Send this comment to the customer" is ticked. Admin emails always carry it.
- A status hidden from customers is masked everywhere a customer looks: the shop, Store API, REST and the customer emails.
- dompdf and its libraries ship under the plugin's own namespace, so another plugin's copy of dompdf can no longer replace or mix with ours.
- Temporary PDF attachments are written outside the web root when the server allows it, and removed when the email is sent.

### Fixed
- Reactivation no longer moves orders back into a custom status after they were completed or changed while the plugin was inactive.
- Moving orders to On hold on deactivation, restoring them and moving orders out of a deleted status no longer touch stock, sales counts or coupon usage and send no emails (customers are notified only when "Notify customers" is ticked).
- These moves run in batches with a time and memory budget, with a progress screen for deactivation and background jobs for restore and status deletion. 20,000 orders at 128 MB complete without errors.
- Uninstall moves orders out of custom statuses into On hold (keeping the old status in order meta and a note), removes every option, transient, email setting, folder and job of the plugin, and covers every site of a network. Order history stays.
- Bulk deactivation on the Plugins screen leads to the deactivation choice; WP-CLI deactivation warns about orders in custom statuses.
- Network activation on multisite works; the plugin starts on `plugins_loaded` only when WooCommerce is loaded.
- Status slugs made of digits only no longer break the site.
- Two people changing the same order at the same time no longer produce duplicate emails or a history that disagrees with the status.
- Status texts and comments have length limits, so a huge value cannot bloat an autoloaded option or the order history.
- A large PDF or a missing temporary folder gives a clear message instead of a fatal error.
- Import in Replace mode only replaces the sections present in the file.
- Restoring orders works with the legacy posts order storage (HPOS off).
- Statuses that are not paid are kept out of WooCommerce Analytics revenue (filter `ofwc_exclude_unpaid_from_analytics`).
- Order notes from Order Flow are added only for changes that involve a custom status or carry a comment.

### Changed
- Translation template shipped in `languages/`.
- New hooks: `ofwc_manage_capability`, `ofwc_enforce_transition_rules`, `ofwc_actor_is_person`, `ofwc_exclude_unpaid_from_analytics`, `ofwc_batch_budget`.
- Code split into smaller classes, strict static analysis (PHPStan at the highest level, Psalm taint analysis, full WordPress Coding Standards).

## [0.1.0] - 2026-10-12

First release.

### Added
- Custom order statuses with label, color, icon, "count as paid", "reduce stock" and "show in My Account" options, managed under WooCommerce > Order Flow.
- Transition rules matrix ("from > to") with allowed roles and an optional required comment. Enforced for human actions in wp-admin: order screen buttons, status dropdown and bulk actions.
- Transition history on every order (who, when, from, to, comment) as order notes and as a table in the Order Flow box.
- A WooCommerce email per custom status for the customer and/or the admin, with placeholders `{order_number}`, `{status}`, `{customer_name}`, `{note}`, `{order_date}`, `{site_title}`, `{order_total}`. Template overridable at `yourtheme/woocommerce/emails/ofwc-status.php`.
- A PDF document per status (logo, store details, items, totals, free text) generated with dompdf, with download buttons on the order screen and in My Account and an optional attachment to the status email. Template overridable at `yourtheme/order-flow-for-woocommerce/pdf/document.php`.
- Import and export of the whole configuration as JSON (merge or replace).
- Safe deactivation: keep the statuses, or move orders to On hold and restore them on reactivation.
- Deleting a status that has orders requires a target status.
- Hooks: `ofwc_status_entered`, `ofwc_email_attachments`, `ofwc_pdf_document_data`, `ofwc_admin_tabs`, `ofwc_mask_status_labels`, `ofwc_is_pro`.
- High-Performance Order Storage (HPOS) compatibility.
