#!/usr/bin/env python3
"""HTTP latency benchmark per TECHNICAL_SPECIFICATION: 5 warmups + 30 measured requests per endpoint,
repeated for N runs. Appends every measured sample to a CSV. p95 = nearest-rank (29th of 30 sorted),
the same index (lat[28]) the supplied tests/benchmark.py uses.
Usage: http_bench.py <out.csv> <phase> <environment> [runs]"""
import csv, json, math, os, statistics, sys, time, urllib.request

out_csv, phase, env = sys.argv[1], sys.argv[2], sys.argv[3]
runs = int(sys.argv[4]) if len(sys.argv) > 4 else 3
token = os.environ.get("API_ACCESS_TOKEN", "local_testing_only_change_me")
base = "http://127.0.0.1:8087"
endpoints = {"checkout": "/api/checkout?order_id=150", "paid-orders": "/api/paid-orders?date=2026-10-15"}

def hit(path):
    req = urllib.request.Request(base + path, headers={"X-API-Token": token})
    t0 = time.perf_counter()
    with urllib.request.urlopen(req, timeout=5) as r:
        body = r.read(); status = r.status
    ms = (time.perf_counter() - t0) * 1000
    return status, ms, json.loads(body)

def p95(xs):
    s = sorted(xs); return s[math.ceil(0.95 * len(s)) - 1]

new = not os.path.exists(out_csv)
summary = []
with open(out_csv, "a", newline="") as f:
    w = csv.writer(f)
    if new:
        w.writerow(["test_id", "endpoint", "phase", "iteration", "http_status", "elapsed_ms", "environment", "notes"])
    for name, path in endpoints.items():
        for run in range(1, runs + 1):
            for _ in range(5):
                hit(path)  # warmups, not recorded
            lat = []
            for i in range(1, 31):
                status, ms, data = hit(path)
                lat.append(ms)
                srv = data.get("duration_ms")
                w.writerow([f"{phase}-{name}-r{run}", path, phase, i, status, f"{ms:.3f}", env, f"run {run}; server duration_ms={srv}"])
            summary.append({"phase": phase, "endpoint": name, "run": run, "n": len(lat),
                            "p50_ms": round(statistics.median(lat), 3), "p95_ms": round(p95(lat), 3),
                            "max_ms": round(max(lat), 3), "mean_ms": round(statistics.mean(lat), 3)})
print(json.dumps(summary, indent=1))
