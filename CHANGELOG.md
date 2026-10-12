# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/).

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
