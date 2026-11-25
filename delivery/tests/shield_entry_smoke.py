"""
ETEL Delivery -- Etel Shield on the files that are NOT behind boot.php (api/lib/shield_entry.php): uploads, the payment and Telegram callbacks, the error and
policy report sinks, the trip share link. Checks each kind gets only the protection that is safe for it (the payment servers are never turned away by
country and never blocked automatically), that the owner's settings reach these files, and that entry_mode off / monitor really do nothing / only log.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/shield_entry_smoke.py

Other connections are pretended with proxy_mode = any_xff and an X-Forwarded-For header (testing only). Needs the seeded demo admin. Throwaway database only.
"""
import json
import os
import random
import sys
import urllib.error
import urllib.request

import etel_test_db

BASE = "http://127.0.0.1:8765/api/"
RUN = random.randint(10, 240)
PASS, FAIL = [], []


def ip(n):
    return "203.1.%d.%d" % (RUN, n)


def hit(path, xff=None, ua="EtelSmoke/1.0", data=b"{}", method="POST", token=None):
    headers = {"User-Agent": ua, "Content-Type": "application/json"}
    if xff:
        headers["X-Forwarded-For"] = xff
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + path, data=data if method == "POST" else None, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as f:
            body = f.read().decode("utf-8", "replace")
            return f.status, body
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def code_of(body):
    try:
        return json.loads(body).get("code")
    except Exception:
        return None


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:240]))


