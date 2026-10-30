<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

if (!in_array('--execute', $argv, true)) {
    echo "PLAN ONLY - no staging state was exercised.\n";
    echo "Execution performs temporary writes inside one rolled-back transaction.\n";
    exit(0);
}

try {
    $pdo = stagingPdo('APP');
    assertStagingMarker($pdo);
    $sessionKey = hash('sha256', random_bytes(32));
    $rateKey = hash('sha256', random_bytes(32));

    $pdo->beginTransaction();
    $pdo->prepare("
        INSERT INTO public.app_sessions (session_key, session_data, expires_at)
        VALUES (?, 'synthetic-session', CURRENT_TIMESTAMP + INTERVAL '10 minutes')
    ")->execute([$sessionKey]);
    $pdo->prepare("
        INSERT INTO public.login_rate_limits (key_hash, failure_count)
        VALUES (?, 1)
        ON CONFLICT (key_hash) DO UPDATE SET failure_count = EXCLUDED.failure_count
    ")->execute([$rateKey]);
    $pdo->prepare('UPDATE public.app_sessions SET session_data = ? WHERE session_key = ?')
        ->execute(['synthetic-session-updated', $sessionKey]);

    $pdo->prepare("
        INSERT INTO public.system_settings (setting_key, setting_value, updated_at)
        VALUES ('staging_trigger_probe', 'before', TIMESTAMPTZ '2000-01-01 00:00:00+00')
    ")->execute();
    $pdo->prepare("
        UPDATE public.system_settings
        SET setting_value = 'after'
        WHERE setting_key = 'staging_trigger_probe'
    ")->execute();
    $updatedAt = $pdo->query("
        SELECT updated_at > TIMESTAMPTZ '2020-01-01 00:00:00+00'
        FROM public.system_settings
        WHERE setting_key = 'staging_trigger_probe'
    ")->fetchColumn();

    $pdo->prepare('DELETE FROM public.app_sessions WHERE session_key = ?')->execute([$sessionKey]);
    $pdo->prepare('DELETE FROM public.login_rate_limits WHERE key_hash = ?')->execute([$rateKey]);
    if (!stagingPgBool($updatedAt)) {
        throw new RuntimeException('The set_updated_at trigger did not update the timestamp.');
    }
    $pdo->rollBack();

    echo "Shared session insert/update/delete: successful.\n";
    echo "Login throttling upsert/delete: successful.\n";
    echo "set_updated_at trigger: successful.\n";
    echo "All probe writes were rolled back.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('InternPilih staging state exercise failed: ' . $error->getMessage());
    fwrite(STDERR, "Staging state exercise failed.\n");
    exit(1);
}
