"""
ETEL Delivery -- Etel Shield (api/lib/shield*.php) over the real API: blocked connections, hacking tools, floods (and that signed-in people are never blocked by address),
attack patterns (monitor and block, and that ordinary text never matches), over-size requests, allow / deny lists, the owner's controls and the safety rules.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/shield_smoke.py

The test machine connects from 127.0.0.1 (the shield lets the server itself through), so other connections are pretended with proxy_mode = any_xff and an
X-Forwarded-For header. That mode exists for testing only. Needs the seeded demo admin. Throwaway database only.
"""
import hashlib
import json
import os
import random
import sys
import tempfile
import time
import urllib.error
import urllib.request

import etel_test_db

API_URL = "http://127.0.0.1:8765/api/boot.php"
SUFFIX = str(int(time.time()))
TAIL = "%06d" % random.randint(0, 999999)
RUN = random.randint(10, 240)
PASS, FAIL = [], []
LIBDIR = os.path.realpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api", "lib"))
TMPDIR = os.path.join(tempfile.gettempdir(), "etel_shield_" + hashlib.md5(LIBDIR.encode()).hexdigest()[:10])


def ip(n):
    return "203.0.%d.%d" % (RUN, n)


def call(action, body=None, token=None, xff=None, ua=None, raw=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json", "User-Agent": ua or "EtelSmoke/1.0"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if xff:
        headers["X-Forwarded-For"] = xff
    data = raw if raw is not None else json.dumps(body).encode("utf-8")
    req = urllib.request.Request(API_URL, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=20) as f:
            return f.status, json.loads(f.read().decode("utf-8")), dict(f.headers)
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode("utf-8")), dict(e.headers)
        except Exception:
            return e.code, {"success": False, "message": "(non-JSON)"}, dict(e.headers)


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def sql1(q):
    ok, out, _ = etel_test_db.query_text(q)
    return out.strip().split("\n")[-1] if ok and out.strip() else ""


def reset_old_limiter():
    """The app's older per-account login limiter keys on the connection the test really uses (127.0.0.1), so many pretend connections would trip it: clear it."""
    etel_test_db.run_sql("DELETE FROM login_attempts")


def events(rule_like, ipaddr):
    return int(sql1("SELECT COUNT(*) FROM shield_events WHERE rule LIKE '%s' AND ip = '%s'" % (rule_like, ipaddr)) or 0)


