#!/usr/bin/env python3
"""
Post-deploy check: is what is live the same as what was uploaded?

Run it after uploading a zip, from anywhere with internet:

    python3 delivery/tests/post_deploy_check.py https://ethelmarket.com

It only reads (GET requests, nothing is written) and checks:
  1. api/health.php answers 200 {"status":"ok"} (database, disk, backup, cron),
  2. the API answers JSON and its version (APP_VER) is the one in this repo's boot.php,
  3. the rider, driver and admin pages load and every script and style they list loads too (a file missing from the
     upload shows up here as 404),
  4. the beacon endpoint is deployed and answers 204.

Exit code 0 = all good, 1 = something is wrong (the lines above say what). Options:
    --prefix /delivery     path of the app on the site (default /delivery; use '' when delivery/ is the web root)
    --allow-degraded       do not fail when health.php says degraded (a fresh test database has no backup yet)
"""
import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request

PAGES = ("ride/index.html", "driver/index.html", "admin.html")
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def get(url, timeout=25):
    req = urllib.request.Request(url, headers={"User-Agent": "etel-post-deploy-check"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as f:
            return f.status, f.read(), dict(f.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)
    except Exception as e:  # noqa: BLE001 - report anything (DNS, TLS, timeout) as a failed check
        return 0, str(e).encode(), {}


def repo_app_version():
    here = os.path.dirname(os.path.abspath(__file__))
    src = open(os.path.join(here, "..", "api", "boot.php"), encoding="utf-8").read()
    m = re.search(r"define\('APP_VER',\s*'([^']+)'\)", src)
    return m.group(1) if m else None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("base", help="site address, e.g. https://ethelmarket.com")
    ap.add_argument("--prefix", default="/delivery")
    ap.add_argument("--allow-degraded", action="store_true")
    a = ap.parse_args()
    root = a.base.rstrip("/") + a.prefix.rstrip("/")
    print("post-deploy check: " + root)

    st, body, _ = get(root + "/api/health.php")
    try:
        status = json.loads(body.decode("utf-8", "replace")).get("status")
    except Exception:  # noqa: BLE001
        status = None
    ok = st == 200 and status == "ok"
    if a.allow_degraded and st in (200, 503) and status in ("ok", "degraded"):
        ok = True
    check("health.php says ok", ok, "HTTP %s %s" % (st, (status or body[:120])))

    st, body, hdr = get(root + "/api/boot.php?action=ping")
    try:
        j = json.loads(body.decode("utf-8", "replace"))
    except Exception:  # noqa: BLE001
        j = {}
    check("the API answers JSON", isinstance(j, dict) and "v" in j, "HTTP %s %s" % (st, body[:120]))
    want = repo_app_version()
    live = j.get("v") if isinstance(j, dict) else None
    check("the API version matches this repo (%s)" % want, want is not None and live == want, "live=%s" % live)
    check("the API sends a request id", bool(hdr.get("X-Request-Id") or hdr.get("x-request-id")), "no X-Request-Id header: api/boot.php is an old copy")

    for page in PAGES:
        st, body, _ = get(root + "/" + page)
        check(page + " loads", st == 200 and len(body) > 500, "HTTP %s" % st)
        if st != 200:
            continue
        html = body.decode("utf-8", "replace")
        base_dir = (root + "/" + page).rsplit("/", 1)[0]
        refs = set(re.findall(r'<script[^>]+src="([^"]+)"', html) + re.findall(r'<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"', html))
        missing = []
        for r in sorted(refs):
            if r.startswith(("http://", "https://", "//", "data:")):
                continue
            path = r.split("?")[0]
            url = path if path.startswith("/") else base_dir + "/" + path
            if path.startswith("/"):
                url = a.base.rstrip("/") + path
            # normalise ../ segments
            parts = []
            for seg in url.split("/"):
                if seg == "..":
                    if len(parts) > 3:
                        parts.pop()
                elif seg != ".":
                    parts.append(seg)
            url = "/".join(parts)
            s2, _, _ = get(url)
            if s2 != 200:
                missing.append("%s (%s)" % (r, s2))
        check(page + ": all %d scripts and styles load" % len(refs), not missing, ", ".join(missing[:6]))

    st, _, _ = get(root + "/api/client-error.php")
    check("the browser-error endpoint is deployed", st == 204, "HTTP %s" % st)

    if fails:
        print("\nPOST-DEPLOY CHECK FAILED: " + ", ".join(fails))
        return 1
    print("\nPost-deploy check passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
