#!/usr/bin/env python3
"""Performance driver (development only, not shipped). Runs inside the container.

  perf-drive.py <site> <result.json> <hpos on|off> <db> <explain 0|1>

Measures the steps of Order Flow on the large data set under memory_limit=128M and max_execution_time=30:
the admin screens, one order with a long history, My Account, the PDF of a 300-item order, export and import, a status
change and a bulk change, deleting a status with 20,000 orders, and deactivating the plugin with the choice "move to
On hold" followed by the restore after activation. Page steps are requests to the PHP web server on 127.0.0.1:8080
(started with those limits, the request signed in through the X-Perf-User header); background steps are WP-CLI
processes started with the same limits. Every step is measured by perf-mu.php (time, peak memory, queries); read-only
steps run a second time with SAVEQUERIES for the query analysis (slowest query, repeated shapes, EXPLAIN).
"""
import http.client
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
from html import unescape

site, result_path, hpos, db, explain = sys.argv[1:6]
HPOS = hpos == "on"
PORT = 8080
HOST = "127.0.0.1:%d" % PORT
LIMIT_S = 30
LIMIT_MEM = 128 * 1024 * 1024
HARD_WALL = 150
PHP_LIMITS = ["-d", "memory_limit=128M", "-d", "max_execution_time=30", "-d", "display_errors=0", "-d", "log_errors=0",
              "-d", "error_reporting=32767", "-d", "sendmail_path=/bin/true"]
P = "wp_"
ENV = {**os.environ, "OF_PERF_DB": db}
steps = []
notes = {}
t_all = time.time()
_counter = [0]


def mysql(sql):
    r = subprocess.run(["mysql", "-h", "db", "-uroot", "-pwordpress", "-N", "-B", db, "-e", sql], capture_output=True, text=True)
    if r.returncode != 0:
        print("mysql failed:", r.stderr.strip(), file=sys.stderr)
    return r.stdout.strip()


def wp(*args, limits=False, out=None, user=None):
    """WP-CLI process; with limits=True under the tested PHP limits and measured through perf-mu.php."""
    cmd = ["php"] + (PHP_LIMITS if limits else ["-d", "memory_limit=2G", "-d", "display_errors=stderr", "-d", "sendmail_path=/bin/true"]) + ["/wp-cli.phar", "--path=" + site] + list(args)
    env = dict(ENV)
    if out:
        env["PERF_OUT"] = out
    if user:
        env["PERF_USER"] = str(user)
    t = time.time()
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=HARD_WALL * 4)
        return r.returncode, r.stdout, r.stderr, (time.time() - t) * 1000
    except subprocess.TimeoutExpired:
        return -9, "", "timeout", (time.time() - t) * 1000


def new_out():
    _counter[0] += 1
    path = "/tmp/perf-out-%d-%d.json" % (os.getpid(), _counter[0])
    try:
        os.remove(path)
    except FileNotFoundError:
        pass
    return path


def read_out(path):
    """The result file is written in a shutdown function, which can run just after the response is complete."""
    for _ in range(100):
        try:
            with open(path) as fh:
                return json.load(fh)
        except Exception:  # noqa: BLE001
            time.sleep(0.05)
    return {"no_result": True}


class Cookie:
    pass


def http_call(method, path, user, data=None, saveq=False, headers=None, raw_body=None):
    """One measured request. Returns (status, response headers, body, metrics)."""
    out = new_out()
    h = {"Host": HOST, "X-Perf-User": str(user), "X-Perf-Out": out, "X-Perf-Explain": explain}
    if saveq:
        h["X-Perf-Saveq"] = "1"
    if headers:
        h.update(headers)
    body = None
    if data is not None:
        body = urllib.parse.urlencode(data, doseq=True)
        h["Content-Type"] = "application/x-www-form-urlencoded"
    t = time.time()
    status, rh, rb = 599, {}, b""
    timed_out = False
    try:
        conn = http.client.HTTPConnection("127.0.0.1", PORT, timeout=HARD_WALL)
        conn.request(method, path, body=body, headers=h)
        resp = conn.getresponse()
        rb = resp.read()
        status = resp.status
        rh = {k.lower(): v for k, v in resp.getheaders()}
        conn.close()
    except Exception as exc:  # noqa: BLE001
        timed_out = True
        rh = {"error": str(exc)}
    m = read_out(out)
    m["driver_wall_ms"] = round((time.time() - t) * 1000, 1)
    m["http"] = status
    m["body_bytes"] = len(rb)
    if timed_out:
        m["fatal_kind"] = "timeout"
        m["fatal"] = "no answer within %d s" % HARD_WALL
    return status, rh, rb, m


