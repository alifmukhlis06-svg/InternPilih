<?php
/**
 * Add fictional, connected demo records to an existing InternPilih database.
 * Run: php database/seed-realistic-demo.php
 * Safe to rerun: every record has a stable natural key and existing records are kept.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$db = db();
$adminId = (int)($db->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch_assoc()['id'] ?? 0);
if (!$adminId) {
    throw new RuntimeException('Create a coordinator account before seeding demo data.');
}

function seedRun(string $sql, array $values = []): mysqli_stmt {
    global $db;
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->execute($values);
    return $stmt;
}

function seedId(string $sql, array $values): ?int {
    $row = seedRun($sql, $values)->get_result()->fetch_assoc();
    return $row ? (int)$row['id'] : null;
}

function pdfSample(string $filename, array $lines): void {
    $path = __DIR__ . '/../uploads/' . $filename;
    if (is_file($path)) {
        $existing = file_get_contents($path);
        if ($existing === false || !str_contains($existing, 'SAMPLE DOCUMENT - DEMO ONLY')) return;
        // Refresh only our generated samples when a student's display name changed.
        if (str_contains($existing, explode(' (', $lines[1])[0])) return;
    }
    $escaped = array_map(static fn(string $line): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line), $lines);
    $stream = "BT /F1 18 Tf 48 790 Td (SAMPLE DOCUMENT - DEMO ONLY) Tj /F1 11 Tf";
    foreach ($escaped as $line) $stream .= " 0 -23 Td ($line) Tj";
    $stream .= ' ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    if (file_put_contents($path, $pdf) === false) throw new RuntimeException('Could not create ' . $path);
}

// Repair the three original schema fixtures so every document row has a file.
pdfSample('offer_letter_petronas.pdf', [
    'InternPilih sample placement record',
    'Student: Alif Mukhlis bin Ahmad (2025161705)',
    'Document: Offer letter',
    'Placement: Data Analyst at Petronas ICT Sdn Bhd',
    'This file is fictional and is for interface demonstration only.',
]);
pdfSample('student_id_alif.pdf', [
    'InternPilih sample student identification record',
    'Student: Alif Mukhlis bin Ahmad',
    'Student ID: 2025161705',
    'Universiti Teknologi MARA',
    'This file is fictional and is for interface demonstration only.',
]);
pdfSample('agreement_alif.pdf', [
    'InternPilih sample internship agreement',
    'Student: Alif Mukhlis bin Ahmad (2025161705)',
    'Company: Petronas ICT Sdn Bhd',
    'Placement: Data Analyst',
    'This file is fictional and is for interface demonstration only.',
]);

// Faculty names follow UiTM's academic faculty listing. All people are fictional.
$students = [];
foreach (require __DIR__ . '/student-data.php' as $matric => $profile) {
    if (str_starts_with((string)$matric, '2026124')) {
        $students[] = [$profile[0], $matric, $profile[1], $profile[2], $profile[3]];
    }
}
$coordinatorProfiles = require __DIR__ . '/coordinator-data.php';

$companies = [
    ['Meridian Data Labs Sdn Bhd', 'Data Analytics', 'Shah Alam, Selangor', 1500, 'Hybrid', 'careers@meridiandatalabs.example.test'],
    ['Cendana Creative Studio Sdn Bhd', 'Creative Services', 'Petaling Jaya, Selangor', 1100, 'Hybrid', 'talent@cendanacreative.example.test'],
    ['Nusa Civil Consultants Sdn Bhd', 'Engineering Consultancy', 'Kuala Lumpur', 1300, 'On-site', 'internship@nusacivil.example.test'],
    ['Sinaraya Health Services Sdn Bhd', 'Healthcare', 'Shah Alam, Selangor', 1250, 'On-site', 'people@sinarayahealth.example.test'],
    ['Bayu Digital Commerce Sdn Bhd', 'E-Commerce', 'Subang Jaya, Selangor', 1400, 'Hybrid', 'hr@bayudigital.example.test'],
    ['Rimba Agrotech Sdn Bhd', 'Agriculture Technology', 'Klang, Selangor', 1200, 'On-site', 'careers@rimbaagrotech.example.test'],
    ['Jelita Hospitality Group Sdn Bhd', 'Hospitality', 'Kuala Lumpur', 1100, 'On-site', 'people@jelitahospitality.example.test'],
    ['Awan Cloudworks Sdn Bhd', 'Cloud Services', 'Cyberjaya, Selangor', 1700, 'Remote', 'interns@awancloudworks.example.test'],
    ['Puncak Finance Advisory Sdn Bhd', 'Financial Services', 'Kuala Lumpur', 1450, 'Hybrid', 'careers@puncakfinance.example.test'],
    ['Lestari Learning Hub Sdn Bhd', 'Education Technology', 'Shah Alam, Selangor', 1150, 'Hybrid', 'team@lestarilearning.example.test'],
    ['Teras Engineering Solutions Sdn Bhd', 'Manufacturing', 'Bangi, Selangor', 1350, 'On-site', 'hr@terasengineering.example.test'],
    ['Wira Media House Sdn Bhd', 'Media and Communications', 'Petaling Jaya, Selangor', 1200, 'Hybrid', 'talent@wiramedia.example.test'],
];

$positions = [
    'Audit and Assurance Intern', 'Marketing Intern', 'Laboratory Research Intern', 'Civil Engineering Intern',
    'Electrical Systems Intern', 'Mechanical Design Intern', 'Process Engineering Intern', 'Quantity Surveying Intern',
    'Medical Laboratory Intern', 'Pharmacy Operations Intern', 'Public Relations Intern', 'Graphic Design Intern',
    'Learning Design Intern', 'Legal Research Intern', 'Information Management Intern', 'Hotel Operations Intern',
    'Policy Research Intern', 'Agritech Operations Intern', 'Sports Programme Intern', 'Animation Production Intern',
    'Audio Production Intern', 'Software Engineering Intern', 'Dental Clinic Operations Intern', 'Clinical Research Intern',
];

$companyChoices = [8, 4, 0, 2, 10, 10, 2, 2, 3, 3, 11, 1, 9, 8, 0, 6, 8, 5, 6, 1, 11, 7, 3, 3];
$employerContactNames = [
    0 => 'Amir Hakimi bin Sulaiman',
    3 => 'Nur Syuhada binti Ibrahim',
    7 => 'Faiz Ariff bin Mohamad',
    11 => 'Siti Hajar binti Ismail',
];
$additionalCompanies = [];
$counts = ['students' => 0, 'coordinators' => 0, 'companies' => 0, 'employers' => 0, 'offers' => 0, 'applications' => 0, 'documents' => 0, 'announcements' => 0, 'notifications' => 0, 'activity' => 0, 'change_requests' => 0];
$today = new DateTimeImmutable('today');
$password = password_hash('password123', PASSWORD_DEFAULT);

$db->begin_transaction();
try {
    seedRun("UPDATE documents SET file_name = 'student_id_alif.pdf' WHERE file_name = 'student_id_alif.jpg'");
    foreach ($companies as $company) {
        $id = seedId('SELECT id FROM companies WHERE name = ? LIMIT 1', [$company[0]]);
        if (!$id) {
            seedRun("INSERT INTO companies (name, industry, location, allowance, work_mode, hr_email, status) VALUES (?, ?, ?, ?, ?, ?, 'active')", $company);
            $id = (int)$db->insert_id;
            $counts['companies']++;
        }
        $additionalCompanies[] = $id;
    }

    foreach ([0, 3, 7, 11] as $index) {
        $company = $companies[$index];
        $email = $company[5];
        if (!seedId('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
            seedRun("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'employer')", [$employerContactNames[$index], $email, $password]);
            $counts['employers']++;
        }
        $employerId = seedId('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        seedRun('UPDATE users SET name = ? WHERE id = ? AND role = \'employer\'', [$employerContactNames[$index], $employerId]);
        if (!seedId('SELECT user_id AS id FROM employers WHERE user_id = ? LIMIT 1', [$employerId])) {
            seedRun('INSERT INTO employers (user_id, company_id) VALUES (?, ?)', [$employerId, $additionalCompanies[$index]]);
        }
    }

    foreach ($coordinatorProfiles as $programme => [$name, $email, $faculty]) {
        $coordinatorId = seedId('SELECT id FROM users WHERE email = ? AND role = \'admin\' LIMIT 1', [$email]);
        if (!$coordinatorId) {
            seedRun("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')", [$name, $email, $password]);
            $coordinatorId = (int)$db->insert_id;
            $counts['coordinators']++;
        }
        seedRun('UPDATE users SET name = ? WHERE id = ?', [$name, $coordinatorId]);
        if (!seedId('SELECT user_id AS id FROM admins WHERE user_id = ? LIMIT 1', [$coordinatorId])) {
            seedRun('INSERT INTO admins (user_id, faculty, programme) VALUES (?, ?, ?)', [$coordinatorId, $faculty, $programme]);
        } else {
            seedRun('UPDATE admins SET faculty = ?, programme = ? WHERE user_id = ?', [$faculty, $programme, $coordinatorId]);
        }
    }

    foreach ($students as $index => $student) {
        [$name, $matric, $faculty, $programme, $cgpa] = $student;
        $coordinatorId = seedId('SELECT user_id AS id FROM admins WHERE programme = ? LIMIT 1', [$programme]);
        if (!$coordinatorId) throw new RuntimeException('No coordinator exists for programme: ' . $programme);
        $email = $matric . '@student.uitm.edu.my';
        $studentId = seedId('SELECT user_id AS id FROM students WHERE matric_id = ? LIMIT 1', [$matric]);
        if (!$studentId) $studentId = seedId('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$studentId) {
            seedRun("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')", [$name, $email, $password]);
            $studentId = (int)$db->insert_id;
            $counts['students']++;
        }
        if (!seedId('SELECT user_id AS id FROM students WHERE user_id = ? LIMIT 1', [$studentId])) {
            seedRun('INSERT INTO students (user_id, matric_id, faculty, programme, cgpa) VALUES (?, ?, ?, ?, ?)', [$studentId, $matric, $faculty, $programme, $cgpa]);
        }
        seedRun('UPDATE users SET name = ?, email = ? WHERE id = ? AND role = \'student\'', [$name, $email, $studentId]);
        seedRun('UPDATE students SET faculty = ?, programme = ? WHERE user_id = ?', [$faculty, $programme, $studentId]);

        $companyIndex = $companyChoices[$index % count($companyChoices)];
        $companyId = $additionalCompanies[$companyIndex];
        $position = $positions[$index % count($positions)];
        $status = $index % 6 === 0 ? 'pending' : ($index % 6 === 5 ? 'rejected' : 'accepted');
        $offerId = seedId('SELECT id FROM offers WHERE student_id = ? AND company_id = ? AND position = ? LIMIT 1', [$studentId, $companyId, $position]);
        if (!$offerId) {
            $offerDate = $today->modify('-' . (4 + $index % 10) . ' days')->format('Y-m-d');
            $deadline = $today->modify('+' . (5 + $index % 11) . ' days')->format('Y-m-d');
            $responseDate = $status === 'pending' ? null : $today->modify('-' . (1 + $index % 3) . ' days')->format('Y-m-d');
            $reason = $status === 'rejected' ? 'Thank you for the opportunity. I have decided to pursue a different internship aligned with my study plan.' : null;
            seedRun('INSERT INTO offers (student_id, company_id, position, offer_date, deadline, status, response_date, reject_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$studentId, $companyId, $position, $offerDate, $deadline, $status, $responseDate, $reason]);
            $offerId = (int)$db->insert_id;
            $counts['offers']++;
        }

        if (!seedId('SELECT id FROM internship_applications WHERE student_id = ? AND company_id = ? AND position = ? LIMIT 1', [$studentId, $companyId, $position])) {
            $appliedAt = $today->modify('-' . (23 + $index % 12) . ' days')->format('Y-m-d');
            seedRun("INSERT INTO internship_applications (student_id, company_id, position, source, status, applied_at, offer_id, notes) VALUES (?, ?, ?, 'student', 'offered', ?, ?, ?)", [$studentId, $companyId, $position, $appliedAt, $offerId, 'Interview completed; the company issued a formal offer.']);
            $counts['applications']++;
        }

        $otherCompanyId = $additionalCompanies[($companyIndex + 5) % count($additionalCompanies)];
        $otherPosition = $position . ' - Alternative Placement';
        $secondOfferId = null;
        if ($index % 3 === 0 || $index % 7 === 0) {
            $secondOfferId = seedId('SELECT id FROM offers WHERE student_id = ? AND company_id = ? AND position = ? LIMIT 1', [$studentId, $otherCompanyId, $otherPosition]);
            if (!$secondOfferId) {
                $secondStatus = $status === 'accepted' && $index % 2 === 0 ? 'pending' : 'rejected';
                $responseDate = $secondStatus === 'pending' ? null : $today->modify('-2 days')->format('Y-m-d');
                $reason = $secondStatus === 'rejected' ? 'Thank you for considering me. I have selected a different internship placement.' : null;
                seedRun('INSERT INTO offers (student_id, company_id, position, offer_date, deadline, status, response_date, reject_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$studentId, $otherCompanyId, $otherPosition, $today->modify('-6 days')->format('Y-m-d'), $today->modify('+8 days')->format('Y-m-d'), $secondStatus, $responseDate, $reason]);
                $secondOfferId = (int)$db->insert_id;
                $counts['offers']++;
            }
            if (!seedId('SELECT id FROM internship_applications WHERE student_id = ? AND company_id = ? AND position = ? LIMIT 1', [$studentId, $otherCompanyId, $otherPosition])) {
                seedRun("INSERT INTO internship_applications (student_id, company_id, position, source, status, applied_at, offer_id, notes) VALUES (?, ?, ?, 'student', 'offered', ?, ?, ?)", [$studentId, $otherCompanyId, $otherPosition, $today->modify('-20 days')->format('Y-m-d'), $secondOfferId, 'Additional offer recorded for comparison.']);
                $counts['applications']++;
            }
        }

        $pipelineCompanyId = $additionalCompanies[($companyIndex + 8) % count($additionalCompanies)];
        $pipelinePosition = 'Graduate Internship - ' . explode(' Intern', $position)[0];
        if (!seedId('SELECT id FROM internship_applications WHERE student_id = ? AND company_id = ? AND position = ? LIMIT 1', [$studentId, $pipelineCompanyId, $pipelinePosition])) {
            $pipelineStatus = ['submitted', 'shortlisted', 'interview', 'rejected', 'draft'][$index % 5];
            $interviewAt = $pipelineStatus === 'interview' ? $today->modify('+4 days')->format('Y-m-d 10:00:00') : null;
            seedRun("INSERT INTO internship_applications (student_id, company_id, position, source, status, applied_at, interview_at, notes) VALUES (?, ?, ?, 'student', ?, ?, ?, ?)", [$studentId, $pipelineCompanyId, $pipelinePosition, $pipelineStatus, $today->modify('-' . (7 + $index % 12) . ' days')->format('Y-m-d'), $interviewAt, 'Application tracked in the student portal.']);
            $counts['applications']++;
        }

        if ($status === 'accepted' && $index % 5 !== 3) {
            foreach (['offer_letter', 'student_id', 'agreement'] as $docIndex => $type) {
                // Leave a few placements incomplete to exercise the verification queue.
                if ($index % 5 === 1 && $type === 'agreement') continue;
                $existingDocumentId = seedId('SELECT id FROM documents WHERE offer_id = ? AND doc_type = ? LIMIT 1', [$offerId, $type]);
                $docStatus = $index % 7 === 4 && $type === 'agreement' ? 'rejected' : ($index % 4 === 1 && $type === 'offer_letter' ? 'pending' : 'approved');
                $filename = 'sample_' . $matric . '_' . $type . '.pdf';
                pdfSample($filename, [
                    'InternPilih sample placement record',
                    'Student: ' . $name . ' (' . $matric . ')',
                    'Document: ' . str_replace('_', ' ', ucfirst($type)),
                    'Placement: ' . $position,
                    'This file is fictional and is for interface demonstration only.',
                ]);
                if ($existingDocumentId) continue;
                $reviewedBy = $docStatus === 'pending' ? null : $coordinatorId;
                $reviewedAt = $docStatus === 'pending' ? null : $today->modify('-1 day')->format('Y-m-d 14:00:00');
                $rejectReason = $docStatus === 'rejected' ? 'Please upload a clearer signed copy.' : null;
                seedRun('INSERT INTO documents (student_id, offer_id, doc_type, file_name, status, reject_reason, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$studentId, $offerId, $type, $filename, $docStatus, $rejectReason, $reviewedBy, $reviewedAt]);
                $counts['documents']++;
            }
        }

        $state = seedRun('SELECT d.doc_type, d.status FROM documents d JOIN (SELECT doc_type, MAX(id) id FROM documents WHERE offer_id = ? AND doc_type IN (\'offer_letter\',\'student_id\',\'agreement\') GROUP BY doc_type) latest ON latest.id = d.id', [$offerId])->get_result()->fetch_all(MYSQLI_ASSOC);
        $verification = 'not_submitted';
        if ($status === 'accepted' && $state) {
            $byType = array_column($state, 'status', 'doc_type');
            $verification = in_array('rejected', $byType, true) ? 'rejected' : (count(array_filter(['offer_letter', 'student_id', 'agreement'], static fn($type) => ($byType[$type] ?? null) === 'approved')) === 3 ? 'verified' : 'pending');
        }
        if ($status === 'accepted') {
            seedRun('UPDATE offers SET placement_verification_status = ?, placement_verification_updated_at = NOW(), placement_verification_updated_by = ? WHERE id = ? AND placement_verification_status <> ?', [$verification, $coordinatorId, $offerId, $verification]);
        }

        if ($status === 'accepted' && $secondOfferId && $index === 14 && !seedId('SELECT id FROM placement_change_requests WHERE student_id = ? AND current_offer_id = ? AND requested_offer_id = ? LIMIT 1', [$studentId, $offerId, $secondOfferId])) {
            seedRun("INSERT INTO placement_change_requests (student_id, current_offer_id, requested_offer_id, reason, status) VALUES (?, ?, ?, ?, 'pending')", [$studentId, $offerId, $secondOfferId, 'The alternative placement offers a stronger match with my information management specialisation.']);
            $counts['change_requests']++;
        }

        $noticeTitle = $status === 'accepted' ? 'Placement confirmation' : ($status === 'pending' ? 'Offer response due soon' : 'Offer response recorded');
        $noticeBody = $status === 'accepted' ? 'Your internship placement has been recorded. Check your document verification status and complete any missing items.' : ($status === 'pending' ? 'You have a pending internship offer. Please review its terms and respond before the deadline.' : 'Your response to the internship offer has been recorded.');
        if (!seedId('SELECT id FROM notifications WHERE recipient_user_id = ? AND title = ? AND body = ? LIMIT 1', [$studentId, $noticeTitle, $noticeBody])) {
            seedRun("INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group, is_read) VALUES (?, ?, 'system', ?, ?, ?, 'student', ?)", [$studentId, $coordinatorId, $noticeTitle, $noticeBody, $status === 'accepted' ? 'documents.html' : 'offers.html', $index % 3 === 0 ? 0 : 1]);
            $counts['notifications']++;
        }
        if (!seedId("SELECT id FROM activity_logs WHERE user_id = ? AND action = 'demo.offer_recorded' AND entity_type = 'offer' AND entity_id = ? LIMIT 1", [$studentId, $offerId])) {
            seedRun("INSERT INTO activity_logs (user_id, action, entity_type, entity_id, metadata) VALUES (?, 'demo.offer_recorded', 'offer', ?, JSON_OBJECT('status', ?, 'sample', true))", [$studentId, $offerId, $status]);
            $counts['activity']++;
        }
    }

    seedRun('UPDATE students s JOIN admins a ON a.programme = s.programme SET s.coordinator_id = a.user_id WHERE s.coordinator_id IS NULL OR s.coordinator_id <> a.user_id');
    seedRun("UPDATE documents d JOIN students s ON s.user_id = d.student_id SET d.reviewed_by = s.coordinator_id WHERE d.file_name LIKE 'sample_%' AND d.reviewed_by IS NOT NULL");
    seedRun('UPDATE offers o JOIN students s ON s.user_id = o.student_id SET o.placement_verification_updated_by = s.coordinator_id WHERE o.placement_verification_updated_by IS NOT NULL');
    seedRun("UPDATE notifications n JOIN students s ON s.user_id = n.recipient_user_id SET n.created_by = s.coordinator_id WHERE n.title LIKE 'Internship offer %'");

    $announcements = [
        ['Internship onboarding checklist', 'general', 'all', 'Review your offer details, upload the required placement documents and check your notifications for coordinator updates.', 'documents.html'],
        ['Upcoming placement document review', 'reminder', 'confirmed', 'Students with accepted placements should check that their offer letter, matric card and agreement form are ready for review.', 'documents.html'],
        ['Respond to pending offers', 'urgent', 'pending', 'Please accept or decline pending offers before their response deadlines so placement records remain current.', 'offers.html'],
    ];
    foreach ($coordinatorProfiles as $programme => $_coordinator) {
        $courseCoordinatorId = seedId('SELECT user_id AS id FROM admins WHERE programme = ? LIMIT 1', [$programme]);
        $courseStudentCount = (int)(seedRun('SELECT COUNT(*) AS total FROM students WHERE coordinator_id = ?', [$courseCoordinatorId])->get_result()->fetch_assoc()['total'] ?? 0);
        foreach ($announcements as [$title, $type, $group, $body, $link]) {
            if (seedId('SELECT id FROM announcements WHERE created_by = ? AND title = ? LIMIT 1', [$courseCoordinatorId, $title])) continue;
            seedRun('INSERT INTO announcements (created_by, type, recipient_group, title, body, link_url, recipient_count) VALUES (?, ?, ?, ?, ?, ?, ?)', [$courseCoordinatorId, $type, $group, $title, $body, $link, $courseStudentCount]);
            $counts['announcements']++;
        }
    }

    // Give newly inserted demo records their event dates in the activity views.
    // Limit date adjustment to the fictional student IDs in this fixture.
    $db->query("UPDATE offers o JOIN students s ON s.user_id = o.student_id SET o.created_at = TIMESTAMP(o.offer_date, '09:30:00') WHERE s.matric_id BETWEEN '2026124101' AND '2026124124' AND DATE(o.created_at) = CURDATE()");
    $db->query("UPDATE internship_applications a JOIN students s ON s.user_id = a.student_id SET a.created_at = TIMESTAMP(a.applied_at, '10:15:00') WHERE s.matric_id BETWEEN '2026124101' AND '2026124124' AND DATE(a.created_at) = CURDATE()");
    $db->query("UPDATE documents d JOIN students s ON s.user_id = d.student_id JOIN offers o ON o.id = d.offer_id SET d.uploaded_at = TIMESTAMP(COALESCE(o.response_date, o.offer_date), '11:00:00') WHERE s.matric_id BETWEEN '2026124101' AND '2026124124' AND d.file_name LIKE 'sample_%' AND DATE(d.uploaded_at) = CURDATE()");
    $db->query("UPDATE activity_logs l JOIN students s ON s.user_id = l.user_id JOIN offers o ON o.id = l.entity_id SET l.created_at = TIMESTAMP(o.offer_date, '16:00:00') WHERE s.matric_id BETWEEN '2026124101' AND '2026124124' AND l.action = 'demo.offer_recorded' AND DATE(l.created_at) = CURDATE()");
    $db->query("UPDATE notifications n JOIN students s ON s.user_id = n.recipient_user_id SET n.created_at = TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL (s.user_id % 5 + 1) DAY), '15:00:00') WHERE s.matric_id BETWEEN '2026124101' AND '2026124124' AND DATE(n.created_at) = CURDATE()");

    $db->commit();
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}

echo "Demo data ready (new rows only):\n";
foreach ($counts as $table => $count) echo '  ' . $table . ': ' . $count . "\n";
echo "Sample student login: 2026124101@student.uitm.edu.my / password123\n";
echo "Sample employer login: careers@meridiandatalabs.example.test / password123\n";
