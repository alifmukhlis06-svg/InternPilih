<?php

declare(strict_types=1);

/**
 * Runtime PostgreSQL configuration.
 *
 * Secrets are read from environment variables first and then from the
 * git-ignored config/supabase.local.php file used by local Laragon installs.
 */
$localFile = __DIR__ . '/supabase.local.php';
$local = is_file($localFile) ? require $localFile : [];

$read = static function (string $environmentName, string $localKey, string $default = '') use ($local): string {
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false && trim($environmentValue) !== '') {
        return trim($environmentValue);
    }

    return trim((string)($local[$localKey] ?? $default));
};

$dsn = $read('INTERNPILIH_DATABASE_DSN', 'database_dsn');
$username = $read('INTERNPILIH_DATABASE_USER', 'database_user');
$password = $read('INTERNPILIH_DATABASE_PASSWORD', 'database_password');
$poolMode = strtolower($read(
    'INTERNPILIH_DATABASE_POOL_MODE',
    'database_pool_mode',
    getenv('VERCEL') !== false ? 'transaction' : 'session'
));

if ($dsn === '' || $username === '' || $password === '' || str_contains($password, 'YOUR_')) {
    throw new RuntimeException(
        'Supabase database configuration is missing. Configure environment variables '
        . 'or copy config/supabase.local.example.php to config/supabase.local.php.'
    );
}

if (!str_starts_with($dsn, 'pgsql:')) {
    throw new RuntimeException('InternPilih requires a PostgreSQL PDO DSN.');
}
if (!in_array($poolMode, ['session', 'transaction'], true)) {
    throw new RuntimeException('The PostgreSQL pool mode must be session or transaction.');
}
if ($poolMode === 'transaction' && !preg_match('/(?:^|;)port=6543(?:;|$)/i', $dsn)) {
    throw new RuntimeException('Transaction pool mode requires the Supabase pooler on port 6543.');
}

return [
    'dsn' => $dsn,
    'username' => $username,
    'password' => $password,
    'pool_mode' => $poolMode,
];
