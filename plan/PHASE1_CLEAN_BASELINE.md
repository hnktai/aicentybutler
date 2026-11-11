# Phase 1 - Clean Baseline and Release Control

**Status:** in progress
**Production decision:** NO-GO; Phase 0 payment hold remains active.

## Observed production baseline

- Checkout: `/opt/goclaw-aicenty`, branch `fork-current`.
- Commit: `22e71c807fe636756d788cf5ce330ddb123a6717`.
- Runtime binary reports `goclaw dev (protocol 3)`.
- Runtime binary SHA-256: `a8cace3a2602af43189cc13181cd8583673f85938964d39e475664a3bb7bed74`.
- Runtime image ID: `sha256:2938d3b7585e78a18595a7c67844d1ae62b0696c71ce0d9f09092576389affd8`.
- PostgreSQL schema: `99`, migration state clean at audit time.
- The runtime binary cannot be proven to come from the checkout commit or its
  current dirty diff. It is not an acceptable release artifact.

## Candidate source policy

The candidate starts from the committed `fork-current` revision. Production
changes are reviewed rather than copied wholesale.

Included for verification:

- Tenant context propagation in managed resolver and prompt preview.
- Tenant ID propagation in cron outbound delivery.
- Relative and Vietnam-time cron schedule parsing with unit tests.
- Phase 0 health monitor, webhook hold, backup scripts, and runbooks.
- Dedicated deployment templates that require immutable image references.

Excluded from the candidate:

- Cross-channel fallback to any running channel; it can route across tenants.
- A user-facing error fallback with a hardcoded chat ID and personal name.
- Tool-loop thresholds changed from `8/12` to `3/4` without product evaluation.
- LLM-provided delivery recipient overriding the authenticated chat context.
- Zalo prompt annotations and retry-message behavior without privacy/evaluation evidence.
- Mutable `latest` image deployment and the writable binary bind mount.
- Runtime data, logs, caches, built UI output, binaries, `.bak` files, test-phone
  helpers, one-off sync scripts, and direct build/restart scripts.

The original staged and unstaged production patches are retained outside the
candidate checkout for audit. The live production checkout is unchanged by this
curation.

## Ownership boundaries

### Release owned

- Go source, web source, migrations, default templates, scripts, systemd/Caddy/
  Compose templates, compatibility matrix, and release manifest schema.

### Customer owned

- PostgreSQL rows, Zalo credentials/sessions, messages, memories, tenant/user
  profiles, customer context, media, OAuth data, task history, and secrets.

### Merge managed

- Environment configuration, enabled providers/channels, plan limits, allowed
  origins, retention settings, and customer overrides. Customer values survive
  release switches unless an explicit migration transforms them.

## Exit gates

1. Candidate worktree is clean and committed.
2. `go fix`, Go builds, unit tests, vet, and tenant invariant tests pass.
3. Python, shell, Compose, Caddy, and systemd templates validate.
4. Candidate binary embeds product version and commit SHA; manifest matches its
   SHA-256 and schema/protocol versions.
5. A disposable staging deployment starts from immutable image references.
6. Restore and rollback rehearsals pass before any production release switch.
7. Laravel custom source obtains equivalent Git provenance; until then Phase 1
   cannot be marked complete.

## Surface parity

- Gateway server: affected by tenant context and cron scheduling changes.
- API contract: cron accepts additional schedule forms; existing `atMs` remains.
- Web UI: N/A in this candidate; no UI contract changes.
- CLI/runtime package: build/version/manifest and deployment templates affected.
