# Performance on a large store

Order Flow runs inside the admin of a shop that may hold tens of thousands of orders. Three kinds of work matter: pages that list or show orders,
a PDF for an order, and the batch jobs that move many orders at once (deleting a status, and the "move to On hold" choice when the plugin is
deactivated, with the restore after activation). This document records what they cost on a big shop at the tightest common limits
(`memory_limit=128M`, `max_execution_time=30`), and where the plugin does more work than it should.

## Conditions

| | |
|---|---|
| Software | WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.4, MySQL 8.4 (InnoDB buffer pool 128 MB, smaller than the biggest tables, so reads are partly cold) |
| Limits | `memory_limit=128M`, `max_execution_time=30`; `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT` both 128M, so WordPress does not raise the limit |
| Active plugins | WooCommerce and Order Flow only (installed from the release zip) |
| Object cache | none |
| Signed in as | the first administrator (the My Account steps: the customer who owns the orders) |

How a step is measured. The pages are requests to PHP's built-in web server on the loopback address, started with the limits above, so
each request is one PHP process like on a real host. A small helper loaded as a must-use plugin (`bin/perf-mu.php`, not shipped) signs the
request in as the chosen user and records, in a shutdown function, the time, the peak memory (`memory_get_peak_usage(true)`), the number of
queries and any fatal error. Read-only steps run a second time with `SAVEQUERIES` for the query analysis: the slowest query, the repeated
query shapes, and an `EXPLAIN` of every distinct `SELECT`. A full table scan means `type=ALL` on a table with more than 10,000 rows. A repeated
shape means the same query with different values more than 10 times inside one step. Changing steps (a status change, the bulk action, the
deletion, the deactivation) run once and are not re-run with `SAVEQUERIES`, which would change their memory use. The background jobs are
Action Scheduler queue runner requests (`admin-ajax.php`, the way WordPress starts them), each under the same limits. One runner request may run
several of the plugin's batches, because Action Scheduler resets the PHP time limit for each action, so a runner request can be longer than 30 s
without being killed.

## Data set

Built once and cached, then restored for later runs. A few template records are created through the WooCommerce API so every row has the
shape WooCommerce writes, then multiplied with `INSERT ... SELECT` straight into the real tables.

- 10,000 products and 50,025 orders with 1 to 5 items each (173,302 items), mixed core statuses, spread over two years.
- 20,000 of the orders are in three Order Flow statuses: 12,000 in `of-review`, 5,000 in `of-proof` (a status with a PDF document), 3,000 in `of-ready`.
- 508 users: 5 administrators, a shop manager, 502 customers. One customer owns 501 orders.
- One order has a status history of 200 changes. One order has 300 line items with long names; its PDF has 39 pages.
- Orders are stored twice, in two databases: HPOS on (`wc_orders` and related tables, with the placeholder posts WooCommerce keeps) and HPOS off
  (posts and postmeta, data sync off). The two are generated separately, so each is exactly what that storage mode produces.

For the deletion and the deactivation the whole group of 20,000 orders is first put into one status (`of-review` for the deletion, `of-proof`
for the deactivation), by SQL, so each of those steps works on 20,000 orders.

## Results

<!-- perf:results:start -->
**Result: 0 timeouts at 30 s, 0 memory errors at 128M, 0 errors in 58 measured steps.**

Environment: PHP 8.4.26, MySQL 8.4.11 (InnoDB buffer pool 128 MB), WordPress 7.1.3, WooCommerce 11.2.1. Plugin zip fa98f01d35f6. Run time 1413 s.

### HPOS on (orders in the dedicated tables)

Data set: 10,000 products, 50,025 orders (20,000 of them in three Order Flow statuses), 173,302 order items, 508 users (5 administrators, 502 customers). One customer has 501 orders, one order has a 200-entry status history, one order has 300 items (PDF of 39 pages).

