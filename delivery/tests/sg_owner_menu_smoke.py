"""
The shop owner's own app: sections, "most popular" and extras on items the owner adds or edits (sg_add_item / sg_update_item / sg_get_my_items),
and the owner handing in business papers (sg_docs_list / sg_doc_submit) with admin approving them. Owners reach only their own business; a
customer reaches neither.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import time
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


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)
st, res = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = data(res).get("token")
if not admin:
    print("SKIP: no seeded demo admin (api/seeds/admin_demo_accounts.sql)")
    sys.exit(0)

phone = "+251996%s" % TAIL
st, res = call("admin_create_service_giver", {"name": "Menu Owner", "phone": phone, "password": "testpass123", "business_name": "Menu Kitchen %s" % SUFFIX,
                                              "service_type": "food", "vehicle_type": "on_foot", "zone": "Piazza"}, admin)
sg_id = data(res).get("service_giver_id")
st, res = call("sg_login", {"phone": phone, "password": "testpass123"})
owner = data(res).get("token")
check("a live test shop with an owner login", bool(sg_id and owner), (st, res))
st, res = call("register", {"name": "Menu Cust", "phone": "+251997%s" % TAIL, "password": "test1234", "role": "customer"})
cust = data(res).get("token")

print("sections, popular and extras")
st, res = call("sg_add_item", {"name": "Special Burger", "category": "food", "price": 750, "stock_quantity": 50, "menu_section": "  Burger  ", "is_popular": 1,
                               "extras": "Mayonnaise:50\nCheese:100\nChili"}, owner)
item = data(res).get("item_id")
check("the owner can add an item with a section, popular and extras", res.get("success") is True and item, (st, res))
st, res = call("sg_get_my_items", {}, owner)
it = [x for x in data(res).get("items", []) if str(x["id"]) == str(item)]
it = it[0] if it else {}
check("the list returns the tidy section and popular flag", it.get("menu_section") == "Burger" and str(it.get("is_popular")) == "1", it)
ex = dict((e["name"], e["price"]) for e in it.get("extras", []))
check("the extras are saved with prices (a missing price is 0)", ex == {"Mayonnaise": 50.0, "Cheese": 100.0, "Chili": 0.0}, ex)
check("the list names the sections the shop uses", data(res).get("sections") == ["Burger"], data(res).get("sections"))

st, res = call("sg_update_item", {"item_id": item, "extras": "Cheese:120", "menu_section": "Specials", "is_popular": 0}, owner)
check("the owner can change them", res.get("success") is True, (st, res))
st, res = call("sg_get_my_items", {}, owner)
it = [x for x in data(res).get("items", []) if str(x["id"]) == str(item)][0]
check("the section and popular flag changed", it.get("menu_section") == "Specials" and str(it.get("is_popular")) == "0", it)
check("extras were replaced, not added to", [(e["name"], e["price"]) for e in it.get("extras", [])] == [("Cheese", 120.0)], it.get("extras"))
st, res = call("sg_update_item", {"item_id": item, "price": 800}, owner)
st, res = call("sg_get_my_items", {}, owner)
it = [x for x in data(res).get("items", []) if str(x["id"]) == str(item)][0]
check("changing only the price leaves the extras alone", len(it.get("extras", [])) == 1 and float(it["price"]) == 800.0, it)
st, res = call("sg_update_item", {"item_id": item, "extras": ""}, owner)
st, res = call("sg_get_my_items", {}, owner)
it = [x for x in data(res).get("items", []) if str(x["id"]) == str(item)][0]
check("an empty extras box clears them", it.get("extras") == [], it.get("extras"))

print("papers")
DOC = "http://127.0.0.1/delivery/uploads/driver_documents/sgdoc_9_%s_bb.jpg" % TAIL
st, res = call("sg_docs_list", {}, owner)
check("the owner sees the paper types, none sent yet", res.get("success") is True and data(res).get("documents") == [] and "business_licence" in data(res).get("types", {}), (st, res))
st, res = call("sg_doc_submit", {"doc_type": "business_licence", "file_url": DOC}, owner)
check("the owner can hand in a paper", res.get("success") is True, (st, res))
st, res = call("sg_doc_submit", {"doc_type": "owner_id", "file_url": "http://x.test/uploads/items/a.jpg"}, owner)
check("only the private folder is accepted", res.get("success") is False, res)
st, res = call("sg_doc_submit", {"doc_type": "owner_id", "file_url": DOC, "sg_id": 1}, owner)
mine = data(call("sg_docs_list", {"sg_id": sg_id}, admin)[1]).get("documents", [])
other = data(call("sg_docs_list", {"sg_id": 1}, admin)[1]).get("documents", [])
check("an owner's sg_id is ignored: the paper lands on their own business only", any(d["doc_type"] == "owner_id" for d in mine) and not any(d["file_url"].find(TAIL) >= 0 for d in other), (mine, other))
st, res = call("sg_docs_list", {}, owner)
docs = data(res).get("documents", [])
check("the paper shows as waiting", [(d["doc_type"], d["status"]) for d in docs if d["doc_type"] == "business_licence"] == [("business_licence", "pending")], docs)
lic = [d for d in docs if d["doc_type"] == "business_licence"][0]
st, res = call("admin_sg_doc_review", {"id": lic["id"], "decision": "reject", "reason": "Too dark"}, admin)
st, res = call("sg_docs_list", {}, owner)
d0 = [d for d in data(res).get("documents", []) if d["doc_type"] == "business_licence"][0]
check("the owner sees why it was sent back", d0["status"] == "rejected" and d0["reject_reason"] == "Too dark", d0)
st, res = call("admin_sg_doc_review", {"id": lic["id"], "decision": "approve"}, owner)
check("an owner cannot approve their own paper", st in (401, 403) or res.get("success") is False, (st, res))
st, res = call("sg_docs_list", {}, cust)
check("a customer cannot see papers", st in (401, 403) or res.get("success") is False, (st, res))
st, res = call("sg_doc_submit", {"doc_type": "owner_id", "file_url": DOC}, cust)
check("a customer cannot hand in papers", st in (401, 403) or res.get("success") is False, (st, res))

if fails:
    print("\nFAILED: %d" % len(fails))
    sys.exit(1)
print("\nOK")
