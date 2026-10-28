<?php
// api/summary.php - Aggregated dashboard data for all portals
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/offer-flags.php';

$user = requireAuth();
$responsePeriodDays = offerResponsePeriodDays();

function rows(string $sql): array {
    $result = db()->query($sql);
    if (!$result) {
        respond(['error' => db()->error], 500);
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function one(string $sql): array {
    $result = db()->query($sql);
    if (!$result) {
        respond(['error' => db()->error], 500);
    }
    return $result->fetch_assoc() ?: [];
}

$students = rows("
    SELECT
        u.id,
        u.name,
        u.email,
        sp.matric_id,
        sp.faculty,
        sp.programme,
        sp.coordinator_id,
        coordinator.name AS coordinator_name,
        sp.cgpa,
        COUNT(o.id) AS offers_count,
        SUM(o.status = 'pending') AS pending_count,
        SUM(o.status = 'accepted') AS accepted_count,
        SUM(o.status = 'rejected') AS rejected_count,
        SUM(o.status = 'withdrawn') AS withdrawn_count,
        SUM(o.status = 'pending' AND DATEDIFF(CURDATE(), o.offer_date) > {$responsePeriodDays}) AS overdue_pending_count,
        MIN(CASE WHEN o.status = 'pending' THEN DATE_ADD(o.offer_date, INTERVAL {$responsePeriodDays} DAY) END) AS next_response_deadline,
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
    WHERE u.role = 'student'
    GROUP BY u.id
    ORDER BY u.name
");

$offers = rows("
    SELECT
        o.*,
        DATE_ADD(o.offer_date, INTERVAL {$responsePeriodDays} DAY) AS configured_response_deadline,
        (o.status = 'pending' AND DATEDIFF(CURDATE(), o.offer_date) > {$responsePeriodDays}) AS response_period_exceeded,
        u.name AS student_name,
        sp.matric_id,
        u.email AS student_email,
        sp.faculty,
        sp.programme,
        sp.cgpa,
        c.name AS company_name,
        c.industry,
        c.location,
        c.allowance,
        c.work_mode,
        c.hr_email
    FROM offers o
    JOIN users u ON u.id = o.student_id
    JOIN students sp ON sp.user_id = u.id
    JOIN companies c ON c.id = o.company_id
    ORDER BY o.created_at DESC
");

$documents = rows("
    SELECT
        d.*,
        u.name AS student_name,
        sp.matric_id,
        c.name AS company_name,
        o.position,
        o.status AS offer_status,
        o.placement_verification_status
    FROM documents d
    JOIN users u ON u.id = d.student_id
    JOIN students sp ON sp.user_id = u.id
    LEFT JOIN offers o ON o.id = d.offer_id
    LEFT JOIN companies c ON c.id = o.company_id
    ORDER BY d.uploaded_at DESC
");

$notifications = rows("
    SELECT n.*, u.name AS recipient_name, sp.matric_id, s.name AS sender_name
    FROM notifications n
    LEFT JOIN users u ON u.id = n.recipient_user_id
    LEFT JOIN students sp ON sp.user_id = u.id
    LEFT JOIN users s ON s.id = n.created_by
    ORDER BY n.created_at DESC
");

$announcements = rows("
    SELECT a.*, u.name AS creator_name
    FROM announcements a
    JOIN users u ON u.id = a.created_by
    ORDER BY a.created_at DESC
    LIMIT 50
");

$applications = rows("
    SELECT
        ia.*,
        u.name AS student_name,
        u.email AS student_email,
        sp.matric_id,
        c.name AS company_name,
        c.industry,
        c.location
    FROM internship_applications ia
    JOIN users u ON u.id = ia.student_id
    JOIN students sp ON sp.user_id = u.id
    JOIN companies c ON c.id = ia.company_id
    ORDER BY ia.created_at DESC
");

$activityLogs = rows("
    SELECT al.*, u.name AS user_name, u.role AS user_role
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 100
");

$companies = rows("
    SELECT c.id, c.name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email, c.status, c.created_at,
        (SELECT COUNT(*) FROM offers o WHERE o.company_id = c.id AND o.status = 'accepted') AS confirmed_placements,
        (SELECT COUNT(*) FROM offers o WHERE o.company_id = c.id) AS offers_count,
        (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(o.position), '') ORDER BY o.position SEPARATOR '||')
         FROM offers o WHERE o.company_id = c.id) AS positions
    FROM companies c
    WHERE c.status = 'active'
    ORDER BY c.name
");

$stats = one("
    SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') AS total_students,
        (SELECT COUNT(*) FROM offers WHERE status = 'accepted') AS accepted_offers,
        (SELECT COUNT(*) FROM offers WHERE status = 'pending') AS pending_offers,
        (SELECT COUNT(*) FROM offers WHERE status = 'rejected') AS rejected_offers,
        (SELECT COUNT(*) FROM offers WHERE status = 'withdrawn') AS withdrawn_offers,
        (SELECT COUNT(*) FROM documents WHERE status = 'pending') AS pending_documents,
        (SELECT COUNT(*) FROM companies WHERE status = 'active') AS active_companies,
        (SELECT COUNT(*) FROM notifications WHERE is_read = 0) AS unread_notifications,
        (SELECT COUNT(*) FROM internship_applications) AS total_applications,
        (SELECT COUNT(*) FROM internship_applications WHERE status IN ('submitted','shortlisted','interview')) AS active_applications,
        (SELECT COUNT(*) FROM announcements) AS total_announcements
");

$risk = 0;
foreach ($students as &$student) {
    $student['offers_count'] = (int)$student['offers_count'];
    $student['pending_count'] = (int)$student['pending_count'];
    $student['accepted_count'] = (int)$student['accepted_count'];
    $student['rejected_count'] = (int)$student['rejected_count'];
    $student['withdrawn_count'] = (int)$student['withdrawn_count'];
    $student['overdue_pending_count'] = (int)$student['overdue_pending_count'];
    $student['flag_reasons'] = offerFlagReasons($student['pending_count'], $student['overdue_pending_count']);
    $student['multiple_pending_flag'] = $student['pending_count'] > 1;
    $student['overdue_pending_flag'] = $student['overdue_pending_count'] > 0;
    $student['is_flagged'] = count($student['flag_reasons']) > 0;
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

$stats['risk_students'] = $risk;

if ($user['role'] === 'admin') {
    $coordinatorId = (int)$user['id'];
    $students = array_values(array_filter($students, fn($student) => (int)($student['coordinator_id'] ?? 0) === $coordinatorId));
    $ownStudentIds = array_fill_keys(array_map(fn($student) => (int)$student['id'], $students), true);
    $offers = array_values(array_filter($offers, fn($offer) => isset($ownStudentIds[(int)$offer['student_id']])));
    $documents = array_values(array_filter($documents, fn($doc) => isset($ownStudentIds[(int)$doc['student_id']])));
    $applications = array_values(array_filter($applications, fn($application) => isset($ownStudentIds[(int)$application['student_id']])));
    $notifications = array_values(array_filter($notifications, fn($note) => isset($ownStudentIds[(int)($note['recipient_user_id'] ?? 0)])));
    $announcements = array_values(array_filter($announcements, fn($announcement) => (int)$announcement['created_by'] === $coordinatorId));
    $activityLogs = array_values(array_filter($activityLogs, fn($log) => (int)($log['user_id'] ?? 0) === $coordinatorId || isset($ownStudentIds[(int)($log['user_id'] ?? 0)])));
    $stats['total_students'] = count($students);
    foreach (['accepted', 'pending', 'rejected', 'withdrawn'] as $status) {
        $stats[$status . '_offers'] = count(array_filter($offers, fn($offer) => $offer['status'] === $status));
    }
    $stats['pending_documents'] = count(array_filter($documents, fn($doc) => $doc['status'] === 'pending'));
    $stats['unread_notifications'] = count(array_filter($notifications, fn($note) => (int)$note['is_read'] === 0));
    $stats['total_applications'] = count($applications);
    $stats['active_applications'] = count(array_filter($applications, fn($application) => in_array($application['status'], ['submitted', 'shortlisted', 'interview'], true)));
    $stats['total_announcements'] = count($announcements);
    $stats['risk_students'] = count(array_filter($students, fn($student) => $student['is_flagged']));
} elseif ($user['role'] === 'employer') {
    $companyId = (int)($user['company_id'] ?? 0);
    $offers = array_values(array_filter($offers, fn($offer) => (int)$offer['company_id'] === $companyId));
    $companies = array_values(array_filter($companies, fn($company) => (int)$company['id'] === $companyId));
    $documents = array_values(array_filter($documents, function ($doc) use ($offers) {
        foreach ($offers as $offer) {
            if ((int)$offer['id'] === (int)($doc['offer_id'] ?? 0)) return true;
        }
        return false;
    }));
    $notifications = array_values(array_filter($notifications, fn($note) => (int)($note['recipient_user_id'] ?? 0) === (int)$user['id']));
    $applications = array_values(array_filter($applications, fn($application) => (int)$application['company_id'] === $companyId));
    $activityLogs = array_values(array_filter($activityLogs, fn($log) => $log['entity_type'] !== 'user'));
} elseif ($user['role'] === 'student') {
    $studentId = (int)$user['id'];
    $offers = array_values(array_filter($offers, fn($offer) => (int)$offer['student_id'] === $studentId));
    $documents = array_values(array_filter($documents, fn($doc) => (int)$doc['student_id'] === $studentId));
    $notifications = array_values(array_filter($notifications, fn($note) => (int)($note['recipient_user_id'] ?? 0) === $studentId));
    $applications = array_values(array_filter($applications, fn($application) => (int)$application['student_id'] === $studentId));
    $activityLogs = array_values(array_filter($activityLogs, fn($log) => (int)($log['user_id'] ?? 0) === $studentId));
    $stats['unread_notifications'] = count(array_filter($notifications, fn($note) => (int)$note['is_read'] === 0));
}

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
    'settings' => [
        'offer_response_period_days' => $responsePeriodDays,
    ],
]);
