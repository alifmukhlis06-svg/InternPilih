<?php

// Copy this file to supabase.local.php and fill the private values locally.
// Never commit the copied file or paste the database password/service key into source code.
return [
    'project_url' => 'https://YOUR_PROJECT_REF.supabase.co',
    'publishable_key' => 'YOUR_SUPABASE_PUBLISHABLE_KEY',
    // Copy the exact Session pooler host from Dashboard > Connect > Session pooler.
    // Session mode uses port 5432 and supports native prepared statements.
    'database_dsn' => 'pgsql:host=YOUR_SESSION_POOLER_HOST;port=5432;dbname=postgres;sslmode=require',
    'database_user' => 'YOUR_DATABASE_ROLE.YOUR_PROJECT_REF',
    'database_password' => 'YOUR_SUPABASE_DATABASE_PASSWORD',
    'database_pool_mode' => 'session',
    'storage_secret_key' => 'YOUR_SUPABASE_SECRET_KEY',
    'storage_bucket' => 'documents',
];
