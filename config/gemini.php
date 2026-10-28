<?php

return [
    'api_key' => getenv('GEMINI_API_KEY') ?: '',
    'model' => getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite',
];

