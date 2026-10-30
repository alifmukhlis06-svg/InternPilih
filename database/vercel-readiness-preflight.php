<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$expectedTables = [
    'users', 'companies', 'admins', 'students', 'employers', 'offers',
    'documents', 'internship_applications', 'announcements', 'notifications',
    'activity_logs', 'placement_change_requests', 'password_reset_tokens',
    'system_settings',
];

try {
    $tableResult = db()->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    $actualTables = array_column($tableResult->fetch_all(MYSQLI_ASSOC), 'table_name');

    $recordCount = 0;
    foreach ($expectedTables as $table) {
        if (!in_array($table, $actualTables, true)) continue;
        $countResult = db()->query('SELECT COUNT(*) AS row_count FROM public.' . $table);
        $recordCount += (int)($countResult->fetch_assoc()['row_count'] ?? 0);
    }

    $stateTables = array_values(array_intersect(['app_sessions', 'login_rate_limits'], $actualTables));
    $columnResult = db()->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'documents'
          AND column_name IN (
              'storage_bucket', 'storage_object_path', 'content_type',
              'byte_size', 'sha256', 'storage_migrated_at'
          )
    ");
    $storageColumns = array_column($columnResult->fetch_all(MYSQLI_ASSOC), 'column_name');

    $bucketResult = db()->query("SELECT COUNT(*) AS bucket_count FROM storage.buckets WHERE id = 'documents'");
    $bucketExists = (int)($bucketResult->fetch_assoc()['bucket_count'] ?? 0) === 1;

    $functionResult = db()->query("
        SELECT COALESCE(array_to_string(proconfig, ','), '') AS settings
        FROM pg_proc
        WHERE oid = 'public.set_updated_at()'::regprocedure
    ");
    $functionSettings = (string)($functionResult->fetch_assoc()['settings'] ?? '');
    $safeSearchPath = str_contains($functionSettings, 'search_path=pg_catalog');

    $roleResult = db()->query("
        SELECT rolsuper, rolbypassrls
        FROM pg_roles
        WHERE rolname = CURRENT_USER
    ");
    $role = $roleResult->fetch_assoc() ?? [];
    $privilegedRole = (bool)($role['rolsuper'] ?? false) || (bool)($role['rolbypassrls'] ?? false);

    $grantResult = db()->query("
        SELECT COUNT(*) AS grant_count
        FROM information_schema.role_table_grants
        WHERE table_schema = 'public'
          AND grantee IN ('anon', 'authenticated')
    ");
    $publicApiGrantCount = (int)($grantResult->fetch_assoc()['grant_count'] ?? 0);

    echo "READ ONLY - no schema, permission, Storage, or record was changed.\n";
    echo 'Application tables: ' . count(array_intersect($expectedTables, $actualTables)) . '/' . count($expectedTables) . "\n";
    echo 'Application records: ' . $recordCount . "\n";
    echo 'Shared serverless state tables: ' . count($stateTables) . "/2\n";
    echo 'Document Storage metadata columns: ' . count($storageColumns) . "/6\n";
    echo 'Private documents bucket exists: ' . ($bucketExists ? 'yes' : 'no') . "\n";
    echo 'set_updated_at secure search_path: ' . ($safeSearchPath ? 'yes' : 'no') . "\n";
    echo 'Current application database role is privileged: ' . ($privilegedRole ? 'yes' : 'no') . "\n";
    echo 'anon/authenticated public-table grants: ' . $publicApiGrantCount . "\n";
} catch (Throwable $error) {
    error_log('InternPilih Vercel preflight failed: ' . $error->getMessage());
    fwrite(STDERR, "Vercel readiness preflight failed.\n");
    exit(1);
}
