<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

$expectedTables = [
    'users', 'companies', 'admins', 'students', 'employers', 'offers',
    'documents', 'internship_applications', 'announcements', 'notifications',
    'activity_logs', 'placement_change_requests', 'password_reset_tokens',
    'system_settings', 'app_sessions', 'login_rate_limits',
];

try {
    $admin = stagingPdo('ADMIN');
    assertStagingMarker($admin);

    $tables = $admin->query("
        SELECT tablename
        FROM pg_tables
        WHERE schemaname = 'public'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $tableCount = count(array_intersect($expectedTables, $tables));

    $rlsStatement = $admin->prepare("
        SELECT COUNT(*)
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relname = ANY(CAST(? AS text[]))
          AND c.relrowsecurity
    ");
    $rlsStatement->execute(['{' . implode(',', $expectedTables) . '}']);
    $rlsCount = (int)$rlsStatement->fetchColumn();

    $grantCount = (int)$admin->query("
        SELECT COUNT(*)
        FROM information_schema.role_table_grants
        WHERE table_schema = 'public'
          AND grantee IN ('anon', 'authenticated')
    ")->fetchColumn();

    $role = $admin->query("
        SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole,
               rolreplication, rolbypassrls
        FROM pg_roles
        WHERE rolname = 'internpilih_staging_app'
    ")->fetch();

    $functionSettings = (string)$admin->query("
        SELECT COALESCE(array_to_string(proconfig, ','), '')
        FROM pg_proc
        WHERE oid = 'public.set_updated_at()'::regprocedure
    ")->fetchColumn();

    $bucket = $admin->query("
        SELECT public, file_size_limit
        FROM storage.buckets
        WHERE id = 'documents'
    ")->fetch();
    $storagePolicyCount = (int)$admin->query("
        SELECT COUNT(*)
        FROM pg_policies
        WHERE schemaname = 'storage' AND tablename = 'objects'
    ")->fetchColumn();

    $userCount = (int)$admin->query('SELECT COUNT(*) FROM public.users')->fetchColumn();
    $nonSyntheticUsers = (int)$admin->query("
        SELECT COUNT(*)
        FROM public.users
        WHERE email !~ '@example[.]test$'
    ")->fetchColumn();

    $application = stagingPdo('APP');
    assertStagingMarker($application);
    $applicationProbe = $application->query("
        SELECT current_user = 'internpilih_staging_app' AS correct_role,
               has_schema_privilege(current_user, 'public', 'CREATE') AS can_create,
               has_table_privilege(current_user, 'public.users', 'SELECT') AS can_read_users,
               has_table_privilege(current_user, 'public.app_sessions', 'INSERT') AS can_write_sessions
    ")->fetch();

    $passed = $tableCount === count($expectedTables)
        && $rlsCount === count($expectedTables)
        && $grantCount === 0
        && $role
        && stagingPgBool($role['rolcanlogin'])
        && !stagingPgBool($role['rolsuper'])
        && !stagingPgBool($role['rolcreatedb'])
        && !stagingPgBool($role['rolcreaterole'])
        && !stagingPgBool($role['rolreplication'])
        && stagingPgBool($role['rolbypassrls'])
        && str_contains($functionSettings, 'search_path=pg_catalog')
        && $bucket
        && !stagingPgBool($bucket['public'])
        && (int)$bucket['file_size_limit'] === 5242880
        && $storagePolicyCount === 0
        && $nonSyntheticUsers === 0
        && stagingPgBool($applicationProbe['correct_role'] ?? false)
        && !stagingPgBool($applicationProbe['can_create'] ?? true)
        && stagingPgBool($applicationProbe['can_read_users'] ?? false)
        && stagingPgBool($applicationProbe['can_write_sessions'] ?? false);

    echo "READ ONLY - staging verification changed nothing.\n";
    echo 'Expected public tables: ' . $tableCount . '/' . count($expectedTables) . "\n";
    echo 'Tables with RLS enabled: ' . $rlsCount . '/' . count($expectedTables) . "\n";
    echo 'anon/authenticated public-table grants: ' . $grantCount . "\n";
    echo 'Synthetic users: ' . $userCount . "\n";
    echo 'Non-synthetic user emails: ' . $nonSyntheticUsers . "\n";
    echo 'Private documents bucket: ' . ($bucket && !stagingPgBool($bucket['public']) ? 'yes' : 'no') . "\n";
    echo 'Storage object policies: ' . $storagePolicyCount . "\n";
    echo 'Restricted backend role: ' . ($passed ? 'verified' : 'FAILED') . "\n";
    exit($passed ? 0 : 1);
} catch (Throwable $error) {
    error_log('InternPilih staging verification failed: ' . $error->getMessage());
    fwrite(STDERR, "Staging verification failed. No credentials were printed.\n");
    exit(1);
}
