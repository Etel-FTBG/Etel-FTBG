"""
An admin who also runs a business of their own (the "Admin test shop" on the ETEL Pro page) opens Settings and the dashboard without naming a business:
the papers list (sg_docs_list) and the go-live checklist (sg_golive) answer for their own shop instead of "400 sg_id required". An admin with no business of
their own still has to name one, and naming someone else's still needs service_givers.manage. Uses the seeded demo admin and a draft business.
Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without them.
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
    print("SKIP: no seeded demo admin")
    sys.exit(0)

print("admin without a business of their own")
st, res = call("sg_docs_list", {}, admin)
check("must name a business (400), as before", st == 400 and "sg_id" in json.dumps(res), (st, res))

st, res = call("admin_create_service_giver", {"draft": 1, "business_name": "Own Shop %s" % SUFFIX, "service_type": "delivery", "zone": "Piazza"}, admin)
sg_id = data(res).get("service_giver_id")
if not sg_id:
    check("a draft business can be made", False, (st, res))
    sys.exit(1)
st, res = call("sg_docs_list", {"sg_id": sg_id}, admin)
check("naming one still works for an admin who may manage them", st == 200 and data(res).get("sg_id") == sg_id, (st, res))

ok, out, err = etel_test_db.query_text("SELECT user_id FROM service_givers WHERE id = %d" % sg_id)
orig_owner = int(out.split()[0]) if ok and out.split() else None
ok, out, err = etel_test_db.query_text("SELECT id FROM users WHERE phone IN ('0900000000', '+251900000000') AND role = 'admin' LIMIT 1")
admin_id = int(out.split()[0]) if ok and out.split() else None
if not (orig_owner and admin_id):
    check("the test can find the admin and the draft's owner", False, (orig_owner, admin_id, err))
    sys.exit(1)
etel_test_db.run_sql("UPDATE service_givers SET user_id = %d WHERE id = %d" % (admin_id, sg_id))

print("admin who runs their own business")
st, res = call("sg_docs_list", {}, admin)
check("the papers list answers for their own shop (no 400)", st == 200 and data(res).get("sg_id") == sg_id and "documents" in data(res), (st, res))
st, res = call("sg_golive", {}, admin)
check("the go-live checklist answers for their own shop (no 400)", st == 200 and data(res).get("sg_id") == sg_id and "readiness" in data(res), (st, res))
st, res = call("sg_docs_list", {"sg_id": sg_id}, admin)
check("naming their own shop works too", st == 200 and data(res).get("sg_id") == sg_id, (st, res))

etel_test_db.run_sql("UPDATE service_givers SET user_id = %d WHERE id = %d" % (orig_owner, sg_id))
print("FAILED: %s" % fails if fails else "ALL PASS")
sys.exit(1 if fails else 0)
