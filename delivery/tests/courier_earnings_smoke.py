"""
Courier earnings summary: counts the delivery fee (not the order total), only delivered orders, and the day streak.
Throwaway test database only. Skips cleanly when no database is reachable.
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


def one(q):
    c = T._cfg()
    env = dict(os.environ, MYSQL_PWD=c["pass"])
    r = subprocess.run(["mysql", "-h", c["host"], "-u", c["user"], c["name"], "-N", "-B", "-e", q],
                       capture_output=True, text=True, env=env, timeout=30)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


ok, why = T.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("courier earnings summary")
sql("DELETE FROM orders WHERE order_code LIKE 'EARN-%'; DELETE FROM couriers WHERE courier_code='ERNA'; DELETE FROM users WHERE phone LIKE '+2519991000%'")
for i, role in enumerate(["customer", "courier"]):
    sql("INSERT INTO users (name, phone, password_hash, role) VALUES ('Earn Test %d', '+2519991000%02d', 'x', '%s')" % (i, i, role))
cu = one("SELECT id FROM users WHERE phone='+251999100000'")
ku = one("SELECT id FROM users WHERE phone='+251999100001'")
sql("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (%s, 'ERNA', 'Stadium', 'online', 1)" % ku)
cid = one("SELECT id FROM couriers WHERE courier_code='ERNA'")


def order(code, status, fee, total, days_ago):
    sql("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, delivery_fee, fare_total, placed_at, delivered_at) "
        "VALUES ('%s', %s, %s, 'Stadium', 'Tabor', '%s', %s, %s, NOW() - INTERVAL %d DAY, NOW() - INTERVAL %d DAY)"
        % (code, cu, cid, status, fee, total, days_ago, days_ago))


order("EARN-1", "delivered", 60, 900, 0)      # today: fee 60 counts, not the 900 order total
order("EARN-2", "delivered", 40, 500, 0)
order("EARN-3", "cancelled", 50, 300, 0)      # not delivered: ignored
order("EARN-4", "delivered", 70, 400, 1)      # yesterday -> streak 2
order("EARN-5", "delivered", 30, 200, 10)     # outside the 7-day window, no streak link

r = json.loads(subprocess.run(["php", os.path.join(HERE, "earnings_cli.php"), cid], capture_output=True, text=True, timeout=60).stdout.strip().splitlines()[-1])
check("today earned is the sum of delivery fees (100)", r["today"]["earned"] == 100)
check("today deliveries = 2 (cancelled ignored)", r["today"]["deliveries"] == 2)
check("week earned = 170", r["week"]["earned"] == 170 and r["week"]["deliveries"] == 3)
check("all-time = 200 over 4 deliveries", r["all"]["earned"] == 200 and r["all"]["deliveries"] == 4)
check("streak is 2 days", r["streak_days"] == 2)

sql("DELETE FROM orders WHERE order_code LIKE 'EARN-%'; DELETE FROM couriers WHERE courier_code='ERNA'; DELETE FROM users WHERE phone LIKE '+2519991000%'")
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
