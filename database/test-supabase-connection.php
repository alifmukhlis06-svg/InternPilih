<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

try {
    $config = require __DIR__ . '/../config/database.php';
    if (!preg_match('/(?:^|;)sslmode=require(?:;|$)/i', (string)$config['dsn'])) {
        throw new RuntimeException('The local PDO DSN must enforce sslmode=require.');
    }

    // With libpq, a successful connection using sslmode=require guarantees
    // that the client-to-pooler connection was negotiated over TLS. Do not use
    // pg_stat_ssl here: behind Supavisor it describes the pooler-to-database
    // backend connection rather than this PHP client's TLS session.
    $result = db()->query('SELECT 1 AS connection_ok');
    $row = $result instanceof DatabaseResult ? $result->fetch_assoc() : null;
    if ((int)($row['connection_ok'] ?? 0) !== 1) {
        throw new RuntimeException('The connection test returned no result.');
    }

    echo "PDO PostgreSQL connection successful.\n";
    echo "Client TLS: required by sslmode=require.\n";
    echo "Read-only SELECT: successful.\n";
} catch (Throwable $error) {
    $message = strtolower($error->getMessage());
    $safeError = match (true) {
        str_contains($message, 'password authentication failed'),
        str_contains($message, 'authentication failed') => 'authentication failed',
        str_contains($message, 'could not translate host'),
        str_contains($message, 'name or service not known'),
        str_contains($message, 'getaddrinfo') => 'Session pooler hostname could not be resolved',
        str_contains($message, 'connection refused') => 'Session pooler refused the connection',
        str_contains($message, 'timeout'),
        str_contains($message, 'timed out') => 'Session pooler connection timed out',
        str_contains($message, 'must enforce sslmode=require') => 'local DSN does not require SSL',
        str_contains($message, 'ssl') => 'TLS negotiation failed',
        str_contains($message, 'configuration is missing') => 'local database configuration is incomplete',
        default => 'PDO PostgreSQL connection failed',
    };
    fwrite(STDERR, 'Connection test failed: ' . $safeError . PHP_EOL);
    exit(1);
}
