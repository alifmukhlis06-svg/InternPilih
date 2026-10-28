# Supabase migration

The target project is `opqqdluryonljmjiaghr`. The migration keeps InternPilih's existing application users and password hashes in `public.users`; it does not silently replace them with Supabase Auth users.

1. Copy `config/supabase.local.example.php` to `config/supabase.local.php`.
2. Set the database password from Supabase **Project Settings > Database**.
3. Run `database/migrate-to-supabase.php`. Add `--replace` only when replacing an earlier InternPilih import.
4. Set the secret/service-role key locally, then run `database/migrate-files-to-supabase.php` to create the private `documents` bucket and upload referenced files.

The schema enables Row Level Security without public policies. The publishable key therefore cannot read private internship data. The PHP backend should connect through server-only database credentials or a narrowly scoped server API.
