"""
Item extras ("Mayonnaise +50") and extra photos: admin sets extras on an item (form action and CSV column), customers receive them with the items,
an order adds the ticked extras' prices on the server (the client's numbers are never trusted), the extras' names go into the order line, extras that
belong to another item or do not exist refuse the order, and only an admin may change them.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import time
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

API_URL = os.environ.get("ETEL_API_URL", "http://127.0.0.1:8765/api/boot.php")
SUFFIX = str(int(time.time()))
TAIL = SUFFIX[-6:]
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def get(action, params, token=None):
    qs = "&".join("%s=%s" % (k, urllib.parse.quote(str(v))) for k, v in dict(params, action=action).items())
    req = urllib.request.Request(API_URL + "?" + qs, headers={"Authorization": "Bearer " + token} if token else {})
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


def data(res):
    return res.get("data") or {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)
etel_test_db.park_couriers()
cphone = "+251934%s" % TAIL
st, res = call("register", {"name": "Extras Cust", "phone": cphone, "password": "test1234", "role": "customer"})
cust = data(res).get("token")

BIZ = "Extras Grill %s" % SUFFIX
st, res = call("admin_create_service_giver", {"name": "Extras Owner", "phone": "+251935%s" % TAIL, "password": "testpass123", "business_name": BIZ,
                                              "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
st, r = get("admin_get_service_givers", {}, admin)
sg = next((int(s["id"]) for s in data(r).get("service_givers") or [] if s.get("business_name") == BIZ), 0)
check("a test shop exists", sg > 0, res)
st, res = call("admin_add_item", {"service_giver_id": sg, "name": "ZZ%s Burger" % SUFFIX, "category": "food", "price": 750}, admin)
burger = int(data(res).get("item_id") or 0)
st, res = call("admin_add_item", {"service_giver_id": sg, "name": "ZZ%s Pizza" % SUFFIX, "category": "food", "price": 500}, admin)
pizza = int(data(res).get("item_id") or 0)
check("two items exist", burger > 0 and pizza > 0, res)

print("set extras")
st, res = call("admin_item_extras_save", {"item_id": burger, "extras": [{"name": "Mayonnaise", "price": 50}, {"name": "Cheese", "price": 100}, {"name": "Chili", "price": 0}]}, admin)
check("admin saves extras", res.get("success") is True and data(res).get("extras") == 3, (st, res))
st, res = call("admin_item_extras_save", {"item_id": pizza, "extras": [{"name": "Olives", "price": 30}]}, admin)
st, res = call("admin_item_extras_save", {"item_id": burger, "extras": [{"name": "X", "price": -5}]}, admin)
check("a negative price is refused", res.get("success") is False, res)
st, res = call("admin_item_extras_save", {"item_id": burger, "extras": [{"name": "Y", "price": "abc"}]}, admin)
check("a price that is not a number is refused", res.get("success") is False, res)
st, res = call("admin_item_extras_save", {"item_id": burger, "extras": [{"name": "Mayonnaise", "price": 50}]}, cust)
check("a customer cannot set extras", st in (401, 403) or res.get("success") is False, (st, res))
st, res = call("admin_item_extras_save", {"item_id": 99999999, "extras": []}, admin)
check("an unknown item is refused", res.get("success") is False, res)

st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
by = dict((i["name"], i) for i in data(r).get("items") or [])
bx = by.get("ZZ%s Burger" % SUFFIX, {}).get("extras") or []
check("admin list shows the extras in order", [e["name"] for e in bx] == ["Mayonnaise", "Cheese", "Chili"], bx)
mayo, cheese, chili = [e["id"] for e in bx] if len(bx) == 3 else (0, 0, 0)
st, res = call("admin_item_extras_save", {"item_id": burger, "extras": [{"id": mayo, "name": "Mayonnaise", "price": 60}, {"id": cheese, "name": "Cheese", "price": 100}]}, admin)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
by = dict((i["name"], i) for i in data(r).get("items") or [])
bx = by["ZZ%s Burger" % SUFFIX]["extras"]
check("saving again updates a kept extra and removes a dropped one", [(e["name"], e["price"]) for e in bx] == [("Mayonnaise", 60.0), ("Cheese", 100.0)] and bx[0]["id"] == mayo, bx)
st, res = call("admin_item_extras_save", {"item_id": burger, "extras": [{"id": mayo, "name": "Mayonnaise", "price": 50}, {"id": cheese, "name": "Cheese", "price": 100}]}, admin)

print("extra photos")
G1 = "zzgal1%s.jpg" % SUFFIX
G1_PATH = os.path.join(HERE, "..", "img", "menu", G1)
os.makedirs(os.path.dirname(G1_PATH), exist_ok=True)
open(G1_PATH, "wb").write(b"\xff\xd8\xff\xd9")
st, res = call("admin_item_extras_save", {"item_id": burger, "gallery": ["/delivery/img/menu/" + G1, "https://example.com/a.jpg"]}, admin)
check("admin saves extra photos", res.get("success") is True, res)
st, res = call("admin_item_extras_save", {"item_id": burger, "gallery": ["javascript:alert(1)"]}, admin)
check("a bad photo address is refused", res.get("success") is False, res)
st, r = get("customer_get_items", {"service_giver_id": sg}, cust)
cit = dict((i["name"], i) for i in data(r).get("items") or [])
cb = cit.get("ZZ%s Burger" % SUFFIX, {})
check("customers get the extras and the extra photos", [e["name"] for e in cb.get("extras", [])] == ["Mayonnaise", "Cheese"] and cb.get("gallery") == ["/delivery/img/menu/" + G1, "https://example.com/a.jpg"], cb)
check("an item without extras has an empty list", cit.get("ZZ%s Pizza" % SUFFIX, {}).get("extras") == [{"id": cit["ZZ%s Pizza" % SUFFIX]["extras"][0]["id"], "name": "Olives", "name_am": None, "price": 30.0}], cit.get("ZZ%s Pizza" % SUFFIX))

print("orders")
def order(items):
    return call("customer_create_order", {"items": items, "to_zone": "Tabor", "recipient_name": "R", "recipient_phone": cphone, "payment_method": "cash"}, token=cust)
st, res = order([{"item_id": burger, "quantity": 2, "extras": [mayo, cheese]}])
check("an order with extras is placed", res.get("success") is True, (st, res))
oid = data(res).get("order_id")
etel_ok, out, err = etel_test_db.query_text("SELECT item_name, quantity, unit_price, subtotal FROM order_items WHERE order_id = %d" % int(oid or 0))
row = out.strip().split("\t") if out.strip() else []
check("the line is the item plus its extras, priced on the server", row[:2] == ["ZZ%s Burger + Mayonnaise, Cheese" % SUFFIX, "2"] and float(row[2]) == 900.0 and float(row[3]) == 1800.0, out)
etel_ok, out2, _ = etel_test_db.query_text("SELECT subtotal FROM orders WHERE id = %d" % int(oid or 0))
check("the order's items subtotal includes the extras", out2.strip() and float(out2.strip()) == 1800.0, out2)
st, res = order([{"item_id": burger, "quantity": 1}])
check("the same item without extras is plain", res.get("success") is True, res)
st, res = order([{"item_id": burger, "quantity": 1, "extras": [mayo, mayo]}])
check("ticking the same extra twice counts it once", res.get("success") is True, res)
st, res = order([{"item_id": burger, "quantity": 1, "extras": [999999]}])
check("an extra that does not exist refuses the order", res.get("success") is False, res)
st, r = get("customer_get_items", {"service_giver_id": sg}, cust)
olives = [e["id"] for e in dict((i["name"], i) for i in data(r).get("items") or [])["ZZ%s Pizza" % SUFFIX]["extras"]][0]
st, res = order([{"item_id": burger, "quantity": 1, "extras": [olives]}])
check("another item's extra refuses the order", res.get("success") is False, res)
st, res = order([{"item_id": burger, "quantity": 1, "extras": "all"}])
check("a malformed extras value is ignored, the item is plain", res.get("success") is True, res)

print("CSV columns")
IMG1 = "zzcsv%s.jpg" % SUFFIX
IMG1_PATH = os.path.join(HERE, "..", "img", "menu", IMG1)
open(IMG1_PATH, "wb").write(b"\xff\xd8\xff\xd9")
rows = [{"name": "ZZ%s Wrap" % SUFFIX, "price": "300", "extras": "Mayonnaise:50|Cheese:100|Chili", "more_photos": IMG1 + "|nope-%s.jpg" % SUFFIX},
        {"name": "ZZ%s Bad" % SUFFIX, "price": "10", "more_photos": "../x.jpg"}]
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": rows}, admin)
d = data(res)
check("import adds the item, lists the bad photo, counts the missing one", d.get("custom") == 1 and len(d.get("invalid", [])) == 1 and d.get("photos_missing") == 1, d)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
wrap = next(i for i in data(r).get("items") if i["name"] == "ZZ%s Wrap" % SUFFIX)
check("the CSV extras were saved with prices (a missing price is 0)", [(e["name"], e["price"]) for e in wrap["extras"]] == [("Mayonnaise", 50.0), ("Cheese", 100.0), ("Chili", 0.0)], wrap["extras"])
check("the CSV extra photo that exists was linked", json.loads(wrap["gallery"]) == ["/delivery/img/menu/" + IMG1], wrap["gallery"])
st, res = call("admin_items_import", {"service_giver_id": sg, "mode": "update", "rows": [{"name": "ZZ%s Wrap" % SUFFIX, "price": "320", "extras": "Egg:40"}]}, admin)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
wrap = next(i for i in data(r).get("items") if i["name"] == "ZZ%s Wrap" % SUFFIX)
check("update mode replaces the extras and leaves the photos when the cell is blank", [e["name"] for e in wrap["extras"]] == ["Egg"] and json.loads(wrap["gallery"]) == ["/delivery/img/menu/" + IMG1], wrap)

print("item studio fields (admin add / edit)")
st, res = call("admin_add_item", {"service_giver_id": sg, "name": "ZZ%s Studio" % SUFFIX, "name_am": "ስቱዲዮ", "category": "food", "price": 300, "original_price": 400,
                                  "quality": "premium", "portion": "Large", "menu_section": "Specials", "is_popular": 1}, admin)
sid = data(res).get("item_id")
check("admin adds an item with Amharic name, quality and portion", res.get("success") is True and sid, (st, res))
st, r = call("admin_get_all_items", {"service_giver_id": sg}, admin)
it = next((i for i in data(r).get("items", []) if str(i["id"]) == str(sid)), {})
check("they are saved on the item", it.get("name_am") == "ስቱዲዮ" and it.get("quality") == "premium" and it.get("portion") == "Large" and it.get("menu_section") == "Specials", it)
st, res = call("admin_update_item", {"item_id": sid, "name_am": "", "quality": "", "portion": "", "is_available": 0}, admin)
st, r = call("admin_get_all_items", {"service_giver_id": sg}, admin)
it = next((i for i in data(r).get("items", []) if str(i["id"]) == str(sid)), {})
check("and can be changed or cleared, with the item hidden", not it.get("quality") and not it.get("portion") and str(it.get("is_available")) == "0", it)

for pth in (G1_PATH, IMG1_PATH):
    try:
        os.remove(pth)
    except OSError:
        pass
if fails:
    print("\nFAILED: %d" % len(fails))
    sys.exit(1)
print("\nOK")
