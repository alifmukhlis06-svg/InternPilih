<?php

declare(strict_types=1);

const INTERNPILIH_EXPECTED_STAGING_PROJECT_REF = 'zohcslnofeuisywqnjts';

function stagingEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value !== false && trim($value) !== '') {
        return trim($value);
    }

    static $local = null;
    if ($local === null) {
        $path = __DIR__ . '/../../config/staging.local.php';
        $local = is_file($path) ? require $path : [];
        if (!is_array($local)) {
            throw new RuntimeException('The staging local configuration must return an array.');
        }
    }
    $keys = [
        'INTERNPILIH_ENVIRONMENT' => 'environment',
        'INTERNPILIH_STAGING_PROJECT_REF' => 'project_ref',
        'INTERNPILIH_STAGING_ADMIN_DSN' => 'admin_dsn',
        'INTERNPILIH_STAGING_ADMIN_USER' => 'admin_user',
        'INTERNPILIH_STAGING_ADMIN_PASSWORD' => 'admin_password',
        'INTERNPILIH_STAGING_APP_DSN' => 'app_dsn',
        'INTERNPILIH_STAGING_APP_USER' => 'app_user',
        'INTERNPILIH_STAGING_APP_PASSWORD' => 'app_password',
        'INTERNPILIH_STAGING_TEST_PASSWORD' => 'test_password',
        'INTERNPILIH_STAGING_SUPABASE_URL' => 'project_url',
        'INTERNPILIH_STAGING_SUPABASE_STORAGE_SECRET' => 'storage_secret',
    ];
    $key = $keys[$name] ?? null;
    return $key === null ? '' : trim((string)($local[$key] ?? ''));
}

function stagingPgBool(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}

function assertStagingProjectUrl(string $projectUrl): void
{
    $expectedUrl = 'https://' . INTERNPILIH_EXPECTED_STAGING_PROJECT_REF . '.supabase.co';
    if (!hash_equals($expectedUrl, rtrim($projectUrl, '/'))) {
        throw new RuntimeException('The Supabase URL is not the approved InternPilih staging project.');
    }
}

/** @return array{dsn: string, user: string, password: string, host: string, port: int, transaction_pool: bool} */
function stagingConnectionSettings(string $credentialSet = 'ADMIN'): array
{
    if (stagingEnvironment('INTERNPILIH_ENVIRONMENT') !== 'staging') {
        throw new RuntimeException('INTERNPILIH_ENVIRONMENT must be staging.');
    }

    $projectRef = stagingEnvironment('INTERNPILIH_STAGING_PROJECT_REF');
    if (!preg_match('/^[a-z0-9]{20}$/', $projectRef)) {
        throw new RuntimeException('The staging project reference is missing or invalid.');
    }
    if (!hash_equals(INTERNPILIH_EXPECTED_STAGING_PROJECT_REF, $projectRef)) {
        throw new RuntimeException('The configured project is not the approved InternPilih staging project.');
    }

    $credentialSet = strtoupper($credentialSet);
    if (!in_array($credentialSet, ['ADMIN', 'APP'], true)) {
        throw new RuntimeException('Unknown staging credential set.');
    }
    $prefix = 'INTERNPILIH_STAGING_' . $credentialSet . '_';
    $dsn = stagingEnvironment($prefix . 'DSN');
    $user = stagingEnvironment($prefix . 'USER');
    $password = stagingEnvironment($prefix . 'PASSWORD');
    if ($dsn === '' || $user === '' || $password === '' || str_contains($password, 'YOUR_')) {
        throw new RuntimeException('Staging database credentials are incomplete.');
    }
    if (!str_starts_with($dsn, 'pgsql:')
        || !preg_match('/(?:^|;)sslmode=require(?:;|$)/i', $dsn)) {
        throw new RuntimeException('The staging PDO DSN must use PostgreSQL with sslmode=require.');
    }

    $expectedUser = ($credentialSet === 'ADMIN' ? 'postgres.' : 'internpilih_staging_app.') . $projectRef;
    if (!hash_equals($expectedUser, $user)) {
        throw new RuntimeException('The staging database username does not match the approved project.');
    }
    if (!preg_match('/(?:^pgsql:|;)host=([^;]+)(?:;|$)/i', $dsn, $hostMatch)) {
        throw new RuntimeException('The staging PDO DSN is missing its host field.');
    }
    $poolerHost = strtolower(trim($hostMatch[1]));
    if (str_contains($poolerHost, ':')) {
        throw new RuntimeException('The staging PDO host field must not include a protocol or port.');
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $poolerHost)
        || !str_ends_with($poolerHost, '.pooler.supabase.com')) {
        throw new RuntimeException('The staging PDO DSN must use a Supabase pooler host.');
    }
    if (!preg_match('/(?:^|;)dbname=postgres(?:;|$)/i', $dsn)) {
        throw new RuntimeException('The staging PDO DSN must target the postgres database.');
    }
    $expectedPort = $credentialSet === 'ADMIN' ? 5432 : 6543;
    if (!preg_match('/(?:^|;)port=' . $expectedPort . '(?:;|$)/i', $dsn)) {
        throw new RuntimeException('The staging PDO DSN uses the wrong pooler port.');
    }

    return [
        'dsn' => $dsn,
        'user' => $user,
        'password' => $password,
        'host' => $poolerHost,
        'port' => $expectedPort,
        'transaction_pool' => $credentialSet === 'APP',
    ];
}

function stagingPdo(string $credentialSet = 'ADMIN'): PDO
{
    $settings = stagingConnectionSettings($credentialSet);
    $pdo = new PDO($settings['dsn'], $settings['user'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => $settings['transaction_pool'],
    ]);
    if (!$settings['transaction_pool']) {
        $pdo->exec("SET TIME ZONE 'UTC'");
    }
    return $pdo;
}

function assertStagingMarker(PDO $pdo): void
{
    $value = $pdo->query("
        SELECT setting_value
        FROM public.system_settings
        WHERE setting_key = 'environment'
        LIMIT 1
    ")->fetchColumn();
    if ($value !== 'staging') {
        throw new RuntimeException('The target database is not marked as InternPilih staging.');
    }
}
