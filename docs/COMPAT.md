# Compatibility

Last full run: 2026-10-13. Plugin version: 0.1.1. The matrix ran on the release candidate build; the published zip differs from it only in the header of the translation template (version and date). Release zip sha256 8d2c90376675...

## Support policy

- WordPress: requires 6.6 or newer. Tested up to 7.1.
- PHP: requires 8.1 or newer.
- WooCommerce: requires 9.0 or newer.
- Versions below these are not supported.

## What is tested

Every row is a clean WordPress install (WP_DEBUG, WP_DEBUG_LOG and SCRIPT_DEBUG on) with WooCommerce activated, a few products and orders, and the plugin installed from the release zip. For each row the matrix runs:

- **Unit**: the unit test suite on that PHP version.
- **Integration** (WP-CLI): three statuses, transition rules, an order, a move as the administrator and as a shop manager (an allowed move, a refused move, a move that needs a comment), the status email for the customer and the admin (intercepted, never sent), the order history and note, the PDF (generated, and its text contains the order number), and an export followed by an import that must change nothing.
- **Smoke** (HTTP, no browser, a throwaway web server in the container): sign-in as the administrator, a shop manager, a subscriber, two customers and a visitor. The settings page with all tabs and the add, edit and delete views; the order list (HPOS screen or `edit.php?post_type=shop_order`) with the custom statuses in its views, filter and bulk actions; the order screen with the Order Flow box; status moves through `admin-post.php`; PDF downloads by the administrator and by the owner of the order in My Account; export and import through the admin. Refusals are checked for a visitor, a subscriber, a shop manager, another customer, a missing nonce and a document of a status that is hidden from customers.
- **Deactivate**: the Deactivate screen with both choices. "Keep" must leave every order in its status and readable. "Move to On hold" runs through the progress page in batches, then the plugin is activated again and Action Scheduler (`wp action-scheduler run`) must restore every order to its status, with stock untouched and no marker left. (On a network only "keep" applies, network deactivation never moves orders.)
- **Uninstall**: deleting the plugin removes its own options, transients, scheduled jobs and temporary folders on every site, keeps options of other plugins with similar names and all WooCommerce data, and keeps every order (orders left in a custom status are moved to On hold with their old status saved).

The debug.log of each row must contain no notices, warnings, deprecations or fatal errors that point at this plugin. Lines that come from WordPress core, WooCommerce or other code are counted separately.

WooCommerce columns show the minimum supported release (the latest 9.0.x) and the newest release that officially installs on that WordPress and PHP combination (the newest 11.x where WordPress allows it). HPOS (custom order tables) is switched on and off in every row and its real state is checked before the plugin is tested.

Reproduce with `bash bin/matrix.sh` from the plugin repository (Docker and about 20 GB of disk are needed). Filters for a single row: `bash bin/matrix.sh --wp=7.1 --php=8.4 --woo=current --hpos=on`.

## Summary

86 of 86 rows passed.

## Matrix: WordPress x PHP x WooCommerce x HPOS

