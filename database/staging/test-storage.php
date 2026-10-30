<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

if (!in_array('--execute', $argv, true)) {
    echo "PLAN ONLY - no Storage object was created.\n";
    echo "Execution uploads one synthetic PDF, verifies private and signed access, then deletes it.\n";
    exit(0);
}

$uploaded = false;
$objectPath = 'staging-probes/' . bin2hex(random_bytes(16)) . '.pdf';
try {
    if (stagingEnvironment('INTERNPILIH_ENVIRONMENT') !== 'staging') {
        throw new RuntimeException('The environment is not staging.');
    }
    $projectRef = stagingEnvironment('INTERNPILIH_STAGING_PROJECT_REF');
    $projectUrl = rtrim(stagingEnvironment('INTERNPILIH_STAGING_SUPABASE_URL'), '/');
    $secret = stagingEnvironment('INTERNPILIH_STAGING_SUPABASE_STORAGE_SECRET');
    if (!hash_equals(INTERNPILIH_EXPECTED_STAGING_PROJECT_REF, $projectRef)
        || $secret === '' || str_contains($secret, 'YOUR_')) {
        throw new RuntimeException('Staging Storage configuration is incomplete or mismatched.');
    }
    assertStagingProjectUrl($projectUrl);
    putenv('INTERNPILIH_STORAGE_DRIVER=supabase');
    putenv('INTERNPILIH_DISABLE_LOCAL_CONFIG=true');
    putenv('INTERNPILIH_SUPABASE_URL=' . $projectUrl);
    putenv('INTERNPILIH_SUPABASE_STORAGE_SECRET=' . $secret);
    putenv('INTERNPILIH_STORAGE_BUCKET=documents');
    require_once __DIR__ . '/../../api/storage-client.php';

    $fixture = __DIR__ . '/fixtures/synthetic-document.pdf';
    $expected = file_get_contents($fixture);
    if ($expected === false || (new finfo(FILEINFO_MIME_TYPE))->file($fixture) !== 'application/pdf') {
        throw new RuntimeException('The synthetic PDF fixture is invalid.');
    }

    uploadPrivateDocument($objectPath, $fixture, 'application/pdf');
    $uploaded = true;
    $authenticated = downloadPrivateDocumentBytes($objectPath);
    if (!hash_equals(hash('sha256', $expected), hash('sha256', $authenticated))) {
        throw new RuntimeException('Authenticated Storage download checksum mismatch.');
    }

    $signedUrl = createPrivateDocumentSignedUrl($objectPath, 60);
    $handle = curl_init($signedUrl);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 20,
    ]);
    $signedBody = curl_exec($handle);
    $signedError = curl_errno($handle);
    $signedStatus = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($signedBody === false) {
        throw new RuntimeException('Signed Storage download request failed (cURL ' . $signedError . ').');
    }
    if ($signedStatus !== 200) {
        throw new RuntimeException('Signed Storage download failed (HTTP ' . $signedStatus . ').');
    }
    if (!is_string($signedBody)
        || !hash_equals(hash('sha256', $expected), hash('sha256', $signedBody))) {
        throw new RuntimeException('Signed Storage download checksum mismatch.');
    }

    $publicUrl = $projectUrl . '/storage/v1/object/public/documents/'
        . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
    $publicHandle = curl_init($publicUrl);
    curl_setopt_array($publicHandle, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 20,
    ]);
    $publicResult = curl_exec($publicHandle);
    $publicError = curl_errno($publicHandle);
    $publicStatus = (int)curl_getinfo($publicHandle, CURLINFO_RESPONSE_CODE);
    curl_close($publicHandle);
    if ($publicResult === false) {
        throw new RuntimeException('Public-access denial request failed (cURL ' . $publicError . ').');
    }
    if ($publicStatus >= 200 && $publicStatus < 300) {
        throw new RuntimeException('The private object was unexpectedly accessible through a public URL.');
    }

    deletePrivateDocument($objectPath);
    $uploaded = false;
    echo "Private upload: successful.\n";
    echo "Authenticated download checksum: successful.\n";
    echo "Short-lived signed download: successful.\n";
    echo 'Unauthenticated public access: denied (HTTP ' . $publicStatus . ").\n";
    echo "Synthetic probe object: deleted.\n";
} catch (Throwable $error) {
    if ($uploaded && function_exists('deletePrivateDocument')) {
        try {
            deletePrivateDocument($objectPath);
        } catch (Throwable $cleanupError) {
            error_log('Synthetic staging Storage cleanup failed.');
        }
    }
    error_log('InternPilih staging Storage test failed: ' . $error->getMessage());
    fwrite(STDERR, "Staging Storage test failed. No credential or URL was printed.\n");
    exit(1);
}
