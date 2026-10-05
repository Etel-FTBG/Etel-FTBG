"""
ETEL Delivery -- small load test (stdlib only).

Registers a handful of throwaway users, then hammers the hot endpoints with N
concurrent workers and prints requests/sec, error rate and p50/p95/p99 latency
per endpoint. Use it to find the breaking point BEFORE launch day.

    python3 tests/load_test.py --base https://staging.example.com --workers 20 --seconds 30

!! Run it against STAGING (see STAGING_GUIDE.md) or a local `php -S`, never against
!! the live site: it creates real users/rows and, at high worker counts, behaves
!! like a small denial-of-service on a shared host.
Local `php -S` numbers are only useful for comparing one change against another.
"""
import argparse
import json
import random
import statistics
import threading
import time
import urllib.error
import urllib.request


def post(base, action, body=None, token=None, timeout=20):
    b = dict(body or {})
    b["action"] = action
    h = {"Content-Type": "application/json"}
    if token:
        h["Authorization"] = "Bearer " + token
    req = urllib.request.Request(base + "/api/api.php", json.dumps(b).encode(), h)
    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as f:
            code, raw = f.status, f.read()
    except urllib.error.HTTPError as e:
        code, raw = e.code, e.read()
    except Exception:
        return 0, None, time.perf_counter() - t0
    try:
        data = json.loads(raw)
    except Exception:
        data = None
    return code, data, time.perf_counter() - t0


def register(base, role, i):
    phone = "+2519%08d" % random.randint(0, 99999999)
    code, d, _ = post(base, "register", {"name": "Load %s %d" % (role, i), "phone": phone,
                                         "password": "LoadTest!234", "role": role})
    if not d or not d.get("success"):
        return None
    return d["data"]["token"]


def pct(vals, p):
    if not vals:
        return 0.0
    vals = sorted(vals)
    return vals[min(len(vals) - 1, int(len(vals) * p / 100))] * 1000


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8765")
    ap.add_argument("--workers", type=int, default=10)
    ap.add_argument("--seconds", type=int, default=20)
    a = ap.parse_args()
    base = a.base.rstrip("/")

    print("Preparing %d throwaway customers..." % a.workers)
    tokens = [t for t in (register(base, "customer", i) for i in range(a.workers)) if t]
    if not tokens:
        raise SystemExit("could not register test users (rate limited? wrong --base?)")

    # (name, action, body-factory, needs_token, weight)
    mix = [
        ("login (wrong pw)", "login", lambda: {"phone": "+251900000000", "password": "x"}, False, 1),
        ("my orders", "customer_get_orders", lambda: {}, True, 5),
        ("profile", "get_profile", lambda: {}, True, 5),
        ("ride fare estimate", "ride_estimate_fare", lambda: {"distance_km": 4.2, "vehicle_type": "bajaj"}, True, 3),
    ]
    stats = {m[0]: {"lat": [], "ok": 0, "err": 0} for m in mix}
    lock = threading.Lock()
    stop = time.time() + a.seconds
    weighted = [m for m in mix for _ in range(m[4])]

    def worker(tok):
        while time.time() < stop:
            name, action, body, needs, _ = random.choice(weighted)
            code, d, dt = post(base, action, body(), tok if needs else None)
            good = code in (200, 400, 401, 409, 429) and code != 0   # app-level answers are fine
            with lock:
                s = stats[name]
                s["lat"].append(dt)
                s["ok" if good else "err"] += 1

    th = [threading.Thread(target=worker, args=(t,)) for t in tokens]
    t0 = time.time()
    [t.start() for t in th]
    [t.join() for t in th]
    el = time.time() - t0

    print("\n%-20s %7s %6s %7s %7s %7s %7s" % ("endpoint", "reqs", "err", "p50ms", "p95ms", "p99ms", "rps"))
    total = 0
    for name, s in stats.items():
        n = len(s["lat"])
        total += n
        print("%-20s %7d %6d %7.0f %7.0f %7.0f %7.1f" % (
            name, n, s["err"], pct(s["lat"], 50), pct(s["lat"], 95), pct(s["lat"], 99), n / el))
    print("\nTotal %d requests in %.1fs = %.1f req/s with %d workers" % (total, el, total / el, len(tokens)))
    print("Rule of thumb: p95 under ~800ms and 0 errors at your expected peak = comfortable.")


if __name__ == "__main__":
    main()
