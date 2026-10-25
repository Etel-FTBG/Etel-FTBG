# NOTE: phone prefix owned by this test: +251 931 / +251 932 (other smokes use 911-988; pick unique ones)
"""
ETEL Delivery -- Etel Business (the shop's own books) smoke test

Covers api/lib/sg_business.php through the real API: the per-shop switch, walk-in sales, the stock ledger, voids, expenses, the daily
cash-up, permissions of a counter-only staff role, resends (must change nothing) and the checks on time, amounts and batch size.

Usage (same convention as the other smoke tests):
    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/shop_business_smoke.py
Needs the seeded admin (api/seeds/admin_demo_accounts.sql); SKIPS when it is missing.
"""
import json
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON error body)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def sql(q):
    c = etel_test_db._cfg()
    r = subprocess.run(["mysql", "-N", "-h", c["host"], "-u", c["user"], c["name"], "-e", q], capture_output=True, text=True,
                       env=dict(__import__("os").environ, MYSQL_PWD=c["pass"]), timeout=30)
    return r.stdout.strip()


def iso(delta_seconds=-60):
    return (datetime.now(timezone.utc) + timedelta(seconds=delta_seconds)).strftime("%Y-%m-%dT%H:%M:%SZ")


def ev(kind, req, data, when=None):
    return {"type": kind, "req_id": req, "occurred_at": when or iso(), "data": data}


def results(res):
    return (res.get("data") or {}).get("results", [])


def stock_of(token, item_id):
    _, res = call("sg_business_state", {}, token=token)
    for it in (res.get("data") or {}).get("items", []):
        if str(it["id"]) == str(item_id):
            return it["stock_quantity"]
    return None


