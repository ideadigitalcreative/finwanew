<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DDoS Protection Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for DDoS protection and rate limiting
    |
    */

    'ddos_protection' => [
        'enabled' => env('DDOS_PROTECTION_ENABLED', true),

        'global_limits' => [
            'requests_per_minute' => env('DDOS_GLOBAL_RPM', 100),
            'requests_per_hour' => env('DDOS_GLOBAL_RPH', 1000),
        ],

        'login_limits' => [
            'attempts_per_minute' => env('DDOS_LOGIN_RPM', 3),
            'attempts_per_hour' => env('DDOS_LOGIN_RPH', 10),
            'lockout_duration_minutes' => env('DDOS_LOGIN_LOCKOUT', 15),
        ],

        'api_limits' => [
            'auth_requests_per_minute' => env('DDOS_API_AUTH_RPM', 10),
            'general_requests_per_minute' => env('DDOS_API_GENERAL_RPM', 60),
        ],

        'webhook_limits' => [
            'whatsapp_messages_per_minute' => env('DDOS_WEBHOOK_WA_RPM', 30),
            'ocr_uploads_per_minute' => env('DDOS_WEBHOOK_OCR_RPM', 10),
        ],

        'blacklist' => [
            'enabled' => env('DDOS_BLACKLIST_ENABLED', true),
            'ips' => explode(',', env('DDOS_BLACKLIST_IPS', '')),
            'user_agents' => explode(',', env('DDOS_BLACKLIST_UA', '')),
        ],

        'whitelist' => [
            'enabled' => env('DDOS_WHITELIST_ENABLED', false),
            'ips' => explode(',', env('DDOS_WHITELIST_IPS', '')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for security monitoring and alerting
    |
    */

    'monitoring' => [
        'log_suspicious_activity' => env('SECURITY_LOG_SUSPICIOUS', true),
        'alert_thresholds' => [
            'failed_logins_per_minute' => env('SECURITY_ALERT_FAILED_LOGINS', 5),
            'blocked_requests_per_minute' => env('SECURITY_ALERT_BLOCKED_RPM', 20),
        ],
        'notification_email' => env('SECURITY_ALERT_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for CAPTCHA protection on sensitive endpoints
    |
    */

    'captcha' => [
        'enabled' => env('CAPTCHA_ENABLED', false),
        'provider' => env('CAPTCHA_PROVIDER', 'recaptcha'), // recaptcha, hcaptcha
        'site_key' => env('CAPTCHA_SITE_KEY'),
        'secret_key' => env('CAPTCHA_SECRET_KEY'),
        'threshold' => env('CAPTCHA_THRESHOLD', 0.5), // For reCAPTCHA v3
    ],
];
