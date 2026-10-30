<?php

// Copy to staging.local.php and enter only InternPilih-Staging values.
// The copied file is ignored by Git and excluded from Vercel uploads.
return [
    'environment' => 'staging',
    'project_ref' => 'YOUR_STAGING_PROJECT_REF',

    // Staging administrator: Session pooler, used only for approved migrations
    // and synthetic seed preparation.
    'admin_dsn' => 'pgsql:host=YOUR_STAGING_POOLER_HOST;port=5432;dbname=postgres;sslmode=require',
    'admin_user' => 'postgres.YOUR_STAGING_PROJECT_REF',
    'admin_password' => 'YOUR_UNIQUE_STAGING_DATABASE_PASSWORD',

    // Restricted PHP role: Transaction pooler for Vercel Preview.
    'app_dsn' => 'pgsql:host=YOUR_STAGING_POOLER_HOST;port=6543;dbname=postgres;sslmode=require',
    'app_user' => 'internpilih_staging_app.YOUR_STAGING_PROJECT_REF',
    'app_password' => 'YOUR_UNIQUE_STAGING_APP_ROLE_PASSWORD',

    // Used only to hash the six fictional staging login passwords.
    'test_password' => 'YOUR_UNIQUE_SYNTHETIC_TEST_PASSWORD',

    'project_url' => 'https://YOUR_STAGING_PROJECT_REF.supabase.co',
    'storage_secret' => 'YOUR_STAGING_SUPABASE_SECRET_KEY',
];
