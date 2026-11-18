#!/usr/bin/env python3
"""Runs INPUT_FILES/tests/contract_cases.json (+ extra guideline cases) against a live server.
Writes one CSV row per case with the actual observed status/body. Usage: contract_tests.py <cases.json> <out.csv> <phase>"""
import csv, json, sys, urllib.request, urllib.error

cases_file, out_csv, phase = sys.argv[1], sys.argv[2], sys.argv[3]
spec = json.load(open(cases_file))
base, headers = spec["base_url"], spec["headers"]

# Extra cases required by PSR12_AND_TEST_GUIDELINES.txt that the JSON file does not cover.
extra = [
    {"id": "X01", "method": "GET", "path": "/api/checkout?order_id=150", "token": "wrong-token", "expected_status": 401},
    {"id": "X02", "method": "GET", "path": "/api/checkout", "expected_status": 400},
    {"id": "X03", "method": "GET", "path": "/api/checkout?order_id=-5", "expected_status": 400},
    {"id": "X04", "method": "GET", "path": "/api/checkout?order_id=1e3", "expected_status": 400},
    {"id": "X05", "method": "GET", "path": "/api/checkout?order_id=99999999999999999999", "expected_status": 400},
    {"id": "X06", "method": "GET", "path": "/api/paid-orders?date=2026-02-30", "expected_status": 400},
    {"id": "X07", "method": "GET", "path": "/api/paid-orders", "expected_status": 400},
    {"id": "X08", "method": "GET", "path": "/api/paid-orders?date=2026-10-15%27%20OR%20%271%27=%271", "expected_status": 400},
    {"id": "X09", "method": "GET", "path": "/api/paid-orders?date=2026-01-01", "expected_status": 200, "assertions": ["count 0"]},
    {"id": "X10", "method": "DELETE", "path": "/api/paid-orders?date=2026-10-15", "expected_status": 405},
    {"id": "X11", "method": "GET", "path": "/api/unknown", "expected_status": 404},
    {"id": "X12", "method": "GET", "path": "/api/checkout?order_id[]=150", "expected_status": 400},
]

def call(case):
    h = {} if case.get("omit_token") else dict(headers)
    if "token" in case:
        h["X-API-Token"] = case["token"]
    req = urllib.request.Request(base + case["path"], headers=h, method=case["method"])
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            return r.status, r.read().decode(), r.headers.get("Content-Type", "")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), e.headers.get("Content-Type", "")

rows, passed = [], 0
for case in spec["cases"] + extra:
    status, body, ctype = call(case)
    notes, ok = [], status == case["expected_status"]
    try:
        data = json.loads(body)
    except ValueError:
        data, ok = None, False
        notes.append("body is not JSON")
    if data is not None and status >= 400:
        if not (isinstance(data, dict) and isinstance(data.get("error"), str)):
            ok = False; notes.append("error JSON missing 'error' string")
    if "json" not in ctype:
        ok = False; notes.append(f"content-type {ctype!r}")
    if data is not None and status == 200 and "/api/checkout" in case["path"]:
        o = data.get("order", {})
        if case["id"] == "T01":
            if o.get("order_id") != 150 and str(o.get("order_id")) != "150":
                ok = False; notes.append("order.order_id != 150")
            if str(o.get("computed_paise")) != str(o.get("total_paise")):
                ok = False; notes.append("computed_paise != total_paise")
            notes.append(f"order_id={o.get('order_id')!r} total={o.get('total_paise')!r} computed={o.get('computed_paise')!r}")
    if data is not None and status == 200 and "/api/paid-orders" in case["path"]:
        notes.append(f"count={data.get('count')}")
        if "count 0" in case.get("assertions", []) and data.get("count") != 0:
            ok = False
    if case["id"] == "T09":
        notes.append("p95 assertion evaluated separately in Performance_Benchmarks.csv")
    passed += ok
    rows.append({
        "case_id": case["id"], "test_type": "contract" if case["id"].startswith("T") else "contract-extra",
        "input": f'{case["method"]} {case["path"]}' + (" (no token)" if case.get("omit_token") else "") + (" (wrong token)" if "token" in case else ""),
        "expected": case["expected_status"], "observed": status,
        "result": "PASS" if ok else "FAIL", "evidence_reference": f"phase={phase}; body={body[:160]}; " + "; ".join(notes),
    })
with open(out_csv, "w", newline="") as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0].keys())); w.writeheader(); w.writerows(rows)
for r in rows:
    print(f'{r["case_id"]:4} {r["input"][:62]:62} exp={r["expected"]} got={r["observed"]} {r["result"]}')
print(f"{phase}: {passed}/{len(rows)} passed")
