=== Order Flow for WooCommerce ===
Contributors: andrewrozumny
Tags: woocommerce, order status, custom order status, pdf, workflow
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your own WooCommerce order statuses, rules for who can move an order where, a status history, an email and a PDF document per status.

== Description ==

Order Flow for WooCommerce lets you build the order workflow your shop really has. You add your own statuses, decide who may move an order from one status to another, and attach an email and a PDF document to each step. Everything is managed under WooCommerce > Order Flow.

It is made for B2B stores, made-to-order shops and service businesses, where an order is not just "processing" and then "completed". A typical flow:

*Awaiting approval > Approved > In production > Ready to ship*

* "Awaiting approval": the customer is told the order is under review. Only a shop manager can approve it.
* "Approved": the customer gets an email with an approval sheet (PDF) attached.
* "In production": counts as paid, stock is reduced, the customer sees the status in My Account.

Every change is recorded on the order: who made it, when, from which status to which, and an optional comment.

The plugin works with High-Performance Order Storage (HPOS) and uses only the WooCommerce order API. No external services are used and no data leaves your site.

**How it works**

1. Add your statuses under WooCommerce > Order Flow > Statuses.
2. Open the Transitions tab and tick which roles may move an order from one status to another. Mark transitions that need a comment.
3. Write the email text and the PDF text for each status.
4. Work as usual. On the order screen, the Order Flow box shows the buttons you may use, the history of the order and the documents you can download.

**Features**

*Custom statuses*

* Label, color and icon for every status, shown in the order list.
* Count as paid: reports and WooCommerce treat the order as paid.
* Reduce stock when an order enters the status.
* Show to the customer in My Account under its own name, or as "Processing" when it is an internal step.
* Registered as real WooCommerce order statuses, so they work in filters, reports and bulk actions.

*Transition rules*

* A matrix of "from > to" with the roles allowed to make each move and an optional required comment.
* Transitions between WooCommerce's own statuses are not restricted.
* A move into or out of a custom status needs a matching rule for the user's role. Administrators can always make any change, so the rules control shop managers and other roles.
* Rules are enforced for people working in wp-admin: the Order Flow box buttons, the status dropdown on the order screen and bulk actions. Changes made by payment gateways, the REST API, cron or WP-CLI are not blocked.
* Bulk actions skip orders whose transition needs a comment and tell you which ones.

*History*

* Every status change is saved on the order: who, when, from, to and the comment.
* Each change is also written as a private order note, and a table of the full history is shown in the Order Flow box.

*Emails*

* One WooCommerce email per custom status, to the customer, to the shop admin, or both.
* Subject, heading and text are set per status, with placeholders: {order_number}, {status}, {customer_name}, {note}, {order_date}, {site_title}, {order_total}.
* Emails appear under WooCommerce > Settings > Emails, so you can switch them on or off like any other email.
* The email template can be overridden in your theme.

*PDF documents*

* A PDF per status, such as an approval sheet or a work order: logo, store details, items, totals and free text.
* Download button on the order screen and in My Account.
* Optional: attach the PDF to the status email.
* The PDF template can be overridden in your theme.

*Configuration*

* Export the whole configuration (statuses, rules and document settings) as JSON, and import it on another site, either merged into what you have or replacing it.
* Safe deactivation: when you deactivate the plugin you choose to keep the statuses as they are, or move the orders in custom statuses to On hold. Orders are put back when you reactivate.
* A status that still has orders cannot be deleted until you choose a status to move them to.

**Extending**

Order Flow has hooks for developers.

Actions and filters:

* `ofwc_status_entered` (action): fires after a change into a custom status is recorded. Arguments: the order, the new status slug, the previous status slug, the comment.
* `ofwc_email_attachments` (filter): the files attached to a status email. Arguments: the list of file paths, the order, the status slug.
* `ofwc_pdf_document_data` (filter): the data passed to the PDF template, so you can add your own fields.
* `ofwc_admin_tabs` (filter): add your own tab under WooCommerce > Order Flow.
* `ofwc_mask_status_labels` (filter): whether customers see "Processing" instead of the label of a status that is not shown in My Account.
* `ofwc_is_pro` (filter): returns false in the free plugin. Used to switch on add-on features.

Template overrides, in your theme or child theme:

