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
**Result: 8 violation(s).** HPOS on, delete a status with 20,000 orders: the request itself: no result; HPOS on, delete a status with 20,000 orders: the request itself: HTTP 500; HPOS on, delete a status: background jobs (worst of 4): moved 1246 of 20000; HPOS on, deactivate: each batch of the progress page (worst of 1): HTTP 500; HPOS on, deactivate: each batch of the progress page (worst of 1): memory limit; HPOS on, deactivate: each batch of the progress page (worst of 1): parked 1464 of 20000; HPOS on, deactivate: each batch of the progress page (worst of 1): plugin still active; HPOS on, restore after activation: background jobs (worst of 5): restored 18536 of 20000, 1464 markers left

Environment: PHP 8.4.26, MySQL 8.4.11 (InnoDB buffer pool 128 MB), WordPress 7.1.3, WooCommerce 11.2.1. Plugin zip eb0fe3913e58. Run time 822 s.

### HPOS on (orders in the dedicated tables)

Data set: 10,000 products, 50,025 orders (20,000 of them in three Order Flow statuses), 173,302 order items, 508 users (5 administrators, 502 customers). One customer has 501 orders, one order has a 200-entry status history, one order has 300 items (PDF of 39 pages).

| Step | Time ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans (plugin) | N+1 shapes (plugin) | Note |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| boot (WordPress + admin) | 1266 | 18 |  | 105 |  |  | 0 (0) | 0 (0) |  |
| order list: all orders, status counters | 1159 | 24 | 6 | 189 | 37 | 8.12 | 0 (0) | 3 (0) |  |
| order list: filtered by a custom status (12,000 orders) | 412 | 16 | 0 | 151 | 23.4 | 8.13 | 0 (0) | 3 (0) |  |
| order list: filtered by a custom status, page 40 | 429 | 16 | 0 | 151 | 23.9 | 8.17 | 0 (0) | 3 (0) |  |
| order list: filtered by another custom status (5,000) | 391 | 24 | 6 | 151 | 37.3 | 8 | 0 (0) | 3 (0) |  |
| order screen: normal order | 416 | 18 | 0 | 151 | 25.7 | 7.94 | 0 (0) | 2 (0) |  |
| order screen: history with 200 transitions | 619 | 16 | 0 | 157 | 22.4 | 7.96 | 0 (0) | 2 (0) |  |
| settings tab: statuses | 431 | 16 | 0 | 118 | 27.4 | 7.93 | 0 (0) | 2 (1) |  |
| settings tab: transitions | 399 | 16 | 0 | 111 | 15.4 | 7.82 | 0 (0) | 2 (0) |  |
| settings tab: documents | 698 | 16 | 0 | 119 | 18.8 | 8.54 | 0 (0) | 2 (0) |  |
| settings tab: import-export | 650 | 16 | 0 | 111 | 24.8 | 8.33 | 0 (0) | 2 (0) |  |
| My Account: orders list, page 1 of the customer with 501 orders | 541 | 24 | 6 | 125 | 30.7 | 7.95 | 0 (0) | 2 (0) |  |
| My Account: orders list, last page (51) | 338 | 20 | 2 | 96 | 18.5 | 8.23 | 0 (0) | 2 (0) |  |
| My Account: one order with its PDF link | 402 | 14 | 0 | 101 | 14.6 | 0.68 | 0 (0) | 2 (0) |  |
| PDF: a normal order | 619 | 30 | 12 | 87 | 8.3 | 0.34 | 0 (0) | 2 (0) | PDF ok, 1 page(s), 23 KB |
| **PDF: order with 300 items** | 2085 | 122 | 104 | 984 | 72.1 | 4.57 | 0 (0) | 5 (2) | PDF ok, 39 page(s), 90 KB |
| export configuration (JSON) | 307 | 44 | 26 | 73 | 12.3 | 0.98 | 0 (0) | 2 (0) |  |
| import configuration (replace, same file) | 321 | 10 | 0 | 76 |  |  | 0 (0) | 0 (0) |  |
| status change by the administrator (admin-post) | 397 | 10 | 0 | 135 |  |  | 0 (0) | 0 (0) |  |
| status change by a shop manager under a rule (admin-post) | 437 | 10 | 0 | 131 |  |  | 0 (0) | 0 (0) |  |
| bulk action on 20 orders (administrator) | 1918 | 16 | 0 | 1394 |  |  | 0 (0) | 0 (0) | 20 of 20 moved |
| **delete a status with 20,000 orders: the request itself** | 16973 | 0 | 0 |  |  |  | 0 (0) | 0 (0) | first request moved 1,246 orders; location  |
| **delete a status: background jobs (worst of 4)** | 6906 | 72 | 54 | 3588 |  |  | 0 (0) | 0 (0) | 4 Action Scheduler jobs, 25 s in total, 1,246 orders in On hold |
| deactivate screen with 20,000 orders in custom statuses | 1002 | 16 | 0 | 146 |  |  | 0 (0) | 0 (0) |  |
| deactivate: choose "move to On hold" | 491 | 16 | 0 | 73 |  |  | 0 (0) | 0 (0) |  |
| **deactivate: each batch of the progress page (worst of 1)** | 15066 | 128 | 110 | 91812 |  |  | 0 (0) | 0 (0) | 1 requests, 16 s in total, 1,464 of 20,000 orders in On hold |
| activate again (WP-CLI, not measured against the limits) | 1081 | 0 | 0 |  |  |  | 0 (0) | 0 (0) |  |
| first admin page after activation (queues the restore) | 553 | 16 | 0 | 117 |  |  | 0 (0) | 0 (0) |  |
| **restore after activation: background jobs (worst of 5)** | 6216 | 22 | 4 | 64 |  |  | 0 (0) | 0 (0) | 5 Action Scheduler jobs, 29 s in total, 18,536 of 20,000 orders back |

