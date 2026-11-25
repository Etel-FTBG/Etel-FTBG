"""
ETEL Delivery -- the nightly backup job writes its 'last run' time on the SAME clock as the app (Addis time). It used to store the database server's own clock (3 hours early on the
live host), so the Backup job row on System health showed a false red for the last hour before each night's run.

    python3 tests/cron_backup_heartbeat_smoke.py

Runs the real script over the command line (it writes a small real backup into the test folder: keep BACKUP_DIR pointed at a throwaway place). Throwaway database only.
"""
import os
import subprocess
import sys

import etel_test_db

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api")
PASS, FAIL = [], []


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:240]))


def main():
    etel_test_db.run_sql("CREATE TABLE IF NOT EXISTS etel_heartbeat (name VARCHAR(40) NOT NULL PRIMARY KEY, last_run DATETIME NOT NULL, detail VARCHAR(200) NULL)")
    etel_test_db.run_sql("DELETE FROM etel_heartbeat WHERE name = 'backup_db'")
    p = subprocess.run(["php", "cron_backup_db.php"], cwd=ROOT, capture_output=True, text=True, timeout=300)
    ok, out, _ = etel_test_db.query_text("SELECT detail, TIMESTAMPDIFF(MINUTE, last_run, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 HOUR)) FROM etel_heartbeat WHERE name = 'backup_db'")
    line = out.strip().split("\n")[-1] if ok and out.strip() else ""
    check("the backup job reported (heartbeat backup_db exists)", bool(line), p.stdout[:200] + p.stderr[:200])
    detail, mins = (line.rsplit("\t", 1) + ["999"])[:2] if line else ("", "999")
    check("it wrote its time on the app's clock (Addis): the age is about 0, not about 180 minutes", abs(int(mins)) <= 1, line)
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
