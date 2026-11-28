<?php

return [
    'api_key' => getenv('GEMINI_API_KEY') ?: '',
    'model' => getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite',
    'connect_timeout_seconds' => (int)(getenv('GEMINI_CONNECT_TIMEOUT_SECONDS') ?: 5),
    'request_timeout_seconds' => (int)(getenv('GEMINI_REQUEST_TIMEOUT_SECONDS') ?: 20),
];

