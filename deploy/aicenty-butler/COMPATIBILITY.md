# Aicenty Butler compatibility matrix

| Component | Baseline | Target requirement |
|---|---|---|
| Ubuntu | 24.04 LTS | 24.04 LTS |
| GoClaw protocol | 3 | 3 |
| PostgreSQL schema | 99 | 99; migration state clean |
| PostgreSQL image | pgvector PostgreSQL 16 | Immutable digest; restore tested |
| Redis | 7 Alpine | Immutable digest |
| Caddy | Containerized | Validated config and rollback copy |
| Product tier | Standard multi-tenant | Tenant invariants pass |

The production binary observed before Phase 1 reported `goclaw dev`; its source
revision could not be proven. A release candidate must embed its version and
commit SHA and must not reuse that binary as provenance evidence.
