<?php

/**
 * Kontrak core-api ↔ FinWa-AI untuk empat aliran hutang/piutang (Fase 3.2).
 *
 * - `remote_contract`: dikirim di body POST /process/text bila send_with_request=true
 * - `nlu_prompt_snippet`: teks siap tempel ke prompt sistem NLU Ai-Finwa (repo terpisah)
 */
$allowed = [
    'pendapatan_hutang',
    'pengeluaran_bayar_hutang',
    'pengeluaran_piutang',
    'pendapatan_terima_piutang',
];

$intentToType = [
    'catat_hutang' => 'pendapatan_hutang',
    'bayar_hutang' => 'pengeluaran_bayar_hutang',
    'catat_piutang' => 'pengeluaran_piutang',
    'terima_piutang' => 'pendapatan_terima_piutang',
];

$primarySlugByType = [
    'pendapatan_hutang' => 'terima_hutang',
    'pengeluaran_bayar_hutang' => 'bayar_hutang',
    'pengeluaran_piutang' => 'keluar_piutang',
    'pendapatan_terima_piutang' => 'terima_piutang',
];

$slugToType = [];
foreach ($primarySlugByType as $type => $slug) {
    $slugToType[$slug] = $type;
}
// Sinonim umum yang mungkin dikeluarkan model lama / LLM
$slugToType['pinjaman_masuk'] = 'pendapatan_hutang';
$slugToType['hutang_masuk'] = 'pendapatan_hutang';
$slugToType['lunas_hutang'] = 'pengeluaran_bayar_hutang';
$slugToType['angsur_hutang'] = 'pengeluaran_bayar_hutang';
$slugToType['piutang_keluar'] = 'pengeluaran_piutang';
$slugToType['kasih_pinjam'] = 'pengeluaran_piutang';
$slugToType['cair_piutang'] = 'pendapatan_terima_piutang';
$slugToType['pelunasan_piutang'] = 'pendapatan_terima_piutang';

$nluPromptSnippet = <<<'PROMPT'
HUTANG & PIUTANG (wajib selaras dengan core-api Laravel):

Intent → category_type (ENUM persis, jangan ganti ke pengeluaran_pinjaman/pengeluaran_cicilan untuk aliran ini):
- catat_hutang → pendapatan_hutang (uang masuk, hutang ke pihak naik)
- bayar_hutang → pengeluaran_bayar_hutang (uang keluar lunasi/angsur hutang)
- catat_piutang → pengeluaran_piutang (uang keluar, piutang ke pihak naik)
- terima_piutang → pendapatan_terima_piutang (uang masuk pelunasan piutang)

Isi entities.category_type dengan salah satu empat nilai di atas bila intent hutang/piutang.
Boleh tambahkan entities.category_slug (slug stabil): terima_hutang | bayar_hutang | keluar_piutang | terima_piutang.

Nama pihak lawan: entities.lawan atau pihak atau counterparty (string).

Jangan memetakan frasa "bayar hutang" ke pengeluaran_pinjaman; itu kategori lama untuk kredit umum, bukan empat aliran hutang-piutang ini.
PROMPT;

return [
    'send_with_request' => env('FINWA_AI_SEND_HP_CONTRACT', true),

    'allowed_category_types' => $allowed,

    'intent_to_category_type' => $intentToType,

    'primary_slug_by_category_type' => $primarySlugByType,

    'slug_to_category_type' => $slugToType,

    /** Ringkas untuk HTTP (tanpa prompt panjang) */
    'remote_contract' => [
        'name' => 'finwa_hutang_piutang',
        'version' => 1,
        'intents' => array_keys($intentToType),
        'category_types' => $allowed,
        'intent_to_category_type' => $intentToType,
        'category_slug_primary' => $primarySlugByType,
        'recommended_entity_keys' => [
            'nominal', 'tanggal', 'catatan', 'merchant',
            'category_type', 'category_slug',
            'lawan', 'pihak', 'counterparty', 'nama_pihak', 'nama_lawan',
        ],
        'json_schema_hint' => [
            'type' => 'object',
            'description' => 'Perluasan entities untuk intent hutang/piutang',
            'properties' => [
                'category_type' => [
                    'type' => 'string',
                    'enum' => $allowed,
                ],
                'category_slug' => [
                    'type' => 'string',
                    'description' => 'Slug disarankan; core tetap memakai intent jika ragukan',
                ],
            ],
        ],
    ],

    'nlu_prompt_snippet' => $nluPromptSnippet,
];
