# InternPilih-Staging apply plan

No step in this document authorizes production access or changes. Stop before every
state-changing section until the user explicitly approves it.

## 1. Verified isolated staging target

The separate project and `supabase-staging` MCP connection have been verified:

- Project name: `InternPilih-Staging`
- Project ID: `zohcslnofeuisywqnjts`
- Verified `public` tables before migration: `0`
- MCP preflight access: read-only

The local staging helpers are pinned to that exact Project ID. A different project
reference or pooler username is rejected before PDO opens a network connection.
Do not use the production MCP connection at any stage of this plan.

## 2. Configure private local staging variables

`config/staging.local.php` has been created with the verified non-secret staging
identifiers and secret placeholders. Enter the remaining values privately, or set
the equivalent environment variables below. The file is ignored by Git and
excluded from Vercel uploads.

```text
INTERNPILIH_ENVIRONMENT=staging
INTERNPILIH_STAGING_PROJECT_REF
INTERNPILIH_STAGING_ADMIN_DSN
INTERNPILIH_STAGING_ADMIN_USER
INTERNPILIH_STAGING_ADMIN_PASSWORD
INTERNPILIH_STAGING_APP_DSN
INTERNPILIH_STAGING_APP_USER
INTERNPILIH_STAGING_APP_PASSWORD
INTERNPILIH_STAGING_TEST_PASSWORD
INTERNPILIH_STAGING_SUPABASE_URL
INTERNPILIH_STAGING_SUPABASE_STORAGE_SECRET
```

Use the Session pooler on port 5432 for the admin helper and the Transaction
pooler on port 6543 for `internpilih_staging_app`. Both DSNs require
`sslmode=require`. The app username is
`internpilih_staging_app.<STAGING_PROJECT_REF>`.

Before any network test, run the offline isolation check:

```text
php database/staging/test-config-isolation.php
```

## 3. Approval-gated staging migrations

Immediately before execution, use only `supabase-staging` to reconfirm Project ID
`zohcslnofeuisywqnjts` and that `public` is still empty. After explicit approval,
apply in this order to the staging project only:

1. `database/supabase/schema.sql` — 14 empty application tables, relationships,
   secure trigger function, and RLS.
2. `database/staging/002_serverless_storage.sql` — two serverless-state tables,
   six Storage metadata columns, private `documents` bucket, and staging marker.
3. `database/staging/003_security.sql` — revoke `anon`/`authenticated`, create the
   restricted `NOLOGIN` backend role, grants, and secure function path.
4. `database/staging/004_qa_profile_phone.sql` — add the nullable profile phone
   field required for partial profile updates without changing existing rows.
5. `database/staging/005_campus_schedule_password_verification.sql` — add campus,
   the March 2027 placement start, password-token purpose, and canonical cycle dates.
6. `database/staging/006_normalize_synthetic_accounts.sql` — normalize academic
   emails, phones, campuses, and unique six-digit coordinator staff IDs.
7. `php database/staging/enable-backend-role.php --execute` — privately set and
   verify the restricted role password.
8. `php database/staging/seed-synthetic.php --execute` — insert six fictional
   test identities and synthetic workflow records.

Do not run `post-import.sql` in staging; it is intended for migrated production
data. Do not copy production rows, password hashes, local documents, or backups.

Each SQL migration uses its own `BEGIN`/`COMMIT`, so a failure rolls back that
file. Earlier successful files remain applied; stop immediately and do not attempt
destructive cleanup without a separate review and approval. The role-enablement
helper restores the role to `NOLOGIN` with no password if connection verification
fails. The synthetic seed runs in one transaction and rolls back on any error.

## 4. Approval-gated verification

Run in this order:

```text
php database/staging/verify-staging.php
php database/staging/exercise-serverless-state.php --execute
php database/staging/test-storage.php --execute
```

The state exercise rolls back all probe rows. The Storage test uploads one
synthetic PDF, verifies authenticated and signed downloads plus public denial,
then deletes the probe object.

Application-level ownership tests happen through the Vercel Preview deployment;
direct Storage tests alone do not prove PHP authorization.

## 5. Vercel Preview variables

Configure these for **Preview only**, using staging values:

```text
INTERNPILIH_DATABASE_DSN
INTERNPILIH_DATABASE_USER
INTERNPILIH_DATABASE_PASSWORD
INTERNPILIH_DATABASE_POOL_MODE=transaction
INTERNPILIH_SESSION_DRIVER=database
INTERNPILIH_SESSION_LIFETIME_SECONDS=7200
INTERNPILIH_RATE_LIMIT_DRIVER=database
INTERNPILIH_APP_URL
INTERNPILIH_ENVIRONMENT=staging
INTERNPILIH_STORAGE_DRIVER=supabase
INTERNPILIH_SUPABASE_URL
INTERNPILIH_SUPABASE_STORAGE_SECRET
INTERNPILIH_STORAGE_BUCKET=documents
INTERNPILIH_MAIL_DRIVER=disabled
INTERNPILIH_TELEGRAM_ENABLED=false
```

Do not add production values to Preview. Optional Gemini, SMTP, and Telegram
secrets should remain unset during synthetic testing.

## 6. Vercel authentication and build

The machine currently has an invalid saved Vercel credential. In an interactive
terminal, the user should run `vercel logout` and then `vercel login` (or
`vercel login --github`) and complete authentication in the browser. Never paste a
token into chat. After a separate approval to link the project:

```text
vercel link
vercel pull --environment=preview
vercel build
```

`vercel build` is local-only. A later `vercel deploy` creates a Preview and still
requires separate deployment approval. Never use `--prod` during staging.
