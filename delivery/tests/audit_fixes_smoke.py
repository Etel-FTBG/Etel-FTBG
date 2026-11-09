#!/usr/bin/env python3
"""Fixes from the 2026-11-08 audit, checked over the real API on the throwaway database:
the migrations list and check need an admin, the public health page shows no disk numbers, and runtime schema changes never run inside an
open transaction (static check over the PHP files). Prints OK / FAIL lines and exits 1 on any failure."""
import json, os, re, sys, glob, urllib.error, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import etel_test_db

BASE = "http://127.0.0.1:8765/api/"
PASS, FAIL = [], []
def check(name, ok, extra=""):
    (PASS if ok else FAIL).append(name)
    print(("  OK   " if ok else "  FAIL ") + name + ("" if ok or not extra else "  (%s)" % str(extra)[:200]))

def get(path, token=None):
    req = urllib.request.Request(BASE + path, headers=({"Authorization": "Bearer " + token} if token else {}))
    try:
        with urllib.request.urlopen(req, timeout=30) as r: return r.status, r.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.read().decode()

def post(action, body=None):
    req = urllib.request.Request(BASE + "boot.php", data=json.dumps(dict(body or {}, action=action)).encode(), headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as r: return json.loads(r.read().decode())
    except urllib.error.HTTPError as e: return json.loads(e.read().decode() or "{}")

ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why); sys.exit(0)
r = post("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
if not admin:
    print("SKIP: no seeded demo admin"); sys.exit(0)

print("migrations list")
st, _ = get("migrate.php"); check("the migrations list is refused without a sign-in", st == 403, st)
st, _ = get("migrate.php?validate=1"); check("the migrations check is refused without a sign-in", st == 403, st)
st, b = get("migrate.php", admin); check("an admin can read the migrations list", st == 200 and '"migrations"' in b, st)

js = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "js", "admin-migrations.js"), encoding="utf-8").read()
check("the admin Migrations panel sends the admin token when it checks the status", re.search(r"fetch\(migrateUrl\(\),\s*\{\s*headers:\s*\{\s*'Authorization'", js) is not None)

print("public health page")
st, b = get("health.php"); h = json.loads(b) if b.startswith("{") else {}
check("health still answers for monitors", st == 200 and h.get("status") == "ok", b[:120])
check("health no longer shows the disk percentage", "disk_free_pct" not in h, h)

print("schema changes inside a transaction (static)")
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api")
bad = []
for f in glob.glob(root + "/controllers/*.php") + glob.glob(root + "/lib/*.php") + [root + "/boot.php"]:
    src = open(f, encoding="utf-8", errors="replace").read().split("\n")
    depth_open = None
    for i, line in enumerate(src):
        if re.search(r"->beginTransaction\(\)", line): depth_open = i
        if re.search(r"->(commit|rollBack)\(\)", line): depth_open = None
        if depth_open is not None and re.search(r"->exec\(\s*[\"'](CREATE|ALTER|DROP)\s", line, re.I):
            bad.append("%s:%d" % (os.path.relpath(f, root), i + 1))
        if re.search(r"^(case '[a-z0-9_]+'|function )", line.strip()): depth_open = None
check("no CREATE/ALTER/DROP runs between beginTransaction and commit", not bad, ", ".join(bad[:8]))

print("file sizes")
def lines(rel): return len(open(os.path.join(root, rel), encoding="utf-8", errors="replace").read().split("\n"))
check("RideController.php stays under 4,500 lines (ride-desk admin actions live in RideAdminController.php)", lines("controllers/RideController.php") < 4500, lines("controllers/RideController.php"))
check("AdminController.php stays under 2,000 lines (pricing, catalog and releases are split out)", lines("controllers/AdminController.php") < 2000, lines("controllers/AdminController.php"))
check("every moved action is still reachable (split files exist)", all(os.path.isfile(os.path.join(root, "controllers", f)) for f in ("RideAdminController.php", "AdminPricingController.php", "AdminCatalogController.php", "AdminReleasesController.php")))

print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
sys.exit(1 if FAIL else 0)
