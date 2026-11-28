<?php

namespace App\Services\Transaction;

use App\Services\Category\CategoryMappingService;
use Illuminate\Support\Facades\Log;

/**
 * ExtractionValidator (Hybrid.md Fase 2, §7-2c).
 *
 * Murni deterministik, tanpa DB, tanpa jaringan: memverifikasi hasil
 * HybridTransactionExtractor terhadap TEKS ASLI pesan. Prinsip §3:
 * LLM tidak pernah jadi sumber kebenaran — angka, tipe, dan kategori
 * selalu disahkan ulang oleh aturan.
 *
 * Aturan (2c):
 *  a. nominal LLM WAJIB == extractAmount(text); beda → pakai hasil
 *     extractAmount + log selisih (TIDAK ADA jalur angka LLM masuk DB).
 *  b. deklarasi eksplisit (detectDeclaredType) menang atas tipe LLM.
 *  b'. frasa yang arahnya pasti menurut aturan (detectPhraseType, mis.
 *     "sumbangan ... 12rb" = memberi) bertentangan dengan tipe LLM →
 *     tolak, kategori LLM pasti ikut salah arah → lepas ke rules.
 *  c. kategori LLM harus di whitelist config; jika tidak → fallback
 *     kategori rules + log (bukan penolakan).
 *  d. items[] batch → tolak di Fase 2 (jatuh ke rules).
 *  e. confidence < threshold → alur konfirmasi existing (dilaporkan
 *     lewat passesConfidence(); pemanggil yang memutuskan).
 */
class ExtractionValidator
{
    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';
    public const REASON_NO_AMOUNT = 'no_amount';
    public const REASON_TYPE_UNKNOWN = 'type_unknown';
    public const REASON_TYPE_OVERRIDDEN = 'type_overridden_by_declaration';
    public const REASON_TYPE_CONTRADICTED = 'type_contradicted_by_rules';
    public const REASON_BATCH = 'batch_items';
    public const REASON_LOW_CONFIDENCE = 'low_confidence';
    public const REASON_NOT_TRANSACTION = 'not_transaction';
    public const REASON_CATEGORY_NORMALIZED = 'category_normalized';

    /** Alasan yang TIDAK memblokir (hasil tetap dipakai, hanya dicatat). */
    private const NON_BLOCKING = [
        self::REASON_AMOUNT_MISMATCH,
        self::REASON_TYPE_OVERRIDDEN,
        self::REASON_CATEGORY_NORMALIZED,
    ];

    public function __construct(
        private readonly float $confidenceThreshold = 0.75,
        private readonly ?TransactionExtractorService $extractor = null,
    ) {}

    public function getConfidenceThreshold(): float
    {
        return $this->confidenceThreshold;
    }

    /**
     * Syarat minimum sebelum memanggil validate() penuh (murah, tanpa
     * membandingkan teks): intent transaksi, tipe jelas, nominal valid,
     * bukan batch, dan confidence ≥ threshold (§7-2d).
     */
    public function shouldIntercept(HybridExtractionResult $result): bool
    {
        if (! $result->isUsable()
            || $result->intent !== 'transaction'
            || ! in_array($result->type, ['income', 'expense'], true)
            || $result->amount === null
            || $result->amount <= 0
            || count($result->items) > 1
        ) {
            return false;
        }

        return $this->passesConfidence($result);
    }

    public function passesConfidence(HybridExtractionResult $result): bool
    {
        return $result->confidence !== null && $result->confidence >= $this->confidenceThreshold;
    }

