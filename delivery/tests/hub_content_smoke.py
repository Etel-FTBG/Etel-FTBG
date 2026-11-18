"""
ETEL app look (names, icons, texts), through the real server: only an admin can upload an icon, the picture is checked (real image,
big enough, not too heavy), the owner saves it with the returned address, and a phone gets it from get_hub_apps.
Uses the seeded demo accounts (no sign-ups, so the shared register limit is not touched).

Needs the running API (php -S ... -t .) and a throwaway test database. Skips cleanly without a database.
"""
import json
import os
import struct
import sys
import time
import urllib.request
import zlib

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


def png(size):
    def ch(t, d):
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    raw = b"".join(b"\x00" + b"\xd4\xaf\x37" * size for _ in range(size))
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 2, 0, 0, 0)) + ch(b"IDAT", zlib.compress(raw)) + ch(b"IEND", b"")


def upload(token, kind, data, name="a.png", mime="image/png"):
    b = "----hub" + str(os.getpid()) + str(time.time())[-4:]
    body = (("--%s\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n%s\r\n--%s\r\nContent-Disposition: form-data; name=\"image\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n" % (b, kind, b, name, mime)).encode()
            + data + ("\r\n--%s--\r\n" % b).encode())
    req = urllib.request.Request(BASE + "upload.php", data=body, headers={"Content-Type": "multipart/form-data; boundary=" + b, "Authorization": "Bearer " + (token or "")}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode())
        except Exception:
            return e.code, {}


ok, why = etel_test_db.run_sql("SELECT 1")
if not ok:
    print("SKIP: no test database (%s)" % why)
    sys.exit(0)

print("hub content: icon upload and phone answer")
st, r = call("login", {"phone": "0900000000", "password": "admin1234"})
admin = (r.get("data") or {}).get("token")
st, r = call("login", {"phone": "+251911000001", "password": "etel1234"})
cust = (r.get("data") or {}).get("token")
if not (admin and cust):
    check("demo accounts can sign in", False, (bool(admin), bool(cust)))
    sys.exit(1)
etel_test_db.run_sql("DELETE FROM upload_attempts")

st, r = upload(cust, "hub_icon", png(200))
check("a customer cannot upload an app icon", st == 403, (st, r))
st, r = upload(None, "hub_icon", png(200))
check("nobody signed in cannot either", st == 401, st)
st, r = upload(admin, "hub_icon", png(64))
check("a tiny picture (64 px) is refused", st == 400 and "small" in str(r.get("message", "")), (st, r))
st, r = upload(admin, "hub_icon", b"<?php echo 1; ?>", "a.png", "image/png")
check("a script posing as a picture is refused", st == 400, (st, r))
st, r = upload(admin, "hub_icon", b"just some text", "a.png", "text/plain")
check("a text file is refused", st == 400, (st, r))
st, r = upload(admin, "hub_icon", png(200) + b"\x00" * (1024 * 1024 + 10))
check("an icon over 1 MB is refused", st == 400, (st, r))

st, r = upload(admin, "hub_icon", png(200))
url = (r.get("data") or {}).get("url", "")
check("an admin uploads a 200 px icon", st == 200 and "/uploads/hub/icon_" in url, (st, r))
if url:
    with urllib.request.urlopen(url.replace("https://", "http://") if url.startswith("https://") else url, timeout=15) as f:
        check("the picture is served as an image", f.status == 200 and f.headers.get("Content-Type", "").startswith("image/"), f.headers.get("Content-Type"))

    st, r = call("admin_hub_content_save", {"key": "ride", "title_en": "ETEL Ride", "title_am": "x", "desc_en": "Book a ride", "badge": "New", "sort_order": 2, "icon_url": url}, admin)
    check("the owner admin saves the look with that picture (same host as this server)", st == 200 and (r.get("success") is True), (st, r))
    st, r = call("get_hub_apps", {}, cust)
    c = ((r.get("data") or {}).get("content") or {}).get("ride") or {}
    check("a phone gets the name, label and icon", c.get("title_en") == "ETEL Ride" and c.get("badge") == "New" and c.get("icon_url") == url, c)
    st, r = call("admin_hub_content_save", {"key": "ride", "icon_url": ""}, admin)
    check("the icon can be removed", st == 200)

st, r = call("admin_hub_content_save", {"key": "ride", "badge": "x"}, cust)
check("a customer cannot save the look", st == 403, (st, r))
st, r = call("admin_hub_settings_save", {"settings": {"support_phone": "+251911234567", "support_telegram": "@etelsupport"}}, admin)
check("the owner admin saves the support contacts", st == 200 and r.get("success") is True, (st, r))
st, r = call("get_hub_apps", {}, cust)
t = (r.get("data") or {}).get("texts") or {}
check("a phone gets the support contacts", t.get("support_phone") == "+251911234567" and t.get("support_telegram") == "https://t.me/etelsupport", t)
call("admin_hub_settings_save", {"settings": {"support_phone": "", "support_telegram": ""}}, admin)
call("admin_hub_content_save", {"key": "ride", "title_en": "", "title_am": "", "desc_en": "", "badge": "", "sort_order": 0}, admin)

print("\n%d failed" % len(fails) if fails else "\nall passed")
sys.exit(1 if fails else 0)