| Step | Time ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans (plugin) | N+1 shapes (plugin) | Note |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| boot (WordPress + admin) | 1538 | 18 |  | 105 |  |  | 0 (0) | 0 (0) |  |
| order list: all orders, status counters | 816 | 24 | 6 | 184 | 34.9 | 7.6 | 0 (0) | 3 (0) |  |
| order list: filtered by a custom status (12,000 orders) | 338 | 20 | 2 | 146 | 21.4 | 7.56 | 0 (0) | 3 (0) |  |
| order list: filtered by a custom status, page 40 | 349 | 16 | 0 | 146 | 34.3 | 8.54 | 0 (0) | 3 (0) |  |
| order list: filtered by another custom status (5,000) | 329 | 16 | 0 | 146 | 26.1 | 8.62 | 0 (0) | 3 (0) |  |
| order screen: normal order | 311 | 16 | 0 | 146 | 19.9 | 7.59 | 0 (0) | 2 (0) |  |
| order screen: history with 200 transitions | 285 | 16 | 0 | 152 | 19.4 | 7.53 | 0 (0) | 2 (0) |  |
| settings tab: statuses | 385 | 16 | 0 | 113 | 28.5 | 8.62 | 0 (0) | 2 (0) |  |
| settings tab: transitions | 308 | 16 | 0 | 106 | 15.8 | 7.49 | 0 (0) | 2 (0) |  |
| settings tab: documents | 355 | 16 | 0 | 108 | 16 | 7.41 | 0 (0) | 2 (0) |  |
| settings tab: import-export | 315 | 16 | 0 | 106 | 17.2 | 7.79 | 0 (0) | 2 (0) |  |
| My Account: orders list, page 1 of the customer with 501 orders | 371 | 24 | 6 | 125 | 31.3 | 7.61 | 0 (0) | 2 (0) |  |
| My Account: orders list, last page (51) | 302 | 20 | 2 | 96 | 17.2 | 7.54 | 0 (0) | 2 (0) |  |
| My Account: one order with its PDF link | 275 | 14 | 0 | 101 | 8.8 | 0.24 | 0 (0) | 2 (0) |  |
| PDF: a normal order | 358 | 30 | 12 | 82 | 7 | 0.3 | 0 (0) | 2 (0) | PDF ok, 1 page(s), 23 KB |
| **PDF: order with 300 items** | 1814 | 118 | 100 | 979 | 70.3 | 4.4 | 0 (0) | 5 (2) | PDF ok, 39 page(s), 90 KB |
| export configuration (JSON) | 230 | 12 | 0 | 68 | 5.2 | 0.23 | 0 (0) | 2 (0) |  |
| import configuration (replace, same file) | 273 | 10 | 0 | 71 |  |  | 0 (0) | 0 (0) |  |
| status change by the administrator (admin-post) | 249 | 10 | 0 | 129 |  |  | 0 (0) | 0 (0) |  |
| status change by a shop manager under a rule (admin-post) | 286 | 10 | 0 | 129 |  |  | 0 (0) | 0 (0) |  |
| bulk action on 20 orders (administrator) | 1156 | 16 | 0 | 1436 |  |  | 0 (0) | 0 (0) | 20 of 20 moved |
| **delete a status with 20,000 orders: the request itself** | 18276 | 48 | 30 | 140100 |  |  | 0 (0) | 0 (0) | first request moved 2,124 orders; location /wp-admin/admin.php?page=ofwc&tab=statuses |
| **delete a status: background jobs (worst of 28)** | 24732 | 18 | 0 | 136901 |  |  | 0 (0) | 0 (0) | 28 Action Scheduler jobs, 201 s in total, 20,000 orders in On hold |
| deactivate screen with 20,000 orders in custom statuses | 940 | 16 | 0 | 144 |  |  | 0 (0) | 0 (0) |  |
| deactivate: choose "move to On hold" | 250 | 14 | 0 | 68 |  |  | 0 (0) | 0 (0) |  |
| **deactivate: each batch of the progress page (worst of 12)** | 18288 | 16 | 0 | 135528 |  |  | 0 (0) | 0 (0) | 12 requests, 209 s in total, 20,000 of 20,000 orders in On hold |
| activate again (WP-CLI, not measured against the limits) | 673 | 0 | 0 |  |  |  | 0 (0) | 0 (0) |  |
| first admin page after activation (queues the restore) | 315 | 16 | 0 | 123 |  |  | 0 (0) | 0 (0) |  |
| **restore after activation: background jobs (worst of 38)** | 24240 | 14 | 0 | 129311 |  |  | 0 (0) | 0 (0) | 38 Action Scheduler jobs, 240 s in total, 20,000 of 20,000 orders back |

