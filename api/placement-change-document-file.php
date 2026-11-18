<?php
// Authenticated delivery for placement-change evidence.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage-client.php';

$user = requireAuth();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) respond(['error' => 'Method not allowed'], 405);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) respond(['error' => 'Document not found'], 404);

$stmt = db()->prepare("
    SELECT pcd.file_name, pcd.storage_object_path, pcr.student_id, s.coordinator_id
    FROM placement_change_documents pcd
    JOIN placement_change_requests pcr ON pcr.id = pcd.request_id
    JOIN students s ON s.user_id = pcr.student_id
    WHERE pcd.id = ? LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
if (!$document) respond(['error' => 'Document not found'], 404);
$authorized = $user['role'] === 'student'
    ? (int)$document['student_id'] === (int)$user['id']
    : ($user['role'] === 'admin' && (int)$document['coordinator_id'] === (int)$user['id']);
if (!$authorized) respond(['error' => 'Document not found'], 404);

$fileName = basename((string)$document['file_name']);
if ($fileName === '') respond(['error' => 'Document file is unavailable'], 404);
$storage = storageConfiguration();
if ($storage['driver'] === 'supabase') {
    $objectPath = trim((string)$document['storage_object_path']);
    if ($objectPath === '') respond(['error' => 'Document file is unavailable'], 404);
    try { $url = createPrivateDocumentSignedUrl($objectPath, 60); }
    catch (Throwable $error) { respond(['error' => 'Document file is temporarily unavailable'], 503); }
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $url, true, 302);
    exit;
}
if ($storage['driver'] !== 'local') respond(['error' => 'Document storage is not configured'], 503);
$base = realpath(__DIR__ . '/../uploads');
$path = realpath(__DIR__ . '/../uploads/' . $fileName);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    respond(['error' => 'Document file is unavailable'], 404);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) respond(['error' => 'Unsupported file type'], 415);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="placement-change-document-' . $id . '.' . strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
if ($_SERVER['REQUEST_METHOD'] === 'GET') readfile($path);
exit;
