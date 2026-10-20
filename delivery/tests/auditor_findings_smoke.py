"""
ETEL Delivery -- checks for the four findings an outside auditor reported (October 2026).

Runs against a real instance of boot.php, like authz_matrix_smoke.py:

    php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
    python3 tests/auditor_findings_smoke.py

1. Broken access control: a signed-in customer is refused the admin session check the dashboard now makes; a staff
   admin with a limited role is refused the money actions (bank / Telebirr payments, payout requests, financial
   report, payment logos); the full owner admin is not.
2. Unrestricted file upload: only real images are kept; a PHP script named .jpg, "GIF89a"+PHP and a PNG with PHP
   appended are refused; a real PNG is accepted.
3. Duplicate phone numbers: the same number written another way cannot create a second account, and several
   sign-ups for one number sent at the same moment create exactly one.

Needs the seeded demo admin (api/seeds/admin_demo_accounts.sql). Creates real rows: throwaway database only.
"""
import json
import struct
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid
import zlib

import etel_test_db

BASE = "http://127.0.0.1:8765/api/"
API_URL = BASE + "boot.php"
SUFFIX = int(time.time())
ADMIN_PHONE = "0900000000"
ADMIN_PASSWORD = "admin1234"
PASS, FAIL = [], []


def post(url, data, headers):
    req = urllib.request.Request(url, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=20) as f:
            status, raw = f.status, f.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        status, raw = e.code, e.read().decode("utf-8", "replace")
    try:
        return status, json.loads(raw)
    except Exception:
        return status, {"success": False, "message": "(non-JSON body) " + raw[:80]}


def call(action, body=None, token=None):
    body = dict(body or {})
    body["action"] = action
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    return post(API_URL, json.dumps(body).encode("utf-8"), headers)


def check(name, cond, detail=""):
    (PASS if cond else FAIL).append(name)
    print(("  OK   " if cond else "  FAIL ") + name + ("" if cond else "  " + str(detail)))