Highlighted (time over 5 s, or more than 64 MB used by the step itself on top of its own boot): PDF: order with 300 items (1.8 s, 118 MB, +100 MB); delete a status with 20,000 orders: the request itself (18.3 s, 48 MB, +30 MB); delete a status: background jobs (worst of 28) (24.7 s, 18 MB, +0 MB); deactivate: each batch of the progress page (worst of 12) (18.3 s, 16 MB, +0 MB); restore after activation: background jobs (worst of 38) (24.2 s, 14 MB, +0 MB).

Slowest step: delete a status: background jobs (worst of 28), 24.73 s. Highest peak: PDF: order with 300 items, 118 MB. WordPress with WooCommerce and the admin includes alone (boot): 18 MB, 1.54 s. Whole pass: 702 s.

Largest tables: wp_woocommerce_order_itemmeta 1,518,791 rows (166 MB), wp_postmeta 180,047 rows (22 MB), wp_woocommerce_order_items 174,956 rows (17 MB), wp_wc_orders_meta 118,969 rows (61 MB), wp_wc_order_addresses 99,459 rows (26 MB), wp_posts 58,003 rows (33 MB), wp_wc_order_operational_data 49,742 rows (12 MB), wp_wc_orders 49,476 rows (36 MB).

### HPOS off (orders in posts and postmeta, sync off)

Data set: 10,000 products, 50,025 orders (20,000 of them in three Order Flow statuses), 173,302 order items, 508 users (5 administrators, 502 customers). One customer has 501 orders, one order has a 200-entry status history, one order has 300 items (PDF of 39 pages).

| Step | Time ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans (plugin) | N+1 shapes (plugin) | Note |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| boot (WordPress + admin) | 1671 | 18 |  | 105 |  |  | 0 (0) | 0 (0) |  |
| order list: all orders, status counters | 1036 | 18 | 0 | 229 | 82.5 | 24.79 | 0 (0) | 5 (0) |  |
| order list: filtered by a custom status (12,000 orders) | 392 | 18 | 0 | 179 | 62.4 | 23.81 | 0 (0) | 5 (0) |  |
| order list: filtered by a custom status, page 40 | 454 | 18 | 0 | 219 | 66.3 | 23.69 | 0 (0) | 7 (0) |  |
| order list: filtered by another custom status (5,000) | 393 | 18 | 0 | 178 | 61 | 23.91 | 0 (0) | 5 (0) |  |
| order screen: normal order | 388 | 18 | 0 | 157 | 27.7 | 7.13 | 0 (0) | 2 (0) |  |
| order screen: history with 200 transitions | 422 | 18 | 0 | 161 | 25.1 | 7.16 | 0 (0) | 2 (0) |  |
| settings tab: statuses | 319 | 16 | 0 | 113 | 26.3 | 7.3 | 0 (0) | 2 (0) |  |
| settings tab: transitions | 282 | 16 | 0 | 106 | 15.5 | 7.23 | 0 (0) | 2 (0) |  |
| settings tab: documents | 328 | 16 | 0 | 108 | 17.1 | 7.21 | 0 (0) | 2 (0) |  |
| settings tab: import-export | 380 | 16 | 0 | 106 | 15.4 | 7.08 | 0 (0) | 2 (0) |  |
| My Account: orders list, page 1 of the customer with 501 orders | 525 | 24 | 6 | 123 | 124.8 | 94.32 | 0 (0) | 2 (0) |  |
| My Account: orders list, last page (51) | 378 | 20 | 2 | 93 | 96.7 | 88.01 | 0 (0) | 2 (0) |  |
| My Account: one order with its PDF link | 279 | 14 | 0 | 100 | 8.6 | 0.23 | 0 (0) | 2 (0) |  |
| PDF: a normal order | 406 | 30 | 12 | 82 | 7.2 | 0.31 | 0 (0) | 2 (0) | PDF ok, 1 page(s), 23 KB |
| **PDF: order with 300 items** | 1849 | 118 | 100 | 979 | 77.2 | 5.37 | 0 (0) | 5 (3) | PDF ok, 39 page(s), 90 KB |
| export configuration (JSON) | 254 | 14 | 0 | 68 | 5.4 | 0.28 | 0 (0) | 2 (0) |  |
| import configuration (replace, same file) | 294 | 10 | 0 | 71 |  |  | 0 (0) | 0 (0) |  |
| status change by the administrator (admin-post) | 273 | 10 | 0 | 136 |  |  | 0 (0) | 0 (0) |  |
| status change by a shop manager under a rule (admin-post) | 256 | 12 | 0 | 137 |  |  | 0 (0) | 0 (0) |  |
| bulk action on 20 orders (administrator) | 1217 | 16 | 0 | 1551 |  |  | 0 (0) | 0 (0) | 20 of 20 moved |
| **delete a status with 20,000 orders: the request itself** | 18264 | 12 | 0 | 147649 |  |  | 0 (0) | 0 (0) | first request moved 2,033 orders; location /wp-admin/admin.php?page=ofwc&tab=statuses |
| **delete a status: background jobs (worst of 26)** | 24793 | 26 | 8 | 149577 |  |  | 0 (0) | 0 (0) | 26 Action Scheduler jobs, 189 s in total, 20,000 orders in On hold |
| deactivate screen with 20,000 orders in custom statuses | 858 | 16 | 0 | 142 |  |  | 0 (0) | 0 (0) |  |
| deactivate: choose "move to On hold" | 246 | 12 | 0 | 64 |  |  | 0 (0) | 0 (0) |  |
| **deactivate: each batch of the progress page (worst of 12)** | 18344 | 22 | 4 | 156428 |  |  | 0 (0) | 0 (0) | 12 requests, 221 s in total, 20,000 of 20,000 orders in On hold |
| activate again (WP-CLI, not measured against the limits) | 661 | 0 | 0 |  |  |  | 0 (0) | 0 (0) |  |
| first admin page after activation (queues the restore) | 316 | 16 | 0 | 123 |  |  | 0 (0) | 0 (0) |  |
| **restore after activation: background jobs (worst of 36)** | 24250 | 16 | 0 | 165008 |  |  | 0 (0) | 0 (0) | 36 Action Scheduler jobs, 228 s in total, 20,000 of 20,000 orders back |

