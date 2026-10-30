<?php

class GeminiClientException extends RuntimeException {
    public function __construct(string $message, public int $httpStatus = 502) {
        parent::__construct($message);
    }
}

function geminiConfiguration(): array {
    $config = require __DIR__ . '/../config/gemini.php';
    $localConfig = __DIR__ . '/../config/gemini.local.php';
    if (is_file($localConfig)) {
        $config = array_replace($config, require $localConfig);
    }
    return $config;
}

function geminiIsConfigured(): bool {
    return trim((string)(geminiConfiguration()['api_key'] ?? '')) !== '';
}

function geminiGenerateText(string $prompt, array $generationConfig = []): array {
    $config = geminiConfiguration();
    $apiKey = trim((string)($config['api_key'] ?? ''));
    $model = trim((string)($config['model'] ?? 'gemini-3.1-flash-lite'));
    if ($apiKey === '') {
        throw new GeminiClientException('Gemini API key is not configured. Set GEMINI_API_KEY in the server environment.', 503);
    }

    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => $generationConfig,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new GeminiClientException('Could not prepare the Gemini request.', 500);
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey];
    $status = 0;
    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new GeminiClientException('Could not connect to Gemini: ' . $curlError);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            $status = (int)$matches[1];
        }
        if ($raw === false) {
            throw new GeminiClientException('Could not connect to Gemini.');
        }
    }

    $data = json_decode((string)$raw, true) ?: [];
    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? 'Gemini request failed.';
        if (stripos($message, 'quota') !== false) {
            throw new GeminiClientException('Gemini quota exceeded. Use an API key with available quota or enable billing.', 429);
        }
        throw new GeminiClientException($message, ($status >= 400 && $status < 500) ? $status : 502);
    }

    $text = trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($text === '') {
        throw new GeminiClientException('Gemini returned an empty response.');
    }

    return ['text' => $text, 'model' => $model];
}
