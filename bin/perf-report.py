#!/usr/bin/env python3
"""Performance report (development only, not shipped).

  perf-report.py <cache> <docs/PERF.md | /dev/null>

Reads <cache>/results/{on,off}.json and meta.kv, prints a summary, rewrites the generated block of docs/PERF.md
(between the perf:results markers; the text around it is hand written and kept) and exits non-zero on any violation.
"""
import json
import os
import re
import sys

cache, doc = sys.argv[1], sys.argv[2]
res = os.path.join(cache, "results")

meta = {}
try:
    for line in open(os.path.join(res, "meta.kv")):
        if "=" in line:
            k, v = line.strip().split("=", 1)
            meta[k] = v
except FileNotFoundError:
    pass

passes = {}
for h in ("on", "off"):
    try:
        passes[h] = json.load(open(os.path.join(res, h + ".json")))
    except FileNotFoundError:
        pass

if not passes:
    print("no results were written; see logs/last-run.log", file=sys.stderr)
    sys.exit(2)

MB = 1048576.0


def ms(m):
    return m.get("wall_ms", m.get("driver_wall_ms", 0))


def label_md(s):
    return s.replace("|", "\\|")


def short_sql(s, n=150):
    s = re.sub(r"\s+", " ", s or "").strip()
    return s if len(s) <= n else s[: n - 3] + "..."


def plugin_made(caller):
    return "OrderFlowWC" in (caller or "") or "order-flow-for-woocommerce" in (caller or "")


out = []
violations = []
setup_failed = False
findings_scan = []
findings_n1 = []

for h in ("on", "off"):
    d = passes.get(h)
    if not d:
        continue
    if d.get("setup_failed"):
        setup_failed = True
        violations.append("HPOS %s: setup failed" % h)
        continue
    boot = next((s for s in d["steps"] if s["label"].startswith("boot")), None)
    boot_peak = boot["plain"].get("peak_bytes", 0) if boot else 0
    boot_ms = ms(boot["plain"]) if boot else 0
    title = "HPOS on (orders in the dedicated tables)" if h == "on" else "HPOS off (orders in posts and postmeta, sync off)"
    out.append("### %s\n" % title)
    c = d.get("counts") or {}
    if c:
        out.append(
            "Data set: %s products, %s orders (%s of them in three Order Flow statuses), %s order items, %s users (%s administrators, %s customers). "
            "One customer has %s orders, one order has a 200-entry status history, one order has 300 items (PDF of %s pages).\n"
            % (format(c.get("products", 0), ","), format(c.get("orders", 0), ","), format(c.get("cohort", 0), ","),
               format(c.get("items", 0), ","), format(c.get("users", 0), ","), c.get("admins"), c.get("customers"),
               c.get("owner_orders"), (d.get("notes") or {}).get("big_pdf_pages", "?"))
        )
    out.append("| Step | Time ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans (plugin) | N+1 shapes (plugin) | Note |")
    out.append("|---|---:|---:|---:|---:|---:|---:|---:|---:|---|")
    highlights = []
    for s in d["steps"]:
        p, q = s["plain"], s["queries"]
        a = (q or {}).get("analysis") or {}
        scans = [e for e in a.get("explain", []) if e.get("flag") == "full_scan"]
        n1 = a.get("n_plus_1", [])
        slow = (a.get("slowest") or {}).get("ms", "")
        peak = p.get("peak_bytes", 0) / MB
        over = max(0.0, (p.get("peak_bytes", 0) - boot_peak) / MB)
        t = ms(p)
        is_boot = s["label"].startswith("boot")
        flag_t = t > 5000
        flag_m = (not is_boot) and over > 64
        name = label_md(s["label"])
        if flag_t or flag_m:
            highlights.append((s["label"], t, peak, over))
            name = "**%s**" % name
        out.append("| %s | %d | %.0f | %s | %s | %s | %s | %d (%d) | %d (%d) | %s |" % (
            name, round(t), peak, "" if is_boot else "%.0f" % over, p.get("queries", ""), a.get("sql_ms", ""), slow,
            len(scans), len([e for e in scans if plugin_made(e.get("caller"))]), len(n1), len([e for e in n1 if plugin_made(e.get("caller"))]),
            label_md(s.get("note", ""))))
        for v in s["violations"]:
            violations.append("HPOS %s, %s: %s" % (h, s["label"], v))
        for e in scans:
            findings_scan.append((h, s["label"], e))
        for e in n1:
            findings_n1.append((h, s["label"], e))
    out.append("")
    if highlights:
        out.append("Highlighted (time over 5 s, or more than 64 MB used by the step itself on top of its own boot): " + "; ".join(
            "%s (%.1f s, %.0f MB, +%.0f MB)" % (l, t / 1000.0, pk, ov) for l, t, pk, ov in highlights) + ".\n")
    else:
        out.append("Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.\n")
    worst_t = max((s for s in d["steps"]), key=lambda s: ms(s["plain"]))
    worst_m = max((s for s in d["steps"]), key=lambda s: s["plain"].get("peak_bytes", 0))
    out.append("Slowest step: %s, %.2f s. Highest peak: %s, %.0f MB. WordPress with WooCommerce and the admin includes alone (boot): %.0f MB, %.2f s. Whole pass: %.0f s.\n" % (
        worst_t["label"], ms(worst_t["plain"]) / 1000.0, worst_m["label"], worst_m["plain"].get("peak_bytes", 0) / MB,
        boot_peak / MB, boot_ms / 1000.0, d.get("total_s", 0)))
    tb = d.get("tables") or {}
    if tb:
        out.append("Largest tables: " + ", ".join("%s %s rows (%s MB)" % (k, format(v["rows"], ","), v["mb"]) for k, v in list(tb.items())[:8]) + ".\n")