def strip_host(loc):
    return re.sub(r"^https?://" + re.escape(HOST), "", loc or "")


def violations(m, ok_codes=(200,), expect=None, body=b"", check_time=True):
    v = []
    kind = m.get("fatal_kind")
    if kind == "timeout":
        v.append("timeout")
    elif kind == "memory":
        v.append("memory limit")
    elif kind:
        v.append("fatal error")
    elif m.get("no_result"):
        v.append("no result")
    if check_time and m.get("wall_ms", m.get("driver_wall_ms", 0)) / 1000.0 > LIMIT_S:
        v.append("over %d s" % LIMIT_S)
    if m.get("peak_bytes", 0) > LIMIT_MEM:
        v.append("peak over 128M")
    if ok_codes and m.get("http") not in ok_codes and not m.get("sapi") == "cli":
        v.append("HTTP %s" % m.get("http"))
    if expect is not None and expect not in body:
        v.append("page lacks the expected content")
    return v


def ms(m):
    return m.get("wall_ms", m.get("driver_wall_ms", 0))


def record(label, plain, queries=None, extra_v=None, note=""):
    entry = {"label": label, "plain": plain, "queries": queries or {}, "violations": (violations_of.get(id(plain), []) + (extra_v or [])), "note": note}
    steps.append(entry)
    print("%-52s wall %8.0f ms  peak %5.1f MB  q %5s  %s %s" % (label, ms(plain), plain.get("peak_bytes", 0) / 1048576.0, plain.get("queries", "-"),
          ",".join(entry["violations"]) or "ok", note), flush=True)
    return entry


violations_of = {}


def page_step(label, method, path, user, data=None, ok_codes=(200,), expect=None, query_pass=True, note_fn=None):
    status, rh, body, m = http_call(method, path, user, data=data)
    violations_of[id(m)] = violations(m, ok_codes=ok_codes, expect=expect, body=body)
    q = {}
    if query_pass and not violations_of[id(m)]:
        _, _, _, q = http_call(method, path, user, data=data, saveq=True)
    note = note_fn(status, rh, body) if note_fn else ""
    record(label, m, q, note=note)
    return status, rh, body, m


def nonce(user, action):
    out = new_out()
    conn = http.client.HTTPConnection("127.0.0.1", PORT, timeout=60)
    conn.request("GET", "/?perf=nonce&action=" + urllib.parse.quote(action), headers={"Host": HOST, "X-Perf-User": str(user)})
    resp = conn.getresponse()
    data = resp.read()
    conn.close()
    try:
        return json.loads(data.decode()).get("nonce", "")
    except ValueError:
        return ""


def helper(user, query):
    conn = http.client.HTTPConnection("127.0.0.1", PORT, timeout=60)
    conn.request("GET", "/?perf=" + query, headers={"Host": HOST, "X-Perf-User": str(user)})
    resp = conn.getresponse()
    data = resp.read()
    conn.close()
    try:
        return json.loads(data.decode())
    except ValueError:
        return {}



def as_http_run():
    """One Action Scheduler queue runner request, the way WordPress starts it in the background (admin-ajax.php, no login)."""
    n = nonce(0, "as_async_request_queue_runner")
    st, rh, body, m = http_call("POST", "/wp-admin/admin-ajax.php", 0, data={"action": "as_async_request_queue_runner", "nonce": n})
    m["http"] = st
    return m


