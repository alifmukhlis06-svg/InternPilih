# Supabase migration

The target project is `opqqdluryonljmjiaghr`. The migration keeps InternPilih's existing application users and password hashes in `public.users`; it does not silently replace them with Supabase Auth users.

1. Copy `config/supabase.local.example.php` to `config/supabase.local.php`.
2. Copy `config/mysql.local.example.php` to `config/mysql.local.php` for the read-only source connection.
3. Set secrets only in those git-ignored local files or environment variables.
4. Review `database/supabase/schema.sql` and `database/supabase/post-import.sql`.
5. Copy the exact Session pooler host and username from **Dashboard > Connect > Session pooler**, then run `php database/test-supabase-connection.php`.
6. Run `php database/migrate-to-supabase.php` to display the non-writing plan.
7. Only after approval, run `php database/migrate-to-supabase.php --execute`.
8. `--replace` is destructive to target rows and must never be used without a fresh backup and explicit approval.
9. After database verification, run `php database/migrate-files-to-supabase.php --execute` to create the private `documents` bucket and upload referenced files.
10. Run `php database/verify-supabase.php` for a read-only table and row-count check.

The schema enables Row Level Security without public policies. The publishable key therefore cannot read private internship data. The PHP backend should connect through server-only database credentials or a narrowly scoped server API.
