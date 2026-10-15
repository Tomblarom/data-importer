# BKB importer deployment

This deploys the BKB derivative image of the Firefly III data-importer
alongside an existing Firefly stack. It never modifies the live stack or
its services: add the importer as a new service, keep everything else
as it is.

## Image

- Registry: `ghcr.io/tomblarom/data-importer`
- Tags: `:bkb` (moving) and `:sha-<full-sha>` (immutable per build).
- Base: `fireflyiii/data-importer:version-2.3.5`, pinned by digest (see
  [Dockerfile](/Dockerfile)); the base already holds the exact v2.3.5 PHP
  dependencies. The derivative only overlays `app/`, `config/`,
  `routes/` and `resources/views/`, ensures
  `storage/import-receipts` and `storage/import-jobs` exist owned by
  `www-data`, and clears compiled config/route/view caches (never
  `storage/framework/sessions`, so runtime user sessions survive).
- OCI labels record `org.opencontainers.image.source` and
  `org.opencontainers.image.revision` for every build.

## Environment

| Variable | Purpose |
|---|---|
| `FIREFLY_III_URL` | Base URL of Firefly III. |
| `FIREFLY_III_ACCESS_TOKEN` | Firefly personal access token. |
| `BKB_BOT_URL` | Base URL of the private browser service (for example `http://bkb-bot:8080`). Never exposed to browsers. |
| `BKB_API_TOKEN` / `BKB_API_TOKEN_FILE` | Internal Bearer [REDACTED] for the bot API. The `_FILE` variant wins when readable. |
| `BKB_ADMIN_PASSWORD_HASH` / `BKB_ADMIN_PASSWORD_HASH_FILE` | `password_hash()` of the BKB admin password. Empty disables BKB management entirely (fail closed). |

Generate the admin hash with:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

No `.env` is baked into the image; all secrets arrive at runtime.

## Volumes and compose

Persist both durable stores in named volumes. Example additive service
for an existing Firefly compose file — add this block only, change no
existing service:

```yaml
services:
  bkb-importer:
    image: ghcr.io/tomblarom/data-importer:bkb
    restart: unless-stopped
    env_file: .bkb-importer.env
    networks:
      - firefly_iii
      - bkb-private
    volumes:
      - bkb_import_receipts:/var/www/html/storage/import-receipts
      - bkb_import_jobs:/var/www/html/storage/import-jobs

volumes:
  bkb_import_receipts:
  bkb_import_jobs:
```

Receipt records hold only job identifiers, status, counts and a payload
fingerprint — never transaction contents. Keep the volumes across
redeploys; losing `bkb_import_receipts` loses idempotency for automated
uploads (see [import-upload-receipts](/docs/import-upload-receipts.md)).

## Deploy and rollback

1. Pin the immutable tag or digest in compose (check the CI digest
   summary on the `deploy/bkb` push build), e.g.
   `image: ghcr.io/tomblarom/data-importer:sha-<full-sha>`.
2. `docker compose up -d bkb-importer`.
3. Verify `/health` answers `OK` and `/api/import-flows` lists the
   `bkb` key.

Rollback: set the image back to the previous `:sha-…` tag or digest
(recorded in the earlier CI summary) and `docker compose up -d` again.
The named volumes keep receipts and jobs across the rollback.

## Verification

Before any push, CI builds the local derivative image and runs
[scripts/check_image.sh](/scripts/check_image.sh), which boots the image
with throwaway credentials, checks `/health`, the public import-flows
entry, the `/bkb/frame` → `/bkb/login` redirect (and 403 with no admin
hash), the login page render, in-container `php -l`, and
`php artisan route:list --path=bkb`, then removes its own containers
and volumes. Run it locally the same way:

```bash
./scripts/check_image.sh ghcr.io/tomblarom/data-importer:sha-<full-sha>
```

Pushes to GHCR happen only on `push` to `deploy/bkb` after green
validation; pull requests validate and smoke without pushing. There are
no scheduled image workflows.

## Notes

- Full browser setup against a real bot service is tested separately by
  the coordinator; the image smoke test deliberately points at
  unreachable loopback URLs and never calls real banks.
- Never run the smoke script or a test importer against the live stack
  volumes; it uses throwaway volumes only.