slow_block = []

# Environment line
first = next(iter(passes.values()))
env_line = "PHP %s, MySQL %s (InnoDB buffer pool %s MB), WordPress %s, WooCommerce %s." % (
    (first.get("php") or "?").strip(), (first.get("mysql") or ["?"])[0], round(int((first.get("mysql") or ["0", "0"])[1]) / MB) if first.get("mysql") and len(first["mysql"]) > 1 else "?",
    meta.get("wp", "?"), meta.get("woo", "?"))

block = []
block.append("Environment: %s Plugin zip %s. Run time %s s.\n" % (env_line, meta.get("zip_sha", "?"), meta.get("elapsed", "?")))
block.extend(out)
block.extend(slow_block)

timeouts = [v for v in violations if "timeout" in v]
mem = [v for v in violations if "memory limit" in v or "peak over" in v]
other = [v for v in violations if v not in timeouts and v not in mem]
if not violations:
    verdict = "**Result: 0 timeouts at 30 s, 0 memory errors at 128M, 0 errors in %d measured steps.**" % sum(len(d.get("steps", [])) for d in passes.values())
else:
    verdict = "**Result: %d violation(s).** " % len(violations) + "; ".join(violations)
block.insert(0, verdict + "\n")

if findings_scan or findings_n1:
    block.append("### Query findings (SAVEQUERIES, EXPLAIN)\n")
    seen = set()
    rows = []
    for h, lab, e in findings_scan:
        if not plugin_made(e.get("caller")):
            continue
        e = dict(e)
        e["origin"] = "plugin" if plugin_made(e.get("caller")) else "core or WooCommerce"
        key = (e["table"], short_sql(e["sql"], 100))
        if key in seen:
            continue
        seen.add(key)
        rows.append("| %s | %s | %s | `%s` | %s | %s | %s | `%s` |" % (h, label_md(lab), e["origin"], e["table"], e["type"], format(e["tbl_rows"], ","), e["ms"], label_md(short_sql(e["sql"], 160))))
    if rows:
        block.append("Full table scans (`type=ALL` on a table with more than 10,000 rows) in the plugin's own queries:\n")
        block.append("| HPOS | Step | Origin | Table | Type | Table rows | Query ms | Query |")
        block.append("|---|---|---|---|---|---:|---:|---|")
        block.extend(rows)
        block.append("")
    rows = []
    seen = set()
    core_n1 = len([1 for h, lab, e in findings_n1 if not plugin_made(e.get("caller"))])
    for h, lab, e in findings_n1:
        if not plugin_made(e.get("caller")):
            continue
        key = (lab, short_sql(e["sql"], 100))
        if key in seen:
            continue
        seen.add(key)
        rows.append("| %s | %s | %s | %d | %s | `%s` |" % (h, label_md(lab), "plugin" if plugin_made(e.get("caller")) else "core or WooCommerce", e["n"], e["ms"], label_md(short_sql(e["sql"], 140))))
    if rows:
        block.append("Repeated query shapes (more than 10 times in one step) that come from the plugin:\n")
        block.append("| HPOS | Step | Origin | Times | Total ms | Query |")
        block.append("|---|---|---|---:|---:|---|")
        block.extend(rows)
        block.append("")

if findings_scan or findings_n1:
    block.append(
        "Repeated query shapes and full scans that come from WordPress core or WooCommerce (not listed): %d repeated shapes, %d scans across all steps. "
        "They are the same on a store without the plugin.\n" % (len([1 for h, lab, e in findings_n1 if not plugin_made(e.get("caller"))]), len([1 for h, lab, e in findings_scan if not plugin_made(e.get("caller"))]))
    )
text = "\n".join(block)
BEGIN, END = "<!-- perf:results:start -->", "<!-- perf:results:end -->"

print(re.sub(r"\*\*", "", verdict))
for v in violations:
    print("  VIOLATION:", v)

if doc != "/dev/null":
    try:
        old = open(doc).read()
    except FileNotFoundError:
        old = ""
    if BEGIN in old and END in old:
        new = re.sub(re.escape(BEGIN) + ".*?" + re.escape(END), lambda m: BEGIN + "\n" + text + "\n" + END, old, flags=re.S)
    else:
        new = old + "\n" + BEGIN + "\n" + text + "\n" + END + "\n"
    with open(doc, "w") as fh:
        fh.write(new)
    print("wrote", doc)

sys.exit(2 if setup_failed else (1 if violations else 0))
