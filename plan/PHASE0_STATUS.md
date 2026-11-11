# Butler Phase 0 - Emergency Containment Status

**Updated:** 12/11/2026, Asia/Ho_Chi_Minh
**Decision:** Containment active. Paid checkout and new registration remain paused. Production launch remains NO-GO.

## Deployed

| Control | Live result |
|---|---|
| Public Butler payment webhooks | `503` on both `aicenty.com` and `butler.aicenty.com` |
| Public onboarding, family member, dedicated SIM, payment status, tenant lookup | `403` |
| New purchase and registration POST | `503` |
| Landing and QR payment page | Landing shows pause notice; payment page `503` |
| Shared gateway token | Removed from new customer links, rotated in engine; old token rejected |
| DB and Redis passwords | Rotated; old passwords rejected; Laravel/engine/daemon connect with new values |
| Hardcoded credentials | Removed from active Butler mailer, Python daemons, Laravel DB config, and generic SePay PHP service |
| GitHub PAT on server | Removed from remote URL and runtime environment; external revocation pending |
| Customer and shared agent evolution | `542` agent flags disabled; original values saved in `butler_phase0_rollback_20261111` |
| Unready dedicated SIM | One SIM disabled; four delivery cron jobs disabled; original flags saved |
| Zalo watchdog | Now reads live `channels.status` RPC and reports degraded; no DB update, Redis publish, or engine restart loop |
| Deep health | `/healthz` returns `503 degraded` while `butlerguru` is not running |
| Immediate backup | New PostgreSQL custom dump plus session/media volume archives; checksums verified |
| Scheduled backup | Missing `backup-db.sh` and `backup-local-db.sh` restored and exercised successfully |
| SSH access | Added key-only `aicenty-admin` with sudo; disabled password and keyboard-interactive authentication; root password hash removed; root SSH restricted to public keys |

## Verification

- `aicenty.com/` and `butler.aicenty.com/` still return `200`.
- `aicenty.com/butler` shows the pause notice; payment page returns `503`.
- Anonymous local POST to the SePay daemon returns `401`; even a request with the newly configured internal key returns `503` while `BUTLER_PAYMENTS_ENABLED` is unset.
- Anonymous public Butler mutation returns `403`; public webhook returns `503`.
- Engine/Postgres/Redis containers are running and healthy; PHP-FPM and both Python units are active.
- Laravel query against the Butler PostgreSQL connection succeeded after DB password rotation.
- Old DB, Redis, and gateway credentials were rejected.
- All live agent `self_evolve` and `skill_evolve` flags are false; no cron remains enabled for a Zalo SIM without credentials.
- The backup script produced a valid PostgreSQL dump and SHA-256 verified archives.
- A fresh SSH session authenticated as `aicenty-admin` with ED25519 key fingerprint `SHA256:zxD7+fFe69mENKMI+XOZ/kK13nNM21JXRBXgPf3Qg/8`; passwordless sudo returned root. A password-only root login was rejected.

## Pending External Coordination

1. Revoke GitHub PAT ending `****KTjO` in the `hnktai/aicentybutler` GitHub account. It was embedded in the prior remote URL and environment. Do not reuse it.
2. Revoke SePay legacy API key ending `****HKOS` in the SePay dashboard. The new inbound key is root-readable on the server at `/etc/aicenty-butler/credentials.env` as `BUTLER_SEPAY_INBOUND_KEY`. If SePay issues its own key, update that server value through a controlled rotation instead of sharing the key in chat.
3. Confirm the actual SePay webhook endpoint, authentication scheme, retry policy, and account binding against the provider dashboard before opening any route.

## Remaining Blockers Before Unpausing Payments

- The Laravel Butler webhook still contains the old non-atomic fulfillment path. Caddy blocks it; do not bypass the block.
- The Python daemon has `BUTLER_PAYMENTS_ENABLED` defaulting to off. This must remain off until the canonical billing ledger, amount/account validation, idempotent fulfillment, retry, and reconciliation pass staging tests.
- New SePay credential must be installed and tested in a disposable environment before public webhook access is restored.
- `butlerguru` is still not running. The deep health endpoint intentionally reports degraded.
- Backup is on the same host. Offsite copy and full restore drill are not complete.
- Laravel source and the GoClaw binary still lack clean release provenance.
- Household RBAC, privacy, consent, usage caps, and child-safety gates remain unresolved.

## Rollback References

- Previous Caddy config: `/root/.caddy/Caddyfile.pre-phase0-20261111`.
- Pre-change PHP copies: `/root/.caddy/ButlerTrialMailer.pre-phase0-20261111.php`, `/root/.caddy/database.pre-phase0-20261112.php`, `/root/.caddy/SepayService.pre-phase0-20261112.php`. These contain obsolete secrets and must not be restored without credential review.
- Previous environment files: `/etc/aicenty-butler/pre-rotation-20261112/` (root only). Their old passwords and tokens are invalid or compromised.
- Prior agent, cron, and channel flags: PostgreSQL table `butler_phase0_rollback_20261111`.
- Fresh database dump: `/opt/backups/aicenty-butler-phase0-20261112/butler-db.dump`.
- Scheduled backup directory: `/opt/backups/aicenty-butler-scheduled/`.
- SSH hardening: `/etc/ssh/sshd_config.d/00-aicenty-hardening.conf`; admin sudo policy: `/etc/sudoers.d/aicenty-admin`.

The Caddy hold is a security control. Reverting to the previous Caddy config would reopen unauthenticated payment and mutation routes.
