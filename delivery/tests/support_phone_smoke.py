"""
Support requests as the phone app makes them, through the real server: write a request, see it in the list, staff answer in Admin > Support desk, the person
sees the unread count, reads the answer, replies, marks it solved and rates it. Another person can never read it.
Uses the seeded demo accounts (no sign-ups). The test request is removed at the end.

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import sys
import subprocess
import time
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

print("support from the phone")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000002", "password": "etel1234"})
drv = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
other = (r.get("data") or {}).get("token")
if not (admin and drv and other):
    check("demo accounts can sign in", False, (bool(admin), bool(drv), bool(other)))
    sys.exit(1)
etel_test_db.run_sql("DELETE FROM sup_messages WHERE ticket_id IN (SELECT id FROM sup_tickets WHERE subject LIKE 'SMOKE %')")
etel_test_db.run_sql("DELETE FROM sup_tickets WHERE subject LIKE 'SMOKE %'")

st, r = call("sup_meta", {})
check("nobody signed in is refused", st == 401, (st, r))
st, r = call("sup_meta", {}, drv)
m = r.get("data") or {}
check("the desk is on and the categories are the ones the phone offers", st == 200 and m.get("enabled") is True and m.get("categories") == ["payment", "account", "trip", "order", "safety", "app", "other"], (st, m))
st, r = call("sup_ticket_create", {"app": "driver", "category": "payment", "subject": "SMOKE payout missing", "body": "My payout did not arrive yesterday", "lang": "en"}, drv)
tk = (r.get("data") or {}).get("ticket") or {}
tid = tk.get("id")
check("the driver writes a request (the phone's call)", st == 200 and tid and tk.get("status") == "open" and tk.get("ref"), (st, r))
st, r = call("sup_ticket_create", {"app": "driver", "category": "payment", "subject": "ab", "body": "x", "lang": "en"}, drv)
check("a too short title is refused by the server too", st == 400, (st, r))
st, r = call("sup_my_tickets", {}, drv)
mine = [x for x in ((r.get("data") or {}).get("tickets") or []) if x.get("id") == tid]
check("the request is in the person's list, no unread yet", len(mine) == 1 and mine[0]["unread"] is False and mine[0]["subject"] == "SMOKE payout missing", r)
st, r = call("sup_my_tickets", {}, other)
check("another person does not see it", not [x for x in ((r.get("data") or {}).get("tickets") or []) if x.get("id") == tid], r)
st, r = call("sup_ticket_get", {"id": tid}, other)
check("another person cannot open it", st == 404, (st, r))
st, r = call("admin_sup_list", {}, admin)
check("staff see it in the Support desk queue (its reference is in the list)", st == 200 and tk.get("ref") and tk["ref"] in json.dumps(r), (st, str(r)[:200]))
st, r = call("admin_sup_reply", {"id": tid, "body": "We found it, it will arrive today."}, admin)
check("staff answer", st == 200, (st, r))
st, r = call("sup_meta", {}, drv)
check("the person sees 1 unread (the count on Help and Profile)", (r.get("data") or {}).get("unread") == 1, r)
st, r = call("sup_ticket_get", {"id": tid}, drv)
d = r.get("data") or {}
msgs = d.get("messages") or []
check("opening it shows the thread: my message, then support's answer", len(msgs) == 2 and msgs[0]["from"] == "me" and msgs[1]["from"] == "support" and "arrive today" in msgs[1]["body"] and d.get("can_reply") is True, d)
st, r = call("sup_meta", {}, drv)
check("reading it clears the unread count", (r.get("data") or {}).get("unread") == 0, r)
st, r = call("sup_ticket_reply", {"id": tid, "body": "Thank you, I will check."}, drv)
check("the person replies", st == 200, (st, r))
st, r = call("sup_ticket_close", {"id": tid}, drv)
check("the person marks it solved", st == 200 and (r.get("data") or {}).get("ticket", {}).get("status") == "solved", (st, r))
st, r = call("sup_ticket_get", {"id": tid}, drv)
check("a solved request can be rated", (r.get("data") or {}).get("can_rate") is True, r)
st, r = call("sup_ticket_rate", {"id": tid, "rating": 5}, drv)
check("the person rates it 5", st == 200 and (r.get("data") or {}).get("ticket", {}).get("rating") == 5, (st, r))
st, r = call("sup_ticket_rate", {"id": tid, "rating": 4}, drv)
check("it cannot be rated twice", st == 409, (st, r))

etel_test_db.run_sql("DELETE FROM sup_messages WHERE ticket_id IN (SELECT id FROM sup_tickets WHERE subject LIKE 'SMOKE %')")
etel_test_db.run_sql("DELETE FROM sup_tickets WHERE subject LIKE 'SMOKE %'")
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