    /**
     * Validasi penuh thd teks asli (2c a–d).
     *
     * @return array{
     *     passed: bool,
     *     is_income: bool,
     *     amount: int,
     *     kategori: ?string,
     *     category_type: string,
     *     reasons: array<int, string>,
     * }
     */
    public function validate(string $messageText, HybridExtractionResult $result): array
    {
        $extractor = $this->extractor ?? new TransactionExtractorService;
        $reasons = [];

        // ── 2c-d: batch ditolak di Fase 2 → lepas ke rules ──
        if (count($result->items) > 1) {
            $reasons[] = self::REASON_BATCH;
        }

        // ── Tipe dasar dari LLM (2c sekaligus syarat "type jelas") ──
        if (! in_array($result->type, ['income', 'expense'], true)) {
            $reasons[] = self::REASON_TYPE_UNKNOWN;
        }

        // ── 2c-a: nominal LLM vs rules ──
        $rulesAmount = $extractor->extractAmountFromText($messageText);
        $llmAmount = $result->amount;

        if ($rulesAmount === null || $rulesAmount <= 0) {
            // Rules tidak menemukan angka di pesan → LLM kemungkinan mengarang → tolak.
            $reasons[] = self::REASON_NO_AMOUNT;
            $amount = max(0, (int) ($llmAmount ?? 0));
        } elseif ($llmAmount === null || $llmAmount <= 0) {
            $reasons[] = self::REASON_NO_AMOUNT;
            $amount = (int) $rulesAmount;
        } elseif ((int) $llmAmount !== (int) $rulesAmount) {
            // 2c-a: beda → pakai extractAmount + log selisih (bukan penolakan).
            $reasons[] = self::REASON_AMOUNT_MISMATCH;
            $amount = (int) $rulesAmount;
            Log::info('ExtractionValidator: nominal LLM beda dari rules — rules menang', [
                'llm_amount' => $llmAmount,
                'rules_amount' => $rulesAmount,
                'text_preview' => mb_substr($messageText, 0, 100),
            ]);
        } else {
            $amount = (int) $rulesAmount;
        }

        // ── 2c-b: deklarasi eksplisit menang atas tipe LLM ──
        $textLower = mb_strtolower(trim($messageText));
        $declaredType = TransactionTypeDetector::detectDeclaredType($textLower);
        $isIncome = $declaredType !== null
            ? $declaredType === 'income'
            : $result->type === 'income';

        if ($declaredType !== null && $declaredType !== $result->type) {
            $reasons[] = self::REASON_TYPE_OVERRIDDEN;
            Log::info('ExtractionValidator: deklarasi eksplisit menang atas tipe LLM', [
                'declared' => $declaredType,
                'llm_type' => $result->type,
                'text_preview' => mb_substr($messageText, 0, 100),
            ]);
        }

        // ── 2c-b': frasa yang arahnya sudah pasti menurut aturan ──
        // Mis. "sumbangan pernikahan 12rb" / "memberi sumbangan 12rb": LLM
        // membacanya sebagai pemasukan padahal memberi sumbangan = pengeluaran.
        // Arah yang salah selalu diiringi kategori yang salah arah juga, jadi
        // hasil hybrid dilepas seluruhnya ke jalur rules (jatuh-when-in-doubt)
        // supaya tipe DAN kategori sama-sama dipetakan ulang dari teks.
        if ($declaredType === null) {
            $phraseType = TransactionTypeDetector::detectPhraseType($textLower);

            if ($phraseType !== null && $phraseType !== $result->type) {
                $reasons[] = self::REASON_TYPE_CONTRADICTED;
                $isIncome = $phraseType === 'income';

                Log::info('ExtractionValidator: frasa pasti bertentangan dengan tipe LLM — lepas ke rules', [
                    'phrase_type' => $phraseType,
                    'llm_type' => $result->type,
                    'text_preview' => mb_substr($messageText, 0, 100),
                ]);
            }
        }

        // ── 2c-c: kategori LLM harus di whitelist config ──
        $kategori = is_string($result->category) ? trim($result->category) : null;
        if ($kategori === '') {
            $kategori = null;
        }

        $categoryType = (new CategoryMappingService)->mapFinwaKategoriToCategoryType($kategori, $isIncome);

        if ($kategori !== null && ! $this->isWhitelistedCategory($categoryType)) {
            // Tak dikenal whitelist → fallback kategori rules (TS/CategoryInference
            // memetakan ulang dari teks) + log. Bukan penolakan.
            $reasons[] = self::REASON_CATEGORY_NORMALIZED;
            Log::info('ExtractionValidator: kategori LLM di luar whitelist — fallback rules', [
                'llm_category' => $kategori,
                'text_preview' => mb_substr($messageText, 0, 100),
            ]);
            $kategori = null;
            $categoryType = (new CategoryMappingService)->mapFinwaKategoriToCategoryType(null, $isIncome);
        }

        // ── Keputusan akhir: ada alasan blokir → lepas saringan ──
        $blocking = array_values(array_diff($reasons, self::NON_BLOCKING));
        $passed = $blocking === [];

        if (! $passed) {
            Log::info('ExtractionValidator: hasil hybrid DITOLAK → jalur lama 100%', [
                'blocking' => $blocking,
                'notes' => array_values(array_intersect($reasons, self::NON_BLOCKING)),
                'text_preview' => mb_substr($messageText, 0, 100),
            ]);
        }

        return [
            'passed' => $passed,
            'is_income' => $isIncome,
            'amount' => $amount,
            'kategori' => $kategori,
            'category_type' => $categoryType,
            'reasons' => $reasons,
        ];
    }

    /**
     * 2c-c: kategori lolos whitelist bila mapFinwaKategoriToCategoryType
     * memetakannya ke kategori nyata — fallback *_lainnya berarti tidak ada
     * satu pun keyword di config (finwa_category_rules) yang cocok.
     */
    private function isWhitelistedCategory(string $categoryType): bool
    {
        return ! str_ends_with($categoryType, '_lainnya');
    }
}
