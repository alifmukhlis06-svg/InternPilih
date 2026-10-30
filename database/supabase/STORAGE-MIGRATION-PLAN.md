# InternPilih private document Storage migration

This is a preparation document only. No bucket, object, or database row is changed by this plan.

## Current verified inventory

- 109 `documents` rows and 109 distinct file names.
- All 109 referenced local PDF files exist under `uploads/`.
- No unsafe path names were found.
- Supabase currently has no Storage bucket and no Storage objects.
- Direct web access to `uploads/` is blocked locally by `uploads/.htaccess`.

## Approved migration sequence

1. Create a **private** `documents` bucket with a 5 MiB object limit and allowed MIME types `application/pdf`, `image/jpeg`, and `image/png`.
2. Keep `anon` and `authenticated` without broad Storage policies. The PHP backend remains the only upload/download broker while InternPilih uses custom PHP sessions.
3. Add nullable columns to `public.documents`: `storage_bucket`, `storage_object_path`, `content_type`, `byte_size`, `sha256`, and `storage_migrated_at`.
4. Read the migration manifest from the migrated PostgreSQL `documents` table, not the old MySQL source.
5. For every local file, verify `basename`, detected MIME, byte size, and SHA-256 before upload.
6. Use deterministic private object paths: `students/{student_id}/{document_id}-{sha256_prefix}.{extension}`.
7. Upload with create-only semantics. Never use unconditional upsert. Treat an existing path as valid only after its checksum and size match.
8. Write Storage metadata to the matching document row only after upload verification. Record failures without changing unrelated rows.
9. Run a complete reconciliation: 109 database rows, 109 verified objects, zero missing files, zero checksum mismatches.
10. Deploy dual-read document delivery: Storage first when metadata exists, local protected file as a temporary fallback.
11. Retain the local files and backup until a separate deletion approval is granted after production validation.

## Runtime design

- Store the Supabase server secret in an environment variable outside the document root; never expose it to browser JavaScript.
- `api/document-file.php` must authorize the student or assigned coordinator before returning a short-lived signed URL or streaming bytes.
- Do not make the bucket public and do not add `USING (true)` Storage policies.
- Rotate the server secret immediately if it is ever logged, committed, or returned to a browser.
