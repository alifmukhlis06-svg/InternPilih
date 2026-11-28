<?php
// api/gemini.php - Server-side Gemini proxy for rejection message generation
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gemini-client.php';
require_once __DIR__ . '/rejection-email.php';

$user = requireAuth();
if ($user['role'] !== 'student') {
    respondError('Students only', 403, 'student_role_required');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $available = geminiIsConfigured();
    respond([
        'available' => $available,
        'message' => $available
            ? 'AI drafting is available.'
            : 'AI drafting is unavailable because the server key is not configured. The normal templates remain available.',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$d = body();
$offerId = (int)($d['offer_id'] ?? 0);
$company = trim((string)($d['company'] ?? ''));
$position = trim((string)($d['position'] ?? ''));
$reason = trim((string)($d['reason'] ?? ''));
$tone = trim((string)($d['tone'] ?? 'Professional & Formal'));
$language = trim((string)($d['language'] ?? 'English'));
$context = trim((string)($d['context'] ?? ''));

// Browser requests bind the draft to the authenticated student's pending
// offer. Keep the legacy fact-only API shape for controlled test clients.
if ($offerId > 0) {
    $offerStatement = db()->prepare("
        SELECT c.name AS company_name, o.position
        FROM offers o
        JOIN companies c ON c.id = o.company_id
        WHERE o.id = ? AND o.student_id = ? AND o.status = 'pending'
        LIMIT 1
    ");
    $offerStatement->bind_param('ii', $offerId, $user['id']);
    $offerStatement->execute();
    $offer = $offerStatement->get_result()->fetch_assoc();
    if (!$offer) {
        respondError('Select a current pending offer before generating a draft.', 422, 'pending_offer_required');
    }
    $company = trim((string)$offer['company_name']);
    $position = trim((string)$offer['position']);
}

if ($company === '' || $position === '' || $reason === '') {
    respondError('Company, position, and reason are required.', 422, 'rejection_details_required');
}

$allowedTones = ['Professional & Formal', 'Warm & Grateful', 'Concise & Direct', 'Apologetic & Polite'];
$allowedLanguages = ['English', 'Malay (Bahasa Malaysia)', 'formal English with a Malay opening greeting'];
if (!in_array($tone, $allowedTones, true) || !in_array($language, $allowedLanguages, true)) {
    respondError('Select a supported tone and language.', 422, 'unsupported_draft_option');
}
if (mb_strlen($company, 'UTF-8') > 160 || mb_strlen($position, 'UTF-8') > 160
    || mb_strlen($reason, 'UTF-8') > 400 || mb_strlen($context, 'UTF-8') > 1000) {
    respondError('The drafting details are too long. Shorten the optional context and try again.', 422, 'draft_input_too_long');
}

$studentName = trim((string)($user['name'] ?? ''));
$studentId = trim((string)($user['matric_id'] ?? ''));
if ($studentName === '' || $studentId === '') {
    respondError('Your student profile is incomplete, so an AI draft cannot be personalized safely.', 422, 'student_profile_incomplete');
}

$facts = [
    'company' => $company,
    'position' => $position,
    'reason' => $reason,
    'tone' => $tone,
    'language' => $language,
    'context' => $context,
    'student_name' => $studentName,
    'student_id' => $studentId,
];

try {
    $result = geminiGenerateText(rejectionEmailUserPayload($facts), [
        'temperature' => 0.3,
        'maxOutputTokens' => 500,
        'responseMimeType' => 'application/json',
        'responseSchema' => rejectionEmailResponseSchema(),
    ], rejectionEmailSystemInstruction());
} catch (GeminiClientException $error) {
    respondError($error->getMessage(), $error->httpStatus, 'ai_provider_error');
}

$structured = json_decode($result['text'], true);
if (!is_array($structured) || !is_string($structured['body'] ?? null)) {
    respondError('The AI response was not a valid rejection-email draft. No draft was displayed; use a manual template or try once more.', 502, 'generated_draft_invalid');
}
$draft = finalizeRejectionEmail($structured['body'], $facts);
if ($draft['errors']) {
    respondError(
        'The AI response did not pass rejection-email validation. No draft was displayed; use a manual template or try once more.',
        502,
        'generated_draft_rejected',
        ['validation_errors' => $draft['errors']]
    );
}

respond([
    'success' => true,
    'text' => $draft['text'],
    'model' => $result['model'],
    'grounding_filter_applied' => $draft['removed_claims'] !== [],
]);
