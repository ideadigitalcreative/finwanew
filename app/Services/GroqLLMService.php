<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Groq LLM Backup Service (Llama via Groq inference)
 *
 * Hanya dipanggil di titik-titik spesifik dimana FinWa-AI tidak bisa handle:
 * 1. Menjawab pertanyaan umum keuangan (tanya_finwa fallback)
 * 2. Generate analisis/insight mendalam dari data keuangan
 * 3. Smart fallback classifier ketika semua classifier gagal
 *
 * Mendukung multiple API key dengan rotasi otomatis.
 */
class GroqLLMService
{
    protected string $baseUrl;

    protected array $apiKeys;

    protected string $model;

    protected int $timeout;

    protected bool $enabled;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah FinWa, asisten keuangan di WhatsApp. Kamu ngobrol santai kayak temen, bukan guru atau dosen.

TOPIK YANG BOLEH DIJAWAB:
Keuangan, finansial, bisnis, UMKM, investasi, nabung, budgeting, asuransi, pajak, utang, cashflow, dan semua hal yang nyambung ke duit/uang. Juga pertanyaan soal aplikasi FinWa.

Kalau topiknya BISA dikaitkan ke keuangan → langsung jawab. JANGAN awali dengan "mohon maaf" atau penolakan.

TOLAK kalau beneran ga nyambung (politik, gosip, game, resep, cuaca). Bilang aja:
"Sori ya, aku cuma bisa bantu soal keuangan aja nih 🙏"

WAJIB DIIKUTI — GAYA BAHASA:
- Kamu itu temen ngobrol, BUKAN asisten formal
- Pakai "kamu/aku", JANGAN pakai "Anda/Saya"
- Bahasa santai sehari-hari, contoh: "simpelnya", "intinya", "jadi gini", "nah", "nih"
- DILARANG pakai bahasa kaku/formal seperti: "Anda", "merupakan", "bertujuan untuk", "mengevaluasi", "mengidentifikasi", "memenuhi kebutuhan"
- MAKSIMAL 80 kata. Lebih dari itu SALAH.
- JANGAN pakai list panjang. Kalau perlu list, maks 3 poin pendek
- JANGAN pakai tanda * untuk bold
- Emoji boleh tapi secukupnya

POLA JAWABAN: Jelaskan dulu → baru kasih tips FinWa di akhir (opsional, 1 kalimat).

CONTOH JAWABAN YANG BENAR:
User: "apa itu cash flow"
Jawaban: "Cash flow itu simpelnya aliran duit masuk dan keluar kamu 💰 Misal gaji masuk 5jt, keluar buat makan dll 3jt, berarti cash flow kamu positif 2jt. Kalau yang keluar lebih gede, berarti udah warning 🚨 Di FinWa bisa ketik 'ringkasan bulan ini' buat cek!"

User: "apa itu UMKM"
Jawaban: "UMKM itu Usaha Mikro Kecil Menengah 🏪 Contohnya warung, toko online, freelancer. Yang penting dari sisi duit: pisahin uang pribadi sama uang usaha biar ga campur aduk. Di FinWa bisa buat dompet terpisah buat usaha!"

User: "tips nabung"
Jawaban: "Gampang kok! 💰 Pertama, tiap gajian langsung sisihkan 20% ke tabungan sebelum dipake apa-apa. Kedua, catat semua pengeluaran biar tau bocornya dimana. Ketiga, kurangin jajan yang ga perlu. Coba ketik 'cek statistik' buat liat pengeluaran terbesarmu!"
PROMPT;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.groq_llm.base_url', 'https://api.groq.com/openai/v1'), '/');
        $this->model = config('services.groq_llm.model', 'llama-3.1-8b-instant');
        $this->timeout = (int) config('services.groq_llm.timeout', 30);
        $this->enabled = (bool) config('services.groq_llm.enabled', true);

