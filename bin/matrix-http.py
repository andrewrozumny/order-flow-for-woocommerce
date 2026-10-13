#!/usr/bin/env python3
"""Browserless smoke test for the compatibility matrix (development only, not shipped).

  matrix-http.py <base-path> <smoke.json> [hpos]

Talks to the throwaway site over HTTP like a browser would (cookie login, wp-admin pages, admin-post.php), as the
administrator, a shop manager, a subscriber, two customers and a visitor. Prints one STEP PASS/FAIL line per check and
a closing "SMOKE PASS" or "SMOKE FAIL" line. The site answers on 127.0.0.1:80 with the host name of-matrix.test.
"""
import http.client
import json
import re
import sys
import urllib.parse
from html import unescape

HOST = "of-matrix.test"
BASE = sys.argv[1].rstrip("/")
DATA = json.load(open(sys.argv[2]))
PASS = DATA["pass"]
STEPS = []


def step(name, ok, detail=""):
    STEPS.append(ok)
    print(("STEP PASS " if ok else "STEP FAIL ") + name + (" :: " + detail if detail else ""), flush=True)


class Session:
    def __init__(self, label):
        self.label = label
        self.cookies = {}

    def request(self, method, path, data=None, follow=False, extra=None):
        for _ in range(8):
            conn = http.client.HTTPConnection("127.0.0.1", 80, timeout=120)
            headers = {"Host": HOST, "User-Agent": "of-matrix-smoke"}
            if self.cookies:
                headers["Cookie"] = "; ".join("%s=%s" % kv for kv in self.cookies.items())
            body = None
            if data is not None:
                body = urllib.parse.urlencode(data, doseq=True)
                headers["Content-Type"] = "application/x-www-form-urlencoded"
            if extra:
                headers.update(extra)
            conn.request(method, path, body=body, headers=headers)
            resp = conn.getresponse()
            raw = resp.read()
            for k, v in resp.getheaders():
                if k.lower() == "set-cookie":
                    first = v.split(";", 1)[0]
                    if "=" in first:
                        name, value = first.split("=", 1)
                        if value in ("", "deleted"):
                            self.cookies.pop(name, None)
                        else:
                            self.cookies[name] = value
            loc = resp.getheader("Location") or ""
            hdrs = {k.lower(): v for k, v in resp.getheaders()}
            conn.close()
            if follow and resp.status in (301, 302, 303, 307) and loc:
                loc = re.sub(r"^https?://" + re.escape(HOST) + r"(:\d+)?", "", loc)
                path, method, data = loc, "GET", None
                continue
            return resp.status, hdrs, raw
        return 599, {}, b"too many redirects"

    def get(self, path, **kw):
        return self.request("GET", path, **kw)

    def post(self, path, data, **kw):
        return self.request("POST", path, data=data, **kw)

    def login(self, user):
        self.cookies = {"wordpress_test_cookie": "WP%20Cookie%20check"}
        self.get(BASE + "/wp-login.php")
        status, hdrs, _ = self.post(BASE + "/wp-login.php", {
            "log": user, "pwd": PASS, "wp-submit": "Log In", "redirect_to": BASE + "/wp-admin/", "testcookie": "1"})
        ok = status == 302 and any(k.startswith("wordpress_logged_in") for k in self.cookies)
        step("login as %s" % user, ok, "" if ok else "HTTP %s, cookies %s" % (status, list(self.cookies)))
        return ok

    def json(self, path):
        status, _, raw = self.get(path)
        try:
            return json.loads(raw.decode("utf-8", "replace"))
        except ValueError:
            return {"_error": "HTTP %s, not JSON: %r" % (status, raw[:120])}

    def nonce(self, action):
        return self.json(BASE + "/?of_matrix=nonce&action=" + urllib.parse.quote(action)).get("nonce", "")

    def order(self, oid):
        return self.json(BASE + "/?of_matrix=order&id=%d" % oid)


