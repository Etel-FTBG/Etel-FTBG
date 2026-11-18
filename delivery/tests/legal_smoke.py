"""
Privacy / Terms text and "Delete my account" requests, through the real server: anyone can read a legal text, only the owner admin writes it,
a request sent from the app shows up in the owner's list, and rejecting it leaves the account working.
Uses the seeded demo accounts (no sign-ups, so the shared register limit is not touched). The demo account is never deleted here (only rejected).

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import etel_test_db  # noqa: E402

BASE = os.environ.get("ETEL_API_BASE", "http://127.0.0.1:8765/api/")
fails = []


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        fails.append(name)


def call(action, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + "boot.php", data=json.dumps(dict(body or {}, action=action)).encode(), headers=headers, method="POST")
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

print("legal texts and deletion requests")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(cust)))
    sys.exit(1)
etel_test_db.run_sql("DELETE FROM legal_docs")

LONG = "We keep only what the apps need to work. " * 8
st, r = call("get_legal_doc", {"doc": "privacy"})
check("anyone can read: nothing custom yet", st == 200 and (r.get("data") or {}).get("custom") is False, (st, r))
st, r = call("admin_legal_save", {"doc": "privacy", "title": "P", "body": LONG}, cust)
check("a customer cannot write the text", st == 403, (st, r))
st, r = call("admin_legal_save", {"doc": "privacy", "title": "P", "body": LONG})
check("nobody signed in cannot write the text", st == 401, (st, r))
st, r = call("admin_legal_save", {"doc": "privacy", "title": "Our privacy", "body": "# Data\n<script>x</script>" + LONG}, admin)
check("the owner saves the privacy text", st == 200 and r.get("success") is True, (st, r))
st, r = call("get_legal_doc", {"doc": "privacy"})
d = r.get("data") or {}
check("anyone now reads the custom text, without tags", d.get("custom") is True and d.get("title") == "Our privacy" and "<" not in d.get("body", ""), d)
st, r = call("admin_legal_save", {"doc": "privacy", "title": "", "body": ""}, admin)
st, r = call("get_legal_doc", {"doc": "privacy"})
check("an empty text brings the built-in page back", (r.get("data") or {}).get("custom") is False, r)

etel_test_db.run_sql("DELETE FROM customer_messages WHERE subject = 'Account deletion request'")
etel_test_db.run_sql("DELETE FROM account_deletion_requests")
st, r = call("customer_send_message", {"subject": "Account deletion request", "body": "I would like to permanently delete my ETEL app account."}, cust)
check("the app sends a deletion request (same call the phone makes)", st == 200 and r.get("success") is True, (st, r))
st, r = call("admin_deletion_list", {}, cust)
check("a customer cannot read the list", st == 403, (st, r))
st, r = call("admin_deletion_list", {}, admin)
reqs = (r.get("data") or {}).get("requests") or []
mine = [q for q in reqs if q.get("phone") in ("+251911000001", "251911000001", "0911000001") or q.get("status") == "open"]
check("the owner sees the request, waiting", st == 200 and len(reqs) == 1 and reqs[0]["status"] == "open", (st, reqs))
mid = reqs[0]["message_id"] if reqs else 0
st, r = call("admin_deletion_decide", {"message_id": mid, "decision": "delete"}, cust)
check("a customer cannot decide", st == 403, (st, r))
st, r = call("admin_deletion_decide", {"message_id": mid, "decision": "reject", "note": "smoke test"}, admin)
check("the owner rejects it", st == 200 and r.get("success") is True, (st, r))
st, r = call("admin_deletion_decide", {"message_id": mid, "decision": "reject"}, admin)
check("handling it twice is refused", st == 422, (st, r))
st, r = call("admin_deletion_list", {}, admin)
reqs = (r.get("data") or {}).get("requests") or []
check("the list shows it rejected with the note", reqs and reqs[0]["status"] == "rejected" and reqs[0]["note"] == "smoke test", reqs)
st, r = call("customer_get_settings", {}, cust)
check("the customer account still works after the rejection", st == 200, (st, r))

etel_test_db.run_sql("DELETE FROM customer_messages WHERE subject = 'Account deletion request'")
etel_test_db.run_sql("DELETE FROM account_deletion_requests")
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
