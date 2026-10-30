<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/offer-flags.php';
require_once __DIR__ . '/report-export.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    respond(['error' => 'Admins only'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
$report = strtolower(trim((string)($_GET['report'] ?? 'placement')));
if (!in_array($format, ['csv', 'pdf'], true)) {
    respond(['error' => 'Unsupported report format'], 422);
}
if (!in_array($report, ['placement', 'offers'], true)) {
    respond(['error' => 'Unsupported report type'], 422);
}

$responseDays = offerResponsePeriodDays();
$coordinatorId = (int)$user['id'];
$headers = [];
$rows = [];
$columns = [];
$title = '';
$subtitle = '';

if ($report === 'placement') {
    $result = db()->query("
        SELECT
            u.id,
            u.name,
            sp.matric_id,
            sp.faculty,
            sp.programme,
            COUNT(o.id) FILTER (WHERE o.status = 'pending') AS pending_count,
            COUNT(o.id) FILTER (WHERE o.status = 'accepted') AS accepted_count,
            COUNT(o.id) FILTER (WHERE o.status = 'rejected') AS rejected_count,
            COUNT(o.id) FILTER (WHERE o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responseDays}) AS overdue_pending_count,
            MAX(CASE WHEN o.status = 'accepted' THEN c.name END) AS placement_company,
            MAX(CASE WHEN o.status = 'accepted' THEN c.industry END) AS placement_industry,
            MAX(CASE WHEN o.status = 'accepted' THEN c.allowance END) AS placement_allowance,
            MAX(CASE WHEN o.status = 'accepted' THEN o.placement_verification_status END) AS placement_verification_status
        FROM users u
        JOIN students sp ON sp.user_id = u.id
        LEFT JOIN offers o ON o.student_id = u.id
        LEFT JOIN companies c ON c.id = o.company_id
        WHERE u.role = 'student' AND sp.coordinator_id = {$coordinatorId}
        GROUP BY u.id, u.name, sp.matric_id, sp.faculty, sp.programme
        ORDER BY u.name
    ");
    if (!$result) {
        respond(['error' => db()->error], 500);
    }

    $records = $result->fetch_all(MYSQLI_ASSOC);
    $confirmed = 0;
    $pending = 0;
    $flagged = 0;
    foreach ($records as $index => $record) {
        $pendingCount = (int)$record['pending_count'];
        $acceptedCount = (int)$record['accepted_count'];
        $overdueCount = (int)$record['overdue_pending_count'];
        $flagReasons = offerFlagReasons($pendingCount, $overdueCount);

        if ($flagReasons !== []) {
            $status = 'Flagged';
            $flagged++;
        } elseif ($acceptedCount > 0) {
            $status = 'Confirmed';
            $confirmed++;
        } elseif ($pendingCount > 0) {
            $status = 'Pending';
            $pending++;
        } else {
            $status = 'No placement';
        }

        $rows[] = [
            $index + 1,
            $record['name'],
            $record['matric_id'] ?: '-',
            $record['faculty'] ?: '-',
            $record['programme'] ?: '-',
            $record['placement_company'] ?: '-',
            $record['placement_industry'] ?: '-',
            $record['placement_allowance'] !== null ? 'RM ' . number_format((float)$record['placement_allowance'], 2) : '-',
            $status,
            ($record['placement_verification_status'] ?? 'not_submitted') === 'verified' ? 'Yes' : 'No',
            $flagReasons !== [] ? implode('; ', $flagReasons) : '-',
        ];
    }

    $headers = ['No.', 'Student', 'Matric ID', 'Faculty', 'Programme', 'Company', 'Industry', 'Allowance', 'Placement Status', 'Placement Verified', 'Risk Indicator'];
    $columns = [
        ['label' => 'No.', 'width' => 25, 'max' => 4],
        ['label' => 'Student', 'width' => 85, 'max' => 18],
        ['label' => 'Matric ID', 'width' => 62, 'max' => 13],
        ['label' => 'Faculty', 'width' => 82, 'max' => 16],
        ['label' => 'Programme', 'width' => 85, 'max' => 17],
        ['label' => 'Company', 'width' => 80, 'max' => 16],
        ['label' => 'Industry', 'width' => 55, 'max' => 11],
        ['label' => 'Allowance', 'width' => 58, 'max' => 12],
        ['label' => 'Status', 'width' => 58, 'max' => 12],
        ['label' => 'Verified', 'width' => 48, 'max' => 9],
        ['label' => 'Risk indicator', 'width' => 115, 'max' => 24],
    ];
    $title = 'InternPilih Placement Summary';
    $subtitle = sprintf(
        '%d students | %d confirmed | %d pending | %d flagged | Response period: %d days',
        count($records),
        $confirmed,
        $pending,
        $flagged,
        $responseDays
    );
} else {
    $result = db()->query("
        SELECT
            o.id,
            u.name AS student_name,
            sp.matric_id,
            c.name AS company_name,
            o.position,
            o.offer_date,
            o.deadline,
            o.offer_date + {$responseDays} AS configured_response_deadline,
            o.status,
            o.response_date,
            o.placement_verification_status,
            (o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responseDays}) AS response_period_exceeded,
            flags.pending_count,
            flags.overdue_pending_count
        FROM offers o
        JOIN users u ON u.id = o.student_id
        JOIN students sp ON sp.user_id = u.id
        JOIN companies c ON c.id = o.company_id
        JOIN (
            SELECT
                student_id,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending_count,
                COUNT(*) FILTER (WHERE status = 'pending' AND CURRENT_DATE - offer_date > {$responseDays}) AS overdue_pending_count
            FROM offers
            GROUP BY student_id
        ) flags ON flags.student_id = o.student_id
        WHERE sp.coordinator_id = {$coordinatorId}
        ORDER BY o.created_at DESC, o.id DESC
    ");
    if (!$result) {
        respond(['error' => db()->error], 500);
    }

    $records = $result->fetch_all(MYSQLI_ASSOC);
    $pendingOffers = 0;
    $overdueOffers = 0;
    foreach ($records as $index => $record) {
        $isOverdue = (int)$record['response_period_exceeded'] > 0;
        if ($record['status'] === 'pending') {
            $pendingOffers++;
        }
        if ($isOverdue) {
            $overdueOffers++;
        }
        $flagReasons = offerFlagReasons((int)$record['pending_count'], (int)$record['overdue_pending_count']);

        $rows[] = [
            $index + 1,
            $record['student_name'],
            $record['matric_id'] ?: '-',
            $record['company_name'],
            $record['position'],
            $record['offer_date'],
            $record['deadline'] ?: $record['configured_response_deadline'],
            ucfirst($record['status']),
            $record['response_date'] ?: '-',
            ucwords(str_replace('_', ' ', $record['placement_verification_status'])),
            $isOverdue ? 'Yes' : 'No',
            $flagReasons !== [] ? implode('; ', $flagReasons) : '-',
        ];
    }

    $headers = ['No.', 'Student', 'Matric ID', 'Company', 'Position', 'Offer Date', 'Response Deadline', 'Status', 'Response Date', 'Placement Verification', 'Overdue', 'Student Risk Indicator'];
    $columns = [
        ['label' => 'No.', 'width' => 24, 'max' => 4],
        ['label' => 'Student', 'width' => 72, 'max' => 15],
        ['label' => 'Matric', 'width' => 58, 'max' => 12],
        ['label' => 'Company', 'width' => 72, 'max' => 15],
        ['label' => 'Position', 'width' => 78, 'max' => 16],
        ['label' => 'Offer date', 'width' => 52, 'max' => 10],
        ['label' => 'Deadline', 'width' => 52, 'max' => 10],
        ['label' => 'Status', 'width' => 48, 'max' => 9],
        ['label' => 'Response', 'width' => 52, 'max' => 10],
        ['label' => 'Verification', 'width' => 67, 'max' => 13],
        ['label' => 'Overdue', 'width' => 40, 'max' => 7],
        ['label' => 'Student risk indicator', 'width' => 160, 'max' => 35],
    ];
    $title = 'InternPilih Offer-Status Report';
    $subtitle = sprintf(
        '%d offers | %d pending | %d overdue | Response period: %d days',
        count($records),
        $pendingOffers,
        $overdueOffers,
        $responseDays
    );
}

$baseFilename = 'internpilih-' . $report . '-report-' . date('Y-m-d');
logActivity((int)$user['id'], 'report.exported', 'report', null, [
    'report' => $report,
    'format' => $format,
    'row_count' => count($rows),
]);

if ($format === 'csv') {
    sendReportCsv($baseFilename . '.csv', $headers, $rows);
}

$pdf = buildReportPdf($title, $subtitle, $columns, $rows, (string)$user['name']);
sendReportPdf($baseFilename . '.pdf', $pdf);