def text(raw):
    return raw.decode("utf-8", "replace")


def has_php_noise(body):
    return re.search(r"(Fatal error|Warning|Notice|Deprecated|Parse error): ", body) is not None and "<b>" in body


O = DATA["orders"]
HPOS = (sys.argv[3] == "on") if len(sys.argv) > 3 else False


def order_edit_url(oid):
    if HPOS:
        return BASE + "/wp-admin/admin.php?page=wc-orders&action=edit&id=%d" % oid
    return BASE + "/wp-admin/post.php?post=%d&action=edit" % oid


def list_url(status=None):
    if HPOS:
        return BASE + "/wp-admin/admin.php?page=wc-orders" + ("&status=" + status if status else "")
    return BASE + "/wp-admin/edit.php?post_type=shop_order" + ("&post_status=" + status if status else "")


def pdf_urls(body):
    return [unescape(m) for m in re.findall(r'href="([^"]*action=ofwc_pdf[^"]*)"', body)]


def pdf_url_for(urls, oid, slug):
    for u in urls:
        if ("order_id=%d" % oid) in u and ("status=" + slug) in u:
            return re.sub(r"^https?://" + re.escape(HOST), "", u)
    return None


def is_pdf(status, hdrs, raw):
    return status == 200 and raw.startswith(b"%PDF") and "application/pdf" in hdrs.get("content-type", "")


def refused(status, hdrs, raw):
    """A refusal: an error status, or at least no PDF and no JSON export body."""
    return not raw.startswith(b"%PDF") and not raw.lstrip().startswith(b'{"format"') and status != 200 or (status == 200 and len(raw.strip()) == 0)


# ---------------------------------------------------------------------------------------------
admin, manager, sub, cust_a, cust_b, guest = (Session(n) for n in ("admin", "manager", "sub", "cust_a", "cust_b", "guest"))
if not (admin.login("admin") and manager.login("of_sm_manager") and sub.login("of_sm_sub") and cust_a.login("of_sm_cust_a") and cust_b.login("of_sm_cust_b")):
    print("SMOKE FAIL steps=%d failed=%d" % (len(STEPS), STEPS.count(False)))
    sys.exit(1)

info = admin.json(BASE + "/?of_matrix=urls&id=%d" % O["a_owned"])
step("storage matches the requested HPOS state", info.get("hpos") == HPOS, "site says hpos=%s" % info.get("hpos"))

# ---- settings page -------------------------------------------------------------------------------
for label, qs in (("statuses", "tab=statuses"), ("statuses add", "tab=statuses&view=add"), ("statuses edit", "tab=statuses&view=edit&status=of-sm-a"),
                  ("statuses delete", "tab=statuses&view=delete&status=of-sm-a"), ("transitions", "tab=transitions"),
                  ("documents", "tab=documents"), ("import and export", "tab=import-export")):
    status, _, raw = admin.get(BASE + "/wp-admin/admin.php?page=ofwc&" + qs)
    body = text(raw)
    ok = status == 200 and "ofwc-tabs" in body and "nav-tab-active" in body and not has_php_noise(body)
    step("settings tab renders for the administrator: " + label, ok, "HTTP %s, %d bytes" % (status, len(raw)))
status, _, raw = admin.get(BASE + "/wp-admin/admin.php?page=ofwc&tab=statuses")
step("statuses tab lists the test statuses", b"SM Alpha" in raw and b"SM Beta" in raw)
status, _, raw = manager.get(BASE + "/wp-admin/admin.php?page=ofwc&tab=statuses")
step("shop manager cannot open the settings page", status in (403, 302) and b"ofwc-tabs" not in raw, "HTTP %s" % status)
status, _, raw = sub.get(BASE + "/wp-admin/admin.php?page=ofwc")
step("subscriber cannot open the settings page", status in (403, 302) and b"ofwc-tabs" not in raw, "HTTP %s" % status)
status, hdrs, raw = guest.get(BASE + "/wp-admin/admin.php?page=ofwc")
step("visitor is sent to the login page", status == 302 and "wp-login.php" in hdrs.get("location", ""), "HTTP %s" % status)

