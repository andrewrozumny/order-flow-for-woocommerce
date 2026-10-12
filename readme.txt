=== Order Flow for WooCommerce ===
Contributors: andrewrozumny
Tags: woocommerce, order status, custom order status, workflow, orders
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom WooCommerce order statuses with colors, icons, paid and stock flags, plus status emails and PDF documents per status.

== Description ==

Order Flow for WooCommerce lets you add your own order statuses and decide what each one means for your shop. Everything is managed under WooCommerce > Order Flow.

**What a status can do**

* Have its own label, color and icon in the order list.
* Count as paid, so reports and WooCommerce treat the order as paid.
* Reduce stock when an order enters it.
* Be shown to customers under its own name, or as "Processing" when it is an internal step.
* Send an email to the customer and to the shop admin, with texts you write per status.
* Produce a PDF document for the order, such as an approval sheet.

The plugin is compatible with High-Performance Order Storage (HPOS) and only uses the WooCommerce order API.

**Who can change a status**

A change that does not involve a custom status behaves like in plain WooCommerce. A change into or out of a custom status needs a matching transition rule for the user's role. Administrators can always make any change. Rules apply to people working in wp-admin; changes made by payment gateways, the REST API, cron or WP-CLI are not blocked.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin and activate it.
3. Open WooCommerce > Order Flow and add your first status.

== Changelog ==

= 0.1.0 =
* First development release: custom statuses with color, icon, paid and stock flags.
