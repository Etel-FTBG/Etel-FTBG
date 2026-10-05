"""
ETEL Delivery -- Week 1 hardening regression smoke test.

Locks in the security fixes so they can't silently regress:
  1. reset_password cannot be brute-forced (locks out after a few wrong codes)
  2. equb_cron.php refuses HTTP callers without the right key
  3. updater.php locks an IP out after repeated wrong passwords and never
     sends a permissive CORS header to a foreign origin
  4. retired diagnostic scripts answer 410
  5. chapa-callback.php refuses a bad / missing signature

Needs APP_ENV != production (so reset_request returns dev_otp) and
CRON_DISPATCH_KEY=test_cron_key in api/.env. Point it at a throwaway DB.
"""
import glob
import json
import os
import sys
import tempfile
import time
import urllib.error
import urllib.request

BASE = os.environ.get("ETEL_TEST_BASE", "http://127.0.0.1:8765")
API = BASE + "/api/boot.php"
CRON_KEY = os.environ.get("ETEL_TEST_CRON_KEY", "test_cron_key")
SUFFIX = str(int(time.time()))
FAILS = []


def http(url, data=None, headers=None, method=None):
    h = dict(headers or {})
    body = None
    if data is not None:
        body = data if isinstance(data, bytes) else json.dumps(data).encode()
        h.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=body, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=15) as f:
            return f.status, f.read().decode("utf-8", "replace"), dict(f.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers)


def api(action, **body):
    body["action"] = action
    st, txt, _ = http(API, body)
    try:
        return json.loads(txt)
    except Exception:
        return {"ok": False, "raw": txt, "http": st}


def check(name, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else "  -> " + str(detail)))
    if not cond:
        FAILS.append(name)


def test_reset_lockout():
    phone = "+2519" + SUFFIX[-8:]
    api("register", name="Reset T", phone=phone, password="Passw0rd!x", role="customer")
    r = api("reset_request", phone=phone)
    otp = r.get("dev_otp") or (r.get("data") or {}).get("dev_otp")
    if not otp:
        print("  SKIP  reset lockout (no dev_otp; APP_ENV is production?)")
        return
    wrong = "000000" if otp != "000000" else "111111"
    results = [api("reset_password", phone=phone, otp=wrong, new_password="NewPassw0rd!") for _ in range(8)]
    check("wrong codes never succeed", not any(x.get("ok") for x in results), results[-1])
    good = api("reset_password", phone=phone, otp=otp, new_password="NewPassw0rd!")
    check("correct code refused after lockout (no brute force)", not good.get("ok"), good)


def test_equb_cron():
    url = BASE + "/api/equb_cron.php"
    st, _, _ = http(url)
    check("equb_cron without key -> 403", st == 403, st)
    st, _, _ = http(url + "?key=wrong")
    check("equb_cron wrong key -> 403", st == 403, st)
    st, _, _ = http(url + "?key=" + CRON_KEY)
    check("equb_cron right key is allowed", st != 403, st)


def test_updater():
    for f in glob.glob(os.path.join(tempfile.gettempdir(), "etel_upd_fail_*")):
        os.remove(f)
    url = BASE + "/api/updater.php?action=status"
    codes = [http(url, headers={"X-Auth": "nope" + str(i)})[0] for i in range(10)]
    check("updater rejects wrong password (401)", codes[0] == 401, codes)
    check("updater locks the IP out (429)", 429 in codes, codes)
    _, _, hdr = http(url, headers={"Origin": "https://evil.example", "X-Auth": "x"})
    acao = {k.lower(): v for k, v in hdr.items()}.get("access-control-allow-origin", "")
    check("updater sends no CORS allow to a foreign origin", "evil.example" not in acao and acao != "*", acao)
    for f in glob.glob(os.path.join(tempfile.gettempdir(), "etel_upd_fail_*")):
        os.remove(f)


def test_retired():
    for name in ("debug-geocode.php", "seed-zone-coords.php", "list-zones.php"):
        st, _, _ = http(BASE + "/api/" + name)
        check(name + " is retired (410)", st == 410, st)


def test_chapa_callback():
    url = BASE + "/api/chapa-callback.php"
    st, _, _ = http(url, {"tx_ref": "x"})
    check("chapa-callback without signature refused", st in (401, 503), st)
    st, _, _ = http(url, {"tx_ref": "x"}, headers={"Chapa-Signature": "bad"})
    check("chapa-callback bad signature refused", st in (401, 503), st)


if __name__ == "__main__":
    for t in (test_reset_lockout, test_equb_cron, test_updater, test_retired, test_chapa_callback):
        print(t.__name__)
        t()
    print("\nFAILED: %d" % len(FAILS) if FAILS else "\nAll week-1 checks passed")
    sys.exit(1 if FAILS else 0)
