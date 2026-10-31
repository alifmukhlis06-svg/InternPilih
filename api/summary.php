<?php
// api/summary.php - Role-scoped dashboard data for all portals.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/offer-flags.php';

$user = requireAuth();
$responsePeriodDays = offerResponsePeriodDays();

/** @return array<int, array<string, mixed>> */
function summaryRows(string $sql, array $parameters = []): array {
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

$role = (string)$user['role'];
$userId = (int)$user['id'];
$companyId = (int)($user['company_id'] ?? 0);
if (!in_array($role, ['admin', 'employer', 'student'], true)) {
    respond(['error' => 'Forbidden'], 403);
}
if ($role === 'employer' && $companyId <= 0) {
    respond(['error' => 'Employer account is not linked to a company.'], 403);
}

if ($role === 'admin') {
    $studentScope = 'sp.coordinator_id = ?';
    $studentParameters = [$userId];
} elseif ($role === 'employer') {
    $studentScope = "(
        EXISTS (SELECT 1 FROM offers scoped_offer WHERE scoped_offer.student_id = u.id AND scoped_offer.company_id = ?)
        OR EXISTS (SELECT 1 FROM internship_applications scoped_application WHERE scoped_application.student_id = u.id AND scoped_application.company_id = ?)
    )";
    $studentParameters = [$companyId, $companyId];
} else {
    $studentScope = 'u.id = ?';
    $studentParameters = [$userId];
}

$students = summaryRows("
    SELECT
        u.id, u.name, u.email, sp.matric_id, sp.campus, sp.faculty, sp.programme,
        sp.coordinator_id, coordinator.name AS coordinator_name, sp.cgpa,
        COUNT(o.id) AS offers_count,
        COUNT(o.id) FILTER (WHERE o.status = 'pending') AS pending_count,
        COUNT(o.id) FILTER (WHERE o.status = 'accepted') AS accepted_count,
        COUNT(o.id) FILTER (WHERE o.status = 'rejected') AS rejected_count,
        COUNT(o.id) FILTER (WHERE o.status = 'withdrawn') AS withdrawn_count,
        COUNT(o.id) FILTER (WHERE o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responsePeriodDays}) AS overdue_pending_count,
        MIN(o.offer_date + {$responsePeriodDays}) FILTER (WHERE o.status = 'pending') AS next_response_deadline,
        MAX(CASE WHEN o.status = 'accepted' THEN c.name END) AS placement_company,
        MAX(CASE WHEN o.status = 'accepted' THEN c.industry END) AS placement_industry,
        MAX(CASE WHEN o.status = 'accepted' THEN c.allowance END) AS placement_allowance,
        MAX(CASE WHEN o.status = 'accepted' THEN o.placement_verification_status END) AS placement_verification_status,
        MAX(CASE WHEN o.status = 'accepted' THEN o.placement_verification_updated_at END) AS placement_verification_updated_at
    FROM users u
    JOIN students sp ON sp.user_id = u.id
    LEFT JOIN users coordinator ON coordinator.id = sp.coordinator_id
    LEFT JOIN offers o ON o.student_id = u.id
    LEFT JOIN companies c ON c.id = o.company_id
    WHERE u.role = 'student' AND {$studentScope}
    GROUP BY u.id, sp.user_id, coordinator.id
    ORDER BY u.name
", $studentParameters);

if ($role === 'admin') {
    $offerScope = 'sp.coordinator_id = ?';
    $offerParameters = [$userId];
} elseif ($role === 'employer') {
    $offerScope = 'o.company_id = ?';
    $offerParameters = [$companyId];
} else {
    $offerScope = 'o.student_id = ?';
    $offerParameters = [$userId];
}

$offers = summaryRows("
    SELECT
        o.*,
        o.offer_date + {$responsePeriodDays} AS configured_response_deadline,
        (o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responsePeriodDays}) AS response_period_exceeded,
        u.name AS student_name, sp.matric_id, u.email AS student_email,
        sp.campus, sp.faculty, sp.programme, sp.cgpa,
        c.name AS company_name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email
    FROM offers o
    JOIN users u ON u.id = o.student_id
    JOIN students sp ON sp.user_id = u.id
    JOIN companies c ON c.id = o.company_id
    WHERE {$offerScope}
    ORDER BY o.created_at DESC
