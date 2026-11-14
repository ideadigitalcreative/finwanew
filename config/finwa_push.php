<?php

/*
|--------------------------------------------------------------------------
| FinWa — Push notification (Firebase Cloud Messaging, HTTP v1)
|--------------------------------------------------------------------------
|
| Popup notifikasi Android untuk aplikasi WebView. Panduan sisi Android:
| docs/ANDROID_PUSH_FCM.md
|
*/

return [

    'enabled' => (bool) env('FINWA_PUSH_ENABLED', false),

    // Service account Firebase: path file JSON (relatif ke base_path) ATAU isi JSON mentah.
    // Firebase Console → Project settings → Service accounts → Generate new private key.
    'credentials' => env('FIREBASE_CREDENTIALS', 'storage/app/firebase-service-account.json'),

    // Opsional — default diambil dari project_id di file service account.
    'project_id' => env('FIREBASE_PROJECT_ID'),

    'timeout' => (int) env('FINWA_PUSH_TIMEOUT', 10),

    // Channel notifikasi Android (harus dibuat juga di aplikasi, lihat docs).
    'android_channel' => env('FINWA_PUSH_ANDROID_CHANNEL', 'transactions'),

    // Transaksi yang dicatat dalam jendela ini (detik) oleh orang yang sama
    // digabung jadi SATU notifikasi ringkasan. Batch / struk dari SATU pesan WA
    // sudah otomatis tergabung (dicatat dalam satu job sebelum job push jalan),
    // jadi jendela pendek cukup — makin besar, makin lama popup muncul.
    'batch_window_seconds' => (int) env('FINWA_PUSH_BATCH_WINDOW', 1),

    // Pencatat ikut menerima notifikasi untuk transaksinya sendiri?
    // Transaksi yang dibuat di dashboard/aplikasi TIDAK pernah dikirim balik ke pencatatnya.
    'notify_actor' => (bool) env('FINWA_PUSH_NOTIFY_ACTOR', true),

    // Jam tenang (WIB), format "22:00-06:00". Kosong = nonaktif. Di jam ini notifikasi tidak dikirim.
    'quiet_hours' => env('FINWA_PUSH_QUIET_HOURS', ''),

    // Sumber transaksi yang dicatat langsung oleh user di dashboard/aplikasi
    'in_app_sources' => ['dashboard', 'manual', 'api', 'web', 'mobile'],

    // Tipe/sumber yang tidak perlu dinotifikasi (pindah saldo antar dompet, saldo awal)
    'skip_types' => ['debit_internal', 'kredit_internal'],
    'skip_sources' => ['opening_balance_command'],

];