def sql1(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def events(rule, ipaddr):
    return int(sql1("SELECT COUNT(*) FROM shield_events WHERE rule = '%s' AND ip = '%s'" % (rule, ipaddr)) or 0)


def main():
    etel_test_db.run_sql("DELETE FROM login_attempts")
    s, b = hit("boot.php", data=json.dumps({"action": "login", "phone": "0900000000", "password": "admin1234"}).encode())
    admin = (json.loads(b).get("data") or {}).get("token") if s == 200 else None
    if not admin:
        print("SKIP: no seeded admin"); return 0

    def cfg(**kw):
        return hit("boot.php", data=json.dumps({"action": "admin_shield_save", "config": kw}).encode(), token=admin)

    base = dict(enabled=1, proxy_mode="any_xff", entry_mode="block", scanner_mode="block", geo_mode="monitor", rate_mode="block", allow_ips=[], deny_ips=[],
                rate_upload_per_min=10000, rate_public_per_min=10000, rate_callback_per_min=10000, rate_sink_per_min=10000)
    s, b = cfg(**base)
    d = (json.loads(b).get("data") or {}).get("config", {}) if s == 200 else {}
    check("the owner can save the entry settings (entry_mode and the four limits)", d.get("entry_mode") == "block" and d.get("rate_callback_per_min") == 10000, b[:200])
    s, b = hit("boot.php", data=json.dumps({"action": "admin_shield_get"}).encode(), token=admin)
    check("the factory settings include the new keys", (json.loads(b).get("data") or {}).get("defaults", {}).get("entry_mode") == "block")

    print("1. Ordinary use of an upload file is not touched")
    s, b = hit("upload.php", xff=ip(1))
    check("a plain request reaches upload.php (its own answer, not the shield's)", s in (200, 400, 401, 405) and code_of(b) not in ("blocked", "rate_limited", "geo_blocked"), (s, b[:120]))

    print("2. Hacking tools are refused on uploads and public files, but not on the payment callbacks")
    s, b = hit("upload.php", xff=ip(2), ua="sqlmap/1.7")
    check("upload.php refuses a hacking tool (403 blocked)", s == 403 and code_of(b) == "blocked", (s, b[:120]))
    s, b = hit("trip_share.php?t=abc", xff=ip(3), ua="Nikto/2.5", method="GET")
    check("the trip share link refuses a hacking tool too", s == 403, (s, b[:120]))
    s, b = hit("chapa-callback.php", xff=ip(4), ua="python-requests/2.31")
    check("a payment callback is NOT refused for its User-Agent (its own answer: signature / not configured)", s != 403 and s != 429, (s, b[:120]))

    print("3. Floods: uploads per connection, callbacks (never blocked), report sinks (quiet 204)")
    cfg(**dict(base, rate_upload_per_min=10, rate_callback_per_min=60, rate_sink_per_min=10))
    codes = []
    for i in range(40):
        s, b = hit("upload.php", xff=ip(5))
        codes.append(s)
        if s == 429:
            break
    check("uploads: normal answers first, then 429 (limit 10 a minute)", 429 in codes and 9 <= codes.index(429) <= 22, codes)
    check("the refusal says rate_limited", code_of(b) == "rate_limited", b[:120])
    codes = []
    for i in range(140):
        s, b = hit("chapa-callback.php", xff=ip(6))
        codes.append(s)
        if s == 429:
            break
    check("callbacks: a very high flood (over 60 a minute) is slowed down with 429", 429 in codes and codes.index(429) >= 55, codes[-3:])
    bans = json.loads(hit("boot.php", data=json.dumps({"action": "admin_shield_bans"}).encode(), token=admin)[1]).get("data", {}).get("bans", [])
    check("callbacks: a flood NEVER blocks the connection for good (not in the block list)", not any(x.get("ip") == ip(6) for x in bans), bans[:3])
    for i in range(30):
        hit("csp-report.php", xff=ip(7))
    s, b = hit("csp-report.php", xff=ip(7))
    check("sinks: over the limit the answer stays a quiet 204", s == 204, (s, b[:80]))
    check("sinks: the flood is in the security log", events("rate_limit", ip(7)) >= 1)

    print("4. Ethiopia only (connection): people's files follow it, the payment servers and the sinks never do")
    cfg(**dict(base, geo_mode="enforce"))
    FOREIGN = "8.8.8.8"
    s, b = hit("upload.php", xff=FOREIGN)
    check("upload.php from a foreign connection is turned away (403 geo_blocked)", s == 403 and code_of(b) == "geo_blocked", (s, b[:120]))
    s, b = hit("trip_share.php?t=abc", xff=FOREIGN, method="GET")
    check("an open page (trip share) is turned away too", s == 403 and code_of(b) == "geo_blocked", (s, b[:120]))
    s, b = hit("chapa-callback.php", xff=FOREIGN)
    check("the payment callback from a foreign address is NOT turned away", s != 403, (s, b[:120]))
    s, b = hit("telebirr-callback.php", xff=FOREIGN)
    check("the Telebirr callback from a foreign address is NOT turned away", s != 403, (s, b[:120]))
    s, b = hit("client-error.php", xff=FOREIGN)
    check("the error beacon from a foreign address is NOT turned away", s == 204, s)
    cfg(**dict(base, geo_mode="monitor"))

    print("5. The owner's block list reaches the callbacks; entry_mode off and monitor")
    cfg(**dict(base, deny_ips=[ip(8)]))
    s, b = hit("chapa-callback.php", xff=ip(8))
    check("an address on the owner's block list is refused by a callback too", s == 403 and code_of(b) == "blocked", (s, b[:120]))
    cfg(**dict(base, entry_mode="monitor"))
    s, b = hit("upload.php", xff=ip(9), ua="sqlmap/1.7")
    check("entry_mode monitor: a hacking tool is only logged, not refused", s != 403, (s, b[:120]))
    check("entry_mode monitor: ... and it is in the log", events("scanner", ip(9)) >= 1)
    cfg(**dict(base, entry_mode="off"))
    s, b = hit("upload.php", xff=ip(10), ua="sqlmap/1.7")
    check("entry_mode off: nothing is checked", s != 403, (s, b[:120]))
    cfg(**dict(base, enabled=0))
    s, b = hit("upload.php", xff=ip(11), ua="sqlmap/1.7")
    check("the master switch (enabled = 0) turns these files' protection off too", s != 403, (s, b[:120]))

    print("6. Signed-in people are not blocked by address; the shield never breaks a normal upload request")
    cfg(**dict(base, rate_upload_per_min=10))
    codes = [hit("upload.php", xff=ip(12), token=admin)[0] for _ in range(14)]
    bans = json.loads(hit("boot.php", data=json.dumps({"action": "admin_shield_bans"}).encode(), token=admin)[1]).get("data", {}).get("bans", [])
    check("a signed-in flood is slowed down (429) but the address is never blocked", not any(x.get("ip") == ip(12) for x in bans), codes)

    cfg(**base)
    etel_test_db.run_sql("DELETE FROM shield_events WHERE ip LIKE '203.1.%' OR ip = '8.8.8.8'")
    etel_test_db.run_sql("DELETE FROM shield_bans WHERE ip LIKE '203.1.%'")
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'shield_config'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