        $keysRaw = config('services.groq_llm.api_keys', '');
        $this->apiKeys = array_filter(
            array_map('trim', explode(',', $keysRaw))
        );
    }

    public function isAvailable(): bool
    {
        return $this->enabled && ! empty($this->apiKeys);
    }

    /**
     * Rotasi API key — round-robin berbasis counter di cache.
     * Jika satu key gagal (rate limit), otomatis pindah ke key berikutnya.
     */
    protected function getApiKey(): string
    {
        $count = count($this->apiKeys);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $this->apiKeys[0];
        }

        $index = (int) Cache::get('groq_llm_key_index', 0);
        $key = $this->apiKeys[$index % $count];

        Cache::put('groq_llm_key_index', ($index + 1) % $count, 3600);

        return $key;
    }

    /**
     * Titik 3: Jawab pertanyaan umum keuangan yang FinWa-AI tidak bisa handle.
     */
    public function answerFinancialQuestion(string $question, ?string $conversationContext = null): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $systemPrompt = self::SYSTEM_PROMPT."\n\n"
            ."User nanya sesuatu. Aturan:\n"
            ."- Kalau nyambung ke keuangan → langsung jawab, JANGAN awali dengan penolakan.\n"
            ."- Kalau lanjutan dari obrolan keuangan sebelumnya → jawab sebagai kelanjutan.\n"
            ."- HANYA tolak kalau beneran ga ada hubungannya sama keuangan.\n"
            .'- Ingat: MAKSIMAL 100 kata, santai, to the point.';

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        if (! empty($conversationContext)) {
            $messages[] = ['role' => 'system', 'content' => $conversationContext];
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        return $this->chat($messages, 200);
    }

    /**
     * Titik 4: Generate analisis/insight mendalam dari data keuangan user.
     */
    public function generateFinancialInsight(array $financialData, ?string $specificQuestion = null): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $dataStr = $this->formatFinancialDataForPrompt($financialData);

        $systemPrompt = self::SYSTEM_PROMPT."\n\n"
            ."Kamu menerima data keuangan user. Berikan analisis singkat dan actionable.\n"
            ."Fokus pada: pola pengeluaran, area penghematan, dan saran konkret.\n"
            .'Format untuk WhatsApp (singkat, poin-poin, emoji).';

        $userContent = "Berikut data keuangan saya:\n\n{$dataStr}";
        if ($specificQuestion) {
            $userContent .= "\n\nPertanyaan spesifik: {$specificQuestion}";
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ];

        return $this->chat($messages, 500);
    }

    /**
     * Titik 8b: Parse struk/receipt langsung dari Gambar (Direct Vision).
     * Menggunakan model llama-3.2-11b-vision-preview.
     */
    public function parseReceiptFromImage(string $base64Image): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        Log::info('GroqLLMService: Parsing receipt directly from image (Direct Vision)...');

        $systemPrompt = 'Kamu adalah mesin ekstraksi struk belanja Indonesia berbasis visi. '
            .'Ekstrak data dari gambar struk menjadi JSON dengan field: is_receipt(bool), merchant(string), nominal(int), date(YYYY-MM-DD), items(array of {name,price}), category(string), confidence(float). '
            .'Aturan: '
            .'1) merchant = nama toko dari header. '
            .'2) nominal = TOTAL AKHIR. '
            .'3) category: belanja/makan/transportasi/hiburan/kesehatan/pendidikan/tagihan/lainnya.';

        // Strip data URI scheme if exists
        $imageData = $base64Image;
        if (preg_match('/^data:image\/[a-z]+;base64,(.*)$/', $base64Image, $matches)) {
            $imageData = $matches[1];
        }

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $systemPrompt,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:image/jpeg;base64,{$imageData}",
                        ],
                    ],
                ],
            ],
        ];

        try {
            // Kita gunakan model vision 3.2 11B
            $response = $this->chat($messages, 1000, 0, [
                'model' => 'llama-3.2-11b-vision-preview',
                'response_format' => ['type' => 'json_object'],
            ]);

            if (! $response) {
                return null;
            }

            $parsed = json_decode($response, true);

            // Fallback regex jika JSON decode gagal
            if (! $parsed && preg_match('/\{.*\}/s', $response, $matches)) {
                $parsed = json_decode($matches[0], true);
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Groq Vision error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Titik 8: Parse struk/receipt dari teks OCR mentah (Hybrid: Regex + AI).
     * Menggunakan JSON Mode untuk memaksa model mengembalikan JSON valid.
     */
    public function parseReceiptFromOCR(string $ocrText, ?array $heuristicHint = null): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        // 1. Pre-clean OCR
        $cleanText = preg_replace('/\s+/', ' ', $ocrText);
        $cleanText = trim($cleanText);

        // 2. Build prompt
        $systemPrompt = 'Kamu adalah mesin ekstraksi struk belanja Indonesia yang ahli. '
            .'Ekstrak data dari teks OCR menjadi JSON dengan field: is_receipt(bool), merchant(string), nominal(int), date(YYYY-MM-DD), items(array of {name,price}), category(string), confidence(float). '
            .'Aturan Penting: '
            .'1) MERCHANT: Ambil Nama Brand/Toko. JANGAN masukkan alamat (Jl, J1, JI, Km, Kel, Makassar, dll). Jika baris pertama alamat, ambil baris di bawahnya yang merupakan Nama Brand. '
            .'2) ITEMS: Fokus hanya pada barang yang dibeli. ABAIKAN metadata/header struk seperti: Nomor (Number, Humber, No, Ref), Nama Kasir (Feby, Kasir, Cashier, Operator), Jam/Waktu, atau kode mesin. '
            .'3) CORRECT SPELLING: Perbaiki typo OCR (Contoh: "Kerip1k" -> "Keripik", "Susu U8T" -> "Susu UHT"). Gunakan Title Case. '
            .'4) MULTI-LINE: Jika Nama Barang terpisah baris dengan harga, gabungkan dengan cerdas. Pastikan nama barang tidak terpotong. '
            .'5) NOMINAL: Ambil TOTAL AKHIR yang dibayar. Angka ini biasanya ada di PALING BAWAH struk. Prioritaskan angka di samping kata "TOTAL", "GRAND TOTAL", "SUBTOTAL", atau di baris pembayaran seperti "CARD", "QRIS", "CASH", "TUNAI". JANGAN ambil angka kode barang, nomor seri, atau persentase diskon yang ada di tengah struk. '
            .'6) CATEGORY: belanja/makan/transportasi/hiburan/kesehatan/pendidikan/tagihan/lainnya.';

        if ($heuristicHint) {
            $hMerchant = $heuristicHint['merchant'] ?? null;
            $hNominal = $heuristicHint['nominal'] ?? 0;
            if ($hMerchant) {
                $systemPrompt .= " Hint: merchant mungkin '{$hMerchant}'.";
            }
            if ($hNominal > 0) {
                $systemPrompt .= " Hint: total mungkin {$hNominal}.";
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $cleanText],
        ];

        // Use JSON mode to FORCE valid JSON output
        $response = $this->chatJson($messages, 1000);

        if (! $response) {
            return null;
        }

        $parsed = json_decode($response, true);
        if (! $parsed || ! isset($parsed['nominal'])) {
            // Fallback: try to extract JSON from response
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
            if (! $parsed || ! isset($parsed['nominal'])) {
                Log::warning('Groq Receipt Parse: failed to parse response', [
                    'raw_preview' => mb_substr($response, 0, 500),
                ]);

                return null;
            }
        }

        return $parsed;
    }

    /**
     * Titik 7: Smart fallback classifier.
     * Return: ['intent' => '...', 'confidence' => 0.x] atau null jika gagal.
     */
    public function classifyIntent(string $messageText, ?string $conversationContext = null): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $systemPrompt = <<<'PROMPT'
Kamu adalah classifier intent untuk aplikasi keuangan WhatsApp berbahasa Indonesia.

Klasifikasikan pesan user ke SALAH SATU intent berikut:
- "transaction": User ingin MENCATAT transaksi (pengeluaran/pemasukan). Contoh: "makan 25rb", "gaji 5jt", "beli bensin 50k"
- "query": User ingin BERTANYA/MELIHAT data keuangan. Contoh: "berapa pengeluaran bulan ini?", "ringkasan minggu ini"
- "greeting": User menyapa (pesan pendek). Contoh: "halo", "hi", "pagi"
- "faq": User bertanya tentang tips, edukasi, atau penjelasan seputar keuangan. Contoh: "tips hemat", "apa itu deposito?", "jelaskan tentang asuransi", "maksud jaga keseimbangan itu apa"
- "irrelevant": Pesan BENAR-BENAR tidak ada hubungannya dengan keuangan. Contoh: "siapa presiden", "resep nasi goreng"

PENTING: Jika ragu antara "faq" dan "irrelevant", pilih "faq". Lebih baik menjawab daripada menolak.

Jawab HANYA dalam format JSON (tanpa markdown code block):
{"intent":"<intent>","confidence":<0.0-1.0>}
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        if (! empty($conversationContext)) {
            $messages[] = ['role' => 'system', 'content' => "Konteks percakapan:\n{$conversationContext}"];
        }

        $messages[] = ['role' => 'user', 'content' => $messageText];

        $response = $this->chat($messages, 50);

        if (! $response) {
            return null;
        }

        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (! $parsed || ! isset($parsed['intent'])) {
            Log::warning('Groq classify: failed to parse response', ['raw' => $response]);

            return null;
        }

        $validIntents = ['transaction', 'query', 'greeting', 'faq', 'irrelevant'];
        if (! in_array($parsed['intent'], $validIntents)) {
            $parsed['intent'] = 'irrelevant';
        }

        return $parsed;
    }

    /**
     * Chat with forced JSON output mode (temperature=0, response_format=json_object).
     * Ideal for structured data extraction like receipts.
     */
    protected function chatJson(array $messages, int $maxTokens = 400): ?string
    {
        return $this->chat($messages, $maxTokens, 0, [
            'response_format' => ['type' => 'json_object'],
        ]);
    }

    /**
     * Core chat completion call ke Groq API (OpenAI-compatible).
     * Mendukung retry dengan key rotation jika rate-limited.
     */
    protected function chat(array $messages, int $maxTokens = 400, float $temperature = 0.7, ?array $extraParams = null): ?string
    {
        $maxRetries = min(count($this->apiKeys), 3);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $apiKey = $this->getApiKey();
            if (empty($apiKey)) {
                return null;
            }

            try {
                $payload = [
                    'model' => $this->model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                ];

                if ($extraParams) {
                    $payload = array_merge($payload, $extraParams);
                }

                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/chat/completions", $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $content = $data['choices'][0]['message']['content'] ?? null;

                    Log::info('Groq LLM response', [
                        'model' => $this->model,
                        'usage' => $data['usage'] ?? [],
                        'content_length' => $content ? mb_strlen($content) : 0,
                    ]);

                    return $content;
                }

                // Rate limited (429) → rotate ke key berikutnya dan retry
                if ($response->status() === 429 && $attempt < $maxRetries) {
                    Log::warning('Groq rate limited, rotating key', [
                        'attempt' => $attempt + 1,
                        'key_suffix' => substr($apiKey, -6),
                    ]);

                    continue;
                }

                Log::error('Groq API error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                    'key_suffix' => substr($apiKey, -6),
                ]);

                return null;

            } catch (\Exception $e) {
                Log::error('Groq API connection error', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < $maxRetries) {
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    protected function formatFinancialDataForPrompt(array $data): string
    {
        $lines = [];

        if (isset($data['period'])) {
            $lines[] = "Periode: {$data['period']}";
        }
        if (isset($data['total_income'])) {
            $lines[] = 'Total Pemasukan: Rp '.number_format($data['total_income'], 0, ',', '.');
        }
        if (isset($data['total_expense'])) {
            $lines[] = 'Total Pengeluaran: Rp '.number_format($data['total_expense'], 0, ',', '.');
        }
        if (isset($data['net_cashflow'])) {
            $lines[] = 'Cashflow Bersih: Rp '.number_format($data['net_cashflow'], 0, ',', '.');
        }
        if (isset($data['transaction_count'])) {
            $lines[] = "Jumlah Transaksi: {$data['transaction_count']}";
        }

        if (! empty($data['top_expense_categories'])) {
            $lines[] = "\nTop Pengeluaran:";
            foreach ($data['top_expense_categories'] as $cat) {
                $catName = is_array($cat) ? ($cat['category'] ?? '?') : '?';
                $catAmount = is_array($cat) ? ($cat['amount'] ?? 0) : 0;
                $catCount = is_array($cat) ? ($cat['count'] ?? 0) : 0;
                $lines[] = "- {$catName}: Rp ".number_format($catAmount, 0, ',', '.')." ({$catCount}x)";
            }
        }

        if (! empty($data['top_income_categories'])) {
            $lines[] = "\nTop Pemasukan:";
            foreach ($data['top_income_categories'] as $cat) {
                $catName = is_array($cat) ? ($cat['category'] ?? '?') : '?';
                $catAmount = is_array($cat) ? ($cat['amount'] ?? 0) : 0;
                $lines[] = "- {$catName}: Rp ".number_format($catAmount, 0, ',', '.');
            }
        }

        if (isset($data['prev_month_expense'])) {
            $lines[] = "\nBulan lalu pengeluaran: Rp ".number_format($data['prev_month_expense'], 0, ',', '.');
        }
        if (isset($data['prev_month_income'])) {
            $lines[] = 'Bulan lalu pemasukan: Rp '.number_format($data['prev_month_income'], 0, ',', '.');
        }

        return implode("\n", $lines);
    }
}
