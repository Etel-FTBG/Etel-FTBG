"""
ETEL Delivery -- keeping the login token out of web addresses: stream tickets (api/lib/stream_ticket.php), the beacon, the admin CSV download, and the
url_token_mode setting (allow | monitor | refuse). Real API.

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/stream_ticket_smoke.py

Needs the seeded demo admin. Throwaway database only.
"""
import glob
import hashlib
import json
import os
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

import etel_test_db

API = "http://127.0.0.1:8765/api/boot.php"
LIBDIR = os.path.realpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "api", "lib"))
TMPDIR = os.path.join(tempfile.gettempdir(), "etel_shield_" + hashlib.md5(LIBDIR.encode()).hexdigest()[:10])


def clear_log_throttle():
    """the security log writes one row a minute for each address and rule: forget that, so a second run in the same minute still sees its row"""
    for f in glob.glob(os.path.join(TMPDIR, "l.*")):
        try:
            os.unlink(f)
        except OSError:
            pass
PASS, FAIL = [], []


def req(url, data=None, token=None, method=None, ua="EtelSmoke/1.0"):
    h = {"User-Agent": ua}
    if data is not None:
        h["Content-Type"] = "application/json"
    if token:
        h["Authorization"] = "Bearer " + token
    r = urllib.request.Request(url, data=json.dumps(data).encode() if data is not None else None, headers=h, method=method or ("POST" if data is not None else "GET"))
    try:
        with urllib.request.urlopen(r, timeout=20) as f:
            return f.status, f.read().decode("utf-8", "replace"), dict(f.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), dict(e.headers)


def call(action, body=None, token=None):
    b = dict(body or {}); b["action"] = action
    s, t, h = req(API, b, token)
    try:
        return s, json.loads(t)
    except Exception:
        return s, {"success": False, "message": t[:100]}


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)[:240]))


def stream(qs):
    """opens a live stream with ?once=1 so it answers one tick and ends; returns the HTTP status"""
    s, t, h = req(API + "?" + qs + "&once=1")
    return s, h.get("Content-Type", "")


