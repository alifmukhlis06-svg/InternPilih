# User tables

`users` stores fields shared by every account: ID, name, email, password hash, role and creation time. The same ID is used as the primary key in exactly one role table:

| Role | Table | Role details |
| --- | --- | --- |
| Student | `students` | Matric ID, faculty, programme, CGPA |
| Employer | `employers` | Company ID |
| Coordinator | `admins` | Optional faculty |

Offers, documents, internship applications and placement change requests point to `students.user_id`. Authentication joins the matching role table and returns the same API fields as before.

For a new database, use `schema.sql`. For an older database with role fields in `users`, run `role_tables_migration.sql` first, verify the copied rows, then run the one-time `role_tables_finalize.sql`. The finalize script removes the old role fields from `users` and changes student foreign keys. Neither script resets or replaces the existing account IDs.
