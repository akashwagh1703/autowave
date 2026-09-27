# Product Specification

[`master-prompt.md`](master-prompt.md) is the canonical, version-controlled product and engineering
specification for AutoWave (copied from the original master development prompt on 2026-09-27).

- Treat it as requirements. Where the implementation deliberately deviates, record the decision in an ADR
  (`docs/12-decisions/`) and note it here.
- Summary for newcomers: [`../00-overview/product-overview.md`](../00-overview/product-overview.md).

## Recorded deviations from the master prompt

| Topic | Deviation | Reason | Reference |
|---|---|---|---|
| Redis client | `predis` instead of `phpredis` locally | phpredis extension is not readily available on Windows | ADR-009 |
| Horizon | Deferred | Requires `pcntl` (unavailable on Windows dev) | AW-001 |
| React Hook Form / Zod | Not installed yet | Added with the first real forms, per dependency policy | AGENTS.md §2 |