def main():
    etel_test_db.run_sql("DELETE FROM login_attempts")
    s, r = call("login", {"phone": "0900000000", "password": "admin1234"})
    admin = (r.get("data") or {}).get("token")
    if not admin:
        print("SKIP: no seeded admin"); return 0

    def mode(m):
        s2, r2 = call("admin_shield_save", {"config": {"url_token_mode": m}}, admin)
        return (r2.get("data") or {}).get("config", {}).get("url_token_mode")

    check("the owner can set url_token_mode (allow / monitor / refuse)", mode("monitor") == "monitor" and mode("bogus") == "monitor", "")
    s, r = call("admin_shield_get", {}, admin)
    check("the factory setting is monitor (the old way still works, and is logged)", (r.get("data") or {}).get("defaults", {}).get("url_token_mode") == "monitor", r)

    print("1. A ticket opens a live stream, the token is not in the address")
    s, r = call("stream_ticket", {"for": "sse_driver_stream"}, admin)
    tk = (r.get("data") or {}).get("ticket")
    check("stream_ticket gives a ticket (sealed: the address shows nothing readable)", s == 200 and bool(tk) and admin not in (tk or "") and r["data"].get("expires_in") == 120, (s, r))
    check("a stream_ticket request without sign-in is refused", call("stream_ticket", {"for": "sse_driver_stream"})[0] == 401)
    check("only stream purposes can be asked for", call("stream_ticket", {"for": "admin_users"}, admin)[0] == 400 and call("stream_ticket", {}, admin)[0] == 400)
    s, ct = stream("action=sse_driver_stream&ticket=" + urllib.parse.quote(tk))
    check("the stream opens with the ticket alone (200, event-stream)", s == 200 and "text/event-stream" in ct, (s, ct))
    s, ct = stream("action=sse_driver_stream&ticket=" + urllib.parse.quote(tk[:-6] + "AAAAAA"))
    check("a tampered ticket gives nothing (401)", s == 401, s)
    s, ct = stream("action=sse_driver_stream&ticket=abc")
    check("a garbage ticket gives nothing (401)", s == 401, s)
    s, r2 = call("stream_ticket", {"for": "sse_ride_status", "id": "5"}, admin)
    t2 = r2["data"]["ticket"]
    s, ct = stream("action=sse_driver_stream&ticket=" + urllib.parse.quote(t2))
    check("a ticket made for another stream does not work here (401)", s == 401, s)
    s, ct = stream("action=sse_ride_status&id=6&ticket=" + urllib.parse.quote(t2))
    check("a ticket made for ride 5 does not open ride 6 (401)", s == 401, s)
    s, ct = stream("action=sse_ride_status&id=5&ticket=" + urllib.parse.quote(t2))
    check("... but it does open ride 5 (not 401)", s != 401, s)
    s, t, h = req(API + "?action=admin_shield_get&ticket=" + urllib.parse.quote(tk))
    check("a ticket is not a login for ordinary actions (it only works on streams and beacons)", s == 401, s)

    print("2. url_token_mode: an old app with the token in the address")
    etel_test_db.run_sql("DELETE FROM shield_events WHERE rule = 'token_in_url'")
    mode("monitor")
    clear_log_throttle()
    s, ct = stream("action=sse_driver_stream&token=" + urllib.parse.quote(admin))
    ev = int(etel_test_db.query_text("SELECT COUNT(*) FROM shield_events WHERE rule = 'token_in_url'")[1].strip().split("\n")[-1] or 0)
    check("monitor: the old address still works ...", s == 200, s)
    check("monitor: ... and it is written in the security log (token_in_url)", ev >= 1, ev)
    mode("allow")
    etel_test_db.run_sql("DELETE FROM shield_events WHERE rule = 'token_in_url'")
    s, ct = stream("action=sse_driver_stream&token=" + urllib.parse.quote(admin))
    ev = int(etel_test_db.query_text("SELECT COUNT(*) FROM shield_events WHERE rule = 'token_in_url'")[1].strip().split("\n")[-1] or 0)
    check("allow: works and is not logged", s == 200 and ev == 0, (s, ev))
    mode("refuse")
    s, ct = stream("action=sse_driver_stream&token=" + urllib.parse.quote(admin))
    check("refuse: the token in the address is refused (401)", s == 401, s)
    s, ct = stream("action=sse_driver_stream&ticket=" + urllib.parse.quote(call("stream_ticket", {"for": "sse_driver_stream"}, admin)[1]["data"]["ticket"]))
    check("refuse: a ticket still works", s == 200, s)

    print("3. The beacon: ticket in the address, or the token in the body")
    s, r = call("stream_ticket", {"for": "driver_beacon_ping"}, admin)
    bt = (r.get("data") or {}).get("ticket")
    check("a beacon ticket lasts 10 minutes", s == 200 and r["data"].get("expires_in") == 600, (s, r))
    s, t, h = req(API + "?action=driver_beacon_ping&ticket=" + urllib.parse.quote(bt), {"action": "driver_beacon_ping"})
    check("the beacon is accepted with a ticket (not 401)", s != 401, (s, t[:100]))
    s, t, h = req(API + "?action=driver_beacon_ping", {"action": "driver_beacon_ping", "token": admin})
    check("the beacon is accepted with the token in the body (not 401)", s != 401, (s, t[:100]))
    s, t, h = req(API + "?action=driver_beacon_ping&token=" + urllib.parse.quote(admin), {"action": "driver_beacon_ping"})
    check("refuse: the beacon with the token in the address is refused (401)", s == 401, (s, t[:100]))

    print("4. The admin CSV download uses the header")
    s, t, h = req(API + "?action=equity_export_investors_csv", None, admin)
    check("the investors CSV downloads with the Authorization header (not 401)", s != 401, (s, t[:100]))
    s, t, h = req(API + "?action=equity_export_investors_csv&token=" + urllib.parse.quote(admin))
    check("refuse: ... and with the token in the address it is refused", s in (401, 403), (s, t[:100]))
    mode("monitor")
    s, t, h = req(API + "?action=equity_export_investors_csv&token=" + urllib.parse.quote(admin))
    check("monitor: the old address still downloads (not 401)", s != 401, (s, t[:100]))

    mode("monitor")
    etel_test_db.run_sql("DELETE FROM shield_events WHERE rule = 'token_in_url'")
    etel_test_db.run_sql("DELETE FROM pricing_rules WHERE rule_key = 'shield_config'")
    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
