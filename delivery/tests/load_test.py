#!/usr/bin/env python3
"""
ETEL Delivery & Ride - a simple load test (Python 3 standard library only, nothing to install).

    python3 tests/load_test.py --base http://127.0.0.1:8765 --users 25 --seconds 30
    python3 tests/load_test.py --base http://127.0.0.1:8765 --users 5 --seconds 30 --flow ride --allow-writes

What it does: --users people (threads) keep asking the server for things for --seconds seconds, then it prints how many requests per
second the server handled, how long they took (median, slowest 5 percent, slowest 1 percent), and how many failed.

Mixes (--flow):
  browse   (default) what every app does on opening and while idle: the app pages, the cities, quick zones, the config, the health check. Read only.
  ride     a rider: sign up once, then estimate a fare, book a ride, look at it, cancel it, over and over. WRITES rows. Throwaway test database only.

SAFETY
  * Against anything that is not 127.0.0.1 / localhost it refuses to start unless you add --live, and then it caps at 20 users and
    refuses --flow ride. Run it on the live site only OFF PEAK, for a short time, starting small (5 users, 20 seconds), with cPanel > Resource Usage open.
  * It never sends anything to payment, SMS or push endpoints.
Numbers from your own computer say how much work the SOFTWARE needs per request. Your HOSTING plan (how many PHP processes and how much CPU you are
allowed on a shared server) is a separate limit that only a run against the live site shows.
"""
import argparse
import json
import random
import statistics
import sys
import threading
import time
import urllib.error
import urllib.request
from collections import Counter, defaultdict
from urllib.parse import urlparse

BROWSE = [
    ("app page: ride", "GET", "/ride/index.html", None, 3),
    ("app page: customer", "GET", "/index.html", None, 2),
    ("cities", "GET", "/api/boot.php?action=get_service_areas", None, 4),
    ("quick zones", "GET", "/api/boot.php?action=get_area_places&area=hawassa", None, 4),
    ("config", "GET", "/api/boot.php?action=get_config", None, 3),
    ("ping", "GET", "/api/boot.php?action=ping", None, 2),
    ("health", "GET", "/api/health.php", None, 1),
]


def request(base, method, path, body=None, token=None, timeout=30):
    headers = {"Accept": "application/json"}
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as f:
            raw = f.read()
            return f.status, time.time() - t0, raw
    except urllib.error.HTTPError as e:
        try:
            raw = e.read()
        except Exception:
            raw = b""
        return e.code, time.time() - t0, raw
    except Exception:
        return 0, time.time() - t0, b""


class Stats:
    def __init__(self):
        self.lock = threading.Lock()
        self.lat = defaultdict(list)       # every request that got an answer
        self.ok = defaultdict(list)        # only the ones answered 200 (the real work, not refusals)
        self.codes = defaultdict(Counter)

    def add(self, name, status, secs):
        with self.lock:
            self.lat[name].append(secs)
            if status == 200:
                self.ok[name].append(secs)
            self.codes[name][status] += 1


def worker_browse(base, stats, stop, rnd):
    weights = [e[4] for e in BROWSE]
    while time.time() < stop:
        name, method, path, body, _ = rnd.choices(BROWSE, weights=weights)[0]
        status, secs, _ = request(base, method, path, body)
        stats.add(name, status, secs)


def worker_ride(base, stats, stop, rnd, idx):
    phone = "+251948%06d" % ((int(time.time()) + idx * 7919) % 1000000)
    st, secs, raw = request(base, "POST", "/api/boot.php", {"action": "register", "name": "Load %d" % idx, "phone": phone, "password": "test1234", "role": "customer"})
    stats.add("sign up", st, secs)
    try:
        token = json.loads(raw).get("data", {}).get("token")
    except Exception:
        token = None
    if not token:
        return
    while time.time() < stop:
        km = round(rnd.uniform(1.5, 9), 1)
        st, secs, raw = request(base, "POST", "/api/boot.php", {"action": "ride_estimate_fare", "distance_km": km, "vehicle_type": "bajaj"}, token)
        stats.add("estimate fare", st, secs)
        try:
            qt = json.loads(raw).get("data", {}).get("quote_token")
        except Exception:
            qt = None
        st, secs, raw = request(base, "POST", "/api/boot.php", {"action": "book_ride", "pickup_lat": 7.05, "pickup_lng": 38.49, "dropoff_lat": 7.09, "dropoff_lng": 38.52, "payment_method": "cash", "quote_token": qt}, token)
        stats.add("book ride", st, secs)
        if st == 429:                      # the server says "slow down": a real rider waits, so do we
            time.sleep(3)
        try:
            rid = json.loads(raw).get("data", {}).get("request_id")
        except Exception:
            rid = None
        st, secs, _ = request(base, "GET", "/api/boot.php?action=my_active_ride", None, token)
        stats.add("my active ride", st, secs)
        if rid:
            st, secs, _ = request(base, "POST", "/api/boot.php", {"action": "cancel_ride", "id": rid}, token)
            stats.add("cancel ride", st, secs)


