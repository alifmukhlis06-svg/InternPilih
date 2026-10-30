<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

if (!in_array('--execute', $argv, true)) {
    echo "PLAN ONLY - no role or credential was changed.\n";
    echo "Execution enables only internpilih_staging_app after validating the staging marker.\n";
    exit(0);
}

try {
    $roleEnabled = false;
    $password = stagingEnvironment('INTERNPILIH_STAGING_APP_PASSWORD');
    if (strlen($password) < 24 || str_contains($password, 'YOUR_')) {
        throw new RuntimeException('The staging application role password must contain at least 24 characters.');
    }

    $admin = stagingPdo('ADMIN');
    assertStagingMarker($admin);
    $role = $admin->query("
        SELECT rolname, rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
        FROM pg_roles
        WHERE rolname = 'internpilih_staging_app'
    ")->fetch();
    if (!$role || stagingPgBool($role['rolsuper']) || stagingPgBool($role['rolcreatedb'])
        || stagingPgBool($role['rolcreaterole']) || stagingPgBool($role['rolreplication'])) {
        throw new RuntimeException('The prepared restricted staging role is missing or unsafe.');
    }

    $quotedPassword = $admin->quote($password);
    $admin->exec('ALTER ROLE internpilih_staging_app LOGIN PASSWORD ' . $quotedPassword);
    $roleEnabled = true;

    $application = stagingPdo('APP');
    $probe = $application->query("
        SELECT current_user = 'internpilih_staging_app' AS correct_role,
               (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS bypass_rls,
               (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) AS superuser
    ")->fetch();
    if (!$probe || !stagingPgBool($probe['correct_role']) || !stagingPgBool($probe['bypass_rls'])
        || stagingPgBool($probe['superuser'])) {
        throw new RuntimeException('The staging application role verification failed.');
    }

    echo "Restricted staging backend role enabled and connection-verified.\n";
} catch (Throwable $error) {
    if (($roleEnabled ?? false) && isset($admin) && $admin instanceof PDO) {
        try {
            $admin->exec('ALTER ROLE internpilih_staging_app NOLOGIN PASSWORD NULL');
        } catch (Throwable $cleanupError) {
            error_log('InternPilih staging role rollback failed; disable the staging role manually.');
        }
    }
    error_log('InternPilih staging role setup failed: ' . $error->getMessage());
    fwrite(STDERR, "Staging role setup failed. No credential was printed.\n");
    exit(1);
}
