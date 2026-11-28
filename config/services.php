<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
        'analytics_id' => env('GOOGLE_ANALYTICS_ID'),
    ],

    'whatsapp' => [
        'engine_url' => env('WHATSAPP_ENGINE_URL', 'http://localhost:3004'),
        'api_key' => env('WHATSAPP_ENGINE_API_KEY'), // No default - must be configured
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],

    'ai_processor' => [
        'url' => env('AI_PROCESSOR_URL', 'http://localhost:8001'),
        'api_key' => env('AI_PROCESSOR_API_KEY'), // No default - must be configured
    ],

    'ocr_worker' => [
        'url' => env('OCR_WORKER_URL', 'http://localhost:8002'),
        'api_key' => env('OCR_WORKER_API_KEY'), // No default - must be configured
        // Host tambahan sumber gambar struk (selain APP_URL & WHATSAPP_ENGINE_URL), dipisah koma
        'allowed_media_hosts' => env('OCR_ALLOWED_MEDIA_HOSTS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Security Configuration
    |--------------------------------------------------------------------------
    |
    | API key for authenticating incoming webhooks from external services.
    | This protects against fake webhook injection attacks.
    |
    */
    'webhook' => [
        'api_key' => env('WEBHOOK_API_KEY'), // Must be configured in .env
    ],

    // Callback dari STT worker (header X-API-Key = STT_WORKER_API_KEY)
    'stt_worker' => [
        'api_key' => env('STT_WORKER_API_KEY'),
    ],

    // Panggilan service-to-service internal (slack-bot: CORE_API_KEY = INTERNAL_API_KEY)
    'internal' => [
        'api_key' => env('INTERNAL_API_KEY'),
    ],

    // Nomor WhatsApp admin/super-admin (dulu tersebar hardcoded di banyak file)
    'finwa' => [
        // Paket yang boleh memakai fitur catat di grup WhatsApp (Lite = 'growth', Pro = 'pro')
        'group_plans' => array_filter(array_map('trim', explode(',', (string) env('FINWA_GROUP_PLANS', 'pro,enterprise')))),
        'super_admin_number' => env('FINWA_SUPER_ADMIN_NUMBER', '6285242766676'),
        'admin_number' => env('FINWA_ADMIN_NUMBER', '6285159205506'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FinWa-AI v2 Configuration
    |--------------------------------------------------------------------------
    |
    | FinWa-AI is a lightweight, rule-based NLU engine for processing
    | WhatsApp finance messages. It provides fast, deterministic intent
    | classification and entity extraction without requiring LLM APIs.
    |
    */
    'finwa_ai' => [
        'url' => env('FINWA_AI_URL', 'https://ai.finwa.web.id'),
        // 30 dtk terlalu lama: user menunggu, dan gagal → fallback aturan lokal
        'timeout' => env('FINWA_AI_TIMEOUT', 10),
        'enabled' => env('FINWA_AI_ENABLED', true),
        // Kirim user_id sebagai hash, bukan nomor WA mentah (aktifkan setelah server AI siap)
        'hash_user_id' => env('FINWA_AI_HASH_USER_ID', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Weekly Digest (Ringkasan Mingguan WhatsApp)
    |--------------------------------------------------------------------------
    |
    | Pesan ringkasan keuangan otomatis setiap Minggu. Matikan di level
    | sistem dengan FINWA_WEEKLY_DIGEST_ENABLED=false (bukan per-user).
    |
    */
    'weekly_digest' => [
        'enabled' => env('FINWA_WEEKLY_DIGEST_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reminder Blasts (Pengingat Massal WhatsApp)
    |--------------------------------------------------------------------------
    |
    | Pengingat yang dikirim massal ke banyak user (bukan percakapan 1-on-1)
    | yang berisiko membuat nomor bot WhatsApp kena restrict. Nonaktifkan di
    | level sistem dengan FINWA_REMINDER_BLASTS_ENABLED=false.
    |
    | YANG TERMASUK (akan di-skip saat off):
    |   - reminder:daily               (reminder harian, setiap 3 menit)
    |   - finwa:send-reminders         (reminder custom user, setiap menit)
    |   - digest:weekly                (ringkasan mingguan)
    |   - cashflow:mid-month           (prediksi cashflow tengah bulan)
    |   - reminder:morning-escalation  (eskalasi user inactive 2+ hari)
    |   - budget:daily-health-check    (alert budget harian)
    |
    | YANG TETAP AKTIF meskipun off (kritis untuk bisnis):
    |   - subscriptions:send-reminders (H-2/H-1 reminder masa aktif paket Pro)
    |   - subscriptions:check-expired  (pengecekan subscription expired)
    |
    */
    'reminder_blasts' => [
        'enabled' => env('FINWA_REMINDER_BLASTS_ENABLED', false),
    ],

    /*
    | Pengingat yang DIMINTA user sendiri — bukan blast, default AKTIF:
    |   - finwa:send-reminders : "ingatkan bayar PDAM tgl 15"
    |   - reminder:daily       : hanya untuk akun yang mengetik "aktifkan reminder"
    |                            (saat blast off; saat blast on = semua akun, perilaku lama)
    */
    'user_reminders' => [
        'enabled' => env('FINWA_USER_REMINDERS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Groq Configuration (for FREE Whisper STT)
    |--------------------------------------------------------------------------
    |
    | Groq API settings for Speech-to-Text using Whisper large-v3.
    | FREE tier available at https://console.groq.com
    | Used to transcribe voice notes in WhatsApp messages.
    |
    */
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration (for Whisper STT - fallback, paid)
    |--------------------------------------------------------------------------
    |
    | OpenAI API settings for Speech-to-Text using Whisper API.
    | Used as fallback if Groq is not configured.
    |
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Groq LLM Configuration - Backup AI (Llama via Groq)
    |--------------------------------------------------------------------------
    |
    | Groq digunakan sebagai backup LLM untuk menangani kasus yang
    | tidak bisa di-handle oleh FinWa-AI (rule-based), seperti:
    | - Menjawab pertanyaan umum keuangan
    | - Generate analisis/insight dari data keuangan user
    | - Smart fallback classifier
    |
    | Mendukung multiple API key (comma-separated) dengan rotasi otomatis.
    |
    */
    'groq_llm' => [
        'api_keys' => env('GROQ_LLM_API_KEYS', env('GROQ_API_KEY', '')),
        'base_url' => env('GROQ_LLM_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('GROQ_LLM_MODEL', 'llama-3.1-8b-instant'),
        'timeout' => env('GROQ_LLM_TIMEOUT', 30),
        'enabled' => env('GROQ_LLM_ENABLED', true),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => env('GEMINI_TIMEOUT', 60),
        'base_url' => env('GEMINI_BASE_URL', ''),
    ],

    // Fallback env untuk ekstraksi hybrid (prioritas utama: AppSetting finwa_hybrid_llm di admin)
    'finwa_hybrid_llm' => [
        'enabled' => env('FINWA_HYBRID_LLM_ENABLED', false),
    ],

    // LLM CADANGAN: dipakai otomatis bila LLM utama (admin) error 5xx/429/timeout,
    // mis. "This model is currently unavailable". Format OpenAI-compatible.
    // Default: Groq (kalau GROQ_LLM_API_KEYS / GROQ_API_KEY terisi).
    'finwa_llm_fallback' => [
        'base_url' => env('FINWA_LLM_FALLBACK_BASE_URL', env('GROQ_LLM_BASE_URL', 'https://api.groq.com/openai/v1')),
        'model' => env('FINWA_LLM_FALLBACK_MODEL', env('GROQ_LLM_MODEL', 'llama-3.1-8b-instant')),
        'api_key' => env('FINWA_LLM_FALLBACK_API_KEY', explode(',', (string) env('GROQ_LLM_API_KEYS', env('GROQ_API_KEY', '')))[0] ?? ''),
    ],

    // Chat LLM untuk pertanyaan umum ("cara agar bisa menabung?") — TERPISAH dari
    // ekstraksi transaksi hybrid. null = ikut flag hybrid (perilaku lama).
    // Endpoint/model/API key sama dengan hybrid (admin app_settings → env groq_llm).
    'finwa_chat_llm' => [
        'enabled' => env('FINWA_CHAT_LLM_ENABLED'),
        'timeout' => (int) env('FINWA_CHAT_LLM_TIMEOUT', 8),
    ],

];
