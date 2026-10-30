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
];

$step = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--step=')) {
        $step = substr($argument, strlen('--step='));
    }
}

if (!isset($steps[$step])) {
    fwrite(STDERR, "Choose --step=schema, --step=serverless-storage, --step=security, or --step=profile-phone.\n");
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

    echo 'SUCCESS: staging migration step completed - ' . $step . "\n";
    echo 'Public tables: ' . $newPublicTableCount . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: staging migration step ' . $step . ". No credential was printed.\n");
    exit(1);
}
