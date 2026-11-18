# Checkout & Billing API Optimisation

Job `rkGmI2UioRXvzqgpCOEH`: find the checkout database bottlenecks, add non-destructive indexes, refactor the PHP 8.3 endpoints securely, and measure p95 latency against the < 180 ms target.

| Deliverable | Description |
|---|---|
| [Bottleneck_Analysis.pdf](Bottleneck_Analysis.pdf) | Q1–Q3 slow paths, EXPLAIN evidence, index choices, risks, before/after numbers |
| [Technical_Change_Summary.pdf](Technical_Change_Summary.pdf) | Code changes, compatibility, security protections, observed results |
| [optimize.sql](optimize.sql) | Idempotent, non-destructive index migration + rewritten read queries |
| [refactored-php/](refactored-php/) | Refactored endpoints (PSR-12), unit tests, least-privilege DB user, [README](refactored-php/README.md) |
| [EXPLAIN_Before_After.txt](EXPLAIN_Before_After.txt) | Actual EXPLAIN / EXPLAIN ANALYZE output, before and after |
| [Performance_Benchmarks.csv](Performance_Benchmarks.csv) | All latency samples, p50/p95 summaries, tests not run |
| [Test_Results.csv](Test_Results.csv) | Contract, unit, PSR-12, security, migration and equivalence checks |
| [EXPLAIN_And_Latency_Log.csv](EXPLAIN_And_Latency_Log.csv) | Filled EXPLAIN/latency log template |
| [benchmark-tools/](benchmark-tools/) | Scripts used to produce every number |
| [COMPLETION_NOTE.txt](COMPLETION_NOTE.txt) | Index, final validation status, limitations |

**Headline results** (local, PHP 8.3.36 + MySQL 8.0.46): contract cases 13/21 → 21/21; p95 < 180 ms met; on a synthetic 500k-order dataset `/api/paid-orders` p95 went from 65.2 ms to 0.87 ms, and Q3 went from 66 ms to 0.09 ms. See the completion note for limitations (no Docker run, no load test).
