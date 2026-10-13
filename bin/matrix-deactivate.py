#!/usr/bin/env python3
"""Deactivation through the admin screens, over HTTP (development only, not shipped).

  matrix-deactivate.py <base-path> keep|move|visit

keep   open the Deactivate screen as the administrator and choose "Keep orders in their statuses".
move   choose "Move them to On hold now": follows the progress page batch by batch until the plugin is deactivated.
visit  load the dashboard once (what a browser does after activation; queues the restore when it was deferred).
Prints "DEACTIVATE <mode> requests=N batches=M result=ok|FAILED detail". Exit code 0 on success.
"""
import http.client
import re
import sys
import urllib.parse
from html import unescape

HOST = "of-matrix.test"
BASE = sys.argv[1].rstrip("/")
MODE = sys.argv[2]
PASS = "matrix-Pass-123!"
cookies = {}
requests = 0


def req(method, path, data=None):
    global requests
    requests += 1
    conn = http.client.HTTPConnection("127.0.0.1", 80, timeout=300)
    headers = {"Host": HOST, "User-Agent": "of-matrix-deactivate"}
    if cookies:
        headers["Cookie"] = "; ".join("%s=%s" % kv for kv in cookies.items())
    body = None
    if data is not None:
        body = urllib.parse.urlencode(data)
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    conn.request(method, path, body=body, headers=headers)
    resp = conn.getresponse()
    raw = resp.read()
    for k, v in resp.getheaders():
        if k.lower() == "set-cookie":
            name, _, value = v.split(";", 1)[0].partition("=")
            if value in ("", "deleted"):
                cookies.pop(name, None)
            else:
                cookies[name] = value
    loc = re.sub(r"^https?://" + re.escape(HOST), "", resp.getheader("Location") or "")
    conn.close()
    return resp.status, loc, raw.decode("utf-8", "replace")


def fail(detail):
    print("DEACTIVATE %s requests=%d batches=0 result=FAILED %s" % (MODE, requests, detail))
    sys.exit(1)


cookies["wordpress_test_cookie"] = "WP%20Cookie%20check"
req("GET", BASE + "/wp-login.php")
status, loc, _ = req("POST", BASE + "/wp-login.php", {"log": "admin", "pwd": PASS, "wp-submit": "Log In", "redirect_to": BASE + "/wp-admin/", "testcookie": "1"})
if status != 302 or not any(k.startswith("wordpress_logged_in") for k in cookies):
    fail("login failed, HTTP %s" % status)

if MODE == "visit":
    status, loc, body = req("GET", BASE + "/wp-admin/index.php")
    if status != 200:
        fail("dashboard HTTP %s" % status)
    print("DEACTIVATE visit requests=%d batches=0 result=ok" % requests)
    sys.exit(0)

status, loc, body = req("GET", BASE + "/wp-admin/admin.php?page=ofwc-deactivate")
m = re.search(r'name="_ofwc_nonce" value="([^"]+)"', body)
if status != 200 or not m or 'value="move"' not in body:
    fail("deactivate screen HTTP %s, nonce %s" % (status, bool(m)))
status, loc, body = req("POST", BASE + "/wp-admin/admin-post.php", {"action": "ofwc_deactivate", "_ofwc_nonce": m.group(1), "choice": MODE})
batches = 0
for _ in range(400):
    if "plugins.php" in loc and "deactivate=true" in loc:
        print("DEACTIVATE %s requests=%d batches=%d result=ok" % (MODE, requests, batches))
        sys.exit(0)
    if status not in (301, 302, 303) or not loc:
        fail("unexpected answer HTTP %s at batch %d, requested %s: %s" % (status, batches, asked if "asked" in dir() else loc, re.sub(r"<[^>]+>", " ", body)[-160:].replace("\n", " ")))
    if "ofwc_deactivate_run" in loc:
        batches += 1
        asked_run = loc
        status, nxt, body = req("GET", loc)
        if status not in (301, 302, 303):
            fail("batch %d answered HTTP %s for %s: %s" % (batches, status, asked_run, re.sub(r"<[^>]+>", " ", body)[-120:].replace("\n", " ")))
        loc = nxt
        continue
    asked = loc
    status, loc2, body = req("GET", loc)
    if status == 200 and "ofwc-continue" in body:
        link = re.search(r'id="ofwc-continue" href="([^"]+)"', body)
        if not link:
            fail("progress page without a continue link")
        loc = re.sub(r"^https?://" + re.escape(HOST), "", unescape(link.group(1)))
        status = 302
        continue
    if status == 200 and "ofwc-continue" not in body:
        fail("progress page stopped without finishing: " + re.sub(r"<[^>]+>", " ", body)[-200:].replace("\n", " "))
    loc = loc2
fail("did not finish in 400 requests")