# ---- storage helpers ---------------------------------------------------------------------------------------------
ORD = P + ("wc_orders" if HPOS else "posts")
ID_COL, ST_COL = ("id", "status") if HPOS else ("ID", "post_status")
META = P + ("wc_orders_meta" if HPOS else "postmeta")
META_ID = "order_id" if HPOS else "post_id"


def cohort_to(status_map=None, status=None):
    """Put the cohort orders into one status, or back to their own."""
    if status:
        mysql("UPDATE %s o JOIN ofp_cohort c ON c.order_id = o.%s SET o.%s = 'wc-%s'" % (ORD, ID_COL, ST_COL, status))
    else:
        mysql("UPDATE %s o JOIN ofp_cohort c ON c.order_id = o.%s SET o.%s = CONCAT('wc-', c.status)" % (ORD, ID_COL, ST_COL))
    mysql("DELETE FROM %s WHERE meta_key = '_ofwc_pre_deactivation_status'" % META)
    mysql("DELETE FROM %s WHERE option_name IN ('ofwc_status_deletions', 'ofwc_restore_pending', '_transient_ofwc_park_progress', '_transient_timeout_ofwc_park_progress')" % (P + "options"))
    mysql("DELETE FROM %sactionscheduler_actions WHERE hook LIKE 'ofwc\\_%%'" % P)
    wp("cache", "flush")
    wp("transient", "delete", "--all")


def count_status(status, cohort_only=True):
    join = "JOIN ofp_cohort c ON c.order_id = o.%s" % ID_COL if cohort_only else ""
    return int(mysql("SELECT COUNT(*) FROM %s o %s WHERE o.%s = 'wc-%s'" % (ORD, join, ST_COL, status)) or 0)


def cohort_ids(status, n):
    out = mysql("SELECT order_id FROM ofp_cohort WHERE status = '%s' ORDER BY order_id LIMIT %d" % (status, n))
    return [int(x) for x in out.split("\n") if x.strip()]


counts = json.loads(wp("option", "get", "ofp_perf_counts", "--format=json")[1].strip().split("\n")[-1] or "{}")
ADMIN = int(wp("user", "get", "admin", "--field=ID")[1].strip())
MANAGER, OWNER = counts["manager"], counts["owner"]
BIG, HIST = counts["big_order"], counts["history_order"]
notes["counts"] = counts


def order_url(oid):
    return "/wp-admin/admin.php?page=wc-orders&action=edit&id=%d" % oid if HPOS else "/wp-admin/post.php?post=%d&action=edit" % oid


def list_url(status=None, paged=None):
    if HPOS:
        return "/wp-admin/admin.php?page=wc-orders" + ("&status=wc-" + status if status else "") + ("&paged=%d" % paged if paged else "")
    return "/wp-admin/edit.php?post_type=shop_order" + ("&post_status=wc-" + status if status else "") + ("&paged=%d" % paged if paged else "")


def dot_links(body):
    return [unescape(m) for m in re.findall(r'href="([^"]*action=ofwc_pdf[^"]*)"', body.decode("utf-8", "replace"))]


# ===================================================================================================================
cohort_to()  # known state: the cohort in its own statuses, no leftovers

# ---- baseline ---------------------------------------------------------------------------------------------------
_, _, _, m = http_call("GET", "/wp-admin/admin-post.php?action=perf_noop", ADMIN)
violations_of[id(m)] = violations(m, ok_codes=(200, 302, 400))
record("boot (WordPress + admin)", m)

# ---- admin screens --------------------------------------------------------------------------------------------------
page_step("order list: all orders, status counters", "GET", list_url(), ADMIN, expect=b"In review")
page_step("order list: filtered by a custom status (12,000 orders)", "GET", list_url("of-review"), ADMIN, expect=b"In review")
page_step("order list: filtered by a custom status, page 40", "GET", list_url("of-review", 40), ADMIN, expect=b"In review")
page_step("order list: filtered by another custom status (5,000)", "GET", list_url("of-proof"), ADMIN, expect=b"Proof sent")
normal = cohort_ids("of-proof", 3)[2]
page_step("order screen: normal order", "GET", order_url(normal), ADMIN, expect=b"ofwc-box")
page_step("order screen: history with 200 transitions", "GET", order_url(HIST), ADMIN, expect=b"Status history")
for tab in ("statuses", "transitions", "documents", "import-export"):
    page_step("settings tab: " + tab, "GET", "/wp-admin/admin.php?page=ofwc&tab=" + tab, ADMIN, expect=b"ofwc-tabs")

