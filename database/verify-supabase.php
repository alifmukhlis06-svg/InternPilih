<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$expected = [
    'users',
    'companies',
    'admins',
    'students',
    'employers',
    'offers',
    'documents',
    'internship_applications',
    'announcements',
    'notifications',
    'activity_logs',
    'placement_change_requests',
    'password_reset_tokens',
    'system_settings',
];

$result = db()->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND table_type = 'BASE TABLE'
    ORDER BY table_name
");

if (!$result instanceof DatabaseResult) {
    throw new RuntimeException('Could not inspect the Supabase public schema.');
}

$actual = array_map(
    static fn(array $row): string => (string)$row['table_name'],
    $result->fetch_all(MYSQLI_ASSOC)
);
$missing = array_values(array_diff($expected, $actual));

if ($missing !== []) {
    fwrite(STDERR, 'Missing public tables: ' . implode(', ', $missing) . PHP_EOL);
    exit(2);
}

foreach ($expected as $table) {
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new RuntimeException('Unsafe table identifier.');
    }
    $countResult = db()->query('SELECT COUNT(*) AS total FROM public.' . $table);
    $count = $countResult instanceof DatabaseResult
        ? (int)($countResult->fetch_assoc()['total'] ?? 0)
        : 0;
    echo str_pad($table, 30) . $count . " rows\n";
}

echo "Supabase schema verification passed.\n";
