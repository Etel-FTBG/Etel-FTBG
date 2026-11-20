"""
SOS alerts to staff, through the real server: pressing the panic button records the alert AND tells the staff (counted on the row), Live operations
shows it as a critical alert, the cron announces it again while it is still open, and resolving it clears everything.
Uses the seeded demo accounts (no sign-ups, so the shared register limit is not touched). The staff push goes to nobody (no device in the test database).

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import subprocess
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

BASE = os.environ.get("ETEL_API_BASE", "http://127.0.0.1:8765/api/")
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + "boot.php", data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("sos alerts to staff")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(cust)))
    sys.exit(1)
etel_test_db.run_sql("DELETE FROM sos_alerts WHERE message LIKE 'SMOKE %'")

st, r = call("sos_trigger", {"message": "SMOKE help", "lat": 7.06, "lng": 38.47})
check("nobody signed in cannot press the button", st == 401, (st, r))
st, r = call("sos_trigger", {"message": "SMOKE help", "lat": 7.06, "lng": 38.47}, cust)
sid = (r.get("data") or {}).get("id")
check("a rider presses the panic button", st == 200 and r.get("success") is True and sid, (st, r))
st, r = call("admin_get_sos_alerts", {}, admin)
rows = [a for a in ((r.get("data") or {}).get("alerts") or (r.get("data") or {}).get("sos_alerts") or []) if a.get("id") == sid]
if not rows and isinstance(r.get("data"), dict):
    for v in r["data"].values():
        if isinstance(v, list):
            rows = [a for a in v if isinstance(a, dict) and a.get("id") == sid]
check("the alert is in the admin list and the staff were told (notice 1, at least 1 staff, time set)", len(rows) == 1 and int(rows[0].get("notify_count", 0)) == 1 and int(rows[0].get("staff_count", 0)) >= 1 and rows[0].get("notified_at"), rows)
st, r = call("admin_live_ops", {}, admin)
d = r.get("data") or {}
texts = [a["text"] for a in d.get("alerts", [])]
check("Live operations shows it: SOS count and a critical alert first", (d.get("now", {}).get("sos", {}).get("open") or 0) >= 1 and texts and "SOS alert" in texts[0] and d["alerts"][0]["level"] == "critical", (d.get("now", {}).get("sos"), texts[:2]))

# the cron: make the alert old enough and announce again
etel_test_db.run_sql("UPDATE sos_alerts SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE), notified_at = DATE_SUB(NOW(), INTERVAL 9 MINUTE) WHERE id = %d" % sid)
root = os.path.join(HERE, "..", "api")
out = subprocess.run(["php", os.path.join(root, "cron_ride_monitor.php")], capture_output=True, text=True, timeout=120, cwd=root)
try:
    j = json.loads(out.stdout)
except Exception:
    j = {"raw": out.stdout[:300], "err": out.stderr[:300]}
check("the cron announces the open SOS again", ((j.get("watch") or {}).get("sos_announced_again") or 0) >= 1, j)
st, r = call("admin_get_sos_alerts", {}, admin)
allrows = []
if isinstance(r.get("data"), dict):
    for v in r["data"].values():
        if isinstance(v, list):
            allrows += [a for a in v if isinstance(a, dict) and a.get("id") == sid]
check("the notice counter went up to 2", len(allrows) == 1 and int(allrows[0].get("notify_count", 0)) == 2, allrows)

st, r = call("admin_resolve_sos", {"id": sid}, admin)
check("staff resolve it", st == 200 and r.get("success") is True, (st, r))
st, r = call("admin_live_ops", {}, admin)
d = r.get("data") or {}
check("Live operations no longer shows an SOS alert", not any("SOS alert" in a["text"] for a in d.get("alerts", [])), d.get("alerts"))
etel_test_db.run_sql("UPDATE sos_alerts SET created_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE), notified_at = DATE_SUB(NOW(), INTERVAL 19 MINUTE) WHERE id = %d" % sid)
out = subprocess.run(["php", os.path.join(root, "cron_ride_monitor.php")], capture_output=True, text=True, timeout=120, cwd=root)
try:
    j = json.loads(out.stdout)
except Exception:
    j = {}
check("a resolved alert is not announced again", ((j.get("watch") or {}).get("sos_announced_again") or 0) == 0, j)

etel_test_db.run_sql("DELETE FROM sos_alerts WHERE message LIKE 'SMOKE %'")
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
