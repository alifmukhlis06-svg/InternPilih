<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

try {
    $pdo = stagingPdo('ADMIN');
    assertStagingMarker($pdo);
    $testPassword = stagingEnvironment('INTERNPILIH_STAGING_TEST_PASSWORD');
    $cases = [
        [
            "SELECT u.password FROM public.users u
             JOIN public.students s ON s.user_id = u.id
             WHERE u.role = ? AND s.matric_id = ?",
            ['student', 'STG000001'],
        ],
        [
            "SELECT u.password FROM public.users u
             JOIN public.admins a ON a.user_id = u.id
             WHERE u.role = ? AND a.staff_id = ?",
            ['admin', '100001'],
        ],
        [
            "SELECT u.password FROM public.users u
             WHERE u.role = ? AND lower(u.email) = lower(?)",
            ['employer', 'employer.alpha@example.test'],
        ],
    ];

    foreach ($cases as [$sql, $parameters]) {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($testPassword, $hash)) {
            throw new RuntimeException('A role-specific login identifier did not authenticate.');
        }
    }

    echo "READ ONLY - login identifier verification changed nothing.\n";
    echo "Student matric ID: PASS\n";
    echo "Coordinator staff ID: PASS\n";
    echo "Employer email: PASS\n";
} catch (Throwable $error) {
    error_log('InternPilih staging login identifier verification failed: ' . $error->getMessage());
    fwrite(STDERR, "Login identifier verification failed. No credential was printed.\n");
    exit(1);
}
