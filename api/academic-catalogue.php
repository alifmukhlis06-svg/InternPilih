<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/academic-catalogue-data.php';
$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondError('Method not allowed.', 405, 'method_not_allowed');
$data = academicCatalogueData();
if ($user['role'] === 'admin') {
    $stmt = db()->prepare('SELECT programme_code FROM academic_coordinator_mappings WHERE admin_user_id=? ORDER BY programme_code');
    $stmt->execute([(int)$user['id']]);
    $data['my_coordinator_codes'] = array_column($stmt->get_result()->fetch_all(), 'programme_code');
}
respond(['academic_catalogue' => $data]);