# ---- order list and order screen -----------------------------------------------------------------
status, _, raw = admin.get(list_url())
body = text(raw)
step("order list shows the custom statuses in its views", status == 200 and "SM Alpha" in body and "SM Beta" in body and not has_php_noise(body), "HTTP %s" % status)
step("order list offers the Order Flow bulk actions", 'value="ofwc_to_of-sm-b"' in body)
status, _, raw = admin.get(list_url("wc-of-sm-a"))
body = text(raw)
row = lambda oid: re.search(r'id="(?:order|post)-%d"' % oid, body) is not None
step("order list filtered by a custom status shows its orders only", status == 200 and row(O["a_owned"]) and row(O["b_owned"]) and not row(O["a_hidden"]),
     "HTTP %s a_owned=%s b_owned=%s hidden=%s" % (status, row(O["a_owned"]), row(O["b_owned"]), row(O["a_hidden"])))
status, _, raw = admin.get(list_url("wc-of-sm-b"))
body = text(raw)
step("order list filtered by the other custom status", status == 200 and row(O["a_hidden"]) and not row(O["a_owned"]))

status, _, raw = admin.get(order_edit_url(O["a_for_adm"]))
body = text(raw)
nonce_m = re.search(r'class="ofwc-box"[^>]*data-nonce="([a-f0-9]+)"', body)
step("order screen shows the Order Flow box with history and targets", status == 200 and nonce_m is not None and 'data-to="of-sm-b"' in body and "Status history" in body and not has_php_noise(body),
     "HTTP %s box=%s" % (status, nonce_m is not None))
step("order screen has the custom status in the status select", "wc-of-sm-a" in body and "SM Beta" in body)
admin_pdf_urls = pdf_urls(body)
step("order screen offers the PDF download", pdf_url_for(admin_pdf_urls, O["a_for_adm"], "of-sm-a") is not None)

# ---- transitions through admin-post.php ---------------------------------------------------------
def transition(session, oid, to, comment=""):
    status, _, raw = session.get(order_edit_url(oid))
    m = re.search(r'class="ofwc-box"[^>]*data-nonce="([a-f0-9]+)"', text(raw))
    nonce = m.group(1) if m else ""
    return session.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_transition", "order_id": oid, "ofwc_to": to, "ofwc_comment": comment, "ofwc_nonce": nonce})

before = admin.order(O["a_for_adm"])
status, hdrs, raw = transition(admin, O["a_for_adm"], "of-sm-b", "Checked by the smoke test")
after = admin.order(O["a_for_adm"])
step("administrator moves an order through admin-post.php", status == 302 and after.get("status") == "of-sm-b" and after.get("history", 0) > before.get("history", 0),
     "HTTP %s status %s -> %s" % (status, before.get("status"), after.get("status")))

