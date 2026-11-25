"""
ETEL Delivery -- secret keys kept out of web addresses (api/lib/cron_key.php): cron jobs, diagnose.php, etel-os.php take their key from a header; a key in the address
follows the Etel Shield setting url_key_mode (allow | monitor | refuse). Real files over php -S.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/key_in_url_smoke.py

Needs the seeded demo admin and CRON_DISPATCH_KEY in api/.env. Throwaway database only.
"""
import glob
import hashlib
import json
import os
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

import etel_test_db

BASE = "http://127.0.0.1:8765/api/"
LIBDIR = os.path.realpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api", "lib"))
TMPDIR = os.path.join(tempfile.gettempdir(), "etel_shield_" + hashlib.md5(LIBDIR.encode()).hexdigest()[:10])
PASS, FAIL = [], []


def env_key():
    for line in open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api", ".env"), encoding="utf-8", errors="replace"):
        if line.startswith("CRON_DISPATCH_KEY="):
            return line.split("=", 1)[1].strip().strip('"').strip("'")
    return ""


def get(path, headers=None, post=None):
    h = {"User-Agent": "EtelSmoke/1.0"}
    h.update(headers or {})
    r = urllib.request.Request(BASE + path, data=json.dumps(post).encode() if post is not None else None, headers=h, method="POST" if post is not None else "GET")
    try:
        with urllib.request.urlopen(r, timeout=60) as f:
            return f.status, f.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:240]))


def clear_throttle():
    for f in glob.glob(os.path.join(TMPDIR, "l.*")):
        try:
            os.unlink(f)
        except OSError:
            pass


def n_events():
    return int(etel_test_db.query_text("SELECT COUNT(*) FROM shield_events WHERE rule LIKE 'keyurl%'")[1].strip().split("\n")[-1] or 0)


def main():
    key = env_key()
    if not key:
        print("SKIP: no CRON_DISPATCH_KEY in api/.env"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts")
    s, b = get("boot.php", post={"action": "login", "phone": "0900000000", "password": "admin1234"})
    admin = (json.loads(b).get("data") or {}).get("token") if s == 200 else None
    if not admin:
        print("SKIP: no seeded admin"); return 0

    def mode(m):
        s2, b2 = get("boot.php", {"Authorization": "Bearer " + admin}, {"action": "admin_shield_save", "config": {"url_key_mode": m}})
        return (json.loads(b2).get("data") or {}).get("config", {}).get("url_key_mode")

    check("the owner can set url_key_mode (allow / monitor / refuse)", mode("refuse") == "refuse" and mode("bogus") == "monitor" and mode("allow") == "allow")
    s, b = get("boot.php", {"Authorization": "Bearer " + admin}, {"action": "admin_shield_get"})
    check("the factory setting is monitor", (json.loads(b).get("data") or {}).get("defaults", {}).get("url_key_mode") == "monitor")

    CRONS = ["cron_dispatch_scheduled.php", "cron_close_stuck_rides.php", "cron_ride_monitor.php", "cron_chapa_reconcile.php", "cron_field_agents.php", "equb_cron.php"]
    print("1. The key in a header opens every cron job; no key never does")
    for c in CRONS:
        s, b = get(c, {"X-Cron-Key": key})
        check(c + ": the X-Cron-Key header is accepted", s == 200, (s, b[:100]))
        s, b = get(c)
        check(c + ": no key is refused", s in (401, 403), (s, b[:100]))
        s, b = get(c, {"X-Cron-Key": key + "x"})
        check(c + ": a wrong key is refused", s in (401, 403), (s, b[:100]))

    print("2. url_key_mode for a key in the address")
    mode("monitor"); clear_throttle(); etel_test_db.run_sql("DELETE FROM shield_events WHERE rule LIKE 'keyurl%'")
    s, b = get("cron_dispatch_scheduled.php?key=" + urllib.parse.quote(key))
    check("monitor: the old address still works ...", s == 200, (s, b[:100]))
    check("monitor: ... and it is written in the security log (keyurl_...)", n_events() >= 1, n_events())
    clear_throttle(); etel_test_db.run_sql("DELETE FROM shield_events WHERE rule LIKE 'keyurl%'")
    s, b = get("cron_dispatch_scheduled.php", {"X-Cron-Key": key})
    check("a header key is NOT written to the log", s == 200 and n_events() == 0, n_events())
    mode("allow")
    s, b = get("cron_dispatch_scheduled.php?key=" + urllib.parse.quote(key))
    check("allow: the old address works", s == 200, s)
    mode("refuse")
    for c in CRONS:
        s, b = get(c + "?key=" + urllib.parse.quote(key))
        check(c + " refuse: the key in the address is refused", s in (401, 403), (s, b[:100]))
    s, b = get("cron_dispatch_scheduled.php", {"X-Cron-Key": key})
    check("refuse: the header still works", s == 200, (s, b[:100]))

    print("3. Diagnose and etel-os: header first")
    s, b = get("diagnose.php?key=wrong")
    check("diagnose.php refuses a wrong key", s == 403, s)
    s, b = get("diagnose.php")
    check("diagnose.php refuses no key", s == 403, s)

    mode("monitor")
    clear_throttle()
    etel_test_db.run_sql("DELETE FROM shield_events WHERE rule LIKE 'keyurl%'")
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'shield_config'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
