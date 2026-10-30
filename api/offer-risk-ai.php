<?php
// AI decision support for students already flagged by deterministic offer rules.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/offer-flags.php';
require_once __DIR__ . '/gemini-client.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    respond(['error' => 'Admins only'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$data = body();
$studentId = (int)($data['student_id'] ?? 0);
if ($studentId < 1) {
    respond(['error' => 'A valid student is required.'], 422);
}

$studentStmt = db()->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$studentStmt->bind_param('i', $studentId);
$studentStmt->execute();
if (!$studentStmt->get_result()->fetch_assoc()) {
    respond(['error' => 'Student not found.'], 404);
}
requireCoordinatorStudent((int)$user['id'], $studentId);

$responseDays = offerResponsePeriodDays();
$offerStmt = db()->prepare("
    SELECT
        status,
        CURRENT_DATE - offer_date AS offer_age_days,
        GREATEST(CURRENT_DATE - (offer_date + {$responseDays}), 0) AS overdue_days
    FROM offers
    WHERE student_id = ?
    ORDER BY offer_date ASC
");
$offerStmt->bind_param('i', $studentId);
$offerStmt->execute();
$offers = $offerStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pendingOffers = array_values(array_filter($offers, fn($offer) => $offer['status'] === 'pending'));
$overdueOffers = array_values(array_filter($pendingOffers, fn($offer) => (int)$offer['overdue_days'] > 0));
$reasons = offerFlagReasons(count($pendingOffers), count($overdueOffers));
if (!$reasons) {
    respond(['error' => 'This student is not currently flagged by the system rules.'], 422);
}

// Only the explicitly approved anonymous timing facts leave InternPilih.
$anonymousContext = [
    'configured_response_period_days' => $responseDays,
    'pending_offer_count' => count($pendingOffers),
    'accepted_offer_count' => count(array_filter($offers, fn($offer) => $offer['status'] === 'accepted')),
    'deterministic_flag_reasons' => $reasons,
    'pending_offer_ages_days' => array_map(fn($offer) => (int)$offer['offer_age_days'], $pendingOffers),
    'pending_offer_overdue_days' => array_map(fn($offer) => (int)$offer['overdue_days'], $pendingOffers),
];

$prompt = "You are assisting an internship coordinator. The system has already calculated offer-risk indicators using fixed rules. Do not replace or reverse that deterministic flag. Using only the supplied anonymous indicators: (1) write a concise, neutral, plain-language summary, (2) suggest an advisory urgency level, (3) explain the contributing factors, (4) recommend a coordinator follow-up action and timeframe, and (5) draft a courteous reminder. Do not infer personal circumstances, make disciplinary or legal conclusions, or add facts. Return JSON matching the provided schema.\n\nAnonymous rule-based indicators:\n"
    . json_encode($anonymousContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$schema = [
    'type' => 'object',
    'properties' => [
        'summary' => ['type' => 'string'],
        'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
        'factors' => ['type' => 'array', 'items' => ['type' => 'string']],
        'recommended_action' => ['type' => 'string'],
        'suggested_follow_up_hours' => ['type' => 'integer'],
        'reminder_message' => ['type' => 'string'],
    ],
    'required' => ['summary', 'urgency', 'factors', 'recommended_action', 'suggested_follow_up_hours', 'reminder_message'],
];

try {
    $result = geminiGenerateText($prompt, [
        'temperature' => 0.2,
        'maxOutputTokens' => 1000,
        'responseMimeType' => 'application/json',
        'responseSchema' => $schema,
    ]);
} catch (GeminiClientException $error) {
    respond(['error' => $error->getMessage()], $error->httpStatus);
}

$analysis = json_decode($result['text'], true);
if (!is_array($analysis)) {
    respond(['error' => 'Gemini returned an invalid risk assessment.'], 502);
}

$allowedUrgency = ['low', 'medium', 'high', 'critical'];
$analysis = [
    'summary' => substr(trim((string)($analysis['summary'] ?? '')), 0, 1000),
    'urgency' => in_array($analysis['urgency'] ?? '', $allowedUrgency, true) ? $analysis['urgency'] : 'medium',
    'factors' => array_slice(array_map(
        fn($factor) => substr(trim((string)$factor), 0, 300),
        is_array($analysis['factors'] ?? null) ? $analysis['factors'] : []
    ), 0, 6),
    'recommended_action' => substr(trim((string)($analysis['recommended_action'] ?? '')), 0, 800),
    'suggested_follow_up_hours' => max(1, min(168, (int)($analysis['suggested_follow_up_hours'] ?? 24))),
    'reminder_message' => substr(trim((string)($analysis['reminder_message'] ?? '')), 0, 1200),
];
if ($analysis['summary'] === '') {
    respond(['error' => 'Gemini returned an empty risk-indicator summary.'], 502);
}

logActivity((int)$user['id'], 'offer_flag.ai_review_generated', 'user', $studentId, [
    'advisory_urgency' => $analysis['urgency'],
    'flag_reasons' => $reasons,
    'model' => $result['model'],
]);

respond([
    'success' => true,
    'student_id' => $studentId,
    'rule_result' => [
        'is_flagged' => true,
        'reasons' => $reasons,
        'pending_count' => count($pendingOffers),
        'overdue_count' => count($overdueOffers),
    ],
    'analysis' => $analysis,
    'model' => $result['model'],
]);