status, _, raw = manager.get(order_edit_url(O["a_for_mgr"]))
step("shop manager sees the allowed target on the order screen", status == 200 and 'data-to="of-sm-b"' in text(raw), "HTTP %s" % status)
status, hdrs, raw = transition(manager, O["a_for_mgr"], "of-sm-b")
after = manager.order(O["a_for_mgr"])
step("shop manager moves an order through admin-post.php (rule allows it)", status == 302 and after.get("status") == "of-sm-b", "HTTP %s status %s" % (status, after.get("status")))
status, hdrs, raw = transition(manager, O["a_for_mgr"], "of-sm-a")
after = manager.order(O["a_for_mgr"])
step("shop manager is refused the opposite move (no rule)", after.get("status") == "of-sm-b", "HTTP %s status %s" % (status, after.get("status")))
n = manager.nonce("ofwc_transition_%d" % O["b_owned"])
status, hdrs, raw = manager.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_transition", "order_id": O["b_owned"], "ofwc_to": "of-sm-b"})
after = manager.order(O["b_owned"])
step("a transition request without a nonce changes nothing", status in (403, 302) and after.get("status") == "of-sm-a", "HTTP %s status %s" % (status, after.get("status")))
n = sub.nonce("ofwc_transition_%d" % O["b_owned"])
status, hdrs, raw = sub.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_transition", "order_id": O["b_owned"], "ofwc_to": "of-sm-b", "ofwc_nonce": n})
after = sub.order(O["b_owned"])
step("subscriber cannot move an order even with a valid nonce", status == 403 and after.get("status") == "of-sm-a", "HTTP %s status %s" % (status, after.get("status")))
status, hdrs, raw = guest.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_transition", "order_id": O["b_owned"], "ofwc_to": "of-sm-b", "ofwc_nonce": "0123456789"})
after = admin.order(O["b_owned"])
step("visitor cannot move an order", after.get("status") == "of-sm-a", "HTTP %s status %s" % (status, after.get("status")))

# ---- PDF downloads -------------------------------------------------------------------------------
status, _, raw = admin.get(order_edit_url(O["a_owned"]))
a_url = pdf_url_for(pdf_urls(text(raw)), O["a_owned"], "of-sm-a")
step("administrator finds the PDF link of an order", a_url is not None)
if a_url:
    res = admin.get(a_url)
    step("administrator downloads the PDF", is_pdf(*res), "HTTP %s %s %d bytes" % (res[0], res[1].get("content-type"), len(res[2])))
    res = manager.get(a_url)
    step("a link signed for another user is refused (shop manager)", not res[2].startswith(b"%PDF"), "HTTP %s" % res[0])
    res = guest.get(a_url)
    step("visitor does not get the PDF", not res[2].startswith(b"%PDF"), "HTTP %s" % res[0])
    res = admin.get(re.sub(r"&_wpnonce=[0-9a-f]+", "", a_url))
    step("PDF link without a nonce is refused", res[0] == 403 and not res[2].startswith(b"%PDF"), "HTTP %s" % res[0])

status, _, raw = cust_a.get(BASE + "/?of_matrix=urls&id=%d" % O["a_owned"])
urls = json.loads(raw.decode())
status, _, raw = cust_a.get(re.sub(r"^https?://" + re.escape(HOST), "", urls["view_order"]), follow=True)
body = text(raw)
owner_url = pdf_url_for(pdf_urls(body), O["a_owned"], "of-sm-a")
step("My Account order page offers the PDF to its owner", status == 200 and owner_url is not None, "HTTP %s" % status)
if owner_url:
    res = cust_a.get(owner_url)
    step("customer downloads the PDF of their own order", is_pdf(*res), "HTTP %s %s" % (res[0], res[1].get("content-type")))
    # Same order, other users with their own valid nonce.
    for who, sess in (("another customer", cust_b), ("subscriber", sub), ("visitor", guest)):
        nonce = sess.nonce("ofwc_pdf_%d_of-sm-a" % O["a_owned"]) if who != "visitor" else "0123456789"
        res = sess.get(BASE + "/wp-admin/admin-post.php?action=ofwc_pdf&order_id=%d&status=of-sm-a&_wpnonce=%s" % (O["a_owned"], nonce))
        step("PDF of someone else's order is refused: " + who, not res[2].startswith(b"%PDF"), "HTTP %s" % res[0])