Highlighted (time over 5 s, or more than 64 MB used by the step itself on top of its own boot): PDF: order with 300 items (2.1 s, 122 MB, +104 MB); delete a status with 20,000 orders: the request itself (17.0 s, 0 MB, +0 MB); delete a status: background jobs (worst of 4) (6.9 s, 72 MB, +54 MB); deactivate: each batch of the progress page (worst of 1) (15.1 s, 128 MB, +110 MB); restore after activation: background jobs (worst of 5) (6.2 s, 22 MB, +4 MB).

Slowest step: delete a status with 20,000 orders: the request itself, 16.97 s. Highest peak: deactivate: each batch of the progress page (worst of 1), 128 MB. WordPress with WooCommerce and the admin includes alone (boot): 18 MB, 1.27 s. Whole pass: 133 s.

Largest tables: wp_woocommerce_order_itemmeta 1,518,791 rows (166 MB), wp_postmeta 179,984 rows (22 MB), wp_woocommerce_order_items 174,956 rows (17 MB), wp_wc_order_addresses 99,459 rows (26 MB), wp_wc_orders_meta 99,161 rows (40 MB), wp_posts 58,003 rows (33 MB), wp_wc_order_operational_data 49,742 rows (12 MB), wp_wc_orders 49,476 rows (35 MB).

### HPOS off (orders in posts and postmeta, sync off)

Data set: 10,000 products, 50,025 orders (20,000 of them in three Order Flow statuses), 173,302 order items, 508 users (5 administrators, 502 customers). One customer has 501 orders, one order has a 200-entry status history, one order has 300 items (PDF of 39 pages).

