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

    'facebook' => [
        'pixel_id' => env('FACEBOOK_PIXEL_ID'),
    ],

    'whatsapp' => [
        'engine_url' => env('WHATSAPP_ENGINE_URL', 'http://localhost:3004'),
        'api_key' => env('WHATSAPP_ENGINE_API_KEY'), // No default - must be configured
    ],

    'ai_processor' => [
        'url' => env('AI_PROCESSOR_URL', 'http://localhost:8001'),
        'api_key' => env('AI_PROCESSOR_API_KEY'), // No default - must be configured
    ],

    'ocr_worker' => [
        'url' => env('OCR_WORKER_URL', 'http://localhost:8002'),
        'api_key' => env('OCR_WORKER_API_KEY'), // No default - must be configured
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
        'timeout' => env('FINWA_AI_TIMEOUT', 30),
        'enabled' => env('FINWA_AI_ENABLED', true),
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

];
