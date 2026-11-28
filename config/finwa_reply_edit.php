<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fitur: Edit Transaksi via Reply WhatsApp
    |--------------------------------------------------------------------------
    | Dokumen rencana: docs/RENCANA-IMPLEMENTASI-REPLY-EDIT.md
    |
    | Flag induk. Selama false, seluruh jalur reply-context dilewatkan dan
    | sistem tetap berperilaku seperti sebelumnya (flow keyword lama).
    | Flag baru pertama kali diaktifkan setelah Fase 4 selesai.
    */
    'enabled' => (bool) env('FINWA_REPLY_EDIT_ENABLED', false),

    // Mode aman untuk rollout Fase 2+: sistem menyiapkan rencana edit tetapi
    // TIDAK mengeksekusi. Selalu true sampai log dry-run ditinjau.
    'dry_run' => (bool) env('FINWA_REPLY_EDIT_DRY_RUN', true),

    // TTL status konfirmasi (detik). Lewat dari ini, permintaan dianggap basi.
    'confirmation_ttl' => (int) env('FINWA_REPLY_EDIT_CONFIRM_TTL', 300),

    // TTL payload daftar riwayat bernomor (detik) — berapa lama balasan ke
    // pesan daftar masih bisa dipetakan ke transaksi (Ops — fitur 1).
    'list_ttl' => (int) env('FINWA_REPLY_EDIT_LIST_TTL', 3600),

    // Batas pencarian transaksi ketika message ID tidak cocok (hari).
    'fuzzy_window_days' => (int) env('FINWA_REPLY_EDIT_FUZZY_DAYS', 30),

    // Prioritas resolusi. 1 = message ID mapping, 2 = fuzzy, 3 = pilihan manual.
    'allowed_resolutions' => ['mapping', 'fuzzy', 'manual_pick'],

    /*
    |--------------------------------------------------------------------------
    | Fase 4 — Hardening
    |--------------------------------------------------------------------------
    */

    // Canary rollout: daftar tenant_id yang Boleh memakai fitur.
    // Kosong [] = semua tenant. Diisi (mis. "1,2") saat rollout bertahap.
    'allowed_tenants' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('FINWA_REPLY_EDIT_TENANTS', ''))
    ))),

    // Rate limit per pengirim (anti-abuse): maksimum N aksi reply-edit
    // (termasuk gagal/konfirmasi basi) per jendela waktu.
    'rate_limit_max' => (int) env('FINWA_REPLY_EDIT_RATE_MAX', 10),
    'rate_limit_decay' => (int) env('FINWA_REPLY_EDIT_RATE_DECAY', 60),

    // Ambang peringatan: bila lebih dari X% dari resolusi fuzzy berupa
    // pilihan manual (tidak yakin), pertimbangkan perbesar jendela/skoring.
    'fuzzy_ambiguity_alert_pct' => (int) env('FINWA_REPLY_EDIT_AMBIG_ALERT', 60),

    // Retensi pemetaan & audit (hari) untuk cleanup job.
    'mapping_retention_days' => (int) env('FINWA_REPLY_EDIT_MAPPING_RETENTION', 90),

];