# The hidden status document is for staff only.
nonce = cust_a.nonce("ofwc_pdf_%d_of-sm-b" % O["a_hidden"])
res = cust_a.get(BASE + "/wp-admin/admin-post.php?action=ofwc_pdf&order_id=%d&status=of-sm-b&_wpnonce=%s" % (O["a_hidden"], nonce))
step("document of a status hidden from customers is refused to the owner", not res[2].startswith(b"%PDF"), "HTTP %s" % res[0])
nonce = admin.nonce("ofwc_pdf_%d_of-sm-b" % O["a_hidden"])
res = admin.get(BASE + "/wp-admin/admin-post.php?action=ofwc_pdf&order_id=%d&status=of-sm-b&_wpnonce=%s" % (O["a_hidden"], nonce))
step("administrator downloads the hidden status document", is_pdf(*res), "HTTP %s" % res[0])
status, _, raw = cust_a.get(re.sub(r"^https?://" + re.escape(HOST), "", urls["orders"]), follow=True)
body = text(raw)
step("My Account orders list shows the order and a PDF action", status == 200 and ("#%d" % O["a_owned"]) in body and "action=ofwc_pdf" in unescape(body), "HTTP %s" % status)
step("My Account orders list hides the document of a hidden status", "status=of-sm-b" not in unescape(body))

# ---- export and import ---------------------------------------------------------------------------
def export(session, nonce=None):
    n = session.nonce("ofwc_export") if nonce is None else nonce
    return session.get(BASE + "/wp-admin/admin-post.php?action=ofwc_export&_ofwc_nonce=" + n)

status, hdrs, raw = export(admin)
exported = raw
try:
    doc = json.loads(raw.decode())
except ValueError:
    doc = {}
step("administrator downloads the configuration as JSON", status == 200 and doc.get("format") == "order-flow-for-woocommerce" and "attachment" in hdrs.get("content-disposition", ""), "HTTP %s" % status)
res = export(manager)
step("shop manager cannot export the configuration", res[0] == 403 and b'"format"' not in res[2], "HTTP %s" % res[0])
res = export(sub)
step("subscriber cannot export the configuration", res[0] == 403 and b'"format"' not in res[2], "HTTP %s" % res[0])
res = export(admin, nonce="0000000000")
step("export without a valid nonce is refused", res[0] == 403 and b'"format"' not in res[2], "HTTP %s" % res[0])
res = guest.get(BASE + "/wp-admin/admin-post.php?action=ofwc_export&_ofwc_nonce=0000000000")
step("visitor cannot export the configuration", b'"format"' not in res[2], "HTTP %s" % res[0])

for mode in ("merge", "replace"):
    n = admin.nonce("ofwc_import")
    status, hdrs, raw = admin.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_import", "_ofwc_nonce": n, "mode": mode, "ofwc_json": exported.decode()})
    status2, _, again = export(admin)
    a, b = json.loads(exported.decode()), json.loads(again.decode() or "{}")
    a.pop("exported_at", None)
    b.pop("exported_at", None)
    step("importing the exported file (%s) through the admin changes nothing" % mode, status == 302 and a == b, "HTTP %s" % status)
n = manager.nonce("ofwc_import")
status, hdrs, raw = manager.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_import", "_ofwc_nonce": n, "mode": "replace", "ofwc_json": '{"format":"order-flow-for-woocommerce","version":1,"statuses":[],"transitions":[]}'})
status2, _, again = export(admin)
step("shop manager cannot import (configuration untouched)", status == 403 and b"SM Alpha" in again, "HTTP %s" % status)
n = admin.nonce("ofwc_import")
status, hdrs, raw = admin.post(BASE + "/wp-admin/admin-post.php", {"action": "ofwc_import", "mode": "replace", "ofwc_json": '{"format":"order-flow-for-woocommerce","version":1,"statuses":[],"transitions":[]}'})
status2, _, again = export(admin)
step("import without a nonce is refused (configuration untouched)", status == 403 and b"SM Alpha" in again, "HTTP %s" % status)

failed = STEPS.count(False)
print("%s steps=%d failed=%d" % ("SMOKE PASS" if failed == 0 else "SMOKE FAIL", len(STEPS), failed))
sys.exit(0 if failed == 0 else 1)
