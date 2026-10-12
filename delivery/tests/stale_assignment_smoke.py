"""
Stale courier assignments: an order that is still 'assigned' and not accepted after N minutes is taken back and
offered to the next courier (never the same one twice), and left alone when it is fresh, accepted, or the feature is off.

Throwaway test database only (same connection settings as etel_test_db.py). Skips cleanly when no database is reachable.
"""
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db as T  # noqa: E402

fails = []


def check(name, cond):
    print(("  PASS  " if cond else "  FAIL  ") + name)
    if not cond:
        fails.append(name)


def sql(q):
    ok, err = T.run_sql(q)
    if not ok:
        raise RuntimeError(err)


def rows(q):
    c = T._cfg()
    env = dict(os.environ, MYSQL_PWD=c["pass"])
    r = subprocess.run(["mysql", "-h", c["host"], "-u", c["user"], c["name"], "-N", "-B", "-e", q],
                       capture_output=True, text=True, env=env, timeout=30)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return [line.split("\t") for line in r.stdout.strip().splitlines() if line.strip()]


def php(mode):
    r = subprocess.run(["php", os.path.join(HERE, "stale_sweep_cli.php"), mode], capture_output=True, text=True, timeout=60)
    if r.returncode != 0:
        raise RuntimeError(r.stdout + r.stderr)
    return json.loads(r.stdout.strip().splitlines()[-1])


ok, why = T.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("stale courier assignments")
php("ensure")  # adds orders.courier_assigned_at when missing

# --- seed: one customer, three couriers (A has the order, B and C are free and online)
sql("DELETE FROM order_courier_offers; DELETE FROM orders WHERE order_code LIKE 'STALE-%'; "
    "DELETE FROM couriers WHERE courier_code LIKE 'STC%'; DELETE FROM users WHERE phone LIKE '+2519990000%'; "
    "DELETE FROM pricing_rules WHERE rule_key='courier_stale_minutes'")
for i, role in enumerate(["customer", "courier", "courier", "courier"]):
    sql("INSERT INTO users (name, phone, password_hash, role) VALUES ('Stale Test %d', '+2519990000%02d', 'x', '%s')" % (i, i, role))
uid = {i: rows("SELECT id FROM users WHERE phone='+2519990000%02d'" % i)[0][0] for i in range(4)}
for code, u in (("STCA", uid[1]), ("STCB", uid[2]), ("STCC", uid[3])):
    sql("INSERT INTO couriers (user_id, courier_code, zone, status, last_seen, active) VALUES (%s, '%s', 'Stadium', 'busy', NOW(), 1)" % (u, code))
cid = {c: rows("SELECT id FROM couriers WHERE courier_code='%s'" % c)[0][0] for c in ("STCA", "STCB", "STCC")}
sql("UPDATE couriers SET status='online' WHERE id IN (%s, %s)" % (cid["STCB"], cid["STCC"]))


def new_order(code, assigned_min_ago, accepted=False):
    sql("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, placed_at, courier_assigned_at%s) "
        "VALUES ('%s', %s, %s, 'Stadium', 'Tabor', 'assigned', NOW() - INTERVAL %d MINUTE, NOW() - INTERVAL %d MINUTE%s)"
        % (", courier_accepted_at" if accepted else "", code, uid[0], cid["STCA"], assigned_min_ago + 1, assigned_min_ago, ", NOW()" if accepted else ""))
    return rows("SELECT id FROM orders WHERE order_code='%s'" % code)[0][0]


def order(oid):
    return rows("SELECT status, IFNULL(courier_id,'') FROM orders WHERE id=%s" % oid)[0]


# 1) 40 minutes old, never accepted -> taken from A, given to B or C (never A)
o1 = new_order("STALE-1", 40)
r = php("sweep")
st, cur = order(o1)
check("stale order is reassigned (still assigned, to a different courier)", st == "assigned" and cur != "" and cur != str(cid["STCA"]))
check("the new courier is one of the free ones", cur in (str(cid["STCB"]), str(cid["STCC"])))
check("the first courier got a 'stale' record", rows("SELECT COUNT(*) FROM order_courier_offers WHERE order_id=%s AND courier_id=%s AND response='stale'" % (o1, cid["STCA"]))[0][0] == "1")
check("the first courier is no longer busy", rows("SELECT status FROM couriers WHERE id=%s" % cid["STCA"])[0][0] != "busy")
check("new assignment time is recent", int(rows("SELECT TIMESTAMPDIFF(MINUTE, courier_assigned_at, NOW()) FROM orders WHERE id=%s" % o1)[0][0]) <= 1)

# 2) fresh order and accepted order are left alone
sql("UPDATE couriers SET status='busy' WHERE id=%s" % cid["STCA"])
o2 = new_order("STALE-2", 5)
o3 = new_order("STALE-3", 90, accepted=True)
php("sweep")
check("a fresh assignment is left alone", order(o2) == ("assigned", str(cid["STCA"])))
check("an accepted order is left alone however old", order(o3) == ("assigned", str(cid["STCA"])))

# 3) tried-out: three couriers already had it -> goes back to the open list, not re-assigned
o4 = new_order("STALE-4", 60)
for c in ("STCB", "STCC"):
    sql("INSERT INTO order_courier_offers (order_id, courier_id, offered_at, expires_at, response) VALUES (%s, %s, NOW(), NOW(), 'declined')" % (o4, cid[c]))
php("sweep")
st, cur = order(o4)
check("when every courier has had it, the order returns to the open list", st == "pending" and cur == "")

# 4) switched off (0) -> nothing is touched
sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('courier_stale_minutes', 0, 'test') ON DUPLICATE KEY UPDATE rule_value=0")
o5 = new_order("STALE-5", 120)
php("sweep")
check("setting 0 turns the feature off", order(o5) == ("assigned", str(cid["STCA"])))

# cleanup
sql("DELETE FROM order_courier_offers WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'STALE-%'); "
    "DELETE FROM orders WHERE order_code LIKE 'STALE-%'; DELETE FROM couriers WHERE courier_code LIKE 'STC%'; "
    "DELETE FROM users WHERE phone LIKE '+2519990000%'; DELETE FROM pricing_rules WHERE rule_key='courier_stale_minutes'")

print("FAILED: %d" % len(fails) if fails else "ALL PASSED")
sys.exit(1 if fails else 0)