def main():
    _, res = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (res.get("data") or {}).get("token")
    if not admin:
        print("  SKIP no seeded admin account in this database")
        return 0
    phone = f"+251931{SUFFIX[-6:]}"
    _, res = call("admin_create_service_giver", {"name": f"Biz SG {SUFFIX}", "phone": phone, "password": "testpass123",
                  "business_name": "Biz Smoke Kitchen", "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, token=admin)
    check("admin creates a shop", res.get("success") is True, res)
    sg_id = (res.get("data") or {}).get("service_giver_id") or (res.get("data") or {}).get("id")
    _, res = call("sg_login", {"phone": phone, "password": "testpass123"})
    tok = (res.get("data") or {}).get("token")
    _, res = call("sg_add_item", {"name": "Tibs", "category": "food", "price": 100, "stock_quantity": 10}, token=tok)
    a = (res.get("data") or {}).get("item_id")
    _, res = call("sg_add_item", {"name": "Injera", "category": "food", "price": 20, "stock_quantity": 50}, token=tok)
    b = (res.get("data") or {}).get("item_id")
    check("two items created", bool(a and b), res)
    if not sg_id:
        sg_id = sql(f"SELECT id FROM service_givers WHERE user_id=(SELECT id FROM users WHERE phone='{phone}')")

    print("1. The switch")
    st, res = call("sg_pos_sync", {"events": []}, token=tok)
    check("sync is refused while the tools are off", st == 403 and res.get("success") is False, res)
    st, res = call("sg_business_state", {}, token=tok)
    check("state is refused while the tools are off", st == 403, res)
    st, res = call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 1}, token=tok)
    check("a shop cannot switch its own tools on", st == 403, res)
    st, res = call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 1}, token=admin)
    check("admin switches the tools on", res.get("success") is True and res["data"]["business_tools"] == 1, res)
    _, res = call("sg_profile", {}, token=tok)
    check("profile tells the app the tools are on", (res.get("data") or {}).get("service_giver", {}).get("business_tools") == 1, res)
    st, res = call("sg_business_state", {}, token=tok)
    check("state lists the items", res.get("success") is True and len(res["data"]["items"]) >= 2, res)

    print("2. Walk-in sales")
    sale = ev("sale", "s1" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 2}, {"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 1},
                                                {"item_id": b, "name": "Injera", "unit_price": 20, "qty": 5}], "payment_method": "cash"})
    _, res = call("sg_pos_sync", {"events": [sale]}, token=tok)
    check("a sale is saved", results(res) and results(res)[0]["status"] == "ok" and results(res)[0]["total"] == 400, res)
    check("stock went down (same item on two lines adds up)", stock_of(tok, a) == 7 and stock_of(tok, b) == 45, (stock_of(tok, a), stock_of(tok, b)))
    _, res = call("sg_pos_sync", {"events": [sale]}, token=tok)
    check("resending the same sale changes nothing", results(res)[0]["status"] == "dup" and stock_of(tok, a) == 7, res)
    big = ev("sale", "s2" + SUFFIX, {"items": [{"name": "Special order", "unit_price": 500, "qty": 1}], "discount": 300, "payment_method": "mobile"})
    _, res = call("sg_pos_sync", {"events": [big]}, token=tok)
    check("owner may give a big discount; a quick (not-in-menu) line needs no item", results(res)[0]["status"] == "ok" and results(res)[0]["total"] == 200, res)
    credit_bad = ev("sale", "s3" + SUFFIX, {"items": [{"item_id": b, "name": "Injera", "unit_price": 20, "qty": 1}], "payment_method": "credit"})
    credit_ok = ev("sale", "s4" + SUFFIX, {"items": [{"item_id": b, "name": "Injera", "unit_price": 20, "qty": 1}], "payment_method": "credit", "customer_name": "Abebe"})
    _, res = call("sg_pos_sync", {"events": [credit_bad, credit_ok]}, token=tok)
    r = results(res)
    check("a credit sale needs a name; one bad event does not block the next", r[0]["status"] == "error" and r[1]["status"] == "ok", r)
    bad_events = [ev("sale", "s5" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 0}]}),
                  ev("sale", "s6" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": -5, "qty": 1}]}),
                  ev("sale", "s7" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 1}], "discount": 5000}),
                  ev("sale", "s8" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 1}]}, when=iso(-8 * 86400)),
                  ev("sale", "s9" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 1}]}, when=iso(3600)),
                  {"type": "sale", "req_id": "bad id!", "occurred_at": iso(), "data": {}}]
    _, res = call("sg_pos_sync", {"events": bad_events}, token=tok)
    check("zero qty, negative price, over-discount, 8-day-old, future and malformed events are all refused",
          all(x["status"] == "error" for x in results(res)) and len(results(res)) == 6, results(res))
    check("refused events did not touch stock", stock_of(tok, a) == 7, stock_of(tok, a))
    st, res = call("sg_pos_sync", {"events": [ev("expense", f"x{i}{SUFFIX}", {}) for i in range(201)]}, token=tok)
    check("more than 200 events in one request is refused", st == 400, res)

    print("3. Void")
    _, res = call("sg_pos_sync", {"events": [ev("void", "v1" + SUFFIX, {"sale_req_id": "s1" + SUFFIX})]}, token=tok)
    check("a void is saved and the stock comes back", results(res)[0]["status"] == "ok" and stock_of(tok, a) == 10 and stock_of(tok, b) == 49, (results(res), stock_of(tok, a), stock_of(tok, b)))
    _, res = call("sg_pos_sync", {"events": [ev("void", "v2" + SUFFIX, {"sale_req_id": "s1" + SUFFIX})]}, token=tok)
    check("voiding twice changes nothing", results(res)[0]["status"] == "dup" and stock_of(tok, a) == 10, results(res))
    _, res = call("sg_pos_sync", {"events": [ev("void", "v3" + SUFFIX, {"sale_req_id": "nope"})]}, token=tok)
    check("voiding an unknown sale is an error", results(res)[0]["status"] == "error", res)

    print("4. Stock")
    evs = [ev("stock", "k1" + SUFFIX, {"item_id": a, "kind": "delivery", "qty": 5}), ev("stock", "k2" + SUFFIX, {"item_id": a, "kind": "waste", "qty": 2}),
           ev("stock", "k3" + SUFFIX, {"item_id": b, "kind": "count", "qty": 30}), ev("stock", "k4" + SUFFIX, {"item_id": a, "kind": "waste", "qty": 0})]
    _, res = call("sg_pos_sync", {"events": evs}, token=tok)
    r = results(res)
    check("delivery, waste and count are saved; a zero quantity is refused", [x["status"] for x in r] == ["ok", "ok", "ok", "error"], r)
    check("stock levels follow the movements", stock_of(tok, a) == 13 and stock_of(tok, b) == 30, (stock_of(tok, a), stock_of(tok, b)))
    _, res = call("sg_pos_sync", {"events": evs[:1]}, token=tok)
    check("resending a stock entry changes nothing", results(res)[0]["status"] == "dup" and stock_of(tok, a) == 13, results(res))
    ledger = sql(f"SELECT COALESCE(SUM(delta),0) FROM sg_stock_events WHERE service_giver_id={sg_id} AND item_id={a}")
    check("the ledger adds up to the change in stock", ledger == "3", ledger)

    print("5. Expenses and cash-up")
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    evs = [ev("expense", "e1" + SUFFIX, {"category": "supplies", "amount": 50, "paid_with": "cash", "note": "onions"}),
           ev("expense", "e2" + SUFFIX, {"category": "electricity", "amount": 70, "paid_with": "mobile"}),
           ev("expense", "e3" + SUFFIX, {"category": "snacks", "amount": 10}), ev("expense", "e4" + SUFFIX, {"category": "rent", "amount": 0})]
    _, res = call("sg_pos_sync", {"events": evs}, token=tok)
    check("expenses saved; unknown category and zero amount refused", [x["status"] for x in results(res)] == ["ok", "ok", "error", "error"], results(res))
    _, res = call("sg_pos_sync", {"events": [ev("cashup", "c1" + SUFFIX, {"business_date": today, "opening_float": 100, "counted_cash": 150})]}, token=tok)
    r = results(res)[0]
    # cash sales left after the void: only the credit sale was not cash; s2 was mobile; s1 voided -> 0 cash sales. expected = 100 + 0 - 50
    check("cash-up works out what the till should hold", r["status"] == "ok" and r["expected_cash"] == 50 and r["difference"] == 100, r)
    _, res = call("sg_pos_sync", {"events": [ev("cashup", "c2" + SUFFIX, {"business_date": today, "opening_float": 0, "counted_cash": 0})]}, token=tok)
    check("a closed day cannot be closed again with other figures", results(res)[0]["status"] == "error", results(res))
    _, res = call("sg_pos_sync", {"events": [ev("cashup", "c1" + SUFFIX, {"business_date": today, "opening_float": 100, "counted_cash": 150})]}, token=tok)
    check("resending the close changes nothing", results(res)[0]["status"] == "dup", results(res))
    _, res = call("sg_business_state", {}, token=tok)
    t = res["data"]["today"]
    cd = t.get("cash_day") or {}
    check("the state tells the app the day is closed, with the figures", cd.get("expected_cash") == 50 and cd.get("counted_cash") == 150 and cd.get("difference") == 100, t)
    check("the state separates cash expenses from the rest, and remembers the last count", t.get("cash_expenses") == 50 and t.get("last_counted") == 150, t)
    check("today's totals count live sales only", t["sales"] == 2 and abs(t["sales_total"] - 220) < 0.01 and abs(t["expenses"] - 120) < 0.01, t)

    print("6. A counter-only staff role")
    _, res = call("sg_save_role", {"role_name": "Counter", "capabilities": ["pos.sell", "orders.view"]}, token=tok)
    role_id = (res.get("data") or {}).get("role_id") or (res.get("data") or {}).get("id")
    check("owner creates a counter role", res.get("success") is True and role_id, res)
    sp = f"+251932{SUFFIX[-6:]}"
    _, res = call("sg_add_staff", {"name": "Counter Kid", "phone": sp, "password": "testpass123", "role_id": role_id}, token=tok)
    check("owner adds the staff login", res.get("success") is True, res)
    _, res = call("login", {"phone": sp, "password": "testpass123"})
    ctok = (res.get("data") or {}).get("token")
    evs = [ev("sale", "t1" + SUFFIX, {"items": [{"item_id": b, "name": "Injera", "unit_price": 20, "qty": 2}]}),
           ev("sale", "t2" + SUFFIX, {"items": [{"item_id": a, "name": "Tibs", "unit_price": 100, "qty": 1}], "discount": 50}),
           ev("void", "t3" + SUFFIX, {"sale_req_id": "t1" + SUFFIX}), ev("stock", "t4" + SUFFIX, {"item_id": a, "kind": "delivery", "qty": 5}),
           ev("expense", "t5" + SUFFIX, {"category": "other", "amount": 5}), ev("cashup", "t6" + SUFFIX, {"business_date": "2000-01-01", "counted_cash": 1})]
    _, res = call("sg_pos_sync", {"events": evs}, token=ctok)
    r = [x["status"] for x in results(res)]
    check("staff can sell, but not give a big discount, void, move stock, spend or close the day", r == ["ok", "error", "error", "error", "error", "error"], results(res))
    st, res = call("sg_business_state", {}, token=ctok)
    check("staff with only the sell right can open the counter", res.get("success") is True, res)

    print("7. Online orders and stock")
    etel_test_db.park_couriers()
    cphone = f"+251933{SUFFIX[-6:]}"
    _, res = call("register", {"name": f"Biz Cust {SUFFIX}", "phone": cphone, "password": "testpass123", "role": "customer"})
    ctok2 = (res.get("data") or {}).get("token")
    check("customer registers", bool(ctok2), res)

    def order(item_id, qty):
        _, r = call("customer_create_order", {"items": [{"item_id": item_id, "quantity": qty}], "to_zone": "Tabor", "recipient_name": "Biz Recipient",
                                              "recipient_phone": cphone, "payment_method": "cash"}, token=ctok2)
        return (r.get("data") or {}).get("order_id"), r
    before = stock_of(tok, a)
    oid, r = order(a, 2)
    check("customer orders 2 of the item", bool(oid), r)
    check("placing an order does not change stock yet", stock_of(tok, a) == before, (before, stock_of(tok, a)))
    _, res = call("sg_update_order_status", {"order_id": oid, "new_status": "preparing"}, token=tok)
    check("shop starts preparing", res.get("success") is True, res)
    check("accepting the order takes its items off the stock", stock_of(tok, a) == before - 2, (before, stock_of(tok, a)))
    _, res = call("sg_update_order_status", {"order_id": oid, "new_status": "preparing"}, token=tok)
    check("a repeat of the same step does not take them off again", stock_of(tok, a) == before - 2, (before, stock_of(tok, a), res))
    _, res = call("sg_update_order_status", {"order_id": oid, "new_status": "accepted"}, token=tok)
    check("the next step does not take them off again either", stock_of(tok, a) == before - 2, (before, stock_of(tok, a), res))
    _, res = call("sg_stock_history", {"item_id": a}, token=tok)
    mv = (res.get("data") or {}).get("movements", [])
    check("history shows the online order", any(m["reason"] == "online_order" and int(m["delta"]) == -2 for m in mv), mv)
    _, res = call("sg_stock_history", {"item_id": a}, token=ctok)
    check("staff without stock.manage cannot read the history", res.get("success") is False, res)
    _, res = call("sg_reject_order", {"order_id": oid, "reason": "out_of_stock"}, token=tok)
    check("the shop can still cancel before pickup", res.get("success") is True, res)
    check("a cancelled order puts its items back", stock_of(tok, a) == before, (before, stock_of(tok, a)))
    _, res = call("sg_reject_order", {"order_id": oid, "reason": "out_of_stock"}, token=tok)
    check("cancelling twice puts nothing back twice", stock_of(tok, a) == before, (before, stock_of(tok, a), res))
    oid2, r = order(a, 1)
    _, res = call("sg_accept_order", {"order_id": oid2}, token=tok)
    check("accept_order also takes the items off", res.get("success") is True and stock_of(tok, a) == before - 1, (res, stock_of(tok, a)))
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 0}, token=admin)
    oid3, r = order(b, 1)
    sb = sql(f"SELECT stock_quantity FROM items WHERE id={b}")
    call("sg_update_order_status", {"order_id": oid3, "new_status": "preparing"}, token=tok)
    check("a shop without Etel Business keeps the old behaviour (stock untouched)", sql(f"SELECT stock_quantity FROM items WHERE id={b}") == sb, (sb, sql(f"SELECT stock_quantity FROM items WHERE id={b}")))
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 1}, token=admin)

    print("8. Switched off again")
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 0}, token=admin)
    st, res = call("sg_pos_sync", {"events": []}, token=tok)
    check("sync is refused again once the tools are off", st == 403, res)
    st, res = call("sg_pos_sync", {"events": []}, token=admin)
    check("a platform admin cannot enter a shop's sales", st in (403, 404), (st, res))

    print("9. A platform admin testing as a shop (\"Test as...\")")
    _, res = call("sg_profile", {}, token=admin)
    mine_id = ((res.get("data") or {}).get("service_giver") or {}).get("id")
    check("the admin has a test shop of their own", bool(mine_id), res)
    call("admin_sg_set_business_tools", {"id": mine_id, "enabled": 1}, token=admin)
    _, res = call("sg_add_item", {"name": "Admin Tibs", "category": "food", "price": 50, "stock_quantity": 10}, token=admin)
    aid = (res.get("data") or {}).get("item_id")
    check("the admin adds an item to the test shop", bool(aid), res)
    _, res = call("sg_pos_sync", {"events": [ev("sale", "ad1" + SUFFIX, {"items": [{"item_id": aid, "name": "Admin Tibs", "unit_price": 50, "qty": 2}]}),
                                             ev("stock", "ad2" + SUFFIX, {"item_id": aid, "kind": "delivery", "qty": 5})]}, token=admin)
    check("the admin can sell and receive stock in their own test shop", [x["status"] for x in results(res)] == ["ok", "ok"], res)
    _, res = call("sg_stock_history", {"item_id": aid}, token=admin)
    check("the admin can read the stock history of their own test shop", res.get("success") is True and len((res.get("data") or {}).get("movements", [])) == 2, res)
    _, res = call("sg_stock_history", {"item_id": a}, token=admin)
    check("...but not another shop's item", res.get("success") is False, res)
    call("admin_sg_set_business_tools", {"id": mine_id, "enabled": 0}, token=admin)

    print("10. The report")
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 1}, token=admin)
    today = datetime.now().strftime("%Y-%m-%d")
    _, res = call("sg_business_report", {"from": today, "to": today}, token=tok)
    d = res.get("data") or {}
    t = d.get("totals") or {}
    check("the owner gets a report", res.get("success") is True and "net" in t, res)
    exp_sales = sql(f"SELECT COALESCE(SUM(total),0) FROM sg_pos_sales WHERE service_giver_id={sg_id} AND status<>'void' AND DATE(occurred_at)=CURDATE()")
    exp_exp = sql(f"SELECT COALESCE(SUM(amount),0) FROM sg_expenses WHERE service_giver_id={sg_id} AND DATE(occurred_at)=CURDATE()")
    check("counter sales in the report match the books", abs(float(t.get("gross", -1)) - float(exp_sales)) < 0.01, (t, exp_sales))
    check("expenses in the report match the books", abs(float(t.get("expenses", -1)) - float(exp_exp)) < 0.01, (t, exp_exp))
    check("net is money in minus money out", abs(float(t.get("net", 0)) - (float(t.get("income", 0)) - float(t.get("expenses", 0)))) < 0.01, t)
    check("voided sales are not counted as sales", t.get("voided", 0) >= 0 and int(t.get("sales", -1)) == int(sql(f"SELECT COUNT(*) FROM sg_pos_sales WHERE service_giver_id={sg_id} AND status<>'void' AND DATE(occurred_at)=CURDATE()")), t)
    check("the best sellers list is ordered", [x["qty"] for x in d.get("best", [])] == sorted([x["qty"] for x in d.get("best", [])], reverse=True), d.get("best"))
    check("the slowest list comes from the shop's own menu", all(x["name"] in ("Tibs", "Injera") for x in d.get("slow", [])), d.get("slow"))
    st, res = call("sg_business_report", {"from": "2024-01-01", "to": today}, token=tok)
    check("more than a year at once is refused", st == 400, res)
    st, res = call("sg_business_report", {"from": "nonsense", "to": today}, token=tok)
    check("a bad date is refused", st == 400, res)
    st, res = call("sg_business_report", {"from": today, "to": "2000-01-01"}, token=tok)
    check("a range that ends before it starts is refused", st == 400, res)
    st, res = call("sg_business_report", {"sg_id": 999999, "from": today, "to": today}, token=tok)
    check("the shop always comes from the account, never the request", res.get("success") is True and "sg_id" not in json.dumps(res.get("data")), res)
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 0}, token=admin)
    st, res = call("sg_business_report", {"from": today, "to": today}, token=tok)
    check("the report is refused once the tools are off", st == 403, res)

    print("11. Limits and the activity log")
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 1}, token=admin)
    _, res = call("sg_business_report", {"from": today, "to": today}, token=tok)
    audit = (res.get("data") or {}).get("audit") or []
    acts = [x["action"] for x in audit]
    check("the report carries the activity log", res.get("success") is True and len(audit) > 0, res)
    cnt = lambda act: int(sql(f"SELECT COUNT(*) FROM sg_business_audit WHERE service_giver_id={sg_id} AND action='{act}'") or 0)
    check("a cancelled sale is logged", cnt("sale_void") >= 1, acts)
    check("a big discount is logged", cnt("big_discount") >= 1, acts)
    check("waste and counts are logged", cnt("stock_waste") >= 1 and cnt("stock_count") >= 1, acts)
    check("the cash-up result is logged", cnt("cashup") >= 1, acts)
    check("a refused action is logged (staff without the right)", cnt("denied") >= 1, acts)
    check("a sale added to a day already counted is logged", cnt("late_entry") >= 1, acts)
    check("switching the tools is logged against the admin", int(sql(f"SELECT COUNT(*) FROM sg_business_audit WHERE service_giver_id={sg_id} AND action IN ('tools_on','tools_off') AND user_id IS NOT NULL") or 0) >= 1)
    check("the log says who did it", any(x.get("who") for x in audit), audit[:2])
    _, res = call("sg_pos_sync", {"events": [ev("void", "lc" + SUFFIX, {"sale_req_id": "s2" + SUFFIX})]}, token=tok)
    r = results(res)
    check("a sale from a day that is already counted cannot be cancelled", r and r[0]["status"] == "error" and "counted" in r[0]["message"], res)
    _, res = call("sg_pos_sync", {"events": [ev("sale", "big" + SUFFIX, {"items": [{"name": "Truck", "unit_price": 6000000, "qty": 2}]})]}, token=tok)
    r = results(res)
    check("one sale above ETB 10,000,000 is refused", r and r[0]["status"] == "error", res)
    sql(f"INSERT INTO sg_pos_sales (service_giver_id, client_req_id, sold_by_user_id, occurred_at, subtotal, discount, total, payment_method, status, created_at) "
        f"SELECT {sg_id}, CONCAT('lim{SUFFIX}_', seq), 1, NOW(), 1, 0, 1, 'cash', 'paid', NOW() FROM seq_1_to_2000")
    _, res = call("sg_pos_sync", {"events": [ev("sale", "over" + SUFFIX, {"items": [{"name": "One too many", "unit_price": 1, "qty": 1}]})]}, token=tok)
    r = results(res)
    check("the 2001st sale of a day is refused with a clear message", r and r[0]["status"] == "error" and "2000" in r[0]["message"], res)
    check("the limit is written to the log", cnt("limit") >= 1)
    sql(f"DELETE FROM sg_pos_sales WHERE service_giver_id={sg_id} AND client_req_id LIKE 'lim{SUFFIX}\\_%'")
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'acct:sgb\\_%'")
    codes = []
    for _ in range(125):
        st, _r = call("sg_pos_sync", {"events": []}, token=tok)
        codes.append(st)
    check("more than 120 syncs in ten minutes get a 429", 429 in codes and codes[:120].count(429) == 0, codes[-6:])
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'acct:sgb\\_%'")
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 0}, token=admin)

    print("12. Expense corrections")
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 1}, token=admin)
    day = (datetime.now() - timedelta(days=2)).strftime("%Y-%m-%d")
    yest = iso(-3600 * 24 * 2)
    _, res = call("sg_pos_sync", {"events": [ev("expense", "xa" + SUFFIX, {"category": "supplies", "amount": 70, "paid_with": "cash"}, yest),
                                             ev("expense", "xb" + SUFFIX, {"category": "rent", "amount": 30, "paid_with": "mobile"}, yest)]}, token=tok)
    check("two expenses on an open day are saved", [x["status"] for x in results(res)] == ["ok", "ok"], res)
    def exp_sum(): return float(sql(f"SELECT COALESCE(SUM(amount),0) FROM sg_expenses WHERE service_giver_id={sg_id} AND status='active' AND DATE(occurred_at)='{day}'") or 0)
    check("both count", abs(exp_sum() - 100) < 0.01, exp_sum())
    _, res = call("sg_pos_sync", {"events": [ev("expense_void", "xv1" + SUFFIX, {"expense_req_id": "xa" + SUFFIX})]}, token=tok)
    check("an expense can be cancelled", [x["status"] for x in results(res)] == ["ok"], res)
    check("the cancelled expense no longer counts", abs(exp_sum() - 30) < 0.01, exp_sum())
    check("...but its row is kept, marked void", sql(f"SELECT status FROM sg_expenses WHERE service_giver_id={sg_id} AND client_req_id='xa{SUFFIX}'") == "void")
    _, res = call("sg_pos_sync", {"events": [ev("expense_void", "xv1" + SUFFIX, {"expense_req_id": "xa" + SUFFIX})]}, token=tok)
    check("sending the same cancel again changes nothing", [x["status"] for x in results(res)] == ["dup"], res)
    _, res = call("sg_pos_sync", {"events": [ev("expense_void", "xv2" + SUFFIX, {"expense_req_id": "xa" + SUFFIX})]}, token=tok)
    check("cancelling an already cancelled expense is a no-op", [x["status"] for x in results(res)] == ["dup"], res)
    _, res = call("sg_pos_sync", {"events": [ev("expense_void", "xv3" + SUFFIX, {"expense_req_id": "nope"})]}, token=tok)
    check("an unknown expense is refused", [x["status"] for x in results(res)] == ["error"], res)
    _, res = call("sg_business_report", {"from": day, "to": day}, token=tok)
    check("the report counts only active expenses", abs(float((res.get("data") or {}).get("totals", {}).get("expenses", -1)) - 30) < 0.01, res)
    check("the cancel is in the activity log", int(sql(f"SELECT COUNT(*) FROM sg_business_audit WHERE service_giver_id={sg_id} AND action='expense_void'") or 0) >= 1)
    _, res = call("sg_pos_sync", {"events": [ev("expense", "xt" + SUFFIX, {"category": "other", "amount": 11, "paid_with": "cash"})]}, token=tok)
    _, res = call("sg_pos_sync", {"events": [ev("expense_void", "xv4" + SUFFIX, {"expense_req_id": "xt" + SUFFIX})]}, token=tok)
    r = results(res)
    check("an expense of a day that is already counted cannot be cancelled", r and r[0]["status"] == "error" and "counted" in r[0]["message"], res)
    call("admin_sg_set_business_tools", {"id": sg_id, "enabled": 0}, token=admin)

    print()
    print(f"{len(PASS)} passed, {len(FAIL)} failed")
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
