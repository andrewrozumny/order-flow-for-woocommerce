# Destructive testing

What was done to the plugin to try to break it, and what came out. Every scenario ran against a throwaway clone of
WordPress 7.1.3 with WooCommerce 11.2.1 (its own database, PHP 8.4, PHP built-in server with eight workers), once with
the HPOS order tables and once with the legacy posts store (`HPOS=off`). The clone installs the plugin as shipped: a
build from the git tag or from `bin/build-zip.sh`. Nothing ran against a real store. First run: version 0.1.0.

Result column: "Result = Expected" means the plugin behaved as expected the first time. "Defect found, fix pending"
means a test that catches the problem exists and fails on 0.1.0; it turns into "Defect found and fixed" when the fix
makes it pass. Categories: security, compatibility, performance, behavior.

## Results

| Scenario | Expected | Result | Test |
| --- | --- | --- | --- |
| 1.1 Status change endpoint `admin-post.php?action=ofwc_transition`: guest, subscriber, editor, customer, shop manager, administrator; missing, empty, forged, over-long, array, other-order, other-action, other-user and expired nonces | Guest 400, subscriber, editor and customer 403, every bad nonce 403, shop manager and administrator work; refused requests change nothing and send no email | Result = Expected (21 checks) | live (1.1) |
| 1.2 Status change endpoint with hostile target, comment and order id: XSS, SQL characters, `%`, `_`, backslash, unicode, emoji, right-to-left marks, null bytes, CRLF, path traversal, negative, fractional and huge numbers, empty, spaces, 1 MB strings, arrays instead of strings, missing and extra parameters | Never a 500; a hostile target changes nothing; comments are inert text in the order screen; a very large comment is refused or cut | Defect found, fix pending (behavior, data loss): a 1 MB comment is accepted, the status changes, and the order's whole history disappears (details in 3.4) | live (1.2); `CommentLimitsTest::test_huge_comment_is_refused` |
| 1.3 Status form (`ofwc_save_status`): every field with every payload above | Every save ends in a redirect; the stored statuses stay well formed (slug, colour, plain-text label); the statuses page shows values as inert text | Defect found, fix pending (security/availability): a numeric slug such as `0` or `-1` is stored under an integer key and the statuses page ends in a fatal error (`count_orders(): Argument #1 must be of type string, int given`); 0.1.0 and the first fix round only covered one of the places that loop over the statuses. Defect found, fix pending (performance): no length limit on any text field, a 1 MB label makes the autoloaded option `ofwc_statuses` 1 MB, loaded on every request. Defect found, fix pending (behavior): an `editing` value of 100 KB is copied into the error redirect and the connection is dropped | live (1.3); `CommentLimitsTest::test_status_text_fields_are_bounded` |
| 1.4 Transitions form, documents form and delete form with hostile values | Hostile rules are cleaned or refused, saved rules only name known statuses and roles; delete with a hostile slug or target never fails hard | Defect found, fix pending (performance): store details accept 1 MB. Defect found, fix pending (behavior): delete with a 100 KB status copies it into the redirect and the connection is dropped. Everything else Result = Expected | live (1.4) |
| 1.5 All settings, import, export and deactivate endpoints: guest, subscriber, editor, customer, shop manager; bad nonces | Guest 400, other roles without the capability 403, bad nonces 403, shop manager cannot deactivate the plugin, no plugin row changes | Result = Expected (87 checks; the PHP warning "Array to string conversion" for an array nonce comes from WordPress core, `pluggable.php`) | live (1.5) |
| 1.6 Document download: guest, subscriber, editor, customer A and B, shop manager, administrator; bad nonces; unknown status, status with no document, missing order | Guest 400, subscriber and editor 403, a customer gets only their own order and only statuses shown in the account, bad nonces 403, unknown status or order 404 | Result = Expected (35 checks) | live (1.6) |
| 1.7 Admin pages with hostile query strings; every role | 200 for administrator and shop manager, 403 for subscriber, editor and customer, redirect for guests; nothing reflected; the deactivation screen is closed to shop managers | Result = Expected (190 checks) | live (1.7) |
| 1.8 Site data as an XSS source: markup in billing name, company, address, product name, comment | Inert text in the order screen, the account page, the orders list and every HTML email; no header injection through names | Result = Expected | live (1.8) |
| 2.1 WooCommerce deactivated, and activating the plugin without it | No fatal error; administrators see a notice; the endpoints are gone; reactivating WooCommerce restores everything | Result = Expected. Defect found, fix pending (compatibility), legacy posts store only: activation calls `wc_get_orders()` with `meta_query`, which that store does not support, so WooCommerce raises a "called incorrectly" notice and the call returns every order | live (2.1), `HPOS=off` |
| 2.2 WooCommerce 9.0.4 (the declared minimum) and 8.9.3 (below it) | The plugin works on the minimum; below it nothing fatal comes from the plugin | Defect found, fix pending (compatibility): on 9.0.4 and 8.9.3 the status change ends in a fatal error because the plugin ships `sabberworm/php-css-parser` in the global namespace and WooCommerce's email inliner picks it up (`Declaration could not be converted to string`) | live (2.2) |
| 2.3 WooCommerce tables missing, one by one (order items, item meta, order stats, product lookup, sessions, HPOS order meta) | No fatal error; screens answer | Result = Expected (WordPress database errors in the log come from WooCommerce's own queries) | live (2.3) |
| 2.4 Plugin options missing or damaged: string, number, boolean, empty, list of strings, rows and rules of the wrong type, hostile strings, huge position, broken serialisation, history meta of the wrong shape | Every screen and endpoint keeps working; damaged values behave like missing ones | Result = Expected (42 checks, 36 option shapes) | live (2.4) |
| 2.5 Uploads folder missing, replaced by a file, read-only; plugin font folder read-only; temporary folder unavailable | The status change completes, the email goes out (without the PDF when it cannot be written), a PDF download still works, no warnings | Defect found, fix pending (compatibility): with an unusable temporary folder the PDF download ends in a fatal error in php-font-lib (`Path must not be empty`). The other variants Result = Expected | live (2.5) |
| 2.6 `DISABLE_WP_CRON`, `WP_HTTP_BLOCK_EXTERNAL`, a mailer that fails | Status changes work, PDFs make no outside request, a failing mailer does not undo the change | Result = Expected | live (2.6) |
| 3.1 Status change from the order screen (full form save) by role; required comment empty, spaces, non-breaking space, zero-width space | Allowed changes go through, forbidden ones are reverted, a missing required comment is refused, the same status is a no-op | Defect found, fix pending (behavior): a comment made only of a non-breaking or zero-width space satisfies "comment required". The rest Result = Expected | live (3.1); `CommentLimitsTest::test_invisible_comment_does_not_satisfy_a_required_comment` |
| 3.2 Bulk actions on the orders list: forged nonce, unknown or hostile action, hostile ids, 300 missing ids, subscriber, customer | Only allowed orders move, the others are skipped with a count; changes that need a comment are skipped; nothing fails hard | Result = Expected (29 checks) | live (3.2) |
| 3.3 Six requests for the same change at once, and three different changes at once | Each change appears once in the history and in the mail; the history reads as one chain; the final status equals the last row | Defect found, fix pending (behavior): one change produced between 4 and 12 emails in 6 of 6 rounds, and in half of the rounds with different targets the status disagreed with the last history row. The change is not atomic: the order is read, checked and saved without a lock | live (3.3) |
| 3.4 Comments of 5,000, 70,000 and 1,048,576 characters; 60 changes with 400-character comments | The history keeps every row | Defect found, fix pending (behavior, data loss): the history is one meta value; with HPOS the column holds 64 KB, so the stored array is cut, cannot be read back, and every earlier row is lost. Shown at 70,000 characters | live (3.4); `CommentLimitsTest::test_huge_comment_is_refused` |
| 3.5 Emails per status: recipients, HTML, multipart and plain text, header injection through the customer name, markup in names, PDF attachment on and off, temporary files | The right people get the right emails; no injected Bcc; plain text shows real characters; the PDF is attached only when asked and its folder is removed | Result = Expected (16 checks) | live (3.5) |
| 4.1 Documents under `memory_limit` 128M and 30 seconds: 300 lines (22 pages), 700 lines (about 50 pages), a status text of 4,200 paragraphs | No timeout and no memory error | Defect found, fix pending (performance): 300 lines take 1.4 s; 700 lines and the long text exhaust 128 MB and end in a fatal error. Admin-post requests do not get the higher memory limit WordPress gives wp-admin pages | live (4.1) |
| 4.2 Product names and addresses in Cyrillic, CJK, Arabic, emoji, accented Latin | The document is produced; Cyrillic and Latin text can be extracted | Result = Expected (Arabic is not shaped and emoji have no glyph in DejaVu Sans; recorded, not a failure) | live (4.2) |
| 4.3 Hostile content in the document: images, background URLs and style imports pointing at a local listener and at `file:///etc/passwd`, in the status text, store details, address and product name | dompdf makes no outside request and reads no local file | Result = Expected (0 requests to the listener, nothing from `/etc/passwd`) | live (4.3) |
| 4.4 woocommerce-pdf-invoices-packing-slips active next to the plugin, both activation orders, both generators called first | Both create PDFs, no "class already declared" fatal; the loaded dompdf is recorded | Result = Expected on 0.1.0: the other plugin's dompdf is namespaced, ours loads from `vendor/dompdf` | live (4.4) |
| 5.1 Import: truncated, empty, not JSON, PNG, PHP, null bytes, wrong format or version, 201 and 10,000 statuses, over 1 MB, slugs longer than 17 or equal to core statuses, duplicates, arrays instead of strings, hostile text, unknown modes, merge and replace | A bad file is refused with a message and changes nothing; a good file adds what it says; core statuses are never replaced; stored statuses stay well formed | Defect found, fix pending (performance): a 900 KB label in an otherwise valid file is stored (no length limit). The other 222 checks Result = Expected | live (5.1) |
| 5.2 Import in replace mode with a file that has no `statuses` section, and with one that has no `transitions` section | A missing section is left alone; statuses with orders are never removed | Defect found, fix pending (behavior, data loss): a missing section counts as empty, so replace removes every status without orders and clears every rule | live (5.2) |
| 5.3 Deactivate with "keep" and with "move to On hold" (130 orders in three statuses), reactivate | Orders stay readable; "move" brings every order back to its own status | Result = Expected on both store layouts. Legacy store: see 2.1 for the notice raised by the restore query | live (5.3) |
| 5.4 `wp plugin uninstall` | Only the plugin's rows and files go; orders, their meta, history and notes stay; look-alike rows of other plugins stay | Defect found, fix pending (behavior): `uninstall.php` removes three options only. Left behind: the flag `ofwc_restore_pending`, the per-user transients `ofwc_notice_*`, `ofwc_form_*`, `ofwc_tn_*` (with their timeout rows), the WooCommerce email settings `woocommerce_ofwc_*_settings`, and the folders `uploads/ofwc-tmp` and `uploads/ofwc-fonts`. Orders, meta and notes untouched | live (5.4) |
| 6.1 0.1.0 with data, upgrade to the current build, new change and document, downgrade to 0.1.0, forward again | Settings, orders and history survive; every screen works; the downgrade does not fail | Result = Expected (22 checks) | live (6.1) |
| 6.2 Multisite with network activation (three sites, WooCommerce network-active), uninstall | The plugin works on a sub site; uninstall cleans every site and leaves look-alike rows | Defect found, fix pending (compatibility): with WooCommerce network-active the plugin does not start on any site (it looks for WooCommerce in the per-site list of active plugins only). Defect found, fix pending (behavior): network uninstall cleans the main site only; the rows of every other site stay | live (6.2) |

## How to reproduce

Needs Docker, Composer on the host, the lab containers (`wplab-db-1` and the `wplab_default` network) and the lab's
`site/.bin/wp-cli.phar`. The clone lives in `$HOME/.cache/order-flow-destructive/`, uses the database `of_destr_1` and
one container, `of-destr-1`. The lab site itself is never touched.

```
bash bin/destructive.sh up                     # build the clone: WordPress, WooCommerce, the plugin, six test users
bash bin/destructive.sh run                    # every scenario against the working tree (about 30 minutes)
bash bin/destructive.sh run 1 3                # only scenario groups 1 and 3
HPOS=off bash bin/destructive.sh run           # the same against the legacy posts store
DESTRUCTIVE_BUILD=v0.1.0 bash bin/destructive.sh run   # against a release instead of the working tree
ONLY=3.3,3.4 bash bin/destructive.sh run 3     # single scenarios
bash bin/destructive.sh down                   # remove the container, the database and the files
```

The scenarios are in `tests/Destructive/` (Node, run inside the container). They log in as six users (administrator,
shop manager, subscriber, editor, two customers), send the requests with `fetch`, read `wp-content/debug.log` before
and after each step, compare the stored options and order data, and capture every outgoing email through a small
must-use plugin (`tests/Destructive/mu/of-capture.php`) that is installed in the clone only.

Behaviour snapshots, used to prove that a refactor changed nothing, are in `tests/Snapshots/`:

```
bash bin/snapshot.sh compare       # install the working tree, snapshot it, diff against tests/Snapshots/v0.1.0
bash bin/snapshot.sh record v0.1.0 # record a reference from a git ref
bash bin/snapshot.sh twice v0.1.0  # determinism check: two runs of one build must be identical
```

The defect tests that need no WordPress run in the normal unit suite (`composer test`, folder `tests/Unit/Defects`).
To see a defect fail, check out 0.1.0 (`git archive v0.1.0`), put the new tests next to it and run `composer test`,
or install the 0.1.0 zip in the clone and run the same scenario.
