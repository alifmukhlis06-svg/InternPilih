<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

$steps = [
    'schema' => __DIR__ . '/../supabase/schema.sql',
    'serverless-storage' => __DIR__ . '/002_serverless_storage.sql',
    'security' => __DIR__ . '/003_security.sql',
    'profile-phone' => __DIR__ . '/004_qa_profile_phone.sql',
    'campus-schedule-password' => __DIR__ . '/005_campus_schedule_password_verification.sql',
    'account-normalization' => __DIR__ . '/006_normalize_synthetic_accounts.sql',
];

$step = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--step=')) {
        $step = substr($argument, strlen('--step='));
    }
}

if (!isset($steps[$step])) {
    fwrite(STDERR, "Choose --step=schema, --step=serverless-storage, --step=security, --step=profile-phone, --step=campus-schedule-password, or --step=account-normalization.\n");
    exit(2);
}

if (!in_array('--execute', $argv, true)) {
    echo 'PLAN ONLY - approved staging step: ' . $step . "\n";
    echo "No SQL was executed.\n";
    exit(0);
}

try {
    $pdo = stagingPdo('ADMIN');
    $publicTableCount = (int)$pdo->query("
        SELECT COUNT(*) FROM pg_tables WHERE schemaname = 'public'
    ")->fetchColumn();

    if ($step === 'schema' && $publicTableCount !== 0) {
        throw new RuntimeException('The base schema requires an empty public schema.');
    }

    if ($step === 'serverless-storage') {
        if ($publicTableCount !== 14
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn())
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.documents') IS NOT NULL")->fetchColumn())
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.system_settings') IS NOT NULL")->fetchColumn())
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.app_sessions') IS NULL")->fetchColumn())) {
            throw new RuntimeException('The serverless/Storage migration is out of order.');
        }
        $bucketCount = (int)$pdo->query("
            SELECT COUNT(*) FROM storage.buckets WHERE id = 'documents'
        ")->fetchColumn();
        if ($bucketCount !== 0) {
            throw new RuntimeException('The documents bucket already exists.');
        }
    }

    if ($step === 'security') {
        if ($publicTableCount !== 16) {
            throw new RuntimeException('The security migration is out of order.');
        }
        assertStagingMarker($pdo);
    }

    if ($step === 'profile-phone') {
        if ($publicTableCount !== 16
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn())) {
            throw new RuntimeException('The profile phone migration is out of order.');
        }
        assertStagingMarker($pdo);
    }

    if ($step === 'campus-schedule-password') {
        if ($publicTableCount !== 16
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.students') IS NOT NULL")->fetchColumn())
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.password_reset_tokens') IS NOT NULL")->fetchColumn())) {
            throw new RuntimeException('The campus/schedule/password migration is out of order.');
        }
        assertStagingMarker($pdo);
    }

    if ($step === 'account-normalization') {
        if ($publicTableCount !== 16
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.admins') IS NOT NULL")->fetchColumn())
            || !stagingPgBool($pdo->query("SELECT to_regclass('public.students') IS NOT NULL")->fetchColumn())) {
            throw new RuntimeException('The account normalization migration is out of order.');
        }
        assertStagingMarker($pdo);
    }

    $sql = file_get_contents($steps[$step]);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('The approved migration file could not be read.');
    }

    try {
        $pdo->exec($sql);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $newPublicTableCount = (int)$pdo->query("
        SELECT COUNT(*) FROM pg_tables WHERE schemaname = 'public'
    ")->fetchColumn();
    $expectedCount = $step === 'schema' ? 14 : 16;
    if ($newPublicTableCount !== $expectedCount) {
        throw new RuntimeException('The post-migration table count is unexpected.');
    }

    if ($step === 'serverless-storage') {
        assertStagingMarker($pdo);
        $bucket = $pdo->query("
            SELECT public, file_size_limit
            FROM storage.buckets
            WHERE id = 'documents'
        ")->fetch();
        if (!$bucket || stagingPgBool($bucket['public']) || (int)$bucket['file_size_limit'] !== 5242880) {
            throw new RuntimeException('The private staging bucket verification failed.');
        }
    }

    if ($step === 'security') {
        $role = $pdo->query("
            SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication
            FROM pg_roles
            WHERE rolname = 'internpilih_staging_app'
        ")->fetch();
        if (!$role || stagingPgBool($role['rolcanlogin']) || stagingPgBool($role['rolsuper'])
            || stagingPgBool($role['rolcreatedb']) || stagingPgBool($role['rolcreaterole'])
            || stagingPgBool($role['rolreplication'])) {
            throw new RuntimeException('The restricted staging role verification failed.');
        }
    }


    if ($step === 'profile-phone') {
        $phoneColumnCount = (int)$pdo->query("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'phone'
        ")->fetchColumn();
        if ($phoneColumnCount !== 1) {
            throw new RuntimeException('The profile phone column verification failed.');
        }
    }


    if ($step === 'campus-schedule-password') {
        $requiredColumns = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = 'public'
              AND ((table_name = 'students' AND column_name = 'campus')
                OR (table_name = 'admins' AND column_name = 'campus')
                OR (table_name = 'offers' AND column_name = 'internship_start_date')
                OR (table_name = 'password_reset_tokens' AND column_name = 'purpose'))
        ")->fetchColumn();
        $scheduleCount = (int)$pdo->query("
            SELECT COUNT(*) FROM public.system_settings
            WHERE setting_key IN ('internship_application_open', 'main_offer_deadline',
                'placement_confirmation_deadline', 'document_completion_deadline', 'internship_start_date')
        ")->fetchColumn();
        if ($requiredColumns !== 4 || $scheduleCount !== 5) {
            throw new RuntimeException('The campus/schedule/password verification failed.');
        }
    }

    if ($step === 'account-normalization') {
        $invalidAccountCount = (int)$pdo->query("
            SELECT
                (SELECT count(*) FROM public.students s JOIN public.users u ON u.id = s.user_id
                 WHERE lower(u.email) <> lower(s.matric_id) || '@student.uitm.edu.my')
              + (SELECT count(*) FROM public.admins a JOIN public.users u ON u.id = a.user_id
                 WHERE a.staff_id !~ '^[0-9]{6}$' OR u.email !~ '^[a-z][a-z0-9]*@uitm[.]edu[.]my$')
              + (SELECT count(*) FROM public.users
                 WHERE role IN ('student','admin','employer') AND phone !~ '^\\+6011[0-9]{8}$')
        ")->fetchColumn();
        if ($invalidAccountCount !== 0) {
            throw new RuntimeException('The account normalization verification failed.');
        }
    }

    echo 'SUCCESS: staging migration step completed - ' . $step . "\n";
    echo 'Public tables: ' . $newPublicTableCount . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: staging migration step ' . $step . ". No credential was printed.\n");
    exit(1);
}