def png_bytes(w=8, h=8):
    def chunk(t, d):
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    raw = b"".join(b"\x00" + bytes([200, 100, 20]) * w for _ in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


def upload(token, data, filename, ctype):
    b = uuid.uuid4().hex
    body = (("--%s\r\nContent-Disposition: form-data; name=\"image\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n" % (b, filename, ctype)).encode()
            + data + ("\r\n--%s--\r\n" % b).encode())
    return post(BASE + "upload.php", body, {"Content-Type": "multipart/form-data; boundary=" + b, "Authorization": "Bearer " + token})


def phone_forms(n):
    """Four ways to write the same number."""
    return ["09%08d" % n, "+2519%08d" % n, "2519%08d" % n, "9%08d" % n]


def main():
    s, r = call("login", {"phone": ADMIN_PHONE, "password": ADMIN_PASSWORD})
    owner = (r.get("data") or {}).get("token")
    if not owner:
        print("  SKIP no seeded demo admin; run api/seeds/admin_demo_accounts.sql")
        return 0

    base = SUFFIX % 9000000 + 1000000
    s, r = call("register", {"name": "Findings Customer", "phone": phone_forms(base)[0], "password": "testpass123", "role": "customer"})
    cust = (r.get("data") or {}).get("token")
    check("a customer account exists", bool(cust), r)
    if not cust:
        return 1

    print("1. Broken access control")
    s, r = call("admin_get_my_capabilities", {}, token=cust)
    check("a customer is refused the admin session check", r.get("success") is False and s in (401, 403), (s, r))
    s, r = call("admin_get_my_capabilities", {}, token=None)
    check("no token is refused the admin session check", r.get("success") is False, (s, r))
    s, r = call("admin_get_my_capabilities", {}, token=owner)
    check("the owner admin passes the admin session check", r.get("success") is True, (s, r))

    lim_phone = phone_forms(base + 1)[0]
    s, r = call("admin_create_user", {"name": "Limited Staff", "phone": lim_phone, "password": "testpass123", "role": "admin"}, token=owner)
    lim_id = (r.get("data") or {}).get("user_id") or (r.get("data") or {}).get("id")
    check("a staff admin account was created", r.get("success") is True, r)
    role_name = "Findings Limited %d" % SUFFIX
    ok1, e1 = etel_test_db.run_sql("INSERT INTO role_permissions (role_name, capabilities) VALUES ('%s', '[\"rides.view\"]') "
                                   "ON DUPLICATE KEY UPDATE capabilities='[\"rides.view\"]'" % role_name)
    ok2, e2 = etel_test_db.run_sql("UPDATE users SET is_etelgroup_managed=1, staff_role='%s' WHERE RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''),9)='9%08d'" % (role_name, base + 1))
    if not (ok1 and ok2):
        print("  SKIP could not make a limited staff role in the test database: %s %s" % (e1, e2))
    else:
        s, r = call("login", {"phone": lim_phone, "password": "testpass123"})
        limited = (r.get("data") or {}).get("token")
        check("the limited staff admin can sign in", bool(limited), r)
        money = ["admin_list_telebirr_payments", "admin_confirm_telebirr_payment", "admin_list_bank_payments", "admin_confirm_bank_payment",
                 "admin_financial_report", "admin_set_payment_logo", "admin_list_payout_requests", "admin_review_payout_request"]
        leaks, blocked_owner = [], []
        for a in money:
            s, r = call(a, {}, token=limited)
            if not (s == 403 and r.get("success") is False):
                leaks.append((a, s, str(r)[:90]))
            s, r = call(a, {}, token=owner)
            if s == 403:
                blocked_owner.append((a, s, str(r)[:90]))
        check("a limited staff admin is refused every money action", not leaks, leaks)
        check("the owner admin is not refused by the permission check", not blocked_owner, blocked_owner)

    print("2. Unrestricted file upload")
    s, r = upload(cust, png_bytes(), "photo.png", "image/png")
    check("a real PNG is accepted", r.get("success") is True, (s, r))
    s, r = upload(cust, b"<?php echo 'x'; ?>", "shell.jpg", "image/jpeg")
    check("a PHP script named .jpg is refused", r.get("success") is False, (s, r))
    s, r = upload(cust, b"<?php echo 'x'; ?>", "shell.php", "image/png")
    check("a .php file sent as image/png is refused", r.get("success") is False, (s, r))
    s, r = upload(cust, b"GIF89a<?php system($_GET['c']); ?>", "x.gif", "image/gif")
    check("GIF89a followed by PHP is refused", r.get("success") is False, (s, r))
    s, r = upload(cust, png_bytes() + b"\n<?php system($_GET['c']); ?>", "x.png", "image/png")
    check("a PNG with PHP appended is refused", r.get("success") is False, (s, r))
    s, r = upload(cust, b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "x.svg", "image/svg+xml")
    check("an SVG with a script is refused", r.get("success") is False, (s, r))

    print("3. One person, one account")
    n = base + 2
    f = phone_forms(n)
    s, r = call("register", {"name": "Dup One", "phone": f[0], "password": "testpass123", "role": "customer"})
    check("first sign-up works", r.get("success") is True, r)
    for form in f[1:3]:
        s, r = call("register", {"name": "Dup Again", "phone": form, "password": "testpass123", "role": "customer"})
        check("the same number written %s is refused" % form, r.get("success") is False, (s, r))
    s, r = call("admin_create_user", {"name": "Dup Admin Path", "phone": f[1], "password": "testpass123", "role": "customer"}, token=owner)
    check("the admin create-user path refuses it too", r.get("success") is False, (s, r))
    s, r = call("admin_create_service_giver", {"name": "Dup Shop", "phone": f[2], "password": "testpass123", "business_name": "Dup Kitchen",
                                               "service_type": "food", "vehicle_type": "on_foot", "zone": "Mehal Ketema"}, token=owner)
    check("the admin create-shop path refuses it too", r.get("success") is False, (s, r))

    m = base + 3
    forms = phone_forms(m) * 2
    results = []

    def go(form, i):
        s2, r2 = call("register", {"name": "Race %d" % i, "phone": form, "password": "testpass123", "role": "customer"})
        results.append(r2.get("success") is True)
    threads = [threading.Thread(target=go, args=(form, i)) for i, form in enumerate(forms)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    check("8 sign-ups for one number at the same moment: at most one succeeds", sum(results) <= 1, results)
    ok, out, err = etel_test_db.query_text("SELECT COUNT(*) FROM users WHERE RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''),9)='9%08d'" % m)
    if ok:
        check("...and exactly one account exists for that number", out.strip() == "1" or (out.strip() == "0" and sum(results) == 0), out)
    else:
        print("  SKIP could not count accounts: " + err)

    print("\n%d passed, %d failed" % (len(PASS), len(FAIL)))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