def pct(values, p):
    if not values:
        return 0.0
    v = sorted(values)
    return v[min(len(v) - 1, int(round(p / 100.0 * (len(v) - 1))))]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", default="http://127.0.0.1:8765", help="site root, e.g. http://127.0.0.1:8765 or https://ethelmarket.com/delivery")
    ap.add_argument("--users", type=int, default=10)
    ap.add_argument("--seconds", type=int, default=30)
    ap.add_argument("--flow", choices=["browse", "ride"], default="browse")
    ap.add_argument("--allow-writes", action="store_true", help="needed for --flow ride (it creates users and rides)")
    ap.add_argument("--live", action="store_true", help="allow a run against a non-local address (caps users at 20, browse only)")
    a = ap.parse_args()

    base = a.base.rstrip("/")
    host = urlparse(base).hostname or ""
    local = host in ("127.0.0.1", "localhost", "::1")
    if not local:
        if not a.live:
            print("REFUSED: %s is not a local address. Add --live to test a real site (browse only, at most 20 users, off peak)." % host)
            return 2
        if a.flow != "browse":
            print("REFUSED: --flow %s writes data and is only for a local throwaway database." % a.flow)
            return 2
        if a.users > 20:
            print("REFUSED: at most 20 users against a real site.")
            return 2
    if a.flow == "ride" and not a.allow_writes:
        print("REFUSED: --flow ride creates users and rides. Add --allow-writes (throwaway database only).")
        return 2

    # one gentle request first: is the site there at all?
    st, secs, _ = request(base, "GET", "/api/boot.php?action=ping")
    if st != 200:
        print("The site did not answer a ping (HTTP %s) at %s. Nothing to test." % (st, base))
        return 2

    stats = Stats()
    stop = time.time() + a.seconds
    threads = []
    for i in range(a.users):
        rnd = random.Random(1000 + i)
        t = threading.Thread(target=(worker_browse if a.flow == "browse" else worker_ride), args=((base, stats, stop, rnd) if a.flow == "browse" else (base, stats, stop, rnd, i)), daemon=True)
        threads.append(t)
    t0 = time.time()
    for t in threads:
        t.start()
    for t in threads:
        t.join(timeout=a.seconds + 60)
    wall = time.time() - t0

    total = sum(len(v) for v in stats.lat.values())
    allv = [x for v in stats.lat.values() for x in v]
    bad = sum(n for name in stats.codes for code, n in stats.codes[name].items() if code == 0 or code >= 500)
    expected_non_200 = ("my active ride",)
    print("\nLoad test: %s flow, %d users, %.0f s, against %s" % (a.flow, a.users, wall, base))
    print("  requests: %d   per second: %.1f   failed (no answer or HTTP 5xx): %d (%.2f%%)" % (total, total / wall if wall else 0, bad, 100.0 * bad / total if total else 0))
    print("  time per request:  median %.0f ms   slowest 5%%: %.0f ms   slowest 1%%: %.0f ms   slowest: %.0f ms" % (
        1000 * statistics.median(allv) if allv else 0, 1000 * pct(allv, 95), 1000 * pct(allv, 99), 1000 * max(allv) if allv else 0))
    print("\n  %-22s %7s %9s %9s %9s  %s" % ("what", "count", "median", "p95", "slowest", "answers (HTTP code x count)"))
    for name in sorted(stats.lat):
        v = stats.ok[name] or stats.lat[name]      # timings of the real work (HTTP 200); refusals are counted in "answers"
        codes = ", ".join("%s x%d" % (c if c else "none", n) for c, n in sorted(stats.codes[name].items()))
        print("  %-22s %7d %7.0f ms %7.0f ms %7.0f ms  %s" % (name, len(stats.lat[name]), 1000 * statistics.median(v), 1000 * pct(v, 95), 1000 * max(v), codes))
    verdict = "OK"
    if total == 0 or bad / float(total) > 0.01:
        verdict = "PROBLEM: more than 1 in 100 requests failed"
    elif pct(allv, 95) > 2.0:
        verdict = "SLOW: 1 in 20 requests took more than 2 seconds"
    print("\n  verdict: %s" % verdict)
    return 0 if verdict == "OK" else 1


if __name__ == "__main__":
    sys.exit(main())
