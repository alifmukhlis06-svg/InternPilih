<?php

return [
    // local_capture uses Laragon Mailpit. Use smtp for real inbox delivery.
    'driver' => 'local_capture',
    'from_email' => 'noreply@internpilih.local',
    'from_name' => 'InternPilih',
    'reply_to' => 'admin@uitm.edu.my',
    'app_url' => 'http://internpilih.test',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_username' => '',
    'smtp_password' => '',
];
