#!/usr/bin/env python3
"""Compatibility matrix helper (development only, not shipped).

Resolves versions and downloads archives into the matrix cache.

  matrix-resolve.py wp <cache> <branch>...          latest patch per branch, one "branch version" per line
  matrix-resolve.py core <cache> <version>          downloads WordPress core zip, prints its path
  matrix-resolve.py woo-min <cache> <series>        latest <series>.x patch (default 9.0), downloads, prints "version path"
  matrix-resolve.py woo-ver <cache> <version>       downloads that exact WooCommerce release, prints "version path"
  matrix-resolve.py woo-current <cache> <wp> <php> <series> newest <series>.x release that installs on this WP and PHP;
                                                    falls back to older series. Prints "version path note"
"""
import json
import os
import re
import sys
import urllib.request
import zipfile

UA = {"User-Agent": "order-flow-matrix/1.0"}


def get(url, binary=False, tries=3):
    last = None
    for _ in range(tries):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120) as r:
                data = r.read()
                return data if binary else data.decode("utf-8")
        except Exception as exc:  # noqa: BLE001
            last = exc
    raise SystemExit("download failed: %s (%s)" % (url, last))


def vkey(v):
    return tuple(int(x) for x in v.split("."))


def download(url, dest):
    if os.path.isfile(dest) and os.path.getsize(dest) > 1000:
        return dest
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    data = get(url, binary=True)
    tmp = dest + ".part"
    with open(tmp, "wb") as fh:
        fh.write(data)
    os.replace(tmp, dest)
    return dest


def cmd_wp(cache, branches):
    try:
        status = json.loads(get("https://api.wordpress.org/core/stable-check/1.0/"))
        with open(os.path.join(cache, "stable-check.json"), "w") as fh:
            json.dump(status, fh)
    except SystemExit:
        with open(os.path.join(cache, "stable-check.json")) as fh:
            status = json.load(fh)
    for branch in branches:
        cands = [v for v in status if re.fullmatch(re.escape(branch) + r"(\.\d+)?", v)]
        if not cands:
            raise SystemExit("no release found for branch " + branch)
        print(branch, max(cands, key=vkey))


def cmd_core(cache, version):
    print(download("https://downloads.wordpress.org/release/wordpress-%s.zip" % version, os.path.join(cache, "core", "wordpress-%s.zip" % version)))


def woo_versions():
    info = json.loads(get("https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=woocommerce"))
    stable = [v for v in info["versions"] if re.fullmatch(r"\d+\.\d+\.\d+", v)]
    return sorted(stable, key=vkey, reverse=True)


def woo_zip(cache, version):
    return download("https://downloads.wordpress.org/plugin/woocommerce.%s.zip" % version, os.path.join(cache, "woo", "woocommerce.%s.zip" % version))


def woo_requires(path):
    with zipfile.ZipFile(path) as z:
        text = z.read("woocommerce/readme.txt").decode("utf-8", "replace")
    wp = re.search(r"^Requires at least:\s*([\d.]+)", text, re.M)
    php = re.search(r"^Requires PHP:\s*([\d.]+)", text, re.M)
    return (wp.group(1) if wp else "0"), (php.group(1) if php else "0")


def cmd_woo_min(cache, series):
    cands = [v for v in woo_versions() if v.startswith(series + ".")]
    if not cands:
        raise SystemExit("no WooCommerce release in series " + series)
    print(cands[0], woo_zip(cache, cands[0]))


def cmd_woo_current(cache, wp, php, series):
    versions = woo_versions()
    ordered = [v for v in versions if v.startswith(series + ".")] + [v for v in versions if not v.startswith(series + ".")]
    skipped = []
    for v in ordered:
        path = woo_zip(cache, v)
        req_wp, req_php = woo_requires(path)
        if vkey(req_wp) <= vkey(wp) and vkey(req_php) <= vkey(php):
            note = ""
            if skipped:
                note = "newest %s.x needs WP %s or newer; this is the newest release that installs on WP %s" % (series, skipped[0][1], wp)
            print(v, path, note)
            return
        skipped.append((v, req_wp, req_php))
    raise SystemExit("no compatible WooCommerce for WP %s PHP %s" % (wp, php))


def main(argv):
    if len(argv) < 3:
        raise SystemExit(__doc__)
    cmd, cache = argv[1], argv[2]
    os.makedirs(cache, exist_ok=True)
    if cmd == "wp":
        cmd_wp(cache, argv[3:])
    elif cmd == "core":
        cmd_core(cache, argv[3])
    elif cmd == "woo-min":
        cmd_woo_min(cache, argv[3] if len(argv) > 3 else "9.0")
    elif cmd == "woo-ver":
        print(argv[3], woo_zip(cache, argv[3]))
    elif cmd == "woo-current":
        cmd_woo_current(cache, argv[3], argv[4], argv[5] if len(argv) > 5 else "11")
    else:
        raise SystemExit(__doc__)


if __name__ == "__main__":
    main(sys.argv)
