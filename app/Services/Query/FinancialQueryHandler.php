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
}