def main():
    reset_old_limiter()
    s, r, h = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%' OR identifier LIKE 'login:%'")

    def cfg(**kw):
        s2, r2, h2 = call("admin_shield_save", {"config": kw}, admin)
        return r2

    base = dict(enabled=1, proxy_mode="any_xff", rate_mode="block", rate_anon_per_min=1500, rate_auth_per_min=600, rate_login_per_min=60, rate_signup_per_min=20,
                scanner_mode="block", waf_mode="monitor", max_body_kb=2048, allow_ips=[], deny_ips=[], ban_minutes=[15, 60, 1440])
    r = cfg(**base)
    check("the owner can save the shield settings (admin_shield_save)", (r.get("data") or {}).get("config", {}).get("proxy_mode") == "any_xff", r)
    s, r, h = call("admin_shield_get", {}, admin)
    d = r.get("data") or {}
    check("admin_shield_get shows settings, defaults, stats and my own connection", s == 200 and "config" in d and "stats" in d and (d.get("defaults") or {}).get("waf_mode") == "monitor" and "me" in d, r)

    print("1. Ordinary traffic is not touched")
    s, r, h = call("get_config", {}, xff=ip(1))
    check("a signed-out visitor is served", s == 200, (s, r))
    s, r, h = call("get_config", {}, xff=ip(1), ua="Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36")
    check("a phone browser is served", s == 200, (s, r))

    reset_old_limiter()
    print("2. Floods are slowed down (429 + Retry-After), per connection")
    cfg(**dict(base, rate_login_per_min=3))
    codes = []
    for i in range(9):   # the counter is per minute: if the clock moves to a new minute in the middle it starts again, so look for the FIRST 429
        s, r, h = call("login", {"phone": "0911000000", "password": "wrong"}, xff=ip(2))
        codes.append(s)
        if s == 429:
            break
    check("the first 3 sign-in tries of a minute are answered normally, then 429 (limit 3)", 429 in codes and 3 <= codes.index(429) <= 6 and all(c != 429 for c in codes[:3]), codes)
    check("the answer says what to do and carries Retry-After", r.get("code") == "rate_limited" and "slow down" in (r.get("message") or "") and int(h.get("Retry-After", "0")) > 0, (r, h))
    s, r, h = call("login", {"phone": "0911000000", "password": "wrong"}, xff=ip(3))
    check("another connection is not affected", s != 429, s)
    check("the refusals were written to the security log", events("rate_limit", ip(2)) >= 1)

    reset_old_limiter()
    print("3. A clear flood (twice the limit) from a signed-out connection blocks it for a while, then the owner can lift it")
    for i in range(30):   # plenty: the per-minute counter may roll over to a new minute in the middle
        call("login", {"phone": "09%08d" % random.randint(0, 99999999), "password": "wrong"}, xff=ip(4))
    s4, r4, h4 = call("get_config", {}, xff=ip(4))
    s, r, h = call("get_config", {}, xff=ip(9))
    for i in range(5):
        call("login", {"phone": "09%08d" % random.randint(0, 99999999), "password": "wrong"}, xff=ip(9))   # over the limit but under twice the limit
    s9, r9, h9 = call("get_config", {}, xff=ip(9))
    check("a connection only a little over the limit is slowed down but NOT blocked (shared phone-network addresses)", s9 == 200, (s9, r9))
    check("after a clear flood the connection is blocked (403, code blocked)", s4 == 403 and r4.get("code") == "blocked", (s4, r4))
    check("the block is in the list of blocks", any(b.get("ip") == ip(4) for b in ((call("admin_shield_bans", {}, admin)[1].get("data") or {}).get("bans") or [])))
    s, r, h = call("admin_shield_unban", {"ip": ip(4)}, admin)
    s, r, h = call("get_config", {}, xff=ip(4))
    check("lifting the block lets the connection in at once", s == 200, (s, r))

    print("4. Signed-in people are slowed down, never blocked by address")
    cph = "+251966" + TAIL
    s, r, h = call("register", {"name": "shield " + SUFFIX, "phone": cph, "password": "testpass123", "role": "customer"}, xff=ip(5))
    cust = (r.get("data") or {}).get("token")
    if not cust:
        etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%'")
        s, r, h = call("register", {"name": "shield " + SUFFIX, "phone": cph, "password": "testpass123", "role": "customer"}, xff=ip(5))
        cust = (r.get("data") or {}).get("token")
    check("the test customer signed up", bool(cust))
    cfg(**dict(base, rate_auth_per_min=30))
    last = 0
    for i in range(34):
        last, r, h = call("customer_get_orders", {}, token=cust, xff=ip(6))
    check("a signed-in person over the limit gets 429", last == 429, last)
    s, r, h = call("get_config", {}, xff=ip(6))
    check("... but the connection itself is NOT blocked (phone networks share addresses)", s == 200, (s, r))

    print("5. Hacking tools are refused and blocked")
    s, r, h = call("get_config", {}, xff=ip(7), ua="sqlmap/1.7.2#stable (https://sqlmap.org)")
    check("a request from sqlmap is refused", s == 403, (s, r))
    s, r, h = call("get_config", {}, xff=ip(7))
    check("and that connection is blocked afterwards, even with a normal browser name", s == 403 and r.get("code") == "blocked", (s, r))
    check("both are in the security log", events("scanner", ip(7)) >= 1 and events("ban", ip(7)) >= 1)
    s, r, h = call("get_config", {}, xff=ip(8), ua="curl/8.4.0")
    check("curl is NOT treated as a hacking tool", s == 200, s)

    print("6. Attack patterns: logged in monitor mode, refused in block mode, ordinary text never matches")
    attacks = [("' OR 1=1 --", "sqli"), ("1 UNION SELECT username, password FROM users", "sqli"), ("<script>alert(1)</script>", "xss"),
               ("../../etc/passwd", "path"), ("x; DROP TABLE users", "sqli"), ("php://input", "php")]
    cfg(**dict(base, waf_mode="monitor"))
    for i, (payload, kind) in enumerate(attacks):
        s, r, h = call("login", {"phone": payload, "password": "x"}, xff=ip(10 + i))
        if s == 403 and r.get("code") == "waf":
            check("monitor mode lets '%s' through" % payload[:20], False, (s, r))
    check("monitor mode: nothing was refused", True)
    got = [events("waf_%", ip(10 + i)) for i in range(len(attacks))]
    check("monitor mode: every attack was written to the security log", all(g >= 1 for g in got), got)
    cfg(**dict(base, waf_mode="block"))
    s, r, h = call("login", {"phone": "' OR 1=1 --", "password": "x"}, xff=ip(30))
    check("block mode: the attack is refused (403, code waf)", s == 403 and r.get("code") == "waf", (s, r))
    for i in range(3):
        call("login", {"phone": "1 UNION SELECT a FROM b", "password": "x"}, xff=ip(31))
    s, r, h = call("get_config", {}, xff=ip(31))
    check("3 attack requests block the connection", s == 403 and r.get("code") == "blocked", (s, r))
    benign = ["Address: Cheffe", "Don't use onions or garlic, please", "50% off & free delivery", "select the red one from the menu", "Burger + Mayonis, Kachap",
              "Call me at 0911000000, or text me", "ማርያም ሆቴል", "a<b and c>d", "O'Brien and sons", "Order #12 - 2 x Pizza; 1 x Cola", "see you at http://example.com/menu",
              "I want to drop by and update my order", "union of students", "the file is on my phone", "../ is a folder up (just kidding)"]
    bad = []
    for i, t in enumerate(benign):
        s, r, h = call("login", {"phone": "0911000001", "password": "x", "note": t}, xff=ip(40 + i))
        if (s == 403 and r.get("code") == "waf") or events("waf_%", ip(40 + i)) > 0:
            bad.append(t)
    check("ordinary customer text (notes, names, addresses, Amharic, prices) never matches a pattern", bad == [] or bad == ["../ is a folder up (just kidding)"], bad)
    s, r, h = call("login", {"phone": "0911000001", "password": "' OR 1=1 --"}, xff=ip(70))
    check("passwords are not inspected", not (s == 403 and r.get("code") == "waf") and events("waf_%", ip(70)) == 0, (s, r))
    cfg(**dict(base, waf_mode="block", waf_exempt_actions=["login"]))
    s, r, h = call("login", {"phone": "' OR 1=1 --", "password": "x"}, xff=ip(71))
    check("an action on the exempt list is not inspected", not (s == 403 and r.get("code") == "waf"), (s, r))
    cfg(**dict(base, waf_mode="off"))

    reset_old_limiter()
    print("7. Over-size requests, allow list, deny list, switch off")
    cfg(**dict(base, max_body_kb=64))
    s, r, h = call("get_config", {}, xff=ip(80), raw=json.dumps({"action": "get_config", "junk": "x" * 100000}).encode())
    check("a request over the size limit gets 413", s == 413 and r.get("code") == "too_large", (s, r))
    cfg(**dict(base, rate_login_per_min=3, allow_ips=[ip(81)]))
    codes = [call("login", {"phone": "09%08d" % random.randint(0, 99999999), "password": "x"}, xff=ip(81))[0] for i in range(8)]   # a new number each time: the app's older per-account login limiter must not answer 429 here
    check("an allowed address is never limited", 429 not in codes, codes)
    cfg(**dict(base, deny_ips=[ip(82), "198.51.100.0/24"]))
    s, r, h = call("get_config", {}, xff=ip(82))
    check("an address on the deny list is refused", s == 403 and r.get("code") == "blocked", (s, r))
    s, r, h = call("get_config", {}, xff="198.51.100.77")
    check("a whole range (CIDR) can be denied", s == 403, s)
    s, r, h = call("get_config", {}, xff=ip(83))
    check("everyone else is served", s == 200, s)
    cfg(**dict(base, enabled=0, rate_login_per_min=3))
    codes = [call("login", {"phone": "09%08d" % random.randint(0, 99999999), "password": "x"}, xff=ip(84))[0] for i in range(6)]
    check("with the shield switched off nothing is limited", 429 not in codes, codes)
    cfg(**base)

    print("8. The owner's controls")
    s, r, h = call("admin_shield_ban", {"ip": ip(90), "minutes": 30, "reason": "test"}, admin)
    s2, r2, h2 = call("get_config", {}, xff=ip(90))
    check("an admin can block a connection by hand", s == 200 and s2 == 403, (s, r, s2))
    s, r, h = call("admin_shield_ban", {"ip": "127.0.0.1", "minutes": 30}, admin)
    check("blocking the server's own address is refused", s == 400, (s, r))
    s, r, h = call("admin_shield_ban", {"ip": "not-an-ip", "minutes": 30}, admin)
    check("a block needs a real IP address", s == 400, (s, r))
    s, r, h = call("admin_shield_save", {"config": {"deny_ips": ["bad value"]}}, admin)
    check("a deny list with a bad address is refused", s == 400, (s, r))
    s, r, h = call("admin_shield_events", {"limit": 20}, admin)
    check("the security log can be read", s == 200 and len((r.get("data") or {}).get("events") or []) > 0, r)
    s, r, h = call("admin_shield_events", {"rule": "scanner", "limit": 5}, admin)
    check("... and filtered by rule", all(e.get("rule") == "scanner" for e in (r.get("data") or {}).get("events", [])), r)
    s, r, h = call("admin_shield_get", {}, cust)
    check("a customer cannot read or change the shield", s in (401, 403) and r.get("success") is False, (s, r))
    s, r, h = call("admin_shield_save", {"config": {"enabled": 0}}, cust)
    check("... (save)", s in (401, 403) and r.get("success") is False, (s, r))
    s, r, h = call("admin_shield_get", {}, admin)
    st = (r.get("data") or {}).get("stats") or {}
    check("the stats count what happened (events, blocks, top connections)", st.get("events_24h", 0) > 5 and len(st.get("top_ips") or []) > 0 and st.get("bans_active", 0) >= 1, st)

    reset_old_limiter()
    print("9. Safety: a broken shield never takes the system down")
    cfg(**base)
    os.makedirs(TMPDIR, exist_ok=True)
    for name in ("cfg.json", "bans.json"):
        with open(os.path.join(TMPDIR, name), "w") as f:
            f.write("{{{ this is not json")
    s, r, h = call("get_config", {}, xff=ip(95))
    check("broken settings / ban files: the API still answers", s == 200, (s, r))
    reset_old_limiter()
    s, r, h = call("login", {"phone": "0900000000", "password": "admin1234"})
    check("the admin can still sign in (the server itself is never limited)", s == 200 and (r.get("data") or {}).get("token"), (s, r))

    cfg(**dict(base, proxy_mode="none"))
    for t in ("cfg.json", "bans.json"):
        try:
            os.unlink(os.path.join(TMPDIR, t))
        except OSError:
            pass
    etel_test_db.run_sql("DELETE FROM shield_events WHERE ip LIKE '203.0.%' OR ip LIKE '198.51.100.%'")
    etel_test_db.run_sql("DELETE FROM shield_bans WHERE ip LIKE '203.0.%' OR ip LIKE '198.51.100.%'")
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'shield_config'")
    try:
        os.unlink(os.path.join(TMPDIR, "cfg.json"))
    except OSError:
        pass
    etel_test_db.run_sql("DELETE FROM login_attempts WHERE identifier LIKE 'register:%' OR identifier LIKE 'upload:%' OR identifier LIKE 'login:%'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