| Step | Time ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans (plugin) | N+1 shapes (plugin) | Note |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| boot (WordPress + admin) | 1776 | 18 |  | 110 |  |  | 0 (0) | 0 (0) |  |
| order list: all orders, status counters | 986 | 18 | 0 | 234 | 92.6 | 25.25 | 0 (0) | 5 (0) |  |
| order list: filtered by a custom status (12,000 orders) | 439 | 18 | 0 | 184 | 66.5 | 25.16 | 0 (0) | 5 (0) |  |
| order list: filtered by a custom status, page 40 | 464 | 18 | 0 | 224 | 71.4 | 24.35 | 0 (0) | 7 (0) |  |
| order list: filtered by another custom status (5,000) | 418 | 18 | 0 | 183 | 66.1 | 24.55 | 0 (0) | 5 (0) |  |
| order screen: normal order | 386 | 18 | 0 | 162 | 52.2 | 7.86 | 0 (0) | 2 (0) |  |
| order screen: history with 200 transitions | 769 | 18 | 0 | 166 | 28.4 | 7.63 | 0 (0) | 2 (0) |  |
| settings tab: statuses | 316 | 18 | 0 | 118 | 27 | 7.37 | 0 (0) | 2 (0) |  |
| settings tab: transitions | 281 | 16 | 0 | 111 | 15.7 | 7.35 | 0 (0) | 2 (0) |  |
| settings tab: documents | 351 | 16 | 0 | 113 | 16.7 | 7.44 | 0 (0) | 2 (0) |  |
| settings tab: import-export | 310 | 16 | 0 | 111 | 15.8 | 7.39 | 0 (0) | 2 (0) |  |
| My Account: orders list, page 1 of the customer with 501 orders | 495 | 24 | 6 | 123 | 121.1 | 90.37 | 0 (0) | 2 (0) |  |
| My Account: orders list, last page (51) | 484 | 16 | 0 | 93 | 106.7 | 97.17 | 0 (0) | 2 (0) |  |
| My Account: one order with its PDF link | 346 | 14 | 0 | 100 | 8.4 | 0.21 | 0 (0) | 2 (0) |  |
| PDF: a normal order | 373 | 26 | 8 | 87 | 8.3 | 0.28 | 0 (0) | 2 (0) | PDF ok, 1 page(s), 23 KB |
| **PDF: order with 300 items** | 2031 | 118 | 100 | 984 | 77.6 | 5.69 | 0 (0) | 5 (4) | PDF ok, 39 page(s), 90 KB |
| export configuration (JSON) | 385 | 44 | 26 | 73 | 8.5 | 0.48 | 0 (0) | 2 (0) |  |
| import configuration (replace, same file) | 429 | 12 | 0 | 76 |  |  | 0 (0) | 0 (0) |  |
| status change by the administrator (admin-post) | 532 | 18 | 0 | 136 |  |  | 0 (0) | 0 (0) |  |
| status change by a shop manager under a rule (admin-post) | 590 | 12 | 0 | 139 |  |  | 0 (0) | 0 (0) |  |
| bulk action on 20 orders (administrator) | 1469 | 18 | 0 | 1509 |  |  | 0 (0) | 0 (0) | 20 of 20 moved |
| **delete a status with 20,000 orders: the request itself** | 18447 | 88 | 70 | 122408 |  |  | 0 (0) | 0 (0) | first request moved 1,985 orders; location /wp-admin/admin.php?page=ofwc&tab=statuses |
| **delete a status: background jobs (worst of 26)** | 25058 | 98 | 80 | 137119 |  |  | 0 (0) | 0 (0) | 26 Action Scheduler jobs, 192 s in total, 20,000 orders in On hold |
| deactivate screen with 20,000 orders in custom statuses | 853 | 16 | 0 | 147 |  |  | 0 (0) | 0 (0) |  |
| deactivate: choose "move to On hold" | 255 | 14 | 0 | 69 |  |  | 0 (0) | 0 (0) |  |
| **deactivate: each batch of the progress page (worst of 11)** | 18603 | 96 | 78 | 135969 |  |  | 0 (0) | 0 (0) | 11 requests, 197 s in total, 20,000 of 20,000 orders in On hold |
| activate again (WP-CLI, not measured against the limits) | 713 | 0 | 0 |  |  |  | 0 (0) | 0 (0) |  |
| first admin page after activation (queues the restore) | 319 | 20 | 2 | 128 |  |  | 0 (0) | 0 (0) |  |
| **restore after activation: background jobs (worst of 34)** | 24263 | 66 | 48 | 159064 |  |  | 0 (0) | 0 (0) | 34 Action Scheduler jobs, 217 s in total, 20,000 of 20,000 orders back |

Highlighted (time over 5 s, or more than 64 MB used by the step itself on top of its own boot): PDF: order with 300 items (2.0 s, 118 MB, +100 MB); delete a status with 20,000 orders: the request itself (18.4 s, 88 MB, +70 MB); delete a status: background jobs (worst of 26) (25.1 s, 98 MB, +80 MB); deactivate: each batch of the progress page (worst of 11) (18.6 s, 96 MB, +78 MB); restore after activation: background jobs (worst of 34) (24.3 s, 66 MB, +48 MB).

Slowest step: delete a status: background jobs (worst of 26), 25.06 s. Highest peak: PDF: order with 300 items, 118 MB. WordPress with WooCommerce and the admin includes alone (boot): 18 MB, 1.78 s. Whole pass: 666 s.

Largest tables: wp_postmeta 2,179,482 rows (341 MB), wp_woocommerce_order_itemmeta 1,518,791 rows (166 MB), wp_woocommerce_order_items 174,956 rows (17 MB), wp_commentmeta 60,258 rows (7 MB), wp_posts 57,857 rows (36 MB), wp_term_relationships 20,488 rows (2 MB), ofp_cohort 19,555 rows (2 MB), wp_wc_order_product_lookup 16,429 rows (9 MB).

### Query findings (SAVEQUERIES, EXPLAIN)

Repeated query shapes (more than 10 times in one step) that come from the plugin:

| HPOS | Step | Origin | Times | Total ms | Query |
|---|---|---|---:|---:|---|
| on | settings tab: statuses | plugin | 56 | 3.1 | `SELECT option_value FROM wp_options WHERE option_name = 'woocommerce_ofwc_of-review_customer_settings' LIMIT 1` |
| on | PDF: order with 300 items | plugin | 300 | 19.5 | `SELECT meta_id as meta_id, meta_key, meta_value FROM wp_woocommerce_order_itemmeta WHERE order_item_id = 401311 ORDER BY meta_id` |
| on | PDF: order with 300 items | plugin | 300 | 19.2 | `SELECT order_item_id, meta_key, meta_value FROM wp_woocommerce_order_itemmeta WHERE order_item_id IN (401119) ORDER BY meta_id ASC` |
| off | PDF: order with 300 items | plugin | 303 | 21.4 | `SELECT * FROM wp_posts WHERE ID = 1322 LIMIT 1` |
| off | PDF: order with 300 items | plugin | 42 | 3.3 | `SELECT option_value FROM wp_options WHERE option_name = 'woocommerce_ofwc_of-proof_admin_settings' LIMIT 1` |

