<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

$failures = 0;

final class SafePreflightException extends RuntimeException
{
}

/** @return array{dsn: string, user: string, password: string, host: string, port: int, transaction_pool: bool} */
function safeStagingConnectionSettings(string $credentialSet): array
{
    try {
        return stagingConnectionSettings($credentialSet);
    } catch (RuntimeException $error) {
        throw new SafePreflightException($error->getMessage());
    }
}

/** @param callable(): string $check */
function reportPreflight(string $label, callable $check): void
{
    global $failures;
    try {
        $detail = $check();
        echo 'PASS: ' . $label . ($detail === '' ? '' : ' - ' . $detail) . "\n";
    } catch (Throwable $error) {
        $failures++;
        $detail = $error instanceof SafePreflightException
            ? $error->getMessage()
            : 'sanitized ' . get_debug_type($error) . '; no credential was printed';
        echo 'FAIL: ' . $label . ' (' . $detail . ")\n";
    }
}

$required = [
    'INTERNPILIH_ENVIRONMENT',
    'INTERNPILIH_STAGING_PROJECT_REF',
    'INTERNPILIH_STAGING_ADMIN_DSN',
    'INTERNPILIH_STAGING_ADMIN_USER',
    'INTERNPILIH_STAGING_ADMIN_PASSWORD',
    'INTERNPILIH_STAGING_APP_DSN',
    'INTERNPILIH_STAGING_APP_USER',
    'INTERNPILIH_STAGING_APP_PASSWORD',
    'INTERNPILIH_STAGING_TEST_PASSWORD',
    'INTERNPILIH_STAGING_SUPABASE_URL',
    'INTERNPILIH_STAGING_SUPABASE_STORAGE_SECRET',
];

reportPreflight('required staging fields', static function () use ($required): string {
    foreach ($required as $name) {
        $value = stagingEnvironment($name);
        if ($value === '' || str_contains($value, 'YOUR_')) {
            throw new RuntimeException('Incomplete staging configuration.');
        }
    }
    if (strlen(stagingEnvironment('INTERNPILIH_STAGING_APP_PASSWORD')) < 24
        || strlen(stagingEnvironment('INTERNPILIH_STAGING_TEST_PASSWORD')) < 16) {
        throw new RuntimeException('A private staging password is too short.');
    }
    return count($required) . '/' . count($required) . ' present';
});

reportPreflight('staging project identity', static function (): string {
    if (!hash_equals(
        INTERNPILIH_EXPECTED_STAGING_PROJECT_REF,
        stagingEnvironment('INTERNPILIH_STAGING_PROJECT_REF')
    )) {
        throw new RuntimeException('Wrong project reference.');
    }
    assertStagingProjectUrl(stagingEnvironment('INTERNPILIH_STAGING_SUPABASE_URL'));
    return 'pinned to approved staging Project ID';
});

reportPreflight('Session pooler configuration', static function (): string {
    $settings = safeStagingConnectionSettings('ADMIN');
    if ($settings['port'] !== 5432 || $settings['transaction_pool']) {
        throw new RuntimeException('Invalid Session pooler settings.');
    }
    return 'PostgreSQL, port 5432, SSL required';
});

reportPreflight('Transaction pooler configuration', static function (): string {
    $settings = safeStagingConnectionSettings('APP');
    if ($settings['port'] !== 6543 || !$settings['transaction_pool']) {
        throw new RuntimeException('Invalid Transaction pooler settings.');
    }
    return 'PostgreSQL, port 6543, SSL required';
});

reportPreflight('required PHP extensions', static function (): string {
    foreach (['pdo_pgsql', 'pgsql', 'curl', 'openssl', 'fileinfo'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Missing extension.');
        }
    }
    return 'PDO PostgreSQL, pgsql, cURL, OpenSSL, fileinfo';
});

reportPreflight('Session pooler authentication', static function (): string {
    $pdo = stagingPdo('ADMIN');
    $probe = $pdo->query("SELECT current_database() AS database_name, current_user AS database_user")
        ->fetch();
    if (!$probe || $probe['database_name'] !== 'postgres' || $probe['database_user'] !== 'postgres') {
        throw new RuntimeException('Unexpected database identity.');
    }
    return 'port 5432 connected';
});

reportPreflight('client-side PostgreSQL TLS', static function (): string {
    $settings = stagingConnectionSettings('ADMIN');
    if (!preg_match('/(?:^|;)sslmode=require(?:;|$)/i', $settings['dsn'])) {
        throw new SafePreflightException('Session pooler does not enforce TLS');
    }
    $pdo = stagingPdo('ADMIN');
    if ((string)$pdo->query('SELECT current_database()')->fetchColumn() !== 'postgres') {
        throw new SafePreflightException('Session-pooler TLS probe returned an unexpected database');
    }
    return 'sslmode=require connection succeeded; pg_ssl_in_use unavailable in this PHP build';
});

reportPreflight('Transaction pooler authentication and TLS', static function (): string {
    $transaction = stagingConnectionSettings('APP');
    $admin = stagingConnectionSettings('ADMIN');
    try {
        $pdo = new PDO($transaction['dsn'], $admin['user'], $admin['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    } catch (Throwable $error) {
        throw new SafePreflightException('Transaction-pooler authentication failed');
    }
    $identity = $pdo->query('SELECT current_database() AS database_name, current_user AS database_user')
        ->fetch();
    if (!$identity || $identity['database_name'] !== 'postgres' || $identity['database_user'] !== 'postgres') {
        throw new SafePreflightException('Transaction pooler returned an unexpected database identity');
    }
    return 'port 6543 connected with sslmode=require; app role activation pending';
});

reportPreflight('staging Storage secret', static function (): string {
    $projectUrl = stagingEnvironment('INTERNPILIH_STAGING_SUPABASE_URL');
    assertStagingProjectUrl($projectUrl);
    $secret = stagingEnvironment('INTERNPILIH_STAGING_SUPABASE_STORAGE_SECRET');
    $handle = curl_init($projectUrl . '/storage/v1/bucket');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secret,
            'apikey: ' . $secret,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($body) || $status !== 200) {
        throw new RuntimeException('Storage authentication failed.');
    }
    return 'authenticated read-only request succeeded';
});

reportPreflight('empty staging public schema', static function (): string {
    $pdo = stagingPdo('ADMIN');
    $count = (int)$pdo->query("
        SELECT COUNT(*)
        FROM pg_tables
        WHERE schemaname = 'public'
    ")->fetchColumn();
    if ($count !== 0) {
        throw new RuntimeException('The public schema is not empty.');
    }
    return '0 tables';
});

echo $failures === 0
    ? "READY: sanitized staging preflight passed.\n"
    : "BLOCKED: staging preflight has {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);
