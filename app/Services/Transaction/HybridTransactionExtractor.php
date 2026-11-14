<?php

namespace App\Services\Transaction;

use App\Services\AI\OpenAIChatClient;
use App\Services\HybridLLMConfigService;
use Illuminate\Support\Facades\Log;

/**
 * HybridTransactionExtractor (Hybrid.md Fase 1).
 *
 * Kontrak: pre-check deterministik → panggil klien LLM (OpenAI Chat
 * Completion, konfigurasi admin §6A) → parse JSON → sanity-check struktural
 * → kembalikan hasil + status kepercayaan.
 *
 * CATATAN HIRARKI (§3): hasil ini BELUM dipercaya — ExtractionValidator
 * (Fase 2) yang memverifikasi angka terhadap teks asli sebelum masuk DB.
 * Extractor ini tidak pernah melempar exception: kegagalan → status
 * `unavailable` → pemanggil jatuh ke rules.
 */
class HybridTransactionExtractor
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah mesin ekstraksi transaksi keuangan berbahasa Indonesia untuk aplikasi WhatsApp FinWa.

Tugasmu: ubah pesan user menjadi JSON saja. JANGAN bicara, JANGAN tambah teks lain.

Skema JSON:
{
  "intent": "transaction" | "query" | "other",
  "type": "income" | "expense" | null,
  "items": [{"amount": 24000, "label": "rokok"}],
  "wallet": "nama dompet jika disebut" | null,
  "category": "label kategori dari daftar" | null,
  "confidence": 0.0
}

Kategori WAJIB dipilih persis dari daftar ini (tanpa variasi/frasa bebas):
• Expense: makanan, bahan makanan, transport, hunian, utilitas, kesehatan, pendidikan,
  belanja, hiburan, pulsa token, tagihan, investasi, bayar hutang, piutang, cicilan,
  asuransi, pajak, donasi, gaji karyawan, keluarga, baby, hewan, gadget, langganan,
  pakaian, perawatan diri, acara, otomotif, sosial, hadiah, modal, operasional, transfer
• Income: gaji, bonus, usaha, sewa, refund, transfer, investasi, hutang
• Ragu-ragu → null (sistem lama yang memutuskan).

Aturan WAJIB:
1. intent = "transaction" hanya jika user jelas ingin MENCATAT uang masuk/keluar.
   Pertanyaan (berapa, ringkasan, berapa saldo) = "query". Sapaan/obrolan = "other".
2. amount = RUPIAH BULAT. Tulisan: "24000"/"24.000"=24000, "24k"=24000, "24rb"=24000,
   "1,5jt"/"1.5jt"=1500000, "3jt"=3000000, "800rb"=800000. JANGAN mengarang angka —
   setiap amount HARUS berasal dari angka yang ada di pesan (boleh konversi satuan).
3. Pesan multi-transaksi ("Beli rokok 24000 sama jajan 6000") → items berisi
   SATU entry per transaksi, amount masing-masing.
4. Deklarasi eksplisit MENGALAHKAN tebakan: "Pengeluaran ... gaji ..." → type=expense;
   "Pemasukan ..." → type=income. Kata "gaji"/"bonus" TANPA deklarasi → income;
   TANPA indikasi sama sekali → expense.
5. Jika ada angka tapi TIDAK yakin intent transaksi → "other" (biar sistem lama yang proses).
6. confidence = seberapa yakinmu (0.0-1.0).

Jawab HANYA JSON valid, tanpa markdown, tanpa penjelasan.
PROMPT;

    public function __construct(
        protected OpenAIChatClient $client = new OpenAIChatClient,
        protected HybridLLMConfigService $configService = new HybridLLMConfigService,
        protected HybridCircuitBreaker $breaker = new HybridCircuitBreaker,
    ) {}

    /**
     * Ekstrak struktur transaksi dari teks bebas.
     */
    public function extract(string $text): HybridExtractionResult
    {
        // Pre-check deterministik (§6A prioritas 3): konfigurasi kosong → nonaktif.
        if (! $this->configService->isReady()) {
            return HybridExtractionResult::disabled();
        }

        // Circuit breaker (Fase 3): 3x gagal beruntun / 1 menit → lewati LLM.
        if ($this->breaker->isOpen()) {
            return HybridExtractionResult::unavailable('breaker_open');
        }

        $raw = $this->client->complete(
            [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $text],
            ],
            maxTokens: 400,
            temperature: 0.0,
            extraParams: ['response_format' => ['type' => 'json_object']],
        );

        if ($raw === null) {
            $this->breaker->recordFailure();

            return HybridExtractionResult::unavailable('llm_error');
        }

        $data = $this->parseJson($raw);
        if ($data === null) {
            $this->breaker->recordFailure();
            Log::warning('HybridTransactionExtractor: JSON tidak valid', [
                'raw_preview' => mb_substr($raw, 0, 300),
            ]);

            return HybridExtractionResult::unavailable('invalid_json', mb_substr($raw, 0, 500));
        }

        // API merespons (baik hasil ok maupun tidak berguna) → hidup, reset gagal.
        $this->breaker->recordSuccess();

        return $this->buildResult($data, $raw);
    }

    /**
     * Parsing tangguh: buang code fence, ambil blok {...} pertama yang utuh.
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Cadangan: JSON tertanam di tengah teks.
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Susun DTO dari array hasil LLM + sanity-check struktural minimal.
     * (Validasi mendalam thd teks asli = ExtractionValidator, Fase 2.)
     */
    private function buildResult(array $data, string $raw): HybridExtractionResult
    {
        $intent = is_string($data['intent'] ?? null) ? strtolower(trim($data['intent'])) : null;
        if (! in_array($intent, ['transaction', 'query', 'other'], true)) {
            return HybridExtractionResult::unavailable('bad_intent', mb_substr($raw, 0, 500));
        }

        $type = $data['type'] ?? null;
        if (! in_array($type, ['income', 'expense'], true)) {
            $type = null;
        }

        // Kumpulkan items — LLM boleh kirim amount tunggal atau daftar.
        $items = [];
        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($rawItems as $item) {
            $amount = is_array($item) ? (int) ($item['amount'] ?? 0) : (int) $item;
            if ($amount > 0) {
                $items[] = [
                    'amount' => $amount,
                    'label' => is_array($item) && is_string($item['label'] ?? null)
                        ? mb_substr(trim($item['label']), 0, 120)
                        : null,
                ];
            }
        }

        if ($items === [] && $intent === 'transaction') {
            $single = (int) ($data['amount'] ?? 0);
            if ($single > 0) {
                $items[] = ['amount' => $single, 'label' => null];
            }
        }

        // intent transaksi tanpa satu pun nominal valid → jangan dipakai.
        if ($intent === 'transaction' && $items === []) {
            return HybridExtractionResult::unavailable('no_amount', mb_substr($raw, 0, 500));
        }

        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null;
        if ($confidence !== null) {
            $confidence = max(0.0, min(1.0, $confidence));
        }

        return new HybridExtractionResult(
            status: HybridExtractionResult::STATUS_OK,
            intent: $intent,
            type: $type,
            amount: $items === [] ? null : array_sum(array_column($items, 'amount')),
            items: $items,
            wallet: is_string($data['wallet'] ?? null) && trim($data['wallet']) !== ''
                ? mb_substr(trim($data['wallet']), 0, 80)
                : null,
            category: is_string($data['category'] ?? null) && trim($data['category']) !== ''
                ? mb_substr(trim($data['category']), 0, 80)
                : null,
            confidence: $confidence,
            raw: mb_substr($raw, 0, 1000),
        );
    }
}
