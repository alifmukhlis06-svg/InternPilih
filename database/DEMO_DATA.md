# Realistic sample data

The local `internpilih` database can be populated with fictional, connected records:

```sh
php database/seed-realistic-demo.php
```

Run this after creating the tables in `database/schema.sql` or migrating an existing database with `role_tables_migration.sql` and `role_tables_finalize.sql`. The script keeps existing accounts and records, and can be run again without adding duplicates. It creates student profiles across multiple UiTM faculties, sample employers and companies, offers, applications, notifications, activity, a placement change request, and readable sample PDFs in `uploads/` for document verification.

Sample logins (password: `password123`):

- Student: `2026124101@student.uitm.edu.my`
- Employer: `careers@meridiandatalabs.example.test`

The people, matric numbers, employers and document contents are fictional. Student email addresses now follow UiTM's requested format, but they are demonstration accounts: do not send real mail to these addresses.
