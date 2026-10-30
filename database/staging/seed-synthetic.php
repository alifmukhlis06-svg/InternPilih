<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

$accounts = [
    ['Synthetic Coordinator Alpha', 'coordinator.alpha@example.test', 'admin'],
    ['Synthetic Coordinator Beta', 'coordinator.beta@example.test', 'admin'],
    ['Synthetic Employer Alpha', 'employer.alpha@example.test', 'employer'],
    ['Synthetic Employer Beta', 'employer.beta@example.test', 'employer'],
    ['Synthetic Student Alpha', 'student.alpha@example.test', 'student'],
    ['Synthetic Student Beta', 'student.beta@example.test', 'student'],
];

if (!in_array('--execute', $argv, true)) {
    echo "PLAN ONLY - no synthetic records were inserted.\n";
    echo 'Planned synthetic users: ' . count($accounts) . "\n";
    echo "Planned scope: two coordinators, two employers, two companies, and two students.\n";
    echo "Use --reset-passwords with --execute to repair only these existing synthetic account passwords.\n";
    exit(0);
}

try {
    $testPassword = stagingEnvironment('INTERNPILIH_STAGING_TEST_PASSWORD');
    if (strlen($testPassword) < 16 || str_contains($testPassword, 'YOUR_')) {
        throw new RuntimeException('The private staging test password must contain at least 16 characters.');
    }
    $hash = password_hash($testPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('Could not hash the staging test password.');
    }

    $pdo = stagingPdo('ADMIN');
    assertStagingMarker($pdo);

    if (in_array('--reset-passwords', $argv, true)) {
        $emails = array_column($accounts, 1);
        $placeholders = implode(', ', array_fill(0, count($emails), '?'));
        $lookup = $pdo->prepare("SELECT email FROM public.users WHERE email IN ({$placeholders}) ORDER BY email");
        $lookup->execute($emails);
        $found = $lookup->fetchAll(PDO::FETCH_COLUMN);
        sort($emails);
        if ($found !== $emails) {
            throw new RuntimeException('The exact six synthetic staging accounts were not found.');
        }

        $pdo->beginTransaction();
        $reset = $pdo->prepare("UPDATE public.users SET password = ? WHERE email IN ({$placeholders})");
        $reset->execute(array_merge([$hash], $emails));
        if ($reset->rowCount() !== count($accounts)) {
            throw new RuntimeException('The synthetic password reset did not update exactly six accounts.');
        }
        $pdo->commit();
        echo "Synthetic staging passwords reset successfully for six accounts.\n";
        echo "The password was read privately and was not printed.\n";
        exit(0);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM public.users')->fetchColumn() !== 0) {
        throw new RuntimeException('Synthetic seed requires an empty staging users table.');
    }

    $insertUser = $pdo->prepare("
        INSERT INTO public.users (name, email, password, role)
        VALUES (?, ?, ?, ?)
        RETURNING id
    ");
    $insertCompany = $pdo->prepare("
        INSERT INTO public.companies
            (name, industry, location, allowance, slots, work_mode, hr_email, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        RETURNING id
    ");

    $pdo->beginTransaction();
    $ids = [];
    foreach ($accounts as [$name, $email, $role]) {
        $insertUser->execute([$name, $email, $hash, $role]);
        $ids[$email] = (int)$insertUser->fetchColumn();
    }

    $insertCompany->execute([
        'Synthetic Alpha Labs', 'Software Testing', 'Synthetic City A',
        '900.00', 3, 'Hybrid', 'hr.alpha@example.test',
    ]);
    $companyAlpha = (int)$insertCompany->fetchColumn();
    $insertCompany->execute([
        'Synthetic Beta Works', 'Data Services', 'Synthetic City B',
        '1000.00', 2, 'Remote', 'hr.beta@example.test',
    ]);
    $companyBeta = (int)$insertCompany->fetchColumn();

    $pdo->prepare('INSERT INTO public.admins (user_id, faculty, programme) VALUES (?, ?, ?)')
        ->execute([$ids['coordinator.alpha@example.test'], 'Synthetic Computing', 'Synthetic Software Engineering']);
    $pdo->prepare('INSERT INTO public.admins (user_id, faculty, programme) VALUES (?, ?, ?)')
        ->execute([$ids['coordinator.beta@example.test'], 'Synthetic Business', 'Synthetic Business Analytics']);

    $pdo->prepare('INSERT INTO public.employers (user_id, company_id) VALUES (?, ?)')
        ->execute([$ids['employer.alpha@example.test'], $companyAlpha]);
    $pdo->prepare('INSERT INTO public.employers (user_id, company_id) VALUES (?, ?)')
        ->execute([$ids['employer.beta@example.test'], $companyBeta]);

    $insertStudent = $pdo->prepare("
        INSERT INTO public.students
            (user_id, matric_id, faculty, programme, coordinator_id, cgpa)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertStudent->execute([
        $ids['student.alpha@example.test'], 'STG000001', 'Synthetic Computing',
        'Synthetic Software Engineering', $ids['coordinator.alpha@example.test'], '3.50',
    ]);
    $insertStudent->execute([
        $ids['student.beta@example.test'], 'STG000002', 'Synthetic Business',
        'Synthetic Business Analytics', $ids['coordinator.beta@example.test'], '3.25',
    ]);

    $insertApplication = $pdo->prepare("
        INSERT INTO public.internship_applications
            (student_id, company_id, position, source, status, applied_at, notes)
        VALUES (?, ?, ?, 'student', ?, CURRENT_DATE, ?)
        RETURNING id
    ");
    $insertApplication->execute([
        $ids['student.alpha@example.test'], $companyAlpha,
        'Synthetic QA Intern', 'submitted', 'Synthetic staging application A',
    ]);
    $applicationAlpha = (int)$insertApplication->fetchColumn();
    $insertApplication->execute([
        $ids['student.beta@example.test'], $companyBeta,
        'Synthetic Data Intern', 'offered', 'Synthetic staging application B',
    ]);
    $applicationBeta = (int)$insertApplication->fetchColumn();

    $insertOffer = $pdo->prepare("
        INSERT INTO public.offers
            (student_id, company_id, position, offer_date, deadline, status,
             response_date, application_id, placement_verification_status)
        VALUES (?, ?, ?, CURRENT_DATE, CURRENT_DATE + 14, ?, ?, ?, ?)
        RETURNING id
    ");
    $insertOffer->execute([
        $ids['student.alpha@example.test'], $companyAlpha, 'Synthetic QA Intern',
        'pending', null, $applicationAlpha, 'not_submitted',
    ]);
    $offerAlpha = (int)$insertOffer->fetchColumn();
    $insertOffer->execute([
        $ids['student.beta@example.test'], $companyBeta, 'Synthetic Data Intern',
        'accepted', date('Y-m-d'), $applicationBeta, 'not_submitted',
    ]);
    $offerBeta = (int)$insertOffer->fetchColumn();

    $updateApplication = $pdo->prepare('UPDATE public.internship_applications SET offer_id = ? WHERE id = ?');
    $updateApplication->execute([$offerAlpha, $applicationAlpha]);
    $updateApplication->execute([$offerBeta, $applicationBeta]);

    $insertNotification = $pdo->prepare("
        INSERT INTO public.notifications
            (recipient_user_id, created_by, type, title, body, recipient_group)
        VALUES (?, ?, 'system', ?, ?, 'student')
    ");
    $insertNotification->execute([
        $ids['student.alpha@example.test'], $ids['coordinator.alpha@example.test'],
        'Synthetic staging notification A', 'Synthetic data only.',
    ]);
    $insertNotification->execute([
        $ids['student.beta@example.test'], $ids['coordinator.beta@example.test'],
        'Synthetic staging notification B', 'Synthetic data only.',
    ]);

    $pdo->commit();
    echo "Synthetic staging data inserted successfully.\n";
    foreach ($accounts as [, $email, $role]) {
        echo $role . ': ' . $email . "\n";
    }
    echo "Passwords were read privately from the environment and were not printed.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('InternPilih synthetic staging seed failed: ' . $error->getMessage());
    fwrite(STDERR, "Synthetic staging seed failed. No password was printed.\n");
    exit(1);
}