# ---- My Account ---------------------------------------------------------------------------------------------------------
u1 = helper(OWNER, "urls&n=1")
last_page = (counts["owner_orders"] + 9) // 10
ulast = helper(OWNER, "urls&n=%d" % last_page)
page_step("My Account: orders list, page 1 of the customer with 501 orders", "GET", strip_host(u1.get("orders", "")), OWNER, expect=b"woocommerce-orders-table")
page_step("My Account: orders list, last page (%d)" % last_page, "GET", strip_host(ulast.get("orders", "")), OWNER, expect=b"woocommerce-orders-table")
owner_proof = int(mysql("SELECT o.%s FROM %s o JOIN ofp_cohort c ON c.order_id = o.%s WHERE c.status = 'of-proof' AND %s ORDER BY o.%s LIMIT 1" % (
    ID_COL, ORD, ID_COL, ("o.customer_id = %d" % OWNER) if HPOS else ("EXISTS (SELECT 1 FROM wp_postmeta pm WHERE pm.post_id = o.ID AND pm.meta_key = '_customer_user' AND pm.meta_value = '%d')" % OWNER), ID_COL)) or 0)
vo = helper(OWNER, "urls&id=%d" % owner_proof)
page_step("My Account: one order with its PDF link", "GET", strip_host(vo.get("view_order", "")), OWNER, expect=b"ofwc_pdf")

# ---- PDF ---------------------------------------------------------------------------------------------------------------------
def pdf_path(oid, slug="of-proof"):
    return "/wp-admin/admin-post.php?action=ofwc_pdf&order_id=%d&status=%s&_wpnonce=%s" % (oid, slug, nonce(ADMIN, "ofwc_pdf_%d_%s" % (oid, slug)))


def pdf_note(status, rh, body):
    pages = len(re.findall(rb"/Type\s*/Page[^s]", body))
    ok = body.startswith(b"%PDF")
    return "PDF %s, %d page(s), %.0f KB" % ("ok" if ok else "NOT A PDF", pages, len(body) / 1024.0)


st, rh, body, m = page_step("PDF: a normal order", "GET", pdf_path(normal), ADMIN, query_pass=True, note_fn=pdf_note)
if not body.startswith(b"%PDF"):
    steps[-1]["violations"].append("not a PDF")
st, rh, body, m = page_step("PDF: order with 300 items", "GET", pdf_path(BIG), ADMIN, query_pass=False, note_fn=pdf_note)
pages = len(re.findall(rb"/Type\s*/Page[^s]", body))
notes["big_pdf_pages"] = pages
notes["big_pdf_kb"] = round(len(body) / 1024.0)
if not body.startswith(b"%PDF"):
    steps[-1]["violations"].append("not a PDF")
# the same order with the query analysis (a second, slower run)
_, _, _, q = http_call("GET", pdf_path(BIG), ADMIN, saveq=True)
steps[-1]["queries"] = q

# ---- export and import ---------------------------------------------------------------------------------------------------------
st, rh, exported, m = page_step("export configuration (JSON)", "GET", "/wp-admin/admin-post.php?action=ofwc_export&_ofwc_nonce=" + nonce(ADMIN, "ofwc_export"),
                                ADMIN, expect=b'"format"')
st, rh, body, m = http_call("POST", "/wp-admin/admin-post.php", ADMIN, data={"action": "ofwc_import", "_ofwc_nonce": nonce(ADMIN, "ofwc_import"), "mode": "replace", "ofwc_json": exported.decode()})
violations_of[id(m)] = violations(m, ok_codes=(302,))
record("import configuration (replace, same file)", m)

