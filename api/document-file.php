<?php
// Authenticated local document delivery. Direct access to /uploads is denied.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage-client.php';

$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    respond(['error' => 'Method not allowed'], 405);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) respond(['error' => 'Document not found'], 404);

$statement = db()->prepare("
    SELECT d.id, d.student_id, d.file_name, s.coordinator_id,
           to_jsonb(d)->>'storage_object_path' AS storage_object_path
    FROM documents d
    JOIN students s ON s.user_id = d.student_id
    WHERE d.id = ?
    LIMIT 1
");
$statement->bind_param('i', $id);
$statement->execute();
$document = $statement->get_result()->fetch_assoc();
if (!$document) respond(['error' => 'Document not found'], 404);

$authorized = $user['role'] === 'student'
    ? (int)$document['student_id'] === (int)$user['id']
    : ($user['role'] === 'admin' && (int)$document['coordinator_id'] === (int)$user['id']);
if (!$authorized) respond(['error' => 'Document not found'], 404);

$fileName = (string)$document['file_name'];
if ($fileName === '' || $fileName !== basename($fileName)) {
    respond(['error' => 'Document not found'], 404);
}
$storage = storageConfiguration();
if ($storage['driver'] === 'supabase') {
    $objectPath = trim((string)($document['storage_object_path'] ?? ''));
    if ($objectPath === '') respond(['error' => 'Document file is unavailable'], 404);
    try {
        $signedUrl = createPrivateDocumentSignedUrl($objectPath, 60);
    } catch (Throwable $error) {
        error_log('InternPilih signed document URL failed: ' . $error->getMessage());
        respond(['error' => 'Document file is temporarily unavailable'], 503);
    }
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $signedUrl, true, 302);
    exit;
}
if ($storage['driver'] !== 'local') {
    respond(['error' => 'Document storage is not configured'], 503);
}
$path = __DIR__ . '/../uploads/' . $fileName;
$realUploadDirectory = realpath(__DIR__ . '/../uploads');
$realPath = realpath($path);
if ($realUploadDirectory === false || $realPath === false
    || !str_starts_with($realPath, $realUploadDirectory . DIRECTORY_SEPARATOR)
    || !is_file($realPath)) {
    respond(['error' => 'Document file is unavailable'], 404);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath) ?: 'application/octet-stream';
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowedMimes, true)) {
    respond(['error' => 'Document file type is not supported'], 415);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: inline; filename="document-' . $id . '.' . strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    readfile($realPath);
}
exit;
