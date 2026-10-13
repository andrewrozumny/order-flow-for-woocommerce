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
* Count as paid: WooCommerce treats the order as paid and includes it in its reports. A status that is not paid is kept out of the revenue in WooCommerce Analytics.
* Reduce stock when an order enters the status.
* Show to the customer in My Account under its own name, or as "Processing" when it is an internal step. The customer sees "Processing" on the shop pages, in the REST and Store APIs and in the emails sent to the customer. People who can edit orders see the real label. The status slug itself is not hidden in API responses.
* Registered as real WooCommerce order statuses, so they work in filters, reports and bulk actions.
* Texts have a length limit so the settings stay small: 2,000 characters for the label, the email subject and heading and the document title; 100,000 characters for the email text, the document text and the store details. A longer text is refused with a message that names the field.

*Transition rules*

* A matrix of "from > to" with the roles allowed to make each move and an optional required comment.
* Transitions between WooCommerce's own statuses are not restricted.
* A move into or out of a custom status needs a matching rule for the user's role. Administrators (users with the manage_options capability) can always make any change, so the role limits control shop managers and other roles. A transition marked "comment required" is different: it needs the comment from everybody, administrators included.
* Creating a new order straight into a custom status is a move too: it is checked like a move out of "new".
* Where the rules apply: every status change a logged-in person makes in the browser. That is the Order Flow box, the status dropdown on the order screen, the bulk actions (WooCommerce's own "Change status to ..." as well as the Order Flow ones), the status buttons in the order list and in the preview window, a new order created in wp-admin, and the REST API when it is used from the browser with the login cookie. A change that is refused leaves the order in its old status and shows a notice. Buttons and bulk actions that cannot be used are not shown.
* Where the rules do not apply: payment gateways and webhooks, cron and Action Scheduler, WP-CLI, REST requests that authenticate with an API key or an application password, and the plugin's own work (parking and restoring orders, deleting a status).
* A full refund moves the order to Refunded whatever the rules say. The money is already back with the customer by then.
* Bulk actions skip orders whose transition needs a comment and tell you which ones.
* One change at a time: while a person changes an order, a second change of the same order made at that moment waits a few seconds and is then checked against the new status. A repeated click or a second tab cannot send the same email twice or leave the status and the history disagreeing.

*History*

* Every status change is saved on the order: who, when, from, to and the comment. This includes the changes WooCommerce makes itself, such as a paid checkout.
* A change that involves a custom status, or that has a comment, is also written as a private order note. WooCommerce already notes the changes between its own statuses. A table of the full history is shown in the Order Flow box.

*Emails*

* One WooCommerce email per custom status, to the customer, to the shop admin, or both.
* Subject, heading and text are set per status, with placeholders: {order_number}, {status}, {customer_name}, {note}, {order_date}, {site_title}, {order_total}.
* The comment typed with a status change is for your team. The shop admin email always gets it. The customer email gets it (in {note}) only if you tick "Send this comment to the customer" for that change. The box is off by default.
* Emails appear under WooCommerce > Settings > Emails, so you can switch them on or off like any other email.
* The email template can be overridden in your theme.

*PDF documents*

* A PDF per status, such as an approval sheet or a work order: logo, store details, items, totals and free text.
* Download button on the order screen and in My Account.
* Optional: attach the PDF to the status email.
* The PDF template can be overridden in your theme.

*Configuration*

* Only administrators (the manage_options capability) can open WooCommerce > Order Flow and change the statuses, rules, documents and imports. Shop managers can work on orders within the rules. A filter hands the settings to another capability.
* Export the whole configuration (statuses, rules and document settings) as JSON, and import it on another site, either merged into what you have or replacing it.
* Safe deactivation: when you deactivate the plugin from the Plugins screen you choose to keep the statuses as they are, or move the orders in custom statuses to On hold. Orders in On hold go back to their status when you reactivate.
* Large shops are fine: moves run in batches with a progress page, and in the background after reactivation.
* A status that still has orders cannot be deleted until you choose a status to move them to. The orders move in batches, without emails to customers (unless you ask for them) and without touching stock, and the status disappears after the last order has moved.

**Extending**

Order Flow has hooks for developers.

Actions and filters:

* `ofwc_status_entered` (action): fires after a change into a custom status is recorded. Arguments: the order, the new status slug, the previous status slug, the comment.
* `ofwc_email_attachments` (filter): the files attached to a status email. Arguments: the list of file paths, the order, the status slug.
* `ofwc_pdf_document_data` (filter): the data passed to the PDF template, so you can add your own fields.
* `ofwc_admin_tabs` (filter): add your own tab under WooCommerce > Order Flow.
* `ofwc_mask_status_labels` (filter): whether the current request shows "Processing" instead of the label of a status that is not shown to customers.
* `ofwc_manage_capability` (filter): the capability needed to configure Order Flow. Default `manage_options`. Moving orders keeps following the rules and `edit_shop_orders`.
* `ofwc_enforce_transition_rules` (filter): whether the rules apply to the status change being saved. Arguments: the default answer, the order.
* `ofwc_actor_is_person` (filter): whether a request counts as a person in the browser (rules apply) or as the system (they do not).
* `ofwc_exclude_unpaid_from_analytics` (filter): return false to let orders in unpaid custom statuses count in WooCommerce Analytics.
* `ofwc_is_pro` (filter): returns false in the free plugin. Used to switch on add-on features.
* `ofwc_batch_budget` (filter): seconds one request may spend on a batch of bulk order work (moving orders when you deactivate the plugin, restoring them, deleting a status). Default 18.

Template overrides, in your theme or child theme:

* `yourtheme/woocommerce/emails/ofwc-status.php` and `yourtheme/woocommerce/emails/plain/ofwc-status.php` for the email.
* `yourtheme/order-flow-for-woocommerce/pdf/document.php` for the PDF.

**Roadmap**

Ideas for later versions: automatic transitions by time and events, approvals by several roles, document numbering, and several templates per status. Tell me what you need at [helpmybiz.org](https://helpmybiz.org).

**Third-party libraries**

The PDF documents are generated by [dompdf](https://github.com/dompdf/dompdf) and its dependencies, which are bundled in the plugin's vendor-prefixed folder. Their classes are copied into the OrderFlowWC\Vendor namespace, so they never clash with another plugin that bundles the same libraries:

* dompdf/dompdf 3.1.6, LGPL-2.1: https://github.com/dompdf/dompdf
* dompdf/php-font-lib 1.0.2, LGPL-2.1-or-later: https://github.com/dompdf/php-font-lib
* dompdf/php-svg-lib 1.0.2, LGPL-3.0-or-later: https://github.com/dompdf/php-svg-lib
* masterminds/html5 2.11.0, MIT: https://github.com/Masterminds/html5-php
* sabberworm/php-css-parser 9.5.0, MIT: https://github.com/MyIntervals/PHP-CSS-Parser

The LGPL and MIT licenses are compatible with the GPL. The plugin itself is licensed under GPL-2.0-or-later, and the combined work is distributed under the terms of the GPL (version 3 for the part that includes the LGPL-3.0-or-later library). The source code of each library is available at the addresses above and in the plugin's vendor-prefixed folder.

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

It depends on how you deactivate it. Orders are never deleted.

* From the Plugins screen, with the Deactivate link or the bulk action: if orders are in a custom status you choose. You can keep them as they are (WooCommerce does not list such orders while the plugin is inactive, they come back when you activate it again), or move them to On hold. Moving adds a note to every order and changes nothing else: no emails, no stock changes, no sales or coupon counts. A shop with many orders sees a progress page, and the plugin is deactivated after the last order.
* With WP-CLI, a deployment script, a hosting panel or a network deactivation: no order is touched and the statuses stay in the database. WP-CLI prints a warning with the number of orders.
* When you activate the plugin again, an order that is still in On hold and was moved by the plugin goes back to its status, in the background. An order whose status changed in the meantime keeps its new status and gets a note.

= What happens if I delete the plugin? =

Orders that are still in one of its statuses are moved to On hold, with the old status saved in the order meta and a note on the order, so they stay visible in WooCommerce. No emails are sent and stock does not change. The plugin then removes its options, temporary files, font cache and scheduled tasks. The history of each order (order notes and the history meta) stays, because it is data of your shop. The plugin's settings and statuses are gone, so export the configuration first if you want to keep it.

= How large can a PDF document be? =

It depends on the memory PHP gives the request. WordPress raises the limit of an admin request to 256 MB where the host allows it. Measured with WordPress 7.1 and PHP 8.4: with 256 MB an order with about 640 lines (46 pages) is made, with a hard limit of 128 MB about 330 lines (24 pages). The status text, at its limit of 100,000 characters, adds up to about 40 pages and fits in 128 MB. A document that would not fit is not attempted: the download page says that the order or the text is too large for the memory of the server and what to do (ask the host to raise `memory_limit`, or shorten the text), and a status email goes out without the PDF with a note on the order. The estimate errs on the side of refusing, so the server does not run out of memory half way through.

= Is the history safe when two changes happen at the same moment? =

The history of an order is stored as one list in the order's meta data, so two writers that save the same order at the very same moment can overwrite each other's row. For changes made through Order Flow (its box, its buttons, the bulk actions) this cannot happen: one change at a time is let through for an order. A payment gateway, an import or another plugin that changes the same order in the same second is outside Order Flow's control; WooCommerce itself does not lock orders either. The status change itself is never lost, only a history row can be.

= Where do the temporary PDF files go? =

A PDF attached to an email is written to the temporary folder of your server (outside the web root) and deleted as soon as the email has been sent or has failed. If that folder is not writable, `uploads/ofwc-tmp` is used and protected against direct access. Anything older than a day is removed by a daily task. If no folder can be written, the email is sent without the PDF and a note on the order says why. The font cache of the PDF library lives in `uploads/ofwc-fonts`.

= Can I limit what shop managers can do? =

Yes. In the Transitions tab you choose, for each "from > to" move, which roles may make it, and you can require a comment. Administrators can make any change, but a required comment applies to them too. The rules apply to every change a logged-in person makes in the browser: the order screen, bulk actions, the buttons in the order list, new orders and the REST API with the login cookie. They do not apply to payment gateways, webhooks, cron, WP-CLI, API keys and application passwords, and not to a full refund that moves the order to Refunded. Shop managers cannot change the statuses or the rules themselves: that needs the manage_options capability.

= Does the customer see my comment? =

Only if you tick "Send this comment to the customer" next to the comment, and the status sends an email to the customer. Without the tick, the comment stays in the order history and in the email to the shop admin.

= Does a status that is not paid count in WooCommerce Analytics? =

No. Orders in a custom status without "Count as paid" are kept out of the revenue in WooCommerce Analytics. Your own Analytics settings are not changed. If you want such orders to count, use the `ofwc_exclude_unpaid_from_analytics` filter or switch on "Count as paid".

= Can I change the email texts? =

Yes. The subject, heading and text of each status email are edited per status under WooCommerce > Order Flow, with placeholders such as {order_number} and {customer_name}. You can also override the template file in your theme at `yourtheme/woocommerce/emails/ofwc-status.php`.

= Can I edit the PDF template? =

You can set the title, the logo, the store details and the free text per status in the plugin. For full control, copy the template to `yourtheme/order-flow-for-woocommerce/pdf/document.php` and edit it, and use the `ofwc_pdf_document_data` filter to add data.

= Will it slow down my site? =

No. Statuses and rules are small settings loaded with the rest of your options. PDF files are made only when someone downloads one or when a status email with an attachment is sent. On the front of the shop the plugin shows the status name. When an order changes status, for example at checkout, it also saves one small history entry on that order.

= Does it work on multisite? =

Yes, per site and network-wide. Each site has its own statuses, rules and history. Use export and import to copy the configuration between sites. When you activate the plugin for the whole network, every site restores its parked orders in the background the next time an administrator opens its dashboard. When you deactivate or delete it for the whole network, no order is moved on deactivation, and on deletion every site is cleaned up.

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
* Transition rules by role with optional required comment, enforced for every change a person makes in the browser.
* Transition history on every order, as a table in the Order Flow box and as private order notes for changes that involve a custom status or carry a comment.
* A WooCommerce email per status for the customer and the admin, with placeholders and a theme-overridable template.
* A PDF document per status with download on the order screen and in My Account, optional email attachment and a theme-overridable template.
* Import and export of the configuration as JSON.
* Safe deactivation and guarded deletion of statuses that have orders.
* HPOS compatible.

== Upgrade Notice ==

= 0.1.0 =
First release.