Highlighted (time over 5 s, or more than 64 MB used by the step itself on top of its own boot): PDF: order with 300 items (1.8 s, 118 MB, +100 MB); delete a status with 20,000 orders: the request itself (18.3 s, 12 MB, +0 MB); delete a status: background jobs (worst of 26) (24.8 s, 26 MB, +8 MB); deactivate: each batch of the progress page (worst of 12) (18.3 s, 22 MB, +4 MB); restore after activation: background jobs (worst of 36) (24.3 s, 16 MB, +0 MB).

Slowest step: delete a status: background jobs (worst of 26), 24.79 s. Highest peak: PDF: order with 300 items, 118 MB. WordPress with WooCommerce and the admin includes alone (boot): 18 MB, 1.67 s. Whole pass: 691 s.

Largest tables: wp_postmeta 2,181,162 rows (341 MB), wp_woocommerce_order_itemmeta 1,519,622 rows (166 MB), wp_woocommerce_order_items 167,201 rows (17 MB), wp_posts 60,653 rows (36 MB), wp_commentmeta 60,089 rows (7 MB), ofp_cohort 20,580 rows (2 MB), wp_term_relationships 19,601 rows (2 MB), wp_wc_order_product_lookup 16,717 rows (9 MB).

### Query findings (SAVEQUERIES, EXPLAIN)

Repeated query shapes (more than 10 times in one step) that come from the plugin:

| HPOS | Step | Origin | Times | Total ms | Query |
|---|---|---|---:|---:|---|
| on | PDF: order with 300 items | plugin | 300 | 19.3 | `SELECT meta_id as meta_id, meta_key, meta_value FROM wp_woocommerce_order_itemmeta WHERE order_item_id = 401089 ORDER BY meta_id` |
| on | PDF: order with 300 items | plugin | 300 | 19.2 | `SELECT order_item_id, meta_key, meta_value FROM wp_woocommerce_order_itemmeta WHERE order_item_id IN (401089) ORDER BY meta_id ASC` |
| off | PDF: order with 300 items | plugin | 303 | 21.5 | `SELECT * FROM wp_posts WHERE ID = 1070 LIMIT 1` |

Repeated query shapes and full scans that come from WordPress core or WooCommerce (not listed): 83 repeated shapes, 0 scans across all steps. They are the same on a store without the plugin.

<!-- perf:results:end -->

