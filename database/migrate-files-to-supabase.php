<?php
/**
 * Private Supabase Storage migration. Plan-only unless --execute is supplied
 * after explicit approval of the schema, bucket, credentials, and migration.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/storage-client.php';

if (in_array('--replace', $argv, true)) {
    fwrite(STDERR, "--replace is not supported. Existing objects are never overwritten.\n");
    exit(2);
}
$execute = in_array('--execute', $argv, true);

$result = db()->query("
    SELECT id, student_id, file_name
    FROM documents
    ORDER BY id
");
if (!$result instanceof DatabaseResult) {
    throw new RuntimeException('Could not read the migrated PostgreSQL document manifest.');
}

$documents = $result->fetch_all(MYSQLI_ASSOC);
$uploadDirectory = realpath(__DIR__ . '/../uploads');
if ($uploadDirectory === false) {
    throw new RuntimeException('The local uploads directory is unavailable.');
}

$missing = 0;
$unsafeNames = 0;
$unsupportedTypes = 0;
$totalBytes = 0;
$manifest = [];
$allowedMimes = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
];
$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($documents as $document) {
    $fileName = (string)$document['file_name'];
    if ($fileName === '' || $fileName !== basename($fileName)) {
        $unsafeNames++;
        continue;
    }

    $path = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($path)) {
        $missing++;
        continue;
    }

    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mime = $finfo->file($path) ?: 'application/octet-stream';
    if (!isset($allowedMimes[$extension]) || !in_array($mime, $allowedMimes[$extension], true)) {
        $unsupportedTypes++;
        continue;
    }

    $size = filesize($path);
    $sha256 = hash_file('sha256', $path);
    if ($size === false || $sha256 === false) {
        throw new RuntimeException('A local document could not be inspected.');
    }
    $totalBytes += $size;
    $manifest[] = [
        'document_id' => (int)$document['id'],
        'student_id' => (int)$document['student_id'],
        'content_type' => $mime,
        'byte_size' => $size,
        'sha256' => $sha256,
        'local_path' => $path,
        'object_path' => sprintf(
            'students/%d/%d-%s.%s',
            (int)$document['student_id'],
            (int)$document['id'],
            substr($sha256, 0, 16),
            $extension
        ),
    ];
}

echo "PLAN ONLY - no bucket, object, or database row was changed.\n";
echo 'PostgreSQL document rows: ' . count($documents) . "\n";
echo 'Files ready for private upload: ' . count($manifest) . "\n";
echo 'Total bytes: ' . $totalBytes . "\n";
echo 'Missing local files: ' . $missing . "\n";
echo 'Unsafe file names: ' . $unsafeNames . "\n";
echo 'Unsupported or mismatched types: ' . $unsupportedTypes . "\n";

if ($missing !== 0 || $unsafeNames !== 0 || $unsupportedTypes !== 0) {
    exit(1);
}

if (!$execute) {
    exit(0);
}

$requiredColumns = [
    'storage_bucket', 'storage_object_path', 'content_type',
    'byte_size', 'sha256', 'storage_migrated_at',
];
$columns = db()->query("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'documents'
      AND column_name IN (
          'storage_bucket', 'storage_object_path', 'content_type',
          'byte_size', 'sha256', 'storage_migrated_at'
      )
")->fetch_all(MYSQLI_ASSOC);
$availableColumns = array_column($columns, 'column_name');
if (count(array_intersect($requiredColumns, $availableColumns)) !== count($requiredColumns)) {
    fwrite(STDERR, "Storage metadata schema is not ready. Migration stopped before upload.\n");
    exit(2);
}

$storage = storageConfiguration();
if ($storage['driver'] !== 'supabase' || $storage['bucket'] !== 'documents') {
    fwrite(STDERR, "Private Supabase Storage is not selected. Migration stopped before upload.\n");
    exit(2);
}
$bucketStatement = db()->prepare("
    SELECT public
    FROM storage.buckets
    WHERE id = ?
    LIMIT 1
");
$bucketStatement->bind_param('s', $storage['bucket']);
$bucketStatement->execute();
$bucket = $bucketStatement->get_result()->fetch_assoc();
if (!$bucket || (bool)$bucket['public']) {
    fwrite(STDERR, "The private documents bucket is not ready. Migration stopped before upload.\n");
    exit(2);
}

$completed = 0;
$resumed = 0;
foreach ($manifest as $item) {
    $lookup = db()->prepare("
        SELECT storage_bucket, storage_object_path, content_type, byte_size, sha256
        FROM documents
        WHERE id = ?
        LIMIT 1
    ");
    $lookup->bind_param('i', $item['document_id']);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    if (!$existing) {
        throw new RuntimeException('A document row disappeared during migration preparation.');
    }

    $hasMetadata = trim((string)($existing['storage_object_path'] ?? '')) !== '';
    if ($hasMetadata) {
        $metadataMatches = (string)$existing['storage_bucket'] === $storage['bucket']
            && (string)$existing['storage_object_path'] === $item['object_path']
            && (string)$existing['content_type'] === $item['content_type']
            && (int)$existing['byte_size'] === $item['byte_size']
            && hash_equals((string)$existing['sha256'], $item['sha256']);
        if (!$metadataMatches) {
            throw new RuntimeException('Existing Storage metadata conflicts with the migration manifest.');
        }
        $remote = downloadPrivateDocumentBytes($item['object_path']);
        if (strlen($remote) !== $item['byte_size'] || !hash_equals(hash('sha256', $remote), $item['sha256'])) {
            throw new RuntimeException('An existing Storage object failed checksum verification.');
        }
        $resumed++;
        continue;
    }

    try {
        uploadPrivateDocument($item['object_path'], $item['local_path'], $item['content_type']);
    } catch (Throwable $uploadError) {
        // A previous interrupted run may have uploaded the deterministic object
        // before its metadata update. Accept it only when its bytes match.
        $remote = downloadPrivateDocumentBytes($item['object_path']);
        if (strlen($remote) !== $item['byte_size'] || !hash_equals(hash('sha256', $remote), $item['sha256'])) {
            throw $uploadError;
        }
    }
    $remote = downloadPrivateDocumentBytes($item['object_path']);
    if (strlen($remote) !== $item['byte_size'] || !hash_equals(hash('sha256', $remote), $item['sha256'])) {
        throw new RuntimeException('A newly uploaded Storage object failed checksum verification.');
    }

    $update = db()->prepare("
        UPDATE documents
        SET storage_bucket = ?, storage_object_path = ?, content_type = ?,
            byte_size = ?, sha256 = ?, storage_migrated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND storage_object_path IS NULL
    ");
    $update->bind_param(
        'sssisi',
        $storage['bucket'], $item['object_path'], $item['content_type'],
        $item['byte_size'], $item['sha256'], $item['document_id']
    );
    $update->execute();
    if ($update->affected_rows !== 1) {
        throw new RuntimeException('A document metadata update was not applied safely.');
    }
    $completed++;
}

echo 'Storage objects uploaded and recorded: ' . $completed . "\n";
echo 'Previously verified objects resumed: ' . $resumed . "\n";
echo 'Verified total: ' . ($completed + $resumed) . '/' . count($manifest) . "\n";
