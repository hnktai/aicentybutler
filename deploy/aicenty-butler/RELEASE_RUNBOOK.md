# Aicenty Butler release runbook

## Required inputs

- Clean, reviewed Git commit.
- Version, commit SHA, PostgreSQL schema version, and immutable image digest.
- Passing Go build, unit tests, tenant invariant tests, web lint/build, and
  Butler containment checks.
- Verified database and customer-state backup.
- A previous immutable release digest for rollback.

## Staging

1. Render Compose with secrets supplied from `/etc/aicenty-butler`; never place
   secret values in Git or build output.
2. Validate image labels and `/version` against the build manifest.
3. Restore a representative backup into a disposable environment.
4. Run schema, tenant, auth, channel, and representative agent workflow checks.
5. Keep payment processing disabled.

## Production switch

1. Drain active agent work.
2. Confirm backup freshness and checksum.
3. Pull the immutable digest and start it in a versioned staging slot.
4. Run required health and tenant checks.
5. Switch the active release only after all gates pass.

## Rollback

Switch back to the previous immutable digest and its compatible configuration.
Do not restore customer data unless a migration changed it and the rollback plan
explicitly requires the verified pre-deploy backup.

The Phase 0 Caddy payment hold remains active until the billing and identity
gates have separate approval.
