<?php

function logActivity(?int $userId, string $action, string $entityType, ?int $entityId = null, ?array $meta = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $metaJson = $meta ? json_encode($meta) : null;

    $stmt = db()->prepare("
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, metadata, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('ississs', $userId, $action, $entityType, $entityId, $metaJson, $ip, $agent);
    $stmt->execute();
}
