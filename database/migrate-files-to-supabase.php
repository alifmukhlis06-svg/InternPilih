<?php
/** Upload every locally referenced document to a private Supabase Storage bucket. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
$config = require __DIR__ . '/../config/supabase.local.php';
$projectUrl = rtrim((string)($config['project_url'] ?? ''), '/');
$serviceKey = trim((string)($config['service_role_key'] ?? ''));
$bucket = trim((string)($config['storage_bucket'] ?? 'documents'));
if ($projectUrl === '' || $serviceKey === '') {
    throw new RuntimeException('Set project_url and service_role_key in config/supabase.local.php before uploading documents.');
}
if (!extension_loaded('curl')) throw new RuntimeException('The curl PHP extension is required.');

function storageRequest(string $method, string $url, string $key, ?string $body = null, array $headers = []): array {
    $handle = curl_init($url);
    $baseHeaders = ['apikey: ' . $key, 'Authorization: Bearer ' . $key];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($handle);
    if ($response === false) throw new RuntimeException(curl_error($handle));
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return [$status, (string)$response];
}

[$bucketStatus, $bucketBody] = storageRequest(
    'POST',
    $projectUrl . '/storage/v1/bucket',
    $serviceKey,
    json_encode(['id' => $bucket, 'name' => $bucket, 'public' => false], JSON_THROW_ON_ERROR),
    ['Content-Type: application/json']
);
if (!in_array($bucketStatus, [200, 201, 400, 409], true)) {
    throw new RuntimeException("Could not create storage bucket ($bucketStatus): $bucketBody");
}

$result = db()->query('SELECT DISTINCT file_name FROM documents ORDER BY file_name');
$uploaded = 0;
$missing = [];
while ($row = $result->fetch_assoc()) {
    $fileName = basename((string)$row['file_name']);
    $path = __DIR__ . '/../uploads/' . $fileName;
    if (!is_file($path)) {
        $missing[] = $fileName;
        continue;
    }
    $contents = file_get_contents($path);
    if ($contents === false) throw new RuntimeException('Could not read ' . $path);
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        default => 'application/octet-stream',
    };
    [$status, $response] = storageRequest(
        'POST',
        $projectUrl . '/storage/v1/object/' . rawurlencode($bucket) . '/' . rawurlencode($fileName),
        $serviceKey,
        $contents,
        ['Content-Type: ' . $mime, 'x-upsert: true']
    );
    if (!in_array($status, [200, 201], true)) {
        throw new RuntimeException("Upload failed for $fileName ($status): $response");
    }
    $uploaded++;
}

echo "Uploaded $uploaded document files to the private '$bucket' bucket.\n";
if ($missing) {
    echo 'Missing local files (' . count($missing) . '): ' . implode(', ', $missing) . "\n";
    exit(2);
}
