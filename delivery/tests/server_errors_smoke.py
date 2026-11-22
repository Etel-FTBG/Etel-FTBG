"""
Server-side PHP errors keep their real reason (api/lib/server_errors.php): the apps only say "Server fatal error", the cause goes into client_errors
(System health > page errors). One row per distinct error with a hit count, long messages cut, never throws. Needs the throwaway test database. Skips without it.
"""
import os
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)
TAG = str(int(time.time()))
etel_test_db.run_sql("DELETE FROM client_errors WHERE app = 'server' AND page = 'customer_create_order'")
r = subprocess.run(["php", os.path.join(HERE, "server_errors_check.php"), TAG], capture_output=True, text=True)
check("the helper writes without throwing", r.stdout.strip() == "written", (r.stdout, r.stderr))
ok, out, err = etel_test_db.query_text("SELECT app, kind, page, source, line_no, hits, CHAR_LENGTH(message) FROM client_errors WHERE message LIKE 'Call to undefined function foo_%s()%%'" % TAG)
rows = [l.split("\t") for l in out.strip().splitlines() if l.strip()]
check("the same error twice is ONE row with 2 hits (numbers do not make a new error)", len(rows) == 1 and rows[0][5] == "2", out)
check("it says where: server / fatal / the action, the file name only, the line", rows and rows[0][:5] == ["server", "fatal", "customer_create_order", "delivery_order_core.php", "321"], rows)
ok, out, err = etel_test_db.query_text("SELECT CHAR_LENGTH(message), source FROM client_errors WHERE app='server' AND kind='error' AND source='c.php' ORDER BY last_seen DESC LIMIT 1")
check("a very long message is cut to 300 characters", out.split()[0] == "300", out)
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
