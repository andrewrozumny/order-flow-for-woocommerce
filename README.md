# Order Flow for WooCommerce

Your own WooCommerce order statuses, rules for who can move an order where, a history of every change, and an email and a PDF document for each status.

![Statuses list](.wordpress-org/screenshot-1.png)

Order Flow is for stores where an order is more than "processing" and "completed": B2B shops, made-to-order stores and service businesses. A typical flow is Awaiting approval, then Approved, then In production. You decide who can approve, what the customer is told, and which document goes out at each step.

Requires WordPress 6.6+, PHP 8.1+, WooCommerce 9.0+. Tested up to WordPress 7.1 and WooCommerce 11.2. Compatible with HPOS.

## What it does

| Area | What you get |
| --- | --- |
| Statuses | Label, color and icon. Count as paid (unpaid statuses stay out of Analytics revenue), reduce stock, show to the customer under its own name or as "Processing" |
| Transition rules | A matrix of "from > to" with the roles allowed to make each move and an optional required comment |
| History | Who, when, from, to and comment for every change, as a table in the Order Flow box, and as order notes for changes that involve a custom status or carry a comment |
| Emails | One WooCommerce email per status for the customer and/or admin, with placeholders `{order_number}` `{status}` `{customer_name}` `{note}` `{order_date}` `{site_title}` `{order_total}` |
| PDF documents | A document per status with logo, store details, items, totals and free text. Download on the order screen and in My Account, optional email attachment |
| Configuration | Import and export as JSON (merge or replace) |
| Safety | Deactivation from the Plugins screen keeps the statuses or moves orders to On hold (in batches, with no emails and no stock changes) and restores them later. Other ways to deactivate never touch orders. Deleting the plugin parks orders in On hold and cleans up its data. A status with orders cannot be deleted without a target status |

### Transition policy

- A move between WooCommerce's own statuses is not restricted.
- A move into or out of a custom status needs a rule that matches the user's role. Administrators (`manage_options`) can always make any change, so rules control shop managers and other roles. A new order created straight into a custom status is checked as a move out of "new".
- **Where the rules apply:** every status change a logged-in person makes in the browser. The Order Flow box, the status dropdown, WooCommerce's own bulk actions and the Order Flow ones, the status buttons in the order list and the preview window, new orders created in wp-admin, and the REST API used with the login cookie. A refused change leaves the old status and shows a notice. Buttons and bulk actions that cannot be used are not shown.
- **Where they do not:** payment gateways and webhooks, cron and Action Scheduler, WP-CLI, REST with an API key or an application password, and the plugin's own work (parking and restoring orders, deleting a status). A full refund that moves the order to Refunded is not refused either, because the money is already back.
- Only administrators can open the Order Flow settings. The `ofwc_manage_capability` filter hands that to another capability.
- The comment of a status change is for the team. The customer email gets it only when "Send this comment to the customer" is ticked.

## Privacy

No external services. No data leaves your site.

## Install

From a zip: download the release zip, then Plugins > Add New > Upload Plugin, activate, and open WooCommerce > Order Flow.

From source:

```bash
git clone https://github.com/andrewrozumny/order-flow-for-woocommerce.git
cd order-flow-for-woocommerce
composer install
```

dompdf generates the PDF documents. `composer install` copies it, with its dependencies, into `vendor-prefixed/` under the `OrderFlowWC\Vendor\` namespace (Strauss, see `bin/prefix.sh`), so it cannot clash with another plugin that bundles the same libraries. Run `composer install` with the development packages when you run the plugin from a git clone. To build an installable zip (with `vendor-prefixed/` inside, without `vendor/`):

```bash
bash bin/build-zip.sh   # writes dist/order-flow-for-woocommerce-<version>.zip
```

## Extending

Run your own code when an order enters a custom status:

```php
add_action(
	'ofwc_status_entered',
	static function ( WC_Order $order, string $to, string $from, string $comment ): void {
		if ( 'in-production' === $to ) {
			$order->update_meta_data( '_my_production_started', time() );
			$order->save();
		}
	},
	10,
	4
);
```

Other hooks: `ofwc_email_attachments`, `ofwc_pdf_document_data`, `ofwc_admin_tabs`, `ofwc_mask_status_labels`, `ofwc_manage_capability`, `ofwc_enforce_transition_rules`, `ofwc_actor_is_person`, `ofwc_exclude_unpaid_from_analytics`, `ofwc_is_pro`, `ofwc_batch_budget` (seconds one request spends on a batch of bulk order work, default 18).

Template overrides in your theme:

- `yourtheme/woocommerce/emails/ofwc-status.php` (and `emails/plain/ofwc-status.php`)
- `yourtheme/order-flow-for-woocommerce/pdf/document.php`

## Development

```bash
composer install
composer lint              # PHPCS (WordPress Coding Standards)
composer analyse           # PHPStan
composer test              # PHPUnit unit suite
composer test:integration  # integration tests against a WordPress install
bash bin/gates.sh          # all quality gates in order
bash bin/build-zip.sh      # builds dist/order-flow-for-woocommerce-<version>.zip
```

## License

GPL-2.0-or-later. See [license](https://www.gnu.org/licenses/gpl-2.0.html).

Third-party libraries bundled for PDF generation: dompdf/dompdf (LGPL-2.1), dompdf/php-font-lib (LGPL-2.1-or-later), dompdf/php-svg-lib (LGPL-3.0-or-later), masterminds/html5 (MIT), sabberworm/php-css-parser (MIT). They are GPL-compatible; the combined work is distributed under GPL terms.

Author: Andrew Rozumny, [helpmybiz.org](https://helpmybiz.org).
