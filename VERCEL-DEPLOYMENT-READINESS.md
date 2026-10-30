# InternPilih Vercel deployment readiness

Assessment date: 2026-10-30

## Verdict

**Protected Vercel Preview deployed and verified.** The isolated Supabase
staging backend, Vercel project link, Preview-only environment, deployment
protection, build, synthetic role checks, and private-document workflow pass.

## Passed locally

- PHP source lint under Laragon PHP 8.3.30.
- Browser JavaScript syntax under Node.js 22.22.0.
- Required Laragon extensions: PDO, pdo_pgsql, pgsql, cURL, OpenSSL, fileinfo, session, sodium, JSON, and mbstring.
- Homepage, direct API, single front controller, unauthenticated document denial, upload denial, configuration denial, and SQL denial under Apache.
- Offline Vercel route checks for the homepage, static assets, portal pages, API allowlist, and private path 404 rules.
- Supabase Session pooler read-only PDO/TLS test.
- Supabase Transaction pooler read-only PDO/TLS, emulated-prepare, and transaction-rollback test.
- Production inventory remains 14 application tables and 1,018 records.
- Storage plan finds 109/109 local documents ready, with zero missing files, unsafe names, or MIME mismatches.
- Credential-pattern scan found no embedded secret values in deployment source.
- Local secrets, uploaded documents, database scripts, backups, and diagnostic/setup files are excluded from the Vercel package.
- InternPilih-Staging project `zohcslnofeuisywqnjts` is isolated from production.
- Staging has 16 RLS-enabled public tables, six fictional users, shared database sessions, and database login throttling.
- The restricted staging backend role connects through the Transaction pooler on port 6543 with `sslmode=require`.
- The private `documents` bucket passed upload, authenticated download, signed URL, public-denial, and cleanup tests.
- `anon` and `authenticated` have no public-table grants in staging.
- `public.set_updated_at` uses the fixed `pg_catalog` search path in staging.
- Vercel CLI 65.7.0 is authenticated and the local directory is linked to project `internpilih`.
- The Vercel project uses Node.js 22.x and Standard Protection covers generated Preview URLs.
- All 15 required Preview-only environment variables are configured from InternPilih-Staging.
- `vercel build` passes with `vercel-php@0.9.0`, producing one PHP function bundle.
- Generated routes retain private-path denials, apply the security headers before terminal routes, and route the API allowlist to the single PHP front controller.
- The generated deployment bundle contains no local configuration, database migration, backup, upload, or private-document files.
- Protected login-fix Preview URL: `https://internpilih-m2fd1p9f8-alifmukhlis06-svg.vercel.app`.
- Anonymous access redirects to Vercel Authentication; authenticated homepage and portal routes return 200.
- Student, employer, and coordinator logins pass with shared sessions, regenerated session IDs, secure cookie attributes, scoped reads, and logout invalidation.
- The login page loads six fictional staging identities from the backend only when the environment is Preview and the project URL matches `zohcslnofeuisywqnjts`; production-mode responses omit the demo list.
- The deployed login page contains no old demo emails, hardcoded password, test-password value, credential, or private key.
- Synthetic document upload, signed download, wrong-student denial, unsigned public denial, and cleanup pass through the deployed application.
- The tested Preview produced zero runtime error/fatal log entries and zero HTTP 5xx request records.

## Prepared for Preview

- One PHP front controller for the Vercel Hobby function limit.
- Explicit API route allowlist and private-path denial routes.
- Preview-safe environment-variable template with staging, shared sessions,
  shared rate limiting, Transaction pooling, and private Storage.
- Vercel Authentication with Standard Protection is the intended access gate.
- Synthetic role and authorization checklist is ready for the deployed Preview.

## Deployment administration

- Vercel automatically assigned the project's first-ever deployment to the production target even though the CLI command omitted `--prod`. After explicit approval, both automatic production aliases and that exact deployment were removed.
- The alias inventory is empty. The preserved deployment is explicitly reported by Vercel as `target: preview`, `status: Ready`, and remains protected by Vercel Authentication.

## Runtime and plan risks

- PHP is provided by Vercel's recommended community `vercel-php` runtime rather than an official runtime. Version `0.9.0` uses PHP 8.5 and advertises the required extensions. The local authenticated build passes; the deployed Preview smoke test remains mandatory.
- Vercel Functions have a 4.5 MB request/response body limit. InternPilih limits Vercel document files to 4 MiB; direct-to-Storage signed uploads would be the next improvement if larger files are required.
- Supabase recommends Transaction pooler mode for serverless workloads. InternPilih now supports port 6543 with PDO emulated prepares; Laragon remains on Session mode port 5432.
- Vercel Hobby is restricted to non-commercial personal use. Confirm that the intended institutional/student deployment is eligible before using Hobby.

## Approval sequence

1. Keep the verified Preview connected only to InternPilih-Staging.
2. Perform an interactive browser/UI check through Vercel Authentication.
3. Review evidence before planning any intentional production work.

No production Supabase access, SQL, permission change, data operation, or Git push was performed. All application writes used synthetic staging data and the temporary document test was cleaned up. Vercel's first-deployment behavior briefly created one protected production-target deployment without production credentials; it and both aliases were removed after explicit approval. No intentional production deployment command was used.
