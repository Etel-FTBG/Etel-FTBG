"""
Maintenance mode and minimum app version, through the real server: a phone asks app_status without signing in, only the owner admin changes the
settings, a bad version or update address is refused, and an old version is told to update.
Uses the seeded demo accounts (no sign-ups, so the shared register limit is not touched). Everything is put back at the end.

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

print("app status: maintenance and minimum version")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(cust)))
    sys.exit(1)
KEYS = "('maint_enabled','maint_text_en','maint_text_am','min_version_android','update_text_en','update_text_am','update_url_android')"
etel_test_db.run_sql("DELETE FROM app_hub_settings WHERE setting_key IN " + KEYS)

st, r = call("app_status", {"version": "1.7.0"})
d = r.get("data") or {}
check("anyone can ask, signed out: all clear by default", st == 200 and d.get("maintenance", {}).get("on") is False and d.get("update", {}).get("required") is False, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"maint_enabled": "1"}}, cust)
check("a customer cannot switch maintenance on", st == 403, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"maint_enabled": "1", "maint_text_en": "Back at 3 PM"}}, admin)
check("the owner switches maintenance on", st == 200 and r.get("success") is True, (st, r))
st, r = call("app_status", {"version": "1.7.0"})
d = r.get("data") or {}
check("a phone, signed out, now sees maintenance with the text", d.get("maintenance", {}).get("on") is True and d["maintenance"].get("text_en") == "Back at 3 PM", d)
call("admin_hub_settings_save", {"settings": {"maint_enabled": "0"}}, admin)
st, r = call("app_status", {"version": "1.7.0"})
check("switching it off lets everyone in again", (r.get("data") or {}).get("maintenance", {}).get("on") is False, r)

st, r = call("admin_hub_settings_save", {"settings": {"min_version_android": "banana"}}, admin)
check("a bad version is refused", st == 422, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"update_url_android": "https://evil.example.com/a.apk"}}, admin)
check("an update address outside Play, Expo and this site is refused", st == 422, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"min_version_android": "1.7.2", "update_text_en": "Please update", "update_url_android": "https://play.google.com/store/apps/details?id=com.etelgroup.driver"}}, admin)
check("the owner sets a minimum version", st == 200 and r.get("success") is True, (st, r))
for v, need in (("1.7.1", True), ("1.7.2", False), ("1.10.0", False), ("1.6.9", True), ("", False), ("banana", False)):
    st, r = call("app_status", {"version": v})
    check("version %r %s" % (v, "must update" if need else "is let in"), (r.get("data") or {}).get("update", {}).get("required") is need, r)
st, r = call("app_status", {"version": "1.7.0"})
u = (r.get("data") or {}).get("update") or {}
check("the update answer has the minimum, text and address", u.get("min") == "1.7.2" and u.get("text_en") == "Please update" and "play.google.com" in u.get("url", ""), u)
st, r = call("get_hub_apps", {}, cust)
check("these settings are not part of the home texts", "maint_text_en" not in ((r.get("data") or {}).get("texts") or {}) and "update_url_android" not in ((r.get("data") or {}).get("texts") or {}), r)

etel_test_db.run_sql("DELETE FROM app_hub_settings WHERE setting_key IN " + KEYS)
print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
