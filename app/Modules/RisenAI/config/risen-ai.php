<?php

return [
    'openrouter_api_key' => env('OPENROUTER_API_KEY'),
    'model' => env('OPENROUTER_MODEL', 'anthropic/claude-3-sonnet'),
    'max_tokens' => env('RISEN_AI_MAX_TOKENS', 4000),
    'article_base_url' => env('RISEN_AI_BASE_URL', '/artikel'),
    'auto_publish' => env('RISEN_AI_AUTO_PUBLISH', false),
    'intent_min_score' => env('RISEN_AI_INTENT_MIN_SCORE', 70),
    'queue_name' => env('RISEN_AI_QUEUE', 'default'),
    'gsc_property_url' => env('RISEN_AI_GSC_PROPERTY'),
    'app_context' => [
        'name' => 'FinWa',
        'full_name' => 'FinWa - Catat Keuangan via WhatsApp',
        'description' => 'Aplikasi pencatatan keuangan otomatis melalui WhatsApp yang memudahkan individu dan UMKM memantau arus kas secepat mengirim pesan chat.',
        'company' => 'PT Idea Digital Creative',
        'features' => [
            'Pencatatan otomatis via Bot WhatsApp (cukup chat seperti "Makan siang 25rb")',
            'Dashboard keuangan real-time yang intuitif',
            'Manajemen anggaran (budgeting) per kategori',
            'Laporan keuangan otomatis (PDF/Excel)',
            'Multi-tenant untuk bisnis dan personal',
            'Aman, cepat, dan praktis tanpa aplikasi rumit',
        ],
        'target_users' => 'Individu, Pemilik Warung, Toko Online, Freelancer, dan pelaku UMKM.',
        'voice' => 'Profesional, hangat, solutif, dan mudah dipahami.',
        'url' => 'https://finwa.web.id',
    ],
];
