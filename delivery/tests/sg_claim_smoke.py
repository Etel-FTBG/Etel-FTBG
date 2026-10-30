"""
Pre-registered businesses and the claim link: admin makes a waiting (pending) business with a spare phone, it cannot sign in or show to customers,
the owner claims it with a one-time code (own phone + password), the code works once, wrong codes are refused, admin can edit the owner and make a
new link, and only an admin with the right to manage service givers may do any of it.

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
st, res = call("register", {"name": "Claim Cust", "phone": "+251995%s" % TAIL, "password": "test1234", "role": "customer"})
cust = data(res).get("token")

print("pre-register")
BIZ = "Claim Express %s" % SUFFIX
st, res = call("admin_create_service_giver", {"draft": 1, "business_name": BIZ, "service_type": "delivery", "zone": "Piazza"}, admin)
d = data(res)
check("a draft needs no password and no phone", res.get("success") is True and d.get("draft") is True and d.get("claim_code"), (st, res))
sg_id, code = d.get("service_giver_id"), d.get("claim_code", "")
st, res = call("admin_service_giver_detail", {"id": sg_id}, admin)
g = data(res).get("service_giver", {})
claim = data(res).get("claim", {})
check("it is waiting, not live", g.get("status") == "pending", g)
check("it has a spare phone and the owner name defaults to the business", g.get("phone", "").startswith("09") and g.get("name") == BIZ, g)
check("the detail says a claim link is waiting and the phone is a stand-in", claim.get("invited") is True and claim.get("placeholder_phone") is True, claim)
st, res = call("sg_login", {"phone": g.get("phone"), "password": "anything-at-all"})
check("nobody can sign in to a draft", res.get("success") is False, res)

print("claim")
st, res = call("sg_claim_info", {"code": "ZZZZZ-ZZZZZ"})
check("a wrong code is refused", res.get("success") is False and st == 404, (st, res))
st, res = call("sg_claim_info", {"code": code.lower()})
check("the code works in any letter case", res.get("success") is True and data(res).get("business_name") == BIZ, (st, res))
_ok, plain, _err = etel_test_db.query_text("SELECT COUNT(*) FROM sg_claim_codes WHERE code_hash = '%s'" % code)
_ok2, hashed, _err2 = etel_test_db.query_text("SELECT COUNT(*) FROM sg_claim_codes WHERE sg_id = %d AND CHAR_LENGTH(code_hash) = 64" % sg_id)
check("the code is stored only as a hash", _ok and plain.strip() == "0" and hashed.strip() == "1", (plain, hashed, _err))
owner_phone = "+251994%s" % TAIL
st, res = call("sg_claim_complete", {"code": code, "phone": owner_phone, "password": "short"})
check("a short password is refused and the code survives", res.get("success") is False and call("sg_claim_info", {"code": code})[1].get("success") is True, res)
st, res = call("sg_claim_complete", {"code": code, "phone": "+251995%s" % TAIL, "password": "ownerpass123"})
check("a phone that already has an account is refused", res.get("success") is False and st == 409, (st, res))
st, res = call("sg_claim_complete", {"code": code, "name": "Owner One", "phone": owner_phone, "password": "ownerpass123"})
check("the owner claims it", res.get("success") is True and data(res).get("status") == "pending", (st, res))
st, res = call("sg_claim_complete", {"code": code, "phone": "+251993%s" % TAIL, "password": "ownerpass123"})
check("the code works only once", res.get("success") is False, res)
st, res = call("sg_login", {"phone": owner_phone, "password": "ownerpass123"})
check("still cannot sign in until admin verifies", res.get("success") is False and "pending" in (res.get("message") or "").lower(), res)
st, res = call("admin_verify_service_giver", {"id": sg_id}, admin)
check("admin verifies", res.get("success") is True, res)
st, res = call("sg_login", {"phone": owner_phone, "password": "ownerpass123"})
check("the owner signs in with their own phone and password", res.get("success") is True, (st, res))
st, res = call("admin_service_giver_detail", {"id": sg_id}, admin)
g = data(res).get("service_giver", {})
check("owner details are the owner's now", g.get("name") == "Owner One" and g.get("phone") == owner_phone and data(res).get("claim", {}).get("claimed") is True, (g, data(res).get("claim")))

print("admin edits")
st, res = call("admin_sg_claim_invite", {"id": sg_id}, admin)
code2 = data(res).get("claim_code", "")
check("admin can make a new claim link", res.get("success") is True and code2 and code2 != code, (st, res))
st, res = call("admin_sg_claim_invite", {"id": sg_id}, admin)
check("a newer link replaces the older one", call("sg_claim_info", {"code": code2})[1].get("success") is False, res)
st, res = call("admin_sg_update_owner", {"id": sg_id, "name": "Owner Two", "phone": "+251992%s" % TAIL}, admin)
check("admin can change the owner's name and phone", res.get("success") is True, (st, res))
st, res = call("admin_sg_update_owner", {"id": sg_id, "phone": "+251995%s" % TAIL}, admin)
check("not onto another account's phone", res.get("success") is False and st == 409, (st, res))
st, res = call("admin_sg_update_owner", {"id": sg_id, "phone": "12"}, admin)
check("not a too-short phone", res.get("success") is False, res)

print("who may")
for action, body in (("admin_sg_claim_invite", {"id": sg_id}), ("admin_sg_update_owner", {"id": sg_id, "name": "X"}), ("admin_create_service_giver", {"draft": 1, "business_name": "Nope %s" % SUFFIX})):
    st, res = call(action, body, cust)
    check("a customer cannot " + action, st in (401, 403) or res.get("success") is False, (st, res))
    st, res = call(action, body)
    check("signed out cannot " + action, st in (401, 403) or res.get("success") is False, (st, res))

print("go-live checklist")
st, res = call("admin_service_giver_detail", {"id": sg_id}, admin)
rd = data(res).get("readiness") or {}
keys = dict((i["key"], i["ok"]) for i in rd.get("items", []))
check("the detail carries a 8-point readiness list", rd.get("total") == 8 and len(keys) == 8, rd)
check("hours and menu start missing", keys.get("hours") is False and keys.get("menu") is False, keys)
st, res = call("admin_sg_set_hours", {"id": sg_id, "opens_at": "08:00", "closes_at": "21:30"}, admin)
check("admin can set opening hours", res.get("success") is True, (st, res))
st, res = call("admin_service_giver_detail", {"id": sg_id}, admin)
keys = dict((i["key"], i["ok"]) for i in (data(res).get("readiness") or {}).get("items", []))
check("hours tick off once set", keys.get("hours") is True, keys)
st, res = call("admin_sg_set_hours", {"id": sg_id, "opens_at": "8am", "closes_at": "21:30"}, admin)
check("not a badly written time", res.get("success") is False, res)
st, res = call("admin_sg_set_hours", {"id": sg_id, "opens_at": "09:00", "closes_at": "09:00"}, admin)
check("not the same open and close time", res.get("success") is False, res)
st, res = call("admin_sg_set_hours", {"id": sg_id, "opens_at": "", "closes_at": ""}, admin)
check("empty hours clear them", res.get("success") is True, res)
st, res = call("admin_sg_set_hours", {"id": sg_id, "opens_at": "08:00", "closes_at": "21:00"}, cust)
check("a customer cannot set hours", st in (401, 403) or res.get("success") is False, (st, res))

print("papers")
DOC = "http://127.0.0.1/delivery/uploads/driver_documents/sgdoc_1_%s_aa.jpg" % TAIL
st, res = call("sg_doc_submit", {"sg_id": sg_id, "doc_type": "business_licence", "file_url": DOC}, admin)
check("admin can hand in a paper for a business", res.get("success") is True, (st, res))
st, res = call("sg_doc_submit", {"sg_id": sg_id, "doc_type": "business_licence", "file_url": "http://x.test/uploads/items/a.jpg"}, admin)
check("only the private documents folder is accepted", res.get("success") is False, res)
st, res = call("sg_doc_submit", {"sg_id": sg_id, "doc_type": "passport", "file_url": DOC}, admin)
check("not an unknown paper type", res.get("success") is False, res)
st, res = call("sg_doc_submit", {"sg_id": sg_id, "doc_type": "owner_id", "file_url": DOC}, cust)
check("a customer cannot hand in papers", st in (401, 403) or res.get("success") is False, (st, res))
st, res = call("sg_docs_list", {"sg_id": sg_id}, admin)
docs = data(res).get("documents") or []
lic = [d for d in docs if d["doc_type"] == "business_licence"]
check("the paper is listed as pending, with a signed link", len(lic) == 1 and lic[0]["status"] == "pending" and "private_file.php" in lic[0]["file_url"], docs)
st, res = call("admin_sg_doc_review", {"id": lic[0]["id"], "decision": "reject"}, admin)
check("rejecting needs a reason", res.get("success") is False, res)
st, res = call("admin_sg_doc_review", {"id": lic[0]["id"], "decision": "reject", "reason": "Photo is blurry"}, admin)
check("admin can send a paper back", res.get("success") is True, (st, res))
st, res = call("sg_doc_submit", {"sg_id": sg_id, "doc_type": "business_licence", "file_url": DOC}, admin)
check("a rejected paper can be sent again", res.get("success") is True and data(res)["documents"][0]["status"] == "pending", res)
st, res = call("admin_sg_doc_review", {"id": lic[0]["id"], "decision": "approve"}, admin)
check("admin can approve", res.get("success") is True, (st, res))
st, res = call("admin_service_giver_detail", {"id": sg_id}, admin)
keys = dict((i["key"], i["ok"]) for i in (data(res).get("readiness") or {}).get("items", []))
check("papers stay missing until both are approved", keys.get("documents") is False, keys)
call("sg_doc_submit", {"sg_id": sg_id, "doc_type": "owner_id", "file_url": DOC}, admin)
oid = [d for d in data(call("sg_docs_list", {"sg_id": sg_id}, admin)[1]).get("documents") if d["doc_type"] == "owner_id"][0]["id"]
call("admin_sg_doc_review", {"id": oid, "decision": "approve"}, admin)
st, res = call("admin_service_giver_detail", {"id": sg_id}, admin)
keys = dict((i["key"], i["ok"]) for i in (data(res).get("readiness") or {}).get("items", []))
check("papers tick off once licence and ID are approved", keys.get("documents") is True, keys)
st, res = call("admin_sg_doc_review", {"id": oid, "decision": "approve"}, cust)
check("a customer cannot review papers", st in (401, 403) or res.get("success") is False, (st, res))

if fails:
    print("\nFAILED: %d" % len(fails))
    sys.exit(1)
print("\nOK")