* `yourtheme/woocommerce/emails/ofwc-status.php` and `yourtheme/woocommerce/emails/plain/ofwc-status.php` for the email.
* `yourtheme/order-flow-for-woocommerce/pdf/document.php` for the PDF.

**Roadmap**

Ideas for later versions: automatic transitions by time and events, approvals by several roles, document numbering, and several templates per status. Tell me what you need at [helpmybiz.org](https://helpmybiz.org).

**Third-party libraries**

The PDF documents are generated by [dompdf](https://github.com/dompdf/dompdf) and its dependencies, which are bundled in the plugin's vendor folder:

* dompdf/dompdf 3.1.6, LGPL-2.1: https://github.com/dompdf/dompdf
* dompdf/php-font-lib 1.0.2, LGPL-2.1-or-later: https://github.com/dompdf/php-font-lib
* dompdf/php-svg-lib 1.0.2, LGPL-3.0-or-later: https://github.com/dompdf/php-svg-lib
* masterminds/html5 2.11.0, MIT: https://github.com/Masterminds/html5-php
* sabberworm/php-css-parser 9.5.0, MIT: https://github.com/MyIntervals/PHP-CSS-Parser

The LGPL and MIT licenses are compatible with the GPL. The plugin itself is licensed under GPL-2.0-or-later, and the combined work is distributed under the terms of the GPL (version 3 for the part that includes the LGPL-3.0-or-later library). The source code of each library is available at the addresses above and in the plugin's vendor folder.

dompdf bundles the DejaVu fonts (version 2.34), which are distributed under the free Bitstream Vera / DejaVu font license, and the Adobe core 14 AFM metric files, which may be used and distributed freely.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin folder to `/wp-content/plugins/`, or install it from Plugins > Add New.
3. Activate the plugin.
4. Open WooCommerce > Order Flow and add your first status.

== Frequently Asked Questions ==

= Is it compatible with HPOS? =

Yes. The plugin declares compatibility with High-Performance Order Storage and reads and writes orders only through the WooCommerce order API. It also works with the legacy order screens.

= What happens if I deactivate the plugin? =

You choose. You can keep the custom statuses as they are, or move the orders that are in a custom status to On hold. If you move them, the plugin remembers where they were and puts them back when you reactivate it. Orders are never deleted.

= Can I limit what shop managers can do? =

Yes. In the Transitions tab you choose, for each "from > to" move, which roles may make it, and you can require a comment. Administrators can always make any change. The rules apply to people working in wp-admin. Changes made by payment gateways, the REST API, cron or WP-CLI are not blocked.

= Can I change the email texts? =

Yes. The subject, heading and text of each status email are edited per status under WooCommerce > Order Flow, with placeholders such as {order_number} and {customer_name}. You can also override the template file in your theme at `yourtheme/woocommerce/emails/ofwc-status.php`.

= Can I edit the PDF template? =

You can set the title, the logo, the store details and the free text per status in the plugin. For full control, copy the template to `yourtheme/order-flow-for-woocommerce/pdf/document.php` and edit it, and use the `ofwc_pdf_document_data` filter to add data.

= Will it slow down my site? =

No. Statuses and rules are small settings loaded with the rest of your options. PDF files are made only when someone downloads one or when a status email with an attachment is sent. Nothing runs on the front of the shop except showing the status name.

= Does it work on multisite? =

Each site has its own statuses, rules and history. Activate it on the sites that need it, and use export and import to copy the configuration between sites.

== Screenshots ==

1. The list of your statuses under WooCommerce > Order Flow.
2. The transition rules matrix: who may move an order from one status to another.
3. The order screen with the Order Flow box and the history of the order.
4. The PDF document for a status.
5. The status email in the customer's inbox.

== Changelog ==

= 0.1.0 =
* First release.
* Custom order statuses with label, color, icon, paid, stock and My Account options.
* Transition rules by role with optional required comment, enforced in wp-admin.
* Transition history on every order, as order notes and a table in the Order Flow box.
* A WooCommerce email per status for the customer and the admin, with placeholders and a theme-overridable template.
* A PDF document per status with download on the order screen and in My Account, optional email attachment and a theme-overridable template.
* Import and export of the configuration as JSON.
* Safe deactivation and guarded deletion of statuses that have orders.
* HPOS compatible.

== Upgrade Notice ==

= 0.1.0 =
First release.