", $offerParameters);

if ($role === 'admin') {
    $documentScope = 'sp.coordinator_id = ?';
    $documentParameters = [$userId];
} elseif ($role === 'employer') {
    $documentScope = 'o.company_id = ?';
    $documentParameters = [$companyId];
} else {
    $documentScope = 'd.student_id = ?';
    $documentParameters = [$userId];
}

$documents = summaryRows("
    SELECT d.*, u.name AS student_name, sp.matric_id, c.name AS company_name,
           o.position, o.status AS offer_status, o.placement_verification_status
    FROM documents d
    JOIN users u ON u.id = d.student_id
    JOIN students sp ON sp.user_id = u.id
    LEFT JOIN offers o ON o.id = d.offer_id
    LEFT JOIN companies c ON c.id = o.company_id
    WHERE {$documentScope}
    ORDER BY d.uploaded_at DESC
", $documentParameters);

if ($role === 'admin') {
    $notifications = summaryRows("
        SELECT n.*, recipient.name AS recipient_name, sp.matric_id, sender.name AS sender_name
        FROM notifications n
        JOIN users recipient ON recipient.id = n.recipient_user_id
        JOIN students sp ON sp.user_id = recipient.id
        LEFT JOIN users sender ON sender.id = n.created_by
        WHERE sp.coordinator_id = ?
        ORDER BY n.created_at DESC
    ", [$userId]);
    $announcements = summaryRows("
        SELECT a.*, creator.name AS creator_name
        FROM announcements a
        JOIN users creator ON creator.id = a.created_by
        WHERE a.created_by = ?
        ORDER BY a.created_at DESC
        LIMIT 50
    ", [$userId]);
} else {
    $notifications = summaryRows("
        SELECT n.*, recipient.name AS recipient_name, sp.matric_id, sender.name AS sender_name
        FROM notifications n
        LEFT JOIN users recipient ON recipient.id = n.recipient_user_id
        LEFT JOIN students sp ON sp.user_id = recipient.id
        LEFT JOIN users sender ON sender.id = n.created_by
        WHERE n.recipient_user_id = ?
        ORDER BY n.created_at DESC
    ", [$userId]);
    $announcements = summaryRows("
        SELECT DISTINCT a.*, creator.name AS creator_name
        FROM announcements a
        JOIN notifications n ON n.announcement_id = a.id
        JOIN users creator ON creator.id = a.created_by
        WHERE n.recipient_user_id = ?
        ORDER BY a.created_at DESC
        LIMIT 50
    ", [$userId]);
}

if ($role === 'admin') {
    $applicationScope = 'sp.coordinator_id = ?';
    $applicationParameters = [$userId];
} elseif ($role === 'employer') {
    $applicationScope = 'ia.company_id = ?';
    $applicationParameters = [$companyId];
} else {
    $applicationScope = 'ia.student_id = ?';
    $applicationParameters = [$userId];
}

$applications = summaryRows("
    SELECT ia.*, u.name AS student_name, u.email AS student_email, sp.matric_id,
           c.name AS company_name, c.industry, c.location
    FROM internship_applications ia
    JOIN users u ON u.id = ia.student_id
    JOIN students sp ON sp.user_id = u.id
    JOIN companies c ON c.id = ia.company_id
    WHERE {$applicationScope}
    ORDER BY ia.created_at DESC
", $applicationParameters);

if ($role === 'admin') {
    $activityLogs = summaryRows("
        SELECT al.*, actor.name AS user_name, actor.role AS user_role
        FROM activity_logs al
        LEFT JOIN users actor ON actor.id = al.user_id
        WHERE al.user_id = ?
           OR EXISTS (
                SELECT 1 FROM students scoped_student
                WHERE scoped_student.user_id = al.user_id
                  AND scoped_student.coordinator_id = ?
           )
        ORDER BY al.created_at DESC
        LIMIT 100
    ", [$userId, $userId]);
} else {
    // Company ownership cannot be derived reliably from every activity entity.
    // Returning only the current user's events is safer than leaking other users'
    // cross-company activity.
    $activityLogs = summaryRows("
        SELECT al.*, actor.name AS user_name, actor.role AS user_role
        FROM activity_logs al
        LEFT JOIN users actor ON actor.id = al.user_id
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 100
    ", [$userId]);
}

$companyScope = $role === 'employer' ? 'AND c.id = ?' : '';
$companyParameters = $role === 'employer' ? [$companyId] : [];
$companies = summaryRows("
    SELECT c.id, c.name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email, c.status, c.created_at,
        (SELECT COUNT(*) FROM offers scoped_offer WHERE scoped_offer.company_id = c.id AND scoped_offer.status = 'accepted') AS confirmed_placements,
        (SELECT COUNT(*) FROM offers scoped_offer WHERE scoped_offer.company_id = c.id) AS offers_count,
        (SELECT string_agg(position_row.position, '||' ORDER BY position_row.position)
         FROM (
             SELECT DISTINCT NULLIF(BTRIM(scoped_offer.position), '') AS position
             FROM offers scoped_offer
             WHERE scoped_offer.company_id = c.id
         ) position_row
         WHERE position_row.position IS NOT NULL) AS positions
    FROM companies c
    WHERE c.status = 'active' {$companyScope}
    ORDER BY c.name
", $companyParameters);

$risk = 0;
foreach ($students as &$student) {
    foreach (['offers_count', 'pending_count', 'accepted_count', 'rejected_count', 'withdrawn_count', 'overdue_pending_count'] as $field) {
        $student[$field] = (int)$student[$field];
    }
    $student['flag_reasons'] = offerFlagReasons($student['pending_count'], $student['overdue_pending_count']);
    $student['multiple_pending_flag'] = $student['pending_count'] > 1;
    $student['overdue_pending_flag'] = $student['overdue_pending_count'] > 0;
    $student['is_flagged'] = $student['flag_reasons'] !== [];
    if ($student['is_flagged']) {
        $student['placement_status'] = 'risk';
        $risk++;
    } elseif ($student['accepted_count'] > 0) {
        $student['placement_status'] = 'confirmed';
    } elseif ($student['pending_count'] > 0) {
        $student['placement_status'] = 'pending';
    } else {
        $student['placement_status'] = 'none';
    }
}
unset($student);

$stats = [
    'total_students' => count($students),
    'accepted_offers' => count(array_filter($offers, static fn(array $offer): bool => $offer['status'] === 'accepted')),
    'pending_offers' => count(array_filter($offers, static fn(array $offer): bool => $offer['status'] === 'pending')),
    'rejected_offers' => count(array_filter($offers, static fn(array $offer): bool => $offer['status'] === 'rejected')),
    'withdrawn_offers' => count(array_filter($offers, static fn(array $offer): bool => $offer['status'] === 'withdrawn')),
    'pending_documents' => count(array_filter($documents, static fn(array $document): bool => $document['status'] === 'pending')),
    'active_companies' => count($companies),
    'unread_notifications' => count(array_filter($notifications, static fn(array $note): bool => empty($note['is_read']))),
    'total_applications' => count($applications),
    'active_applications' => count(array_filter(
        $applications,
        static fn(array $application): bool => in_array($application['status'], ['submitted', 'shortlisted', 'interview'], true)
    )),
    'total_announcements' => count($announcements),
    'risk_students' => $risk,
];

respond([
    'user' => $user,
    'stats' => $stats,
    'students' => $students,
    'offers' => $offers,
    'documents' => $documents,
    'companies' => $companies,
    'notifications' => $notifications,
    'announcements' => $announcements,
    'applications' => $applications,
    'activity_logs' => $activityLogs,
    'settings' => ['offer_response_period_days' => $responsePeriodDays],
]);