# ---- one status change and a bulk change -----------------------------------------------------------------------------------------
review_ids = cohort_ids("of-review", 40)
oid = review_ids[0]
st, rh, body, m = http_call("POST", "/wp-admin/admin-post.php", ADMIN, data={"action": "ofwc_transition", "order_id": oid, "ofwc_to": "of-proof", "ofwc_comment": "Checked", "ofwc_nonce": nonce(ADMIN, "ofwc_transition_%d" % oid)})
now = helper(ADMIN, "order&id=%d" % oid).get("status")
violations_of[id(m)] = violations(m, ok_codes=(302,)) + ([] if now == "of-proof" else ["order did not change (%s)" % now])
record("status change by the administrator (admin-post)", m)
oid = review_ids[1]
st, rh, body, m = http_call("POST", "/wp-admin/admin-post.php", MANAGER, data={"action": "ofwc_transition", "order_id": oid, "ofwc_to": "of-proof", "ofwc_nonce": nonce(MANAGER, "ofwc_transition_%d" % oid)})
now = helper(ADMIN, "order&id=%d" % oid).get("status")
violations_of[id(m)] = violations(m, ok_codes=(302,)) + ([] if now == "of-proof" else ["order did not change (%s)" % now])
record("status change by a shop manager under a rule (admin-post)", m)
bulk = review_ids[2:22]
if HPOS:
    bpath = "/wp-admin/admin.php?page=wc-orders"
    bdata = {"action": "ofwc_to_of-ready", "action2": "-1", "page": "wc-orders", "_wpnonce": nonce(ADMIN, "bulk-orders"), "id[]": bulk}
else:
    bpath = "/wp-admin/edit.php"
    bdata = {"action": "ofwc_to_of-ready", "action2": "-1", "post_type": "shop_order", "_wpnonce": nonce(ADMIN, "bulk-posts"), "post[]": bulk}
st, rh, body, m = http_call("POST", bpath, ADMIN, data=bdata)
moved = int(mysql("SELECT COUNT(*) FROM %s WHERE %s IN (%s) AND %s = 'wc-of-ready'" % (ORD, ID_COL, ",".join(str(i) for i in bulk), ST_COL)) or 0)
violations_of[id(m)] = violations(m, ok_codes=(200, 302)) + ([] if moved == 20 else ["bulk moved %d of 20" % moved])
record("bulk action on 20 orders (administrator)", m, note="%d of 20 moved" % moved)

# ---- delete a status with 20,000 orders -------------------------------------------------------------------------------------------------
cohort_to(status="of-review")
before = count_status("of-review")
page_step_ok = http_call("GET", "/wp-admin/admin.php?page=ofwc&tab=statuses&view=delete&status=of-review", ADMIN)
form_nonce = re.search(rb'name="_wpnonce" value="([0-9a-f]+)"', page_step_ok[2])
st, rh, body, m = http_call("POST", "/wp-admin/admin-post.php", ADMIN, data={"action": "ofwc_delete_status", "_wpnonce": form_nonce.group(1).decode() if form_nonce else "", "status": "of-review", "target": "on-hold"})
moved_first = count_status("on-hold")
violations_of[id(m)] = violations(m, ok_codes=(302,))
record("delete a status with %s orders: the request itself" % format(before, ","), m, note="first request moved %s orders; location %s" % (format(moved_first, ","), strip_host(rh.get("location", ""))[:70]))
jobs = []
t_bg = time.time()
stalled = 0
last_seen = count_status("on-hold")
for i in range(400):
    pending = mysql("SELECT option_value FROM %soptions WHERE option_name = 'ofwc_status_deletions'" % P)
    if not pending or pending in ("a:0:{}",):
        break
    seen = count_status("on-hold")
    stalled = 0 if seen > last_seen else stalled + 1
    last_seen = seen
    if stalled >= 5:
        notes["delete_stalled"] = True
        break
    mm = as_http_run()
    jobs.append(mm)