| WordPress | PHP | WooCommerce | HPOS | Unit | Integration | Smoke | Deactivate | Uninstall | debug.log plugin | debug.log other | Result |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 6.6.10 | 8.1 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.1 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.1 | 9.8.7 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.1 | 9.8.7 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.8.7 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.8.7 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.8.7 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.8.7 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.4 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 2167 | PASS |
| 6.6.10 | 8.4 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 1691 | PASS |
| 6.6.10 | 8.4 | 9.8.7 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 9 | PASS |
| 6.6.10 | 8.4 | 9.8.7 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 9 | PASS |
| 6.8.11 | 8.1 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 6.8.11 | 8.1 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 6.8.11 | 8.1 | 10.7.0 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.1 | 10.7.0 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.8.11 | 8.2 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 6.8.11 | 8.2 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 6.8.11 | 8.2 | 10.7.0 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.2 | 10.7.0 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.8.11 | 8.3 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 144 | PASS |
| 6.8.11 | 8.3 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 6.8.11 | 8.3 | 10.7.0 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.3 | 10.7.0 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.8.11 | 8.4 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 2241 | PASS |
| 6.8.11 | 8.4 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 1864 | PASS |
| 6.8.11 | 8.4 | 10.7.0 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.4 | 10.7.0 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.1 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 144 | PASS |
| 6.9.10 | 8.1 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 6.9.10 | 8.1 | 11.0.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.1 | 11.0.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.2 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 144 | PASS |
| 6.9.10 | 8.2 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 138 | PASS |
| 6.9.10 | 8.2 | 11.0.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.2 | 11.0.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.3 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 6.9.10 | 8.3 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 6.9.10 | 8.3 | 11.0.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.3 | 11.0.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.4 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 2239 | PASS |
| 6.9.10 | 8.4 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 1864 | PASS |
| 6.9.10 | 8.4 | 11.0.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.4 | 11.0.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.1 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 144 | PASS |
| 7.0.7 | 8.1 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 136 | PASS |
| 7.0.7 | 8.1 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.1 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.2 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 7.0.7 | 8.2 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 7.0.7 | 8.2 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.2 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.3 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 7.0.7 | 8.3 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 7.0.7 | 8.3 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.3 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.4 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 2242 | PASS |
| 7.0.7 | 8.4 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 1864 | PASS |
| 7.0.7 | 8.4 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.4 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.1 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 7.1.3 | 8.1 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 136 | PASS |
| 7.1.3 | 8.1 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.1 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.2 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 7.1.3 | 8.2 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 136 | PASS |
| 7.1.3 | 8.2 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.2 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.3 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 142 | PASS |
| 7.1.3 | 8.3 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 135 | PASS |
| 7.1.3 | 8.3 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.3 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.4 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 2239 | PASS |
| 7.1.3 | 8.4 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 1794 | PASS |
| 7.1.3 | 8.4 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.4 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 0 | PASS |

## PHP 8.5 (smoke)

| WordPress | PHP | WooCommerce | HPOS | Unit | Integration | Smoke | Deactivate | Uninstall | debug.log plugin | debug.log other | Result |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 7.1.3 | 8.5 | 9.0.4 (min) | on | PASS | PASS | PASS | PASS | PASS | 0 | 3375 | PASS |
| 7.1.3 | 8.5 | 9.0.4 (min) | off | PASS | PASS | PASS | PASS | PASS | 0 | 2400 | PASS |
| 7.1.3 | 8.5 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 1114 | PASS |
| 7.1.3 | 8.5 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 583 | PASS |

## Multisite (network activation)

| WordPress | PHP | WooCommerce | HPOS | Unit | Integration | Smoke | Deactivate | Uninstall | debug.log plugin | debug.log other | Result |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 7.1.3 (multisite, network activation) | 8.4 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | PASS | 0 | 3 | PASS |
| 7.1.3 (multisite, network activation) | 8.4 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | PASS | 0 | 1 | PASS |

The plugin is network-activated. Integration and smoke run on the main site and on a sub-site (a sub-directory network); the deactivation cycle runs on the sub-site; the uninstall check covers every site and the network tables.

## Plugin Check on the release zip

| WordPress | PHP | WooCommerce | Findings | Result |
|---|---|---|---|---|
| 7.1.3 | 8.4 | 11.2.1 | errors=0 warnings=0 | PASS |

Command: `wp plugin check order-flow-for-woocommerce --include-experimental --format=csv`, run against the plugin installed from the zip. Zero errors is required.

## Notes

WooCommerce selection:

- WordPress 6.6.10: WooCommerce 9.8.7 (newest 11.x needs WP 7.0 or newer; this is the newest release that installs on WP 6.6.10).
- WordPress 6.8.11: WooCommerce 10.7.0 (newest 11.x needs WP 7.0 or newer; this is the newest release that installs on WP 6.8.11).
- WordPress 6.9.10: WooCommerce 11.0.1 (newest 11.x needs WP 7.0 or newer; this is the newest release that installs on WP 6.9.10).

Third-party noise: debug.log lines from WordPress core, WooCommerce and other code ranged from 0 to 3375 per row. They are listed per row above and are not counted against the plugin.

No failures or error-severity checks were recorded.

