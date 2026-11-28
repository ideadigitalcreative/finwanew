<?php

namespace App\Services\Query;

use App\Models\Message;
use App\Services\ConversationContextService;
use App\Services\FinancialQueryService;
use Illuminate\Support\Facades\Log;

/**
 * FinancialQueryHandler - Handles financial queries and questions
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class FinancialQueryHandler
{
    protected Message $message;

    protected $sendReplyCallback;

    protected $handleCheckBalanceCallback;

    /**
     * Constructor
     *
     * @param  Message  $message  The message being processed
     * @param  callable  $sendReplyCallback  Callback to send reply via WhatsApp
     * @param  callable  $handleCheckBalanceCallback  Callback to handle check balance
     */
    public function __construct(
        Message $message,
        callable $sendReplyCallback,
        ?callable $handleCheckBalanceCallback = null
    ) {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
        $this->handleCheckBalanceCallback = $handleCheckBalanceCallback;
    }

    /**
     * Send reply via callback
     */
    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Handle check balance via callback
     */
    protected function handleCheckBalance(?string $messageText = null): void
    {
        if ($this->handleCheckBalanceCallback) {
            call_user_func($this->handleCheckBalanceCallback, $messageText);
        }
    }

    /**
     * Get the actual sender ID (participant if in group)
     */
    protected function getAttributionSenderId(): string
    {
        $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
        if (($metadata['is_group'] ?? false) && ! empty($metadata['author'])) {
            return $metadata['author'];
        }

        return $this->message->sender_id;
    }

    /**
     * Handle financial query
     *
     * MOVED FROM: ProcessIncomingMessage::handleQuery()
     * LINES: 3342-3418
     * MODIFICATION: None (structural move only)
     */
    public function handleQuery(string $question): void
    {
        try {
            // Check for cashflow/balance queries - handle locally instead of AI
            $questionLower = strtolower($question);
            if (preg_match('/\b(cek\s+cashflow|cashflow|arus\s+kas|cash\s+flow|saldo|cek\s+saldo)\b/i', $questionLower)) {
                $this->handleCheckBalance($question);

                return;
            }

            // VALIDASI: Pastikan pesan benar-benar merupakan query keuangan
            // Cegah pesan tidak jelas (misal "edd", "halo", "ok") agar tidak
            // default menjadi "Ringkasan Keuangan"
            if (!$this->isRecognizedFinancialQuery($questionLower)) {
                Log::info('Pesan bukan query keuangan yang dikenali, mencoba jawaban LLM', [
                    'message_id' => $this->message->id,
                    'question' => $question,
                ]);

                // CHAT LLM (Hybrid §6A): pertanyaan umum (mis. "Kelebihan finwa
                // apa") dijawab LLM konfigurasi admin. Gagal → balasan lokal.
                $llmAnswer = (new \App\Services\AI\FinWaChatService)->answer($question);
                if ($llmAnswer !== null) {
                    Log::info('Chat LLM: balasan terkirim', [
                        'message_id' => $this->message->id,
                        'answer_len' => mb_strlen($llmAnswer),
                    ]);
                    $this->sendReply($llmAnswer);

                    return;
                }

                $this->sendReply(\App\Services\AI\FinWaChatService::fallbackReply());

                return;
            }

            $queryService = new FinancialQueryService;
            $result = $queryService->answerQuestion($this->message->tenant_id, $question);

            if (! $result['success']) {
                Log::error('Failed to answer query', [
                    'message_id' => $this->message->id,
                    'error' => $result['error'],
                ]);
                $this->sendReply('Maaf, saya tidak bisa menjawab pertanyaan Anda saat ini. Silakan coba lagi nanti.');

                return;
            }

            // Send reply
            $this->sendReply($result['answer']);

            // Update context with detected intent and category
            try {
                $contextService = new ConversationContextService($this->message->tenant_id, $this->getAttributionSenderId());

                // Detect query type and category from question
                $intent = 'cek_pengeluaran'; // default
                $category = null;

                if (preg_match('/pemasukan|pendapatan|penghasilan|omzet|omset|income|revenue/i', $questionLower)) {
                    $intent = 'cek_pemasukan';
                }

                // Extract category if mentioned
                $categoryPatterns = [
                    'makan' => 'Makanan & Minuman',
                    'transport' => 'Transportasi',
                    'belanja' => 'Belanja',
                    'hiburan' => 'Hiburan',
                    'tagihan' => 'Tagihan',
                    'listrik' => 'Tagihan',
                    'pulsa' => 'Komunikasi',
                    'baby' => 'Baby & Anak',
                    'bayi' => 'Baby & Anak',
                    'hewan' => 'Hewan Peliharaan',
                    'kucing' => 'Hewan Peliharaan',
                    'gadget' => 'Gadget & Elektronik',
                    'elektronik' => 'Gadget & Elektronik',
                ];
                foreach ($categoryPatterns as $keyword => $cat) {
                    if (str_contains($questionLower, $keyword)) {
                        $category = $cat;
                        break;
                    }
                }

                $contextService->updateLastContext($intent, [
                    'category' => $category,
                    'query_type' => 'financial_query',
                ], 'query');

            } catch (\Exception $e) {
                Log::warning('Failed to update query context', ['error' => $e->getMessage()]);
            }

            Log::info('Query answered and reply sent', [
                'message_id' => $this->message->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling query', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply('Maaf, terjadi error saat memproses pertanyaan Anda.');
        }
    }

    /**
     * Validasi apakah pesan benar-benar merupakan query keuangan yang dikenali.
     * Mencegah pesan acak (misal "edd", "ok", "test") diproses sebagai query
     * yang default-nya menghasilkan Ringkasan Keuangan.
     */
    public function isRecognizedFinancialQuery(string $questionLower): bool
    {
        // Satu sumber: config/finwa_query.php (sama dengan routing di ProcessIncomingMessage)
        $queryKeywords = array_merge(
            config('finwa_query.query_keywords', []),
            config('finwa_query.query_keywords_extra', []),
            config('finwa_query.period_keywords', []),
        );

        foreach ($queryKeywords as $keyword) {
            if (str_contains($questionLower, $keyword)) {
                return true;
            }
        }

        // Nama bulan sebagai kata utuh ("transaksi september", "rekap okt")
        $months = array_keys(config('finwa_query.month_names', []));
        if ($months && preg_match('/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn ($m) => preg_quote($m, '/'), $months)).')(?![\p{L}\p{N}])/u', $questionLower)) {
            return true;
        }

        // (Dulu: semua pesan bertanda "?" dianggap query keuangan → "apa itu dana
        // darurat?" dibalas Ringkasan Keuangan. Sekarang pertanyaan umum jatuh ke Chat LLM.)
        return false;
    }
}
