"""
Admin CSV imports (Menu catalog and a shop's items): a dry run saves nothing but returns the real counts, the real run adds the dishes,
running it again skips them (or updates them when asked), bad rows are listed with their line number, photo file names turn into
/delivery/img/menu/ addresses, and only an admin may do it.

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


def data(res):
    return res.get("data") or {}


def get(action, params, token):
    qs = "&".join("%s=%s" % (k, urllib.parse.quote(str(v))) for k, v in dict(params, action=action).items())
    req = urllib.request.Request(API_URL + "?" + qs, headers={"Authorization": "Bearer " + token})
    try:
        with urllib.request.urlopen(req, timeout=30) as f:
            return f.status, json.loads(f.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)
st, res = call("register", {"name": "Imp Cust", "phone": "+251997%s" % TAIL, "password": "test1234", "role": "customer"})
cust = data(res).get("token")


def catalog_names():
    st, r = get("admin_catalog_list", {"status": "all", "q": "ZZ%s" % SUFFIX}, admin)
    return sorted(x["name"] for x in (data(r).get("catalog") or []))


print("catalog import")
PHOTO = "zz%s.jpg" % SUFFIX
PHOTO_PATH = os.path.join(HERE, "..", "img", "menu", PHOTO)
os.makedirs(os.path.dirname(PHOTO_PATH), exist_ok=True)
open(PHOTO_PATH, "wb").write(b"\xff\xd8\xff\xd9")
D1, D2, D3 = "ZZ%s Kitfo" % SUFFIX, "ZZ%s Tibs" % SUFFIX, "ZZ%s Juice" % SUFFIX
rows = [
    {"name": D1, "name_am": "ክትፎ", "description": "Minced beef", "category": "food", "cuisine": "Ethiopian", "packs": "Ethiopian Restaurant, cafe", "prep_time": "20", "image_file": PHOTO, "_line": 2},
    {"name": D2, "category": "food", "prep_time": "999", "status": "hidden", "_line": 3},
    {"name": D3, "category": "spaceship", "_line": 4},
    {"name": "", "_line": 5},
    {"name": "ZZ%s Bad photo" % SUFFIX, "image_file": "../etc/passwd", "_line": 6},
    {"name": D1, "category": "food", "_line": 7},
    {"name": "ZZ%s Late photo" % SUFFIX, "image_file": "not-uploaded-%s.jpg" % SUFFIX, "_line": 8},
]
st, res = call("admin_catalog_import", {"rows": rows, "dry_run": 1}, admin)
d = data(res)
check("dry run answers", res.get("success") is True and d.get("dry_run") is True, (st, res))
check("dry run counts the good rows", d.get("added") == 3 and d.get("skipped") == 1 and d.get("photos_missing") == 1, d)
check("bad rows are listed with their line number", sorted(i["row"] for i in d.get("invalid", [])) == [4, 5, 6], d)
check("dry run saved nothing", catalog_names() == [], catalog_names())

st, res = call("admin_catalog_import", {"rows": rows}, admin)
d = data(res)
check("real run adds the good dishes", d.get("added") == 3 and d.get("dry_run") is False, d)
check("they are in the catalog", catalog_names() == sorted([D1, D2, "ZZ%s Late photo" % SUFFIX]), catalog_names())
st, r = get("admin_catalog_list", {"status": "all", "q": D1}, admin)
dish = (data(r).get("catalog") or [{}])[0]
check("photo file name became a site address", dish.get("image_url") == "/delivery/img/menu/" + PHOTO, dish.get("image_url"))
check("packs were tidied", dish.get("packs") == "ethiopian_restaurant,cafe", dish.get("packs"))
st, r = get("admin_catalog_list", {"status": "hidden", "q": D2}, admin)
dish2 = (data(r).get("catalog") or [{}])[0]
check("prep time is capped and status kept", dish2.get("prep_time") == 240 and dish2.get("status") == "hidden", dish2)

st, r = get("admin_catalog_list", {"status": "all", "q": "Late photo"}, admin)
late = [x for x in data(r).get("catalog") or [] if SUFFIX in x["name"]]
check("a photo whose file is not on the server is not linked", late and late[0].get("image_url") is None, late)
st, res = call("admin_catalog_import", {"rows": rows[:2]}, admin)
d = data(res)
check("running it again skips the dishes", d.get("added") == 0 and d.get("skipped") == 2, d)
st, res = call("admin_catalog_import", {"rows": [{"name": D1, "prep_time": "35"}], "mode": "update"}, admin)
d = data(res)
st, r = get("admin_catalog_list", {"status": "all", "q": D1}, admin)
dish = (data(r).get("catalog") or [{}])[0]
check("update mode changes only what the file filled in", d.get("updated") == 1 and dish.get("prep_time") == 35 and dish.get("name_am") == "ክትፎ", (d, dish))

st, res = call("admin_catalog_import", {"rows": []}, admin)
check("an empty file is refused", res.get("success") is False, res)
st, res = call("admin_catalog_import", {"rows": rows}, cust)
check("a customer cannot import", st in (401, 403) or res.get("success") is False, (st, res))

print("shop items import")
shop_phone = "+251996%s" % TAIL
st, res = call("admin_create_service_giver", {"name": "Imp Shop", "phone": shop_phone, "password": "testpass123", "business_name": "Import Kitchen %s" % SUFFIX,
                                              "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, admin)
st, r = get("admin_get_service_givers", {}, admin)
shop = None
for s in data(r).get("service_givers") or []:
    if s.get("business_name") == "Import Kitchen %s" % SUFFIX:
        shop = s
sg = int(shop["id"]) if shop else 0
check("a test shop exists", sg > 0, (res, shop))
irows = [{"name": "ZZ%s Soup" % SUFFIX, "price": "80", "category": "food", "_line": 2}, {"name": "ZZ%s Tea" % SUFFIX, "price": "abc", "_line": 3},
         {"name": D1, "price": "250", "category": "food", "_line": 4}]
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": irows, "dry_run": 1}, admin)
d = data(res)
check("items dry run answers with the shop name", res.get("success") is True and d.get("dry_run") is True and "Import Kitchen" in d.get("shop", ""), (st, res))
check("items dry run counts", d.get("custom") == 1 and d.get("catalog") == 1 and len(d.get("invalid", [])) == 1, d)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
check("items dry run saved nothing", len(data(r).get("items") or []) == 0, data(r))
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": irows}, admin)
d = data(res)
check("items real run adds them", d.get("custom") == 1 and d.get("catalog") == 1, d)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
check("the shop now sells two items", len(data(r).get("items") or []) == 2, data(r))
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": irows}, admin)
check("items run again skips them", data(res).get("skipped") == 2 and data(res).get("custom") == 0, data(res))
st, res = call("admin_items_import", {"service_giver_id": 99999999, "rows": irows}, admin)
check("an unknown shop is refused", res.get("success") is False, res)
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": irows}, cust)
check("a customer cannot import items", st in (401, 403) or res.get("success") is False, (st, res))

print("menu sections, popular and photos on a shop's items")
SHOT = "zzitem%s.jpg" % SUFFIX
SHOT_PATH = os.path.join(HERE, "..", "img", "menu", SHOT)
open(SHOT_PATH, "wb").write(b"\xff\xd8\xff\xd9")
S1, S2, S3 = "ZZ%s Special Burger" % SUFFIX, "ZZ%s Mojito" % SUFFIX, "ZZ%s Beef Chao" % SUFFIX
srows = [{"name": S1, "price": "750", "section": "  Burger ", "popular": "yes", "image_file": SHOT},
         {"name": S2, "price": "180", "section": "Drinks", "image_file": "late-%s.jpg" % SUFFIX},
         {"name": S3, "price": "450", "popular": "no"},
         {"name": "ZZ%s Bad pic" % SUFFIX, "price": "10", "image_file": "../x.jpg"}]
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": srows, "dry_run": 1}, admin)
d = data(res)
check("sections dry run counts items, sections, missing photos", d.get("custom") == 3 and d.get("sections") == 2 and d.get("photos_missing") == 1 and len(d.get("invalid", [])) == 1, d)
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": srows}, admin)
check("sections real run adds them", data(res).get("custom") == 3, res)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
by = dict((i["name"], i) for i in data(r).get("items") or [])
check("section is tidied and saved", by.get(S1, {}).get("menu_section") == "Burger" and by.get(S2, {}).get("menu_section") == "Drinks" and by.get(S3, {}).get("menu_section") in (None, ""), by.get(S1))
check("popular is saved only when asked", int(by.get(S1, {}).get("is_popular") or 0) == 1 and int(by.get(S3, {}).get("is_popular") or 0) == 0, (by.get(S1), by.get(S3)))
check("a photo that exists is linked, one that does not is not", by.get(S1, {}).get("image_url") == "/delivery/img/menu/" + SHOT and not by.get(S2, {}).get("image_url"), (by.get(S1, {}).get("image_url"), by.get(S2, {}).get("image_url")))
st, r = get("customer_get_items", {"service_giver_id": sg}, cust)
cit = dict((i["name"], i) for i in data(r).get("items") or [])
check("customers get the section and popular flag", cit.get(S1, {}).get("menu_section") == "Burger" and int(cit.get(S1, {}).get("is_popular") or 0) == 1, cit.get(S1))
st, res = call("admin_items_import", {"service_giver_id": sg, "rows": [{"name": S3, "price": "500", "section": "Breakfast", "popular": "yes"}, {"name": S2, "price": "190"}]}, admin)
check("without update mode existing items are left alone", data(res).get("skipped") == 2 and data(res).get("updated") == 0, data(res))
st, res = call("admin_items_import", {"service_giver_id": sg, "mode": "update", "rows": [{"name": S3, "price": "500", "section": "Breakfast", "popular": "yes"}, {"name": S2, "price": "190"}]}, admin)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
by = dict((i["name"], i) for i in data(r).get("items") or [])
check("update mode changes only what the file filled in", data(res).get("updated") == 2 and by[S3]["menu_section"] == "Breakfast" and int(by[S3]["is_popular"]) == 1 and float(by[S3]["price"]) == 500 and by[S2]["menu_section"] == "Drinks" and float(by[S2]["price"]) == 190, (data(res), by[S3], by[S2]))
item1 = by[S1]["id"]
st, res = call("admin_update_item", {"item_id": item1, "menu_section": "", "is_popular": 0}, admin)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
by = dict((i["name"], i) for i in data(r).get("items") or [])
check("the edit form can clear a section and the popular flag", res.get("success") is True and by[S1]["menu_section"] in (None, "") and int(by[S1]["is_popular"]) == 0, (res, by[S1]))
st, res = call("admin_add_item", {"service_giver_id": sg, "name": "ZZ%s Added" % SUFFIX, "category": "food", "price": 60, "menu_section": "Sides", "is_popular": 1}, admin)
st, r = get("admin_get_all_items", {"service_giver_id": sg}, admin)
added = [i for i in data(r).get("items") or [] if i["name"] == "ZZ%s Added" % SUFFIX]
check("the add form saves a section and popular", res.get("success") is True and added and added[0]["menu_section"] == "Sides" and int(added[0]["is_popular"]) == 1, (res, added))
st, res = call("admin_update_item", {"item_id": item1, "menu_section": "Hack"}, cust)
check("a customer cannot set a section", st in (401, 403) or res.get("success") is False, (st, res))

for pth in (PHOTO_PATH, SHOT_PATH):
    try:
        os.remove(pth)
    except OSError:
        pass
if fails:
    print("\nFAILED: %d" % len(fails))
    sys.exit(1)
print("\nOK")