bg_total = time.time() - t_bg
on_hold_now = count_status("on-hold")
if jobs:
    worst = max(jobs, key=ms)
    worst_mem = max(jobs, key=lambda j: j.get("peak_bytes", 0))
    agg = dict(worst)
    agg["peak_bytes"] = worst_mem.get("peak_bytes", 0)
    agg["queries"] = max(j.get("queries", 0) for j in jobs)
    violations_of[id(agg)] = [v for j in jobs for v in violations(j, ok_codes=(200, 400), check_time=False)][:3] + ([] if on_hold_now == before else ["moved %d of %d" % (on_hold_now, before)])
    record("delete a status: background jobs (worst of %d)" % len(jobs), agg, note="%d Action Scheduler jobs, %.0f s in total, %s orders in On hold" % (len(jobs), bg_total, format(on_hold_now, ",")))
    notes["delete_jobs"] = len(jobs)
else:
    notes["delete_jobs"] = 0
    agg = {"wall_ms": 0, "peak_bytes": 0}
    violations_of[id(agg)] = [] if on_hold_now == before else ["moved %d of %d" % (on_hold_now, before)]
    record("delete a status: background jobs (none were needed)", agg, note="%s orders in On hold" % format(on_hold_now, ","))
wp("eval-file", "/repo/bin/perf-state.php")  # the deleted status comes back for the next step

# ---- deactivate with "move to On hold" and restore ----------------------------------------------------------------------------------------------
cohort_to(status="of-proof")
wp("eval-file", "/repo/bin/perf-state.php")
total_orders = count_status("of-proof")
st, rh, body, m = http_call("GET", "/wp-admin/admin.php?page=ofwc-deactivate", ADMIN)
nm = re.search(rb'name="_ofwc_nonce" value="([^"]+)"', body)
violations_of[id(m)] = violations(m, ok_codes=(200,), expect=b"Move them to On hold", body=body)
record("deactivate screen with %s orders in custom statuses" % format(total_orders, ","), m)
st, rh, body, m = http_call("POST", "/wp-admin/admin-post.php", ADMIN, data={"action": "ofwc_deactivate", "_ofwc_nonce": nm.group(1).decode() if nm else "", "choice": "move"})
violations_of[id(m)] = violations(m, ok_codes=(302,))
record("deactivate: choose \"move to On hold\"", m)
loc = strip_host(rh.get("location", ""))
batches = []
t_move = time.time()
for i in range(500):
    if "plugins.php" in loc:
        break
    if "ofwc_deactivate_run" in loc:
        st, rh, body, m = http_call("GET", loc, ADMIN)
        violations_of[id(m)] = violations(m, ok_codes=(302,))
        batches.append(m)
        loc = strip_host(rh.get("location", ""))
        continue
    st, rh, body, m = http_call("GET", loc, ADMIN)
    link = re.search(rb'id="ofwc-continue" href="([^"]+)"', body)
    if st == 200 and link:
        loc = strip_host(unescape(link.group(1).decode()))
        continue
    break
move_total = time.time() - t_move
parked = count_status("on-hold")
if batches:
    worst = max(batches, key=ms)
    worst_mem = max(batches, key=lambda j: j.get("peak_bytes", 0))
    agg = dict(worst)
    agg["peak_bytes"] = worst_mem.get("peak_bytes", 0)
    agg["queries"] = max(j.get("queries", 0) for j in batches)
    violations_of[id(agg)] = sorted({v for b in batches for v in violations_of.get(id(b), [])}) + ([] if parked == total_orders else ["parked %d of %d" % (parked, total_orders)])
    record("deactivate: each batch of the progress page (worst of %d)" % len(batches), agg,
           note="%d requests, %.0f s in total, %s of %s orders in On hold" % (len(batches), move_total, format(parked, ","), format(total_orders, ",")))
    notes["deactivate_batches"] = len(batches)
    notes["deactivate_seconds"] = round(move_total)
    notes["deactivate_avg_ms"] = round(sum(ms(b) for b in batches) / len(batches))
else:
    agg = {"wall_ms": 0, "peak_bytes": 0}
    violations_of[id(agg)] = ["no batch ran"]
    record("deactivate: batches", agg)