## Findings

This is the run of release 0.1.1. Nothing is about the pages: lists, order screens, settings tabs, My Account, export and import, a single status
change and the bulk action on 20 orders all stay under 2 s and 30 MB, and the query analysis found no full table scan and no repeated query shape
in the plugin's own list and screen code. The batch jobs and the 300-item PDF are the only steps that need attention.

### Fixed since the first run

The first run, on the code before 0.1.1, ended with two memory failures and one close call. They are gone in 0.1.1.

| Step | First run (before 0.1.1) | This run (0.1.1) |
|---|---|---|
| Delete a status with 20,000 orders, HPOS on | The first request ran out of memory at 128M after about 1,250 orders (HTTP 500), nothing was queued, about 15 presses of "Continue now" each ended in an error page | No error. The first request moves about 2,100 orders in 18 s (peak 48 MB), the remaining orders go through 28 background jobs (201 s in total, worst job 24.7 s, peak 18 MB) |
| Delete a status with 20,000 orders, HPOS off | Worked, peak 88 to 98 MB, close to the limit | Peak 12 MB for the request and 26 MB for the jobs; 26 background jobs, 189 s in total |
| Deactivate with "move to On hold", 20,000 orders, HPOS on | The first request of the progress page ran out of memory after about 1,500 orders (HTTP 500), the plugin stayed active with part of the orders parked | No error. 12 progress requests of at most 18.3 s with a peak of 16 MB, 20,000 of 20,000 orders in On hold |
| Deactivate with "move to On hold", 20,000 orders, HPOS off | 11 requests, peak 96 MB | 12 requests, worst 18.3 s, peak 22 MB |
| Restore after activation, 20,000 orders, HPOS off | The legacy order store ignored the query, so the restore returned every order | 36 background jobs, 228 s in total, 20,000 of 20,000 orders back (HPOS on: 38 jobs, 240 s) |
| PDF of an order that is too large for the memory limit | A fatal error | The size is estimated first; a document that does not fit gets a clear message. The 300-item order fits at 128M and is created |

What changed: the batches keep a budget for time (filter `ofwc_batch_budget`) and for memory, the object cache of the request is flushed
between batches of orders, and the legacy order store query uses a meta key that it understands.

### Still open

1. **The PDF of an order with 300 items peaks at 118 MB** (HPOS on and off), about 100 MB above a normal request, at a limit of 128M. The PDF has 39 pages
   (90 KB) and takes about 1.8 s, but any other plugin that adds memory to the same request can push it over the limit; the size check then
   refuses the document with a message instead of a fatal error. Two causes: WooCommerce reads the product and the item meta once per line item
   (about 980 queries; one `SELECT * FROM wp_posts` per item with HPOS off, and two item meta queries per item), and dompdf keeps the whole
   layout of 39 pages in memory. Priming the product and item meta caches for all items in one query each would remove about 900 queries; it
   would not lower the peak by itself.  The size estimate allows this order at 128M only just; a larger order is refused with a message.
2. **The batch jobs are slow, not broken.** 20,000 orders take 3 to 4 minutes in the background (about 10 ms per order, most of it WooCommerce saving
   the order), and each request stays under 25 s. Deactivation shows a progress page for that time. The cost is
   WooCommerce's own order save; the plugin only avoids doing it twice.

### Smaller notes

- The settings tab with the statuses reads the option `woocommerce_ofwc_<slug>_<type>_settings` about 56 times for three statuses: every status
  registers two email objects and each reads its own option, which is not autoloaded. It grows with the number of statuses and costs a few
  milliseconds today.
- The bulk action on 20 orders takes 1.2 s and about 1,400 to 1,550 queries (70 to 78 per order), mostly WooCommerce itself.
- With HPOS off, the first page of My Account orders for a customer with 501 orders has a slowest query of 94 ms (the posts query by customer meta).
  The page still takes 0.5 s.

## Reproduce

`bash bin/perf.sh` builds the zip, creates the two databases (`of_perf` and `of_perf_legacy`) in the lab database server, generates the data
set (about 25 s each), measures both storage modes in one throwaway container and rewrites the results block above. Needs Docker and the lab network.
`--hpos=on|off`, `--reuse` (restore the data set from the cache), `--products=` and `--orders=` (a smaller set for development, does not rewrite this file).
