"""
Courier weekly statement: delivered orders of one Monday-to-Sunday week with courier pay + tip (never the order total), totals per day,
week navigation (up to 12 weeks back) and bad dates. Throwaway test database only. Skips cleanly when no database is reachable.
"""
import json
import os
import subprocess
import sys
from datetime import date, timedelta

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


def stmt(cid, week=None):
    args = ["php", os.path.join(HERE, "courier_statement_cli.php"), cid] + ([week] if week else [])
    return json.loads(subprocess.run(args, capture_output=True, text=True, timeout=60).stdout.strip().splitlines()[-1])


ok, why = T.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("courier weekly statement")
subprocess.run(["php", os.path.join(HERE, "earnings_cli.php"), "ensure"], capture_output=True, text=True, timeout=60)
sql("DELETE FROM orders WHERE order_code LIKE 'CST-%'; DELETE FROM couriers WHERE courier_code='CSTA'; DELETE FROM users WHERE phone LIKE '+2519992000%'")
for i, role in enumerate(["customer", "courier", "courier"]):
    sql("INSERT INTO users (name, phone, password_hash, role) VALUES ('Stmt Test %d', '+2519992000%02d', 'x', '%s')" % (i, i, role))
cu = one("SELECT id FROM users WHERE phone='+251999200000'")
ku = one("SELECT id FROM users WHERE phone='+251999200001'")
ku2 = one("SELECT id FROM users WHERE phone='+251999200002'")
sql("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (%s, 'CSTA', 'Stadium', 'online', 1)" % ku)
cid = one("SELECT id FROM couriers WHERE courier_code='CSTA'")

today = date.today()
monday = today - timedelta(days=today.weekday())


def order(code, status, fee, pay, tip, day, total=900, courier=None):
    p = "NULL" if pay is None else str(pay)
    sql("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, delivery_fee, courier_pay, courier_tip, fare_total, placed_at, delivered_at) "
        "VALUES ('%s', %s, %s, 'Stadium', 'Tabor', '%s', %s, %s, %s, %s, '%s 10:00:00', '%s 11:00:00')"
        % (code, cu, courier or cid, status, fee, p, tip, total, day, day))


order("CST-1", "delivered", 60, None, 15, monday)                          # no saved pay: the fee 60 counts, plus tip 15 = 75
order("CST-2", "delivered", 60, 80, 0, monday)                             # saved distance pay 80 wins over the fee
order("CST-3", "delivered", 50, None, 20, monday + timedelta(days=2))      # Wednesday: 70
order("CST-4", "cancelled", 50, None, 20, monday)                          # not delivered: ignored
order("CST-5", "delivered", 40, None, 0, monday - timedelta(days=1))       # last Sunday: the other week
sql("INSERT INTO couriers (user_id, courier_code, zone, status, active) VALUES (%s, 'CSTB', 'Stadium', 'online', 1)" % ku2)
cid2 = one("SELECT id FROM couriers WHERE courier_code='CSTB'")
order("CST-6", "delivered", 99, None, 9, monday, courier=cid2)             # another courier: never shown

r = stmt(cid)
check("this week starts on Monday and ends on Sunday", r["week_start"] == monday.isoformat() and r["week_end"] == (monday + timedelta(days=6)).isoformat())
check("3 delivered orders (cancelled, last week's and another courier's are left out)", r["totals"]["deliveries"] == 3 and len(r["orders"]) == 3)
check("pay 60+80+50 = 190, tips 15+20 = 35, earned 225 (the order totals never count)", r["totals"]["pay"] == 190 and r["totals"]["tips"] == 35 and r["totals"]["earned"] == 225)
check("per delivery is 75", r["per_delivery"] == 75)
check("seven days, Monday has 2 deliveries and 155, Wednesday has 70", len(r["days"]) == 7 and r["days"][0]["deliveries"] == 2 and r["days"][0]["earned"] == 155 and r["days"][2]["earned"] == 70)
check("days add up to the total", sum(d["earned"] for d in r["days"]) == r["totals"]["earned"])
check("the saved pay (80) wins over the fee, the fee is used when no pay was saved", [o["pay"] for o in r["orders"] if o["order_code"] in ("CST-2", "CST-1")] in ([80, 60], [60, 80]))
check("this week has a previous week and no next week", r["prev_week"] == (monday - timedelta(days=7)).isoformat() and r["next_week"] is None)

r2 = stmt(cid, (monday - timedelta(days=7)).isoformat())
check("last week: 1 delivery, 40", r2["totals"]["deliveries"] == 1 and r2["totals"]["earned"] == 40 and r2["next_week"] == monday.isoformat())
r3 = stmt(cid, (monday + timedelta(days=30)).isoformat())
check("a future date shows this week", r3["week_start"] == monday.isoformat())
r4 = stmt(cid, (monday - timedelta(days=84)).isoformat())
check("12 weeks back is the oldest and has no previous week", r4["week_start"] == (monday - timedelta(days=84)).isoformat() and r4["prev_week"] is None)
check("13 weeks back is refused", "error" in stmt(cid, (monday - timedelta(days=91)).isoformat()))
check("a bad date is refused", "error" in stmt(cid, "2026-13-45") and "error" in stmt(cid, "tomorrow"))
r5 = stmt(cid2, monday.isoformat())
check("another courier sees only their own", r5["totals"]["deliveries"] == 1 and r5["totals"]["earned"] == 108)

sql("DELETE FROM orders WHERE order_code LIKE 'CST-%'; DELETE FROM couriers WHERE courier_code IN ('CSTA','CSTB'); DELETE FROM users WHERE phone LIKE '+2519992000%'")
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
