"""
Tiny database helper for the smoke tests (local + CI only; never run against live).

Real drivers must finish onboarding (vehicle info, selfie, licence photo, identity
check) before they can go online or accept rides - that gate is deliberate, and the
tests used to predate it. Rather than fake photo uploads, the tests flip the
onboarded flag directly in the TEST database, the same state an admin approval
produces.

Connection settings come from ETEL_TEST_DB_HOST / _USER / _PASS / _NAME, or fall back
to DB_HOST / DB_USER / DB_PASS / DB_NAME in api/.env. Needs the `mysql` command-line
client. If the database is not reachable, onboard_driver() returns False and the
caller should SKIP the checks that need an onboarded driver (not fail them).
"""
import os
import subprocess


def _cfg():
    env_file = os.path.join(os.path.dirname(__file__), "..", "api", ".env")
    from_file = {}
    if os.path.exists(env_file):
        for line in open(env_file, encoding="utf-8", errors="replace"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                from_file[k.strip()] = v.strip().strip('"').strip("'")
    pick = lambda a, b, d="": os.environ.get(a) or from_file.get(b) or d
    return {
        "host": pick("ETEL_TEST_DB_HOST", "DB_HOST", "127.0.0.1"),
        "user": pick("ETEL_TEST_DB_USER", "DB_USER"),
        "pass": pick("ETEL_TEST_DB_PASS", "DB_PASS"),
        "name": pick("ETEL_TEST_DB_NAME", "DB_NAME"),
    }


def run_sql(sql):
    c = _cfg()
    if not (c["user"] and c["name"]):
        return False, "no test database configured"
    env = dict(os.environ, MYSQL_PWD=c["pass"])
    try:
        r = subprocess.run(
            ["mysql", "-h", c["host"], "-u", c["user"], c["name"], "-e", sql],
            capture_output=True, text=True, env=env, timeout=30)
    except (OSError, subprocess.TimeoutExpired) as e:
        return False, str(e)
    return r.returncode == 0, r.stderr.strip()


def onboard_driver(user_id):
    """Mark a ride driver as fully onboarded in the test DB. True on success."""
    uid = int(user_id)
    ok, _ = run_sql(
        "INSERT INTO ride_drivers (user_id, onboarded, identity_match) VALUES (%d, 1, 'matched') "
        "ON DUPLICATE KEY UPDATE onboarded = 1, identity_match = 'matched'" % uid)
    return ok


def query_text(sql, force=False):
    """Run SQL through the mysql client; return (ok, stdout, stderr). Used by CI checks."""
    c = _cfg()
    if not (c["user"] and c["name"]):
        return False, "", "no test database configured"
    env = dict(os.environ, MYSQL_PWD=c["pass"])
    cmd = ["mysql", "-h", c["host"], "-u", c["user"], "-N", "-B"] + (["--force"] if force else []) + [c["name"]]
    try:
        r = subprocess.run(cmd, input=sql, capture_output=True, text=True, env=env, timeout=120)
    except (OSError, subprocess.TimeoutExpired) as e:
        return False, "", str(e)
    return r.returncode == 0, r.stdout, r.stderr


def park_couriers():
    """Take every courier out of the auto-dispatch pool (status='offline').

    customer_create_order auto-assigns to any courier that is 'online', so
    leftovers from earlier runs or seed accounts would grab the test order
    before the courier under test can accept it. TEST DATABASE ONLY.
    """
    ok, _ = run_sql("UPDATE couriers SET status='offline' WHERE status IN ('online','busy')")
    return ok
