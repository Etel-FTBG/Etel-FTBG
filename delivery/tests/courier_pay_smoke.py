"""
Courier pay by distance, against a real database: OFF pays the delivery fee, ON pays by distance and saves the amount on the
order, later setting changes never rewrite a saved amount, an admin override wins, the tip is added on top, earnings follow.
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
    r = subprocess.run(["mysql", "-h", c["host"], "-u", c["user"], c["name"], "-N", "-B", "-e", q], capture_output=True, text=True, env=env, timeout=30)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def cli(*args):
    r = subprocess.run(["php", os.path.join(HERE, "courier_pay_cli.php")] + [str(a) for a in args], capture_output=True, text=True, timeout=60)
    if r.returncode != 0:
        raise RuntimeError(r.stdout + r.stderr)
    return json.loads(r.stdout.strip().splitlines()[-1])


ok, why = T.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("courier pay by distance")
cli("ensure")
sql("ALTER TABLE service_givers ADD COLUMN IF NOT EXISTS lat DECIMAL(10,7) NULL, ADD COLUMN IF NOT EXISTS lng DECIMAL(10,7) NULL")
sql("ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_lat DECIMAL(10,7) NULL, ADD COLUMN IF NOT EXISTS delivery_lng DECIMAL(10,7) NULL")
sql("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'CPAY-%'); DELETE FROM orders WHERE order_code LIKE 'CPAY-%'; "
    "DELETE FROM service_givers WHERE service_giver_code='CPSG'; DELETE FROM couriers WHERE courier_code='CPCA'; DELETE FROM users WHERE phone LIKE '+2519992000%'; "
    "DELETE FROM pricing_rules WHERE rule_key LIKE 'courier_pay_%' OR rule_key IN ('tip_max','tip_presets')")
for i, role in enumerate(["customer", "courier", "service_giver"]):
    sql("INSERT INTO users (name, phone, password_hash, role) VALUES ('Pay Test %d', '+2519992000%02d', 'x', '%s')" % (i, i, role))
uid = [one("SELECT id FROM users WHERE phone='+2519992000%02d'" % i) for i in range(3)]
# shop at (7.0500, 38.4800); customer 0.018 deg north (~2.0 km straight); courier 0.009 deg south of the shop (~1.0 km straight)
sql("INSERT INTO service_givers (user_id, service_giver_code, business_name, lat, lng) VALUES (%s, 'CPSG', 'Pay Test Shop', 7.0500, 38.4800)" % uid[2])
sg = one("SELECT id FROM service_givers WHERE service_giver_code='CPSG'")
sql("INSERT INTO couriers (user_id, courier_code, zone, status, active, last_lat, last_lng) VALUES (%s, 'CPCA', 'Stadium', 'busy', 1, 7.0410, 38.4800)" % uid[1])
cid = one("SELECT id FROM couriers WHERE courier_code='CPCA'")


def order(code, status="assigned", fee=60, tip=0, hour=12):
    sql("INSERT INTO orders (order_code, customer_id, courier_id, from_zone, to_zone, status, delivery_fee, courier_tip, fare_total, delivery_lat, delivery_lng, placed_at) "
        "VALUES ('%s', %s, %s, 'Shop', 'Tabor', '%s', %s, %s, 500, 7.0680, 38.4800, DATE_FORMAT(NOW(), '%%Y-%%m-%%d %02d:00:00'))" % (code, uid[0], cid, status, fee, tip, hour))
    oid = one("SELECT id FROM orders WHERE order_code='%s'" % code)
    sql("INSERT INTO order_assignments (order_id, service_giver_id, status) VALUES (%s, %s, 'assigned')" % (oid, sg))
    return oid


# 1) OFF (default): the courier is paid the delivery fee, tip on top
o1 = order("CPAY-1", fee=60, tip=15)
r = cli("apply", o1, cid, 1)
check("OFF: pay is the delivery fee (60) and the app shows fee + tip (75)", r["courier_pay"] == 60 and r["delivery_fee"] == 75 and r["courier_tip"] == 15)
check("OFF: nothing is saved on the order", one("SELECT courier_pay IS NULL FROM orders WHERE id=%s" % o1) == "1")

# 2) ON: admin switches it on (defaults: base 25, 7/km, cap 3 km, min 40, road 1.3, night 1.3 from 21 to 6)
s = cli("save", json.dumps({"enabled": 1}))
check("settings saved with the admin's switch on", s["enabled"] == 1 and s["base"] == 25)
o2 = order("CPAY-2", fee=60, tip=10)
r = cli("apply", o2, cid, 1)   # pickup ~1.0 km x1.3 = 1.3 ; drop ~2.0 km x1.3 = 2.6 ; 25 + 7 x 3.9 = 52.3 -> 52 (+ tip 10 = 62)
check("ON: pay follows distance (about 52) and the app shows pay + tip", 50 <= r["courier_pay"] <= 54 and abs(r["delivery_fee"] - (r["courier_pay"] + 10)) < 0.01)
saved = float(one("SELECT courier_pay FROM orders WHERE id=%s" % o2))
check("ON: the amount is saved on the order", abs(saved - r["courier_pay"]) < 0.01)

# 3) a later setting change never rewrites a saved amount
cli("save", json.dumps({"enabled": 1, "base": 100}))
r2 = cli("apply", o2, cid, 1)
check("a saved amount does not change when the settings change", abs(r2["courier_pay"] - saved) < 0.01)

# 4) an offer (not saved) uses current settings and is not stored
o3 = order("CPAY-3", status="pending", fee=60)
sql("UPDATE orders SET courier_id = NULL WHERE id=%s" % o3)
r3 = cli("apply", o3, cid, 0)
check("an unassigned offer shows an estimate from the new settings (base 100)", r3["courier_pay"] > 100)
check("an estimate is never saved", one("SELECT courier_pay IS NULL FROM orders WHERE id=%s" % o3) == "1")

# 5) admin override wins and earnings use it
cli("save", json.dumps({"enabled": 1}))
sql("UPDATE orders SET courier_pay = 80, courier_pay_note = 'set by admin: test', status = 'delivered', delivered_at = NOW() WHERE id=%s" % o2)
r4 = cli("apply", o2, cid, 1)
check("an admin override is what the courier is paid (80 + tip 10)", r4["courier_pay"] == 80 and r4["delivery_fee"] == 90)
e = cli("earnings", cid)
check("earnings today count the saved pay + tip for the delivered order (90)", e["today"]["earned"] == 90)

# 6) no coordinates: falls back to the delivery fee
o5 = order("CPAY-5", fee=70)
sql("UPDATE orders SET delivery_lat = NULL, delivery_lng = NULL WHERE id=%s" % o5)
r5 = cli("apply", o5, cid, 1)
check("no customer position: the delivery fee (70) is paid", r5["courier_pay"] == 70)

sql("DELETE FROM order_assignments WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'CPAY-%'); DELETE FROM orders WHERE order_code LIKE 'CPAY-%'; "
    "DELETE FROM service_givers WHERE service_giver_code='CPSG'; DELETE FROM couriers WHERE courier_code='CPCA'; DELETE FROM users WHERE phone LIKE '+2519992000%'; "
    "DELETE FROM pricing_rules WHERE rule_key LIKE 'courier_pay_%' OR rule_key IN ('tip_max','tip_presets')")
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
