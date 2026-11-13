# Aicenty Laravel application

This directory is the source-controlled Laravel application deployed at
`/var/www/aicenty`.

Runtime state is not part of the release:

- secrets and environment files;
- Composer and Node dependency directories;
- logs, caches, sessions, uploads, generated media, and customer data;
- backups and prebuilt public assets.

Production releases must be built from a reviewed Git commit into a versioned
release directory. Deployment supplies secrets from the server, runs migrations
through the release transaction, and atomically updates the active release
pointer only after health and synthetic-tenant checks pass.
