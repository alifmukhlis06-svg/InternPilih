<?php

declare(strict_types=1);

/** Create the read-only source connection used by migration utilities. */
function mysqlSource(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('The mysqli extension is required for the source database.');
    }

    $localFile = __DIR__ . '/mysql.local.php';
    $local = is_file($localFile) ? require $localFile : [];
    $read = static function (string $environmentName, string $localKey, mixed $default = '') use ($local): mixed {
        $value = getenv($environmentName);
        return $value !== false && $value !== '' ? $value : ($local[$localKey] ?? $default);
    };

    $host = (string)$read('INTERNPILIH_MYSQL_HOST', 'host', '127.0.0.1');
    $database = (string)$read('INTERNPILIH_MYSQL_DATABASE', 'database', 'internpilih');
    $username = (string)$read('INTERNPILIH_MYSQL_USER', 'username');
    $password = (string)$read('INTERNPILIH_MYSQL_PASSWORD', 'password');
    $port = (int)$read('INTERNPILIH_MYSQL_PORT', 'port', 3306);

    if ($username === '' || str_contains($username, 'YOUR_') || str_contains($password, 'YOUR_')) {
        throw new RuntimeException(
            'MySQL source configuration is missing. Configure environment variables '
            . 'or copy config/mysql.local.example.php to config/mysql.local.php.'
        );
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($host, $username, $password, $database, $port);
    $connection->set_charset((string)($local['charset'] ?? 'utf8mb4'));
    return $connection;
}
