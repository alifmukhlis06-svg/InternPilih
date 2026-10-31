<?php
/** Synchronize existing fictional student accounts with the canonical demo profiles. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../config/db.php';
$profiles = require __DIR__ . '/student-data.php';
$db = db();
$db->begin_transaction();
try {
    $find = $db->prepare('SELECT s.user_id, u.email FROM students s JOIN users u ON u.id = s.user_id WHERE s.matric_id = ? LIMIT 1');
    $emailOwner = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $updateUser = $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
    $updateStudent = $db->prepare('UPDATE students SET faculty = ?, programme = ? WHERE user_id = ?');
    $updated = 0;
    foreach ($profiles as $matric => [$name, $faculty, $programme]) {
        $find->bind_param('s', $matric);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        if (!$row) continue;
        $userId = (int)$row['user_id'];
        $email = $matric . '@student.uitm.edu.my';
        $emailOwner->bind_param('s', $email);
        $emailOwner->execute();
        $owner = $emailOwner->get_result()->fetch_assoc();
        if ($owner && (int)$owner['id'] !== $userId) {
            throw new RuntimeException("Email already belongs to another account: $email");
        }
        $updateUser->bind_param('ssi', $name, $email, $userId);
        $updateUser->execute();
        $updateStudent->bind_param('ssi', $faculty, $programme, $userId);
        $updateStudent->execute();
        $updated++;
    }
    $contacts = [
        'sitinorzahra@uitm.edu.my' => ['admin', 'Dr. Siti Norzahra binti Mahmud'],
        'hr@techsolutions.com.my' => ['employer', 'Nadia Farzana binti Rahim'],
        'careers@meridiandatalabs.example.test' => ['employer', 'Amir Hakimi bin Sulaiman'],
        'people@sinarayahealth.example.test' => ['employer', 'Nur Syuhada binti Ibrahim'],
        'interns@awancloudworks.example.test' => ['employer', 'Faiz Ariff bin Mohamad'],
        'talent@wiramedia.example.test' => ['employer', 'Siti Hajar binti Ismail'],
    ];
    $updateContact = $db->prepare('UPDATE users SET name = ? WHERE email = ? AND role = ?');
    foreach ($contacts as $email => [$role, $name]) {
        $updateContact->bind_param('sss', $name, $email, $role);
        $updateContact->execute();
    }
    $db->commit();
    echo "Synchronized $updated student profiles.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}
