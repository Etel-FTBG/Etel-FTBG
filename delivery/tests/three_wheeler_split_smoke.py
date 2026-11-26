"""
ETEL Delivery -- "Bajaj / Qute" is two vehicles: Bajaj and Qute, each with its own cart entry (own picture word), own prices and own couriers.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/three_wheeler_split_smoke.py

Four situations, all over the real API (get_delivery_tiers is the cart's vehicle list):
  A. an install that already has the old combined type -> it becomes Bajaj, Qute is added with the same prices and delivery fees, once
  B. a fresh install -> Bajaj and Qute are both added
  C. the list of added types is full, so Qute cannot be added -> the old type is put back as it was (nobody loses jobs)
  D. staff already changed the type so it carries only one word -> nothing is split
Throwaway database only. Needs the seeded demo admin.
"""
import json
import random
import sys
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
TAIL = "%06d" % random.randint(0, 999999)
PASS, FAIL = [], []
ICON_SQL = "CONVERT(UNHEX('F09F9BBA') USING utf8mb4)"   # the three-wheeler emoji; the mysql client here cannot send 4-byte text


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def sql(q):
    ok, err = etel_test_db.run_sql(q)
    if not ok:
        raise RuntimeError(err)


def one(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def wipe():
    sql("DELETE FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')")
    sql("DELETE FROM package_settings WHERE setting_key IN ('seeded_threewheel','split_threewheel')")
    sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'dfee\\_x%'")


def add_type(cls, label, names):
    sql("INSERT INTO package_rates (vehicle_class, label, icon, base_fee, per_km, free_kg, per_kg_over, min_fee, max_weight_kg, sort_order, active, size_rank, courier_names, updated_at) "
        "VALUES ('%s', '%s', %s, 61, 13, 5, 6, 81, 100, 2, 1, 2, '%s', NOW())" % (cls, label, ICON_SQL, names))


def old_install():
    """What a server that already ran the old seed looks like: the combined type, the seed flag, no split flag."""
    wipe()
    add_type("x1", "Bajaj / Qute", "bajaj, qute")
    sql("INSERT INTO package_settings (setting_key, setting_value, updated_at) VALUES ('seeded_threewheel', '1', NOW())")


def cart():
    s, r = call("get_delivery_tiers", {})
    return (r.get("data") or {}).get("vehicles") or []


def by_label(vs):
    return dict((v.get("label"), v) for v in vs)


def make_courier(tag, vehicle):
    ph = "+25198%s%s" % (tag, TAIL)
    s, r = call("register", {"name": "wheel %s %s" % (tag, SUFFIX), "phone": ph, "password": "testpass123", "role": "courier"})
    if not (r.get("data") or {}).get("token"):
        sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        call("register", {"name": "wheel %s %s" % (tag, SUFFIX), "phone": ph, "password": "testpass123", "role": "courier"})
    sql("UPDATE couriers c JOIN users u ON u.id = c.user_id SET c.status = 'online', c.active = 1, c.vehicle_type = '%s', "
        "c.last_lat = 7.05, c.last_lng = 38.48, c.last_seen = NOW() WHERE u.phone = '%s'" % (vehicle, ph))
    return ph


def main():
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    if not (r.get("data") or {}).get("token"):
        print("SKIP: no seeded admin"); return 0
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    etel_test_db.park_couriers()

    print("A. an install with the old combined type")
    old_install()
    sql("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('dfee_x1_base', 77, 't'), ('dfee_x1_per_km', 19, 't'), ('dfee_x1_min', 99, 't') "
        "ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")
    ph_b = make_courier("1", "bajaj")
    ph_q = make_courier("2", "qute")
    vs = cart()
    lab = by_label(vs)
    check("the cart lists Bajaj and Qute, no more 'Bajaj / Qute'", "Bajaj" in lab and "Qute" in lab and "Bajaj / Qute" not in lab, sorted(lab))
    if "Bajaj" in lab and "Qute" in lab:
        check("each has its own key", lab["Bajaj"]["tier_key"] != lab["Qute"]["tier_key"], (lab["Bajaj"]["tier_key"], lab["Qute"]["tier_key"]))
        check("Bajaj keeps the old key (orders already placed still match)", lab["Bajaj"]["tier_key"] == "x1", lab["Bajaj"]["tier_key"])
        check("each carries its own courier word, for its own picture", lab["Bajaj"].get("names") == ["bajaj"] and lab["Qute"].get("names") == ["qute"], (lab["Bajaj"].get("names"), lab["Qute"].get("names")))
        check("each counts only its own couriers online (1 bajaj, 1 qute)", lab["Bajaj"]["online"] == 1 and lab["Qute"]["online"] == 1, (lab["Bajaj"]["online"], lab["Qute"]["online"]))
        k = lab["Qute"]["tier_key"]
        check("Qute starts with the same price rows as the old type (base 61, per km 13, min 81)",
              one("SELECT CONCAT(ROUND(base_fee), '|', ROUND(per_km), '|', ROUND(min_fee)) FROM package_rates WHERE vehicle_class = '%s'" % k) == "61|13|81")
        check("Qute gets the delivery fee staff had set for the old type (77 / 19 / 99)",
              one("SELECT CONCAT(MAX(CASE WHEN rule_key = 'dfee_%s_base' THEN rule_value+0 END), '|', MAX(CASE WHEN rule_key = 'dfee_%s_per_km' THEN rule_value+0 END), '|', "
                  "MAX(CASE WHEN rule_key = 'dfee_%s_min' THEN rule_value+0 END)) FROM pricing_rules" % (k, k, k)) == "77|19|99")
        check("the old type's own delivery fee is untouched", one("SELECT rule_value+0 FROM pricing_rules WHERE rule_key = 'dfee_x1_base'") == "77")
    n1 = one("SELECT COUNT(*) FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')")
    cart(); cart()
    check("it happens once: asking again adds nothing", one("SELECT COUNT(*) FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')") == n1 == "2", n1)
    check("the 'done' flag is set", one("SELECT setting_value FROM package_settings WHERE setting_key = 'split_threewheel'") == "1")

    print("B. a fresh install")
    wipe()
    vs = cart(); lab = by_label(vs)
    check("fresh: Bajaj and Qute are both there", "Bajaj" in lab and "Qute" in lab and "Bajaj / Qute" not in lab, sorted(lab))
    check("fresh: exactly two added types", one("SELECT COUNT(*) FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')") == "2")
    check("fresh: each carries its own word", (lab.get("Bajaj") or {}).get("names") == ["bajaj"] and (lab.get("Qute") or {}).get("names") == ["qute"], lab)
    check("fresh: both flags are set", one("SELECT COUNT(*) FROM package_settings WHERE setting_key IN ('seeded_threewheel','split_threewheel')") == "2")

    print("C. the list of added types is full")
    old_install()
    for i in range(2, 13):
        add_type("x%d" % i, "Filler %d" % i, "filler%d" % i)
    cart()
    check("C: Qute could not be added, so the old type is kept whole (still bajaj + qute)",
          one("SELECT CONCAT(label, '|', courier_names) FROM package_rates WHERE vehicle_class = 'x1'") == "Bajaj / Qute|bajaj, qute", one("SELECT CONCAT(label, '|', courier_names) FROM package_rates WHERE vehicle_class = 'x1'"))
    check("C: no Qute type exists", one("SELECT COUNT(*) FROM package_rates WHERE label = 'Qute'") == "0")
    check("C: it does not try again on every request", one("SELECT setting_value FROM package_settings WHERE setting_key = 'split_threewheel'") == "1")

    print("D. staff already changed the type")
    old_install()
    sql("UPDATE package_rates SET label = 'Three wheeler', courier_names = 'bajaj' WHERE vehicle_class = 'x1'")
    vs = cart(); lab = by_label(vs)
    check("D: nothing is split or added", sorted(k for k in lab if k in ("Three wheeler", "Bajaj", "Qute")) == ["Three wheeler"] and
          one("SELECT COUNT(*) FROM package_rates WHERE vehicle_class NOT IN ('bike','moto','car','van')") == "1", sorted(lab))

    wipe()
    sql("DELETE FROM pricing_rules WHERE rule_key LIKE 'dfee\\_x%'")
    etel_test_db.park_couriers()
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