active = "order-flow-for-woocommerce" in wp("plugin", "list", "--status=active", "--field=name")[1]
if active:
    steps[-1]["violations"].append("plugin still active")

# Activate again and let Action Scheduler restore the orders, one job at a time, each in its own limited process.
rc, so, se, wall = wp("plugin", "activate", "order-flow-for-woocommerce")
am = {"driver_wall_ms": round(wall, 1), "peak_bytes": 0}
violations_of[id(am)] = [] if rc == 0 else ["activation failed"]
record("activate again (WP-CLI, not measured against the limits)", am)
st, rh, body, m = http_call("GET", "/wp-admin/index.php", ADMIN)
violations_of[id(m)] = violations(m, ok_codes=(200,))
record("first admin page after activation (queues the restore)", m)
jobs = []
t_bg = time.time()
stalled = 0
last_left = None
for i in range(400):
    left = int(mysql("SELECT COUNT(*) FROM %s WHERE meta_key = '_ofwc_pre_deactivation_status'" % META) or 0)
    if left == 0:
        break
    stalled = stalled + 1 if last_left is not None and left >= last_left else 0
    last_left = left
    if stalled >= 5:
        notes["restore_stalled"] = True
        break
    mm = as_http_run()
    jobs.append(mm)
bg_total = time.time() - t_bg
restored = count_status("of-proof")
left = int(mysql("SELECT COUNT(*) FROM %s WHERE meta_key = '_ofwc_pre_deactivation_status'" % META) or 0)
if jobs:
    worst = max(jobs, key=ms)
    worst_mem = max(jobs, key=lambda j: j.get("peak_bytes", 0))
    agg = dict(worst)
    agg["peak_bytes"] = worst_mem.get("peak_bytes", 0)
    agg["queries"] = max(j.get("queries", 0) for j in jobs)
    violations_of[id(agg)] = sorted({v for j in jobs for v in violations(j, ok_codes=(200, 400), check_time=False)}) + ([] if restored == total_orders and left == 0 else ["restored %d of %d, %d markers left" % (restored, total_orders, left)])
    record("restore after activation: background jobs (worst of %d)" % len(jobs), agg,
           note="%d Action Scheduler jobs, %.0f s in total, %s of %s orders back" % (len(jobs), bg_total, format(restored, ","), format(total_orders, ",")))
    notes["restore_jobs"] = len(jobs)
    notes["restore_seconds"] = round(bg_total)
    notes["restore_avg_ms"] = round(sum(ms(j) for j in jobs) / len(jobs))
else:
    agg = {"wall_ms": 0, "peak_bytes": 0}
    violations_of[id(agg)] = ["no restore job ran"]
    record("restore after activation: background jobs", agg)

# Leave the data set as it was found.
cohort_to()

php_v = subprocess.run(["php", "-r", "echo PHP_VERSION;"], capture_output=True, text=True).stdout
my_v = subprocess.run(["mysql", "-h", "db", "-uroot", "-pwordpress", "-N", "-e", "SELECT VERSION(), @@innodb_buffer_pool_size"], capture_output=True, text=True).stdout.strip().split("\t")
tables = {}
tr = subprocess.run(["mysql", "-h", "db", "-uroot", "-pwordpress", "-N", "-e",
                     "SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576) FROM information_schema.TABLES WHERE TABLE_SCHEMA='%s' AND TABLE_ROWS > 1000 ORDER BY TABLE_ROWS DESC" % db],
                    capture_output=True, text=True)
for line in tr.stdout.strip().split("\n"):
    parts = line.split("\t")
    if len(parts) == 3:
        tables[parts[0]] = {"rows": int(parts[1]), "mb": int(parts[2])}

summary = {
    "counts": counts, "notes": notes, "tables": tables, "php": php_v, "mysql": my_v, "hpos": hpos, "db": db, "steps": steps,
    "total_s": round(time.time() - t_all, 1), "violations": sum(len(s["violations"]) for s in steps),
}
with open(result_path, "w") as fh:
    json.dump(summary, fh)
print("driver done: %d steps, %d violations, %.0f s" % (len(steps), summary["violations"], summary["total_s"]))
