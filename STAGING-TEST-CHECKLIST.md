# InternPilih Vercel staging test checklist

Use only synthetic staging records and dummy documents.

## Platform and routing

- Homepage and login assets return 200 over HTTPS.
- Static student, employer, and coordinator shells load without server errors.
- Unauthenticated API calls return 401; private source/configuration paths return 404.
- A signed-in user who visits another role's portal is redirected to their own dashboard.
- Direct `/uploads/...`, `/database/...`, `/config/...`, SQL, backup, and helper API paths return 404.

## Shared authentication state

- Login remains valid across repeated requests that may reach different function instances.
- Session ID changes after login and the cookie is Secure, HttpOnly, and SameSite=Lax.
- Logout invalidates the shared session.
- Failed login limits are shared across instances and expire as designed.
- Cross-origin state-changing requests are rejected.

## Functional roles

- Student: dashboard, companies, offers, applications, placement changes, notifications, profile, upload, and authorized download.
- Employer: dashboard, company-scoped applications/offers, notifications, and profile.
- Coordinator: assigned-student dashboard, offers, documents, approvals/rejections, reports, notifications, and settings.

## Negative authorization

- Student A cannot read or modify Student B's records or files.
- Employer A cannot read or modify Employer B's company records, applications, or offers.
- Coordinator A cannot read or modify Coordinator B's assigned students or documents.
- Guessed document IDs return 404 when the caller is outside scope.
- Unauthenticated and wrong-role writes return 401/403 and leave record counts unchanged.

## PostgreSQL and Storage

- Transaction-pooler PDO connections on port 6543 require TLS, use emulated
  prepares, and authenticate as the restricted staging backend role.
- Normal create/update/delete workflows succeed only within application scope.
- `anon` and `authenticated` cannot access application tables directly.
- The `documents` bucket is private; direct object URLs fail without a short-lived signed URL.
- Upload rejects disallowed MIME types, mismatched extensions, and payloads over the serverless limit.
- A failed database transaction does not leave a usable orphan object.

Record the deployment URL, commit, test time, tester, pass/fail evidence, and any cleanup required. Do not include credentials or personal data in evidence.
