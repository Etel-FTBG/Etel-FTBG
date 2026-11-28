"""
ETEL Delivery -- the language an account can save (api set_user_language, 28 Nov 2026).

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/language_choice_smoke.py

The picker offers English, Amharic, Oromo, Tigrinya, Somali and Afar: en, am, om, ti, so, aa. The server accepts exactly those six two-letter codes and nothing else.
(Some installs have no users.lang column: the server then answers 501 "not supported on this install" for a valid code; that is fine here. What must never happen is
"Unsupported language" for a valid code, or a save for a made-up one.) Creates a throwaway account: throwaway database only.
"""
import json
import sys
import time
import urllib.error
import urllib.request

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
PASS, FAIL = [], []


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(API_URL, data=json.dumps(body).encode("utf-8"), headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status, json.loads(f.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8"))
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON error body)"}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:300]))


def main():
    n = int(SUFFIX) % 1000000
    s, r = call("register", {"name": "Lang %s" % SUFFIX, "phone": "+251964%06d" % n, "password": "testpass123", "role": "customer"})
    tok = (r.get("data") or {}).get("token")
    check("test account exists", bool(tok), r)
    s, r = call("set_user_language", {"lang": "om"})
    check("no sign-in is refused", r.get("success") is False and s in (401, 403), (s, r))
    for code in ("en", "am", "om", "ti", "so", "aa"):
        s, r = call("set_user_language", {"lang": code}, token=tok)
        ok = (r.get("success") is True and (r.get("data") or {}).get("lang") == code) or (s == 501 and "not supported" in str(r.get("message", "")).lower())
        check("%s is a language the server accepts (saved, or 'not supported on this install' when the column is missing)" % code, ok, (s, r))
    for bad in ("xx", "", "english", "OM", "om-ET", "am;DROP", "<b>", "en,am"):
        s, r = call("set_user_language", {"lang": bad}, token=tok)
        check("%r is refused with 400 'Unsupported language'" % bad, s == 400 and r.get("success") is False and "unsupported" in str(r.get("message", "")).lower(), (s, r))
    # the owner's control: get_language_config is public, admin_save_languages is admin only and cleans what it is given
    s, r = call("get_language_config")
    d = r.get("data") or {}
    states = dict((x.get("code"), x.get("status")) for x in d.get("langs", []))
    check("anyone can read the language settings (no sign-in): six languages, English live", s == 200 and list(states) == ["en", "am", "om", "ti", "so", "aa"] and states.get("en") == "live", (s, r))
    s, r = call("admin_save_languages", {"default": "so", "langs": {"so": "live"}}, token=tok)
    check("a customer cannot save the language settings", r.get("success") is False and s in (401, 403), (s, r))
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    atok = (r.get("data") or {}).get("token")
    if not atok:
        print("  SKIP no seeded demo admin: the admin part of the language settings is not tested")
    else:
        s, r = call("admin_save_languages", {"default": "so", "langs": {"en": "off", "om": "off", "ti": "live", "so": "beta", "aa": "banana", "xx": "live"}}, token=atok)
        cfg = (r.get("data") or {}).get("config") or {}
        check("admin save: English stays live, bad states and unknown codes are ignored", s == 200 and cfg.get("langs", {}).get("en") == "live" and cfg["langs"].get("aa") == "soon" and "xx" not in cfg["langs"], (s, r))
        s, r = call("get_language_config")
        d = r.get("data") or {}
        states = dict((x.get("code"), x.get("status")) for x in d.get("langs", []))
        check("the saved states are what the picker reads: Oromo hidden, Tigrinya live, Somali beta, default Somali", states.get("om") == "off" and states.get("ti") == "live" and states.get("so") == "beta" and d.get("default") == "so", (s, r))
        s, r = call("admin_save_languages", {"default": "om", "langs": {"om": "off"}}, token=atok)
        check("a hidden language cannot be the default (falls back to English)", (r.get("data") or {}).get("config", {}).get("default") == "en", (s, r))
        s, r = call("admin_get_languages", {}, token=atok)
        check("admin can read the settings with how many people chose each language", s == 200 and "stats" in (r.get("data") or {}), (s, r))
        call("admin_save_languages", {"default": "en", "langs": {"om": "beta", "ti": "beta", "so": "beta", "aa": "soon", "am": "live"}}, token=atok)
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    for f in FAIL:
        print("  FAIL: " + f)
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
