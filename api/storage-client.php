<?php

declare(strict_types=1);

function storageConfiguration(): array {
    $local = [];
    $localPath = __DIR__ . '/../config/supabase.local.php';
    $disableLocal = filter_var(
        getenv('INTERNPILIH_DISABLE_LOCAL_CONFIG') ?: 'false',
        FILTER_VALIDATE_BOOL
    );
    if (!$disableLocal && is_file($localPath)) {
        $local = require $localPath;
    }
    $read = static function (string $environment, string $localKey, string $default = '') use ($local): string {
        $value = getenv($environment);
        if ($value !== false && trim($value) !== '') return trim($value);
        return trim((string)($local[$localKey] ?? $default));
    };

    return [
        'driver' => strtolower($read(
            'INTERNPILIH_STORAGE_DRIVER',
            'storage_driver',
            getenv('VERCEL') !== false ? 'supabase' : 'local'
        )),
        'project_url' => rtrim($read('INTERNPILIH_SUPABASE_URL', 'project_url'), '/'),
        'secret' => $read(
            'INTERNPILIH_SUPABASE_STORAGE_SECRET',
            'storage_secret_key',
            (string)($local['service_role_key'] ?? '')
        ),
        'bucket' => $read('INTERNPILIH_STORAGE_BUCKET', 'storage_bucket', 'documents'),
    ];
}

function storageObjectUrl(array $config, string $operation, string $objectPath): string {
    $segments = array_map('rawurlencode', explode('/', ltrim($objectPath, '/')));
    return $config['project_url'] . '/storage/v1/object/' . trim($operation, '/') . '/'
        . rawurlencode($config['bucket']) . '/' . implode('/', $segments);
}

function storageRequest(
    array $config,
    string $method,
    string $url,
    string|false|null $body = null,
    array $headers = []
): array {
    if ($config['project_url'] === '' || $config['secret'] === '' || $config['bucket'] === '') {
        throw new RuntimeException('Private Supabase Storage is not configured.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The cURL extension is required for Supabase Storage.');
    }

    $handle = curl_init($url);
    $authenticationHeaders = [
        'apikey: ' . $config['secret'],
    ];
    // Legacy service_role keys are JWTs and authenticate Storage directly.
    // New sb_secret_ keys are opaque API keys; the Supabase gateway converts
    // the apikey header into the short-lived service credential Storage needs.
    if (!str_starts_with($config['secret'], 'sb_secret_')) {
        $authenticationHeaders[] = 'Authorization: Bearer ' . $config['secret'];
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($authenticationHeaders, $headers),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    if ($response === false) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Supabase Storage request failed: ' . $message);
    }
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return [$status, (string)$response];
}

function uploadPrivateDocument(string $objectPath, string $localPath, string $contentType): void {
    $config = storageConfiguration();
    $contents = file_get_contents($localPath);
    if ($contents === false) throw new RuntimeException('Could not read the uploaded file.');
    $url = $config['project_url'] . '/storage/v1/object/' . rawurlencode($config['bucket']) . '/'
        . implode('/', array_map('rawurlencode', explode('/', ltrim($objectPath, '/'))));
    [$status, $response] = storageRequest($config, 'POST', $url, $contents, [
        'Content-Type: ' . $contentType,
        'x-upsert: false',
    ]);
    if (!in_array($status, [200, 201], true)) {
        throw new RuntimeException(
            'Private document upload was rejected by Storage ('
            . storageSanitizedErrorDescription($status, $response) . ').'
        );
    }
}

function deletePrivateDocument(string $objectPath): void {
    $config = storageConfiguration();
    $url = $config['project_url'] . '/storage/v1/object/' . rawurlencode($config['bucket']) . '/'
        . implode('/', array_map('rawurlencode', explode('/', ltrim($objectPath, '/'))));
    [$status] = storageRequest($config, 'DELETE', $url);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Private document cleanup was rejected by Storage.');
    }
}

function downloadPrivateDocumentBytes(string $objectPath): string {
    $config = storageConfiguration();
    $url = storageObjectUrl($config, 'authenticated', $objectPath);
    [$status, $body] = storageRequest($config, 'GET', $url);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Private document verification was rejected by Storage.');
    }
    return $body;
}

function storageSanitizedErrorDescription(int $status, string $response): string {
    $decoded = json_decode($response, true);
    $description = '';
    if (is_array($decoded)) {
        foreach (['message', 'error_description', 'error'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                $description = $decoded[$key];
                break;
            }
        }
    }
    $description = preg_replace('/[^a-zA-Z0-9 .,:;_()\/-]/', '', $description) ?? '';
    $description = substr(trim($description), 0, 160);
    return 'HTTP ' . $status . ($description === '' ? '' : ': ' . $description);
}

function normalizeStorageSignedUrl(array $config, string $signedPath): string {
    $project = parse_url($config['project_url']);
    if (!is_array($project) || !isset($project['scheme'], $project['host'])) {
        throw new RuntimeException('The configured Storage project URL is invalid.');
    }

    if (filter_var($signedPath, FILTER_VALIDATE_URL) !== false) {
        $signed = parse_url($signedPath);
        $sameOrigin = is_array($signed)
            && ($signed['scheme'] ?? '') === $project['scheme']
            && ($signed['host'] ?? '') === $project['host']
            && ($signed['port'] ?? null) === ($project['port'] ?? null);
        $path = is_array($signed) ? (string)($signed['path'] ?? '') : '';
        if (!$sameOrigin
            || !preg_match('#^/storage/v1/(?:object|render/image)/sign/#', $path)) {
            throw new RuntimeException('Storage returned an unsafe signed URL.');
        }
        return $signedPath;
    }

    if (!str_starts_with($signedPath, '/')) {
        $signedPath = '/' . $signedPath;
    }
    if (str_starts_with($signedPath, '/storage/v1/')) {
        $relative = $signedPath;
    } elseif (str_starts_with($signedPath, '/object/sign/')
        || str_starts_with($signedPath, '/render/image/sign/')) {
        $relative = '/storage/v1' . $signedPath;
    } else {
        throw new RuntimeException('Storage returned an unexpected signed URL path.');
    }
    return $config['project_url'] . $relative;
}

function createPrivateDocumentSignedUrl(string $objectPath, int $expiresIn = 60): string {
    $config = storageConfiguration();
    $url = storageObjectUrl($config, 'sign', $objectPath);
    [$status, $response] = storageRequest(
        $config,
        'POST',
        $url,
        json_encode(['expiresIn' => max(30, min(300, $expiresIn))], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json']
    );
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException(
            'Supabase Storage signing failed (' . storageSanitizedErrorDescription($status, $response) . ').'
        );
    }
    $decoded = json_decode($response, true);
    $signedPath = is_array($decoded) ? (string)($decoded['signedURL'] ?? $decoded['signedUrl'] ?? '') : '';
    if (!is_array($decoded)) {
        throw new RuntimeException('Supabase Storage signing returned invalid JSON (HTTP ' . $status . ').');
    }
    if ($signedPath === '') {
        throw new RuntimeException('Supabase Storage signing response omitted signedURL (HTTP ' . $status . ').');
    }
    return normalizeStorageSignedUrl($config, $signedPath);
}
