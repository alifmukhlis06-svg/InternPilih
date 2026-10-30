<?php

return [
    // local_capture uses Laragon Mailpit. Use smtp for real inbox delivery.
    'driver' => getenv('INTERNPILIH_MAIL_DRIVER') ?: 'local_capture',
    'from_email' => getenv('INTERNPILIH_MAIL_FROM_EMAIL') ?: 'noreply@internpilih.local',
    'from_name' => getenv('INTERNPILIH_MAIL_FROM_NAME') ?: 'InternPilih',
    'reply_to' => getenv('INTERNPILIH_MAIL_REPLY_TO') ?: 'admin@uitm.edu.my',
    'app_url' => getenv('INTERNPILIH_APP_URL') ?: 'http://internpilih.test',
    'smtp_host' => getenv('INTERNPILIH_SMTP_HOST') ?: 'smtp.gmail.com',
    'smtp_port' => (int)(getenv('INTERNPILIH_SMTP_PORT') ?: 587),
    'smtp_encryption' => getenv('INTERNPILIH_SMTP_ENCRYPTION') ?: 'tls',
    'smtp_username' => getenv('INTERNPILIH_SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('INTERNPILIH_SMTP_PASSWORD') ?: '',
];
