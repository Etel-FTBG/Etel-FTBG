"""
ETEL Delivery -- cron_ride_monitor.php never dies silently: the heartbeat is written even when a step fails, the reason is kept (row ride_monitor_error) and shown on the
System health page (Cron job row); a fatal error is caught by the shutdown function. Real script over the command line + the real health check over php -S.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/cron_ride_monitor_visible_smoke.py

Needs APP_ENV=development in api/.env (the fail= test switch only exists there) and the seeded demo admin. Throwaway database only.
"""
import json
import os
import subprocess
import sys
import urllib.request

import etel_test_db

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api")
API = "http://127.0.0.1:8765/api/boot.php"
PASS, FAIL = [], []


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:260]))


def run(*args):
    p = subprocess.run(["php", "cron_ride_monitor.php"] + list(args), cwd=ROOT, capture_output=True, text=True, timeout=120)
    return p.stdout


def hb(name):
    # the script writes Addis time (the API sets its own session zone): compare with Addis time too, whatever zone this client session has
    ok, out, _ = etel_test_db.query_text("SELECT detail, TIMESTAMPDIFF(MINUTE, last_run, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR)) FROM etel_heartbeat WHERE name = '%s'" % name)
    lines = [l for l in out.strip().split("\n") if l] if ok else []
    if not lines:
        return None, None
    d, m = lines[-1].rsplit("\t", 1)
    return d, int(m)


def call(action, token=None):
    h = {"Content-Type": "application/json"}
    if token:
        h["Authorization"] = "Bearer " + token
    r = urllib.request.Request(API, data=json.dumps({"action": action, "phone": "0900000000", "password": "admin1234"}).encode(), headers=h, method="POST")
    return json.loads(urllib.request.urlopen(r, timeout=30).read().decode())


def cron_row(token):
    for c in (call("admin_system_health", token).get("data") or {}).get("checks", []):
        if c.get("name") == "Cron job":
            return c
    return {}


def main():
    etel_test_db.run_sql("DELETE FROM login_attempts")
    tok = (call("login").get("data") or {}).get("token")
    if not tok:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("CREATE TABLE IF NOT EXISTS etel_heartbeat (name VARCHAR(40) NOT NULL PRIMARY KEY, last_run DATETIME NOT NULL, detail VARCHAR(200) NULL)")

    print("1. A normal run")
    out = run()
    j = json.loads(out[out.index("{"):]) if "{" in out else {}
    d, m = hb("ride_monitor_error")
    check("the run succeeds and reports no step errors", j.get("success") is True and j.get("errors") == [], out[:200])
    check("the heartbeat is fresh", (hb("ride_monitor")[1] is not None and hb("ride_monitor")[1] <= 1))
    check("the error row says ok", d == "ok", d)
    check("System health: Cron job is ok", cron_row(tok).get("status") == "ok", cron_row(tok))

    print("2. A step fails (the sweep): the run still finishes and says what failed")
    out = run("fail=sweep")
    j = json.loads(out[out.index("{"):]) if "{" in out else {}
    d, m = hb("ride_monitor_error")
    check("the answer lists the failed step", any("test failure in the sweep" in e for e in j.get("errors", [])), out[:200])
    check("the heartbeat is still written (the run is alive)", (hb("ride_monitor")[1] is not None and hb("ride_monitor")[1] <= 1))
    check("the reason is kept", d is not None and d.startswith("sweep: test failure"), d)
    row = cron_row(tok)
    check("System health: Cron job warns and names the failing step", row.get("status") == "warn" and "a step fails" in row.get("detail", "") and "sweep" in row.get("detail", ""), row)

    print("3. A fatal error: the run dies, but leaves the reason")
    etel_test_db.run_sql("UPDATE etel_heartbeat SET last_run = DATE_SUB(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR), INTERVAL 2 DAY) WHERE name = 'ride_monitor'")
    out = run("fail=fatal")
    d, m = hb("ride_monitor_error")
    check("the heartbeat is NOT refreshed by a run that died", (hb("ride_monitor")[1] or 0) >= 2000, hb("ride_monitor"))
    check("the reason (the fatal error) is kept", d is not None and d.startswith("stopped:") and "cs_this_function_does_not_exist" in d, d)
    row = cron_row(tok)
    check("System health: Cron job is bad and shows the error", row.get("status") == "bad" and "stops with an error" in row.get("detail", "") and "cs_this_function_does_not_exist" in row.get("detail", ""), row)

    print("4. It recovers")
    run()
    d, m = hb("ride_monitor_error")
    check("a good run clears the error", d == "ok", d)
    check("System health: Cron job is ok again", cron_row(tok).get("status") == "ok", cron_row(tok))
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
