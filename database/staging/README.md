# InternPilih staging environment

No production student data may be copied into this environment.

## Isolation requirements

1. Use a separate Supabase project and a separate restricted backend database role.
2. Apply the reviewed schema and security migrations to staging only after approval.
3. Seed synthetic users for each role. Student emails follow the matric-ID format, coordinator emails are fictional name-based UiTM addresses, employer emails use reserved example domains, and all records use dummy identifiers and files.
4. Configure Vercel Preview environment variables with staging-only values. Never reuse the production database password or Storage secret.
5. Set `INTERNPILIH_ENVIRONMENT=staging`, use the staging application origin in `INTERNPILIH_APP_URL`, and keep the deployment protected from public discovery.
6. Use a private staging `documents` bucket. Do not copy the 109 production documents.

## Required test identities

- Two students assigned to different coordinators.
- Two coordinators with separate student scopes.
- Two employers belonging to different companies.

This minimum data set permits positive tests and cross-user, cross-company, and cross-coordinator denial tests without exposing real information.

## Release gate

Run `STAGING-TEST-CHECKLIST.md` against a Preview deployment. Production is blocked until database writes, shared sessions, rate limiting, private document operations, and all negative authorization tests pass.

See `STAGING-APPLY-PLAN.md` for the exact project-creation, approval, migration,
credential, verification, and Preview sequence. None of the staging scripts use
`config/supabase.local.php`; this prevents accidental production fallback.
