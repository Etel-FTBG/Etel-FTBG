"""
Content-Security-Policy report endpoint (api/csp-report.php): stores one row per distinct violation with a counter, strips query strings,
ignores junk and oversized bodies, never answers with content. Runs against the test server like the other smokes:

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/csp_report_smoke.py

Creates rows in csp_reports: throwaway database only.
"""
import json
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

URL = "http://127.0.0.1:8765/api/csp-report.php"
SUFFIX = str(int(time.time()))
PASS, FAIL = [], []


def post(data, ctype="application/csp-report"):
    body = data if isinstance(data, bytes) else json.dumps(data).encode("utf-8")
    req = urllib.request.Request(URL, data=body, headers={"Content-Type": ctype}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=10) as f:
            return f.status, f.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def rows(where):
    ok, out, err = etel_test_db.query_text("SELECT hits, page, blocked, source, line_no FROM csp_reports WHERE " + where)
    return out.strip().split("\n") if ok and out.strip() else []


def main():
    ok, _, _ = etel_test_db.query_text("SELECT 1")
    if not ok:
        print("SKIP: no test database")
        return 0
    tag = "inline-" + SUFFIX
    rep = {"csp-report": {"document-uri": "https://ethelmarket.com/delivery/customer.html?token=SECRET#frag", "effective-directive": "script-src-attr",
                          "blocked-uri": "inline", "source-file": "https://ethelmarket.com/delivery/js/customer.js?v=1", "line-number": 123, "script-sample": tag}}
    s, b = post(rep)
    check("a report is accepted with no content back (204)", s == 204 and b == b"", (s, b))
    s, b = post(rep)
    r = rows("sample = '%s'" % tag)
    check("the same violation twice is one row with 2 hits", len(r) == 1 and r[0].split("\t")[0] == "2", r)
    check("query strings and fragments are never stored", r and "SECRET" not in r[0] and "?v=1" not in r[0] and "#frag" not in r[0], r)
    s, b = post([{"type": "csp-violation", "body": {"documentURL": "https://ethelmarket.com/delivery/customer.html", "effectiveDirective": "script-src-elem", "blockedURL": "https://evil.example/x.js", "sample": tag + "-b"}}], "application/reports+json")
    check("the Reporting API format is understood", s == 204 and len(rows("sample = '%s'" % (tag + "-b"))) == 1)
    s, b = post(b"not json at all")
    check("junk is ignored quietly", s == 204 and b == b"")
    s, b = post(b"{" + b"\"a\":\"" + b"x" * 9000 + b"\"}")
    check("an oversized body is ignored", s == 204)
    s, b = post({"csp-report": {"document-uri": "x"}})
    check("a report with no directive is ignored", s == 204)
    req = urllib.request.Request(URL, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=10) as f:
            gs, gb = f.status, f.read()
    except urllib.error.HTTPError as e:
        gs, gb = e.code, e.read()
    check("a GET does nothing", gs == 204 and gb == b"")
    etel_test_db.run_sql("DELETE FROM csp_reports WHERE sample LIKE 'inline-%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
