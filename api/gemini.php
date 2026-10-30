<?php
// api/gemini.php - Server-side Gemini proxy for rejection message generation
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gemini-client.php';

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
$company = trim((string)($d['company'] ?? ''));
$position = trim((string)($d['position'] ?? ''));
$reason = trim((string)($d['reason'] ?? ''));
$tone = trim((string)($d['tone'] ?? 'Professional & Formal'));
$language = trim((string)($d['language'] ?? 'English'));
$context = trim((string)($d['context'] ?? ''));

if ($company === '' || $position === '' || $reason === '') {
    respond(['error' => 'Company, position, and reason are required.'], 422);
}

$studentName = trim((string)($user['name'] ?? ''));
$studentId = trim((string)($user['matric_id'] ?? ''));
if ($studentName === '' || $studentId === '') {
    respondError('Your student profile is incomplete, so an AI draft cannot be personalized safely.', 422, 'student_profile_incomplete');
}

$prompt = "You are a professional career advisor helping a Malaysian university student write a polite and formal internship offer rejection email.\n\n"
    . "Details:\n"
    . "- Student name: {$studentName}\n"
    . "- Student ID: {$studentId}\n"
    . "- University: Universiti Teknologi MARA (UiTM)\n"
    . "- Company: {$company}\n"
    . "- Position: {$position}\n"
    . "- Reason for rejection: {$reason}\n"
    . "- Tone: {$tone}\n"
    . "- Language: {$language}"
    . ($context !== '' ? "\n- Additional context: {$context}" : '')
    . "\n\nWrite ONLY the email body, no subject line. It must be professional, respectful, and leave a good lasting impression. End with the student's name and ID.";

try {
    $result = geminiGenerateText($prompt, [
        'temperature' => 0.7,
        'maxOutputTokens' => 600,
    ]);
} catch (GeminiClientException $error) {
    respond(['error' => $error->getMessage()], $error->httpStatus);
}

respond(['success' => true, 'text' => $result['text'], 'model' => $result['model']]);