Repeated query shapes and full scans that come from WordPress core or WooCommerce (not listed): 81 repeated shapes, 0 scans across all steps. They are the same on a store without the plugin.

<!-- perf:results:end -->

## Findings

Ordered by cost. Nothing here is about the pages: lists, order screens, settings tabs, My Account, export and import, a single status change
and the bulk action on 20 orders all stay under 2.2 s and 31 MB, and the query analysis found no full table scan and no repeated query
shape in the plugin's own list and screen code. The problems are in the batch jobs and in the 300-item PDF.

### 1. Deleting a status with 20,000 orders runs out of memory (HPOS on)

`Deletions::run()` moves orders in a loop until an 18 s time budget is used up. At 128M the memory runs out first: the first request
(the "Delete status" button) moved about 1,250 orders in 11 to 17 s, then died with "Allowed memory size exhausted" and HTTP 500 (a white
"critical error" page). The status stays in the "being deleted" state and nothing is queued: the next batch is queued by `schedule()` only after
`run()` returns, and it never does. Each press of "Continue now" moves another 1,000 to 1,500 orders and fails the same way, so 20,000 orders
need about 15 presses and every one ends in an error page. About 85 KB stays in memory per order moved (the order object, its items and
meta, in the object cache of the request).

With HPOS off the same step does not run out of memory (peak 88 MB in the first request, which moved 1,985 orders in 18 s), and the background
jobs finish the rest (26 runner requests, 192 s in total, peak 98 MB). That is still close to the limit.

Fix to consider: stop the batch on memory as well as on time (for example when `memory_get_usage()` passes 60 percent of the limit), call
`wp_cache_flush_runtime()` between batches or between orders, queue the next job before the work starts so a dying request does not strand
the deletion, and keep a smaller batch size. No behaviour change is needed for the user.

### 2. Deactivating with "move to On hold" runs out of memory the same way (HPOS on)

`Orders::park_batch()` has the same loop. With 20,000 orders the first request of the progress page moved about 1,500 orders in 14 to 16 s and died
at 128M (HTTP 500). The plugin stays active, the 1,500 orders sit in On hold with their marker, and the other 18,500 are untouched. The screen cannot
be continued from the error page; the administrator has to open the Deactivate screen again, and each round fails after another 1,500 orders.
If the administrator gives up, the parked orders stay in On hold while the plugin is active (reactivating does nothing, because the plugin is already
active, so the restore never runs).

With HPOS off the same deactivation works: 11 requests, 197 s, peak 96 MB, all 20,000 orders in On hold, and the restore after activation puts all of
them back (34 background requests, 217 s, peak 66 MB). The fix for finding 1 applies here too.

### 3. The PDF of an order with 300 items peaks at 118 to 122 MB

The PDF has 39 pages (90 KB) and takes about 2 s, but the request peaks at 118 MB (HPOS off) to 122 MB (HPOS on), about 100 MB above a normal request, at a
limit of 128M. Any other plugin that adds memory to the same request will push it over. It also runs 984 queries: one `SELECT * FROM wp_posts` per
line item for the product (302 times), and two item meta queries per line item (about 300 times each). Priming the product and item meta caches
for all items in one query each would remove about 900 queries; building the HTML without keeping per-item objects would lower the peak.

### 4. Smaller notes

- The settings tab with the statuses runs `get_option( 'woocommerce_ofwc_<slug>_<type>_settings' )` about 56 times for three statuses: every status registers
  two email objects and each reads its own, not autoloaded, option. It grows with the number of statuses; it is a few milliseconds today.
- The bulk action on 20 orders takes 1.1 to 1.9 s and about 1,400 queries (70 per order), mostly WooCommerce itself.
- Restoring the orders after activation with HPOS off worked only after the query in `Orders::restore_batch()` was changed from `meta_query` to
  `meta_key` with `meta_compare`: the legacy order store ignores `meta_query` (it only logs a notice), so the original query returned every order.
  That is a separate defect, found by the compatibility matrix.

## Reproduce

`bash bin/perf.sh` builds the zip, creates the two databases (`of_perf` and `of_perf_legacy`) in the lab database server, generates the data
set (about 25 s each), measures both storage modes in one throwaway container and rewrites the results block above. Needs Docker and the lab network.
`--hpos=on|off`, `--reuse` (restore the data set from the cache), `--products=` and `--orders=` (a smaller set for development, does not rewrite this file).
