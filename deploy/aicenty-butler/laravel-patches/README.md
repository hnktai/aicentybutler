# Aicenty Laravel deployment patches

The production Laravel application is deployed separately from this GoClaw
checkout. These patches record release-owned integration changes that must be
applied to the matching Laravel source before deployment.

## Standard Butler skill provisioning

`20261113-standard-skill-provisioning.patch` adds an idempotent provisioning
step to all three tenant creation and upgrade paths in
`app/Services/ButlerAutomationService.php`.

The step:

- validates that the target agent belongs to the tenant and has
  `agent_key=aicenty-master-butler`;
- grants the canonical system PDF skills directly;
- creates or updates tenant-local catalog rows for the Quangia image and mobile
  messaging skills, referencing their canonical release-owned files;
- upserts grants on `(skill_id, agent_id)` and pins the current standard
  version;
- places all four skill slugs in `other_config.pinned_skills` so they are
  inlined in the agent prompt, and explicitly enables image generation;
- normalizes dedicated Zalo channel defaults and enables verified-customer
  friend-request acceptance without overwriting credentials;
- verifies that all four skills are present before returning.

Apply only to the matching Laravel baseline:

```bash
git apply --check deploy/aicenty-butler/laravel-patches/20261113-standard-skill-provisioning.patch
git apply deploy/aicenty-butler/laravel-patches/20261113-standard-skill-provisioning.patch
php -l app/Services/ButlerAutomationService.php
```

Run `verify-standard-skill-provisioning.php` as the Laravel service user. The
verification creates a synthetic tenant, agent, and Zalo channel inside a
database transaction, runs provisioning twice, repairs a deliberately stale
pin, verifies prompt pins/tools/channel policy, and rolls the transaction back.
A passing run must end with
`"rollback_verified":true`.

Before production deployment, take a transaction-safe PostgreSQL dump and copy
the current Laravel source. Rollback restores the prior PHP file; restore the
database dump only if a database mutation escaped its transaction.
