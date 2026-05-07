<?php

namespace App\Services;

use App\Models\ConversationContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ConversationContextService
{
    protected int $tenantId;

    protected ?string $senderId;

    // Context expires after 1 hour of inactivity
    const CONTEXT_EXPIRY_MINUTES = 60;

    // Maximum context entries to keep
    const MAX_CONTEXT_ENTRIES = 5;

    public function __construct(int $tenantId, ?string $senderId = null)
    {
        $this->tenantId = $tenantId;
        $this->senderId = $senderId;
    }

    /**
     * Helper to get context query scoped by tenant and sender
     */
    protected function getBaseQuery()
    {
        $query = ConversationContext::where('tenant_id', $this->tenantId);

        if ($this->senderId) {
            $query->where('entities->sender_id', $this->senderId);
        }

        return $query;
    }

    /**
     * Add context from a message
     */
    public function addContext(string $message, ?string $intent = null, array $entities = [], ?string $responseType = null): void
    {
        try {
            // Clean old contexts first
            $this->cleanOldContexts();

            // Add sender_id to entities if available
            if ($this->senderId) {
                $entities['sender_id'] = $this->senderId;
            }

            // Add new context
            ConversationContext::create([
                'tenant_id' => $this->tenantId,
                'message' => substr($message, 0, 500), // Limit message length
                'intent' => $intent,
                'entities' => $entities,
                'response_type' => $responseType,
            ]);

            // Keep only last N entries
            $this->pruneContexts();

        } catch (\Exception $e) {
            Log::warning('Failed to add conversation context', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the last context with intent and entities (after AI classification)
     */
    public function updateLastContext(?string $intent = null, array $entities = [], ?string $responseType = null): void
    {
        try {
            $lastContext = $this->getBaseQuery()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastContext) {
                $updates = [];
                if ($intent) {
                    $updates['intent'] = $intent;
                }
                if (! empty($entities)) {
                    $updates['entities'] = $entities;
                }
                if ($responseType) {
                    $updates['response_type'] = $responseType;
                }

                if (! empty($updates)) {
                    $lastContext->update($updates);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update conversation context', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store last transaction ID in context (for edit/correction)
     */
    public function storeLastTransactionId(int $transactionId): void
    {
        try {
            $lastContext = $this->getBaseQuery()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastContext) {
                $entities = $lastContext->entities ?? [];
                $entities['last_transaction_id'] = $transactionId;
                $lastContext->update(['entities' => $entities]);

                Log::info('Stored last transaction ID in context', [
                    'tenant_id' => $this->tenantId,
                    'transaction_id' => $transactionId,
                    'context_id' => $lastContext->id,
                    'context_message' => substr($lastContext->message, 0, 50),
                ]);
            } else {
                Log::warning('No context found to store transaction ID', [
                    'tenant_id' => $this->tenantId,
                    'transaction_id' => $transactionId,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to store last transaction ID', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get last transaction ID from context
     */
    public function getLastTransactionId(): ?int
    {
        try {
            // Get recent contexts (within expiry time)
            $contexts = $this->getBaseQuery()
                ->where('created_at', '>=', Carbon::now()->subMinutes(self::CONTEXT_EXPIRY_MINUTES))
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            // Search through recent contexts for last_transaction_id
            foreach ($contexts as $context) {
                $entities = $context->entities ?? [];
                if (isset($entities['last_transaction_id'])) {
                    Log::info('Found last transaction ID in context', [
                        'tenant_id' => $this->tenantId,
                        'transaction_id' => $entities['last_transaction_id'],
                        'context_id' => $context->id,
                    ]);

                    return (int) $entities['last_transaction_id'];
                }
            }

            Log::info('No last transaction ID found in context', [
                'tenant_id' => $this->tenantId,
                'contexts_checked' => $contexts->count(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::warning('Error getting last transaction ID', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get recent context entries
     */
    public function getContext(int $limit = 5): array
    {
        return $this->getBaseQuery()
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::CONTEXT_EXPIRY_MINUTES))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->toArray();
    }

    /**
     * Get last context entry
     */
    public function getLastContext(): ?array
    {
        $context = $this->getBaseQuery()
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::CONTEXT_EXPIRY_MINUTES))
            ->orderBy('created_at', 'desc')
            ->first();

        return $context ? $context->toArray() : null;
    }

    /**
     * Get specific entity from recent context
     * Useful for follow-up questions
     */
    public function getEntityFromContext(string $entityType): mixed
    {
        $contexts = $this->getContext(5);

        foreach (array_reverse($contexts) as $ctx) {
            $entities = $ctx['entities'] ?? [];
            if (isset($entities[$entityType]) && ! empty($entities[$entityType])) {
                return $entities[$entityType];
            }
        }

        return null;
    }

    /**
     * Get last mentioned category from context
     */
    public function getLastCategory(): ?string
    {
        return $this->getEntityFromContext('category');
    }

    /**
     * Get last mentioned time period from context
     */
    public function getLastTimePeriod(): ?array
    {
        return $this->getEntityFromContext('time_period');
    }

    /**
     * Get last intent from context
     */
    public function getLastIntent(): ?string
    {
        $last = $this->getLastContext();

        return $last['intent'] ?? null;
    }

    /**
     * Check if user is asking a follow-up question
     */
    public function isFollowUpQuestion(string $message): bool
    {
        $messageLower = strtolower(trim($message));
        $messageLen = mb_strlen($message);

        $followUpIndicators = [
            // Waktu relatif
            'minggu lalu', 'bulan lalu', 'kemarin', 'tadi', 'yang lalu',
            'tahun lalu', 'bulan kemarin', '3 bulan', '2 bulan', 'semester',
            // Pertanyaan lanjutan
            'berapa', 'bagaimana', 'gimana', 'gmn', 'gmna',
            'kenapa', 'mengapa', 'kok bisa',
            // Referensi ke pesan sebelumnya
            'yang itu', 'itu tadi', 'yang tadi', 'yg tadi', 'yg itu',
            'maksudnya', 'maksud saya',
            // Kelanjutan
            'terus', 'lalu', 'selanjutnya', 'trus', 'abis itu',
            'kalau', 'kalo', 'klo',
            // Detail & klarifikasi
            'lebih detail', 'jelaskan', 'detailnya', 'rinciannya',
            'lebih lengkap', 'elaborasi', 'spesifik',
            // Perbandingan dari konteks sebelumnya
            'dibanding', 'dibandingkan', 'perbandingan', 'vs', 'versus',
            // Kategori dari konteks sebelumnya
            'yang transport', 'yang makan', 'yang belanja', 'yang hiburan',
            'yang lainnya', 'yang lain', 'selain itu',
            'kategori lain', 'yang kategori',
        ];

        // Pesan pendek (<60 karakter) dengan indicator -> follow-up
        if ($messageLen < 60) {
            foreach ($followUpIndicators as $indicator) {
                if (str_contains($messageLower, $indicator)) {
                    return true;
                }
            }
        }

        // Pesan sangat pendek (<15 karakter) yang dimulai "?" atau hanya kata tunggal
        // misal "transport?", "makanan?", "kemarin?"
        if ($messageLen < 15 && str_contains($messageLower, '?')) {
            return true;
        }

        // Pola "kalau X gimana/berapa" meskipun >60 karakter
        if (preg_match('/^(kalau|kalo|klo|gmn|gimana|bagaimana)\b/i', $messageLower)) {
            return true;
        }

        // Pola "yang + kategori" meskipun >60 karakter
        if (preg_match('/^(yang|yg)\s+(transport|makan|belanja|hiburan|tagihan|kesehatan|pendidikan|lain)/i', $messageLower)) {
            return true;
        }

        return false;
    }

    /**
     * Build context string for AI
     */
    public function buildContextForAI(): string
    {
        $contexts = $this->getContext(3);

        if (empty($contexts)) {
            return '';
        }

        $contextStr = "Konteks percakapan sebelumnya:\n";

        foreach ($contexts as $ctx) {
            $intent = $ctx['intent'] ?? 'unknown';
            $msg = $ctx['message'] ?? '';
            $entities = $ctx['entities'] ?? [];

            $contextStr .= "- User: \"{$msg}\" (intent: {$intent}";

            if (! empty($entities)) {
                $entityParts = [];
                foreach ($entities as $key => $value) {
                    if (is_string($value)) {
                        $entityParts[] = "{$key}: {$value}";
                    }
                }
                if (! empty($entityParts)) {
                    $contextStr .= ', '.implode(', ', $entityParts);
                }
            }

            $contextStr .= ")\n";
        }

        return $contextStr;
    }

    /**
     * Store pending transaction description (when user sends keyword without amount)
     * e.g., "naik ojek" -> stores for later when user sends "15000"
     */
    public function storePendingTransaction(string $description, string $keyword, string $type = 'expense'): void
    {
        try {
            $lastContext = $this->getBaseQuery()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastContext) {
                $entities = $lastContext->entities ?? [];
                $entities['pending_transaction'] = [
                    'description' => $description,
                    'keyword' => $keyword,
                    'type' => $type,
                    'created_at' => now()->toIso8601String(),
                ];
                $lastContext->update(['entities' => $entities]);

                Log::info('Stored pending transaction in context', [
                    'tenant_id' => $this->tenantId,
                    'description' => $description,
                    'keyword' => $keyword,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to store pending transaction', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get pending transaction from context (if exists and not expired)
     * Returns null if no pending transaction or if it's older than 5 minutes
     */
    public function getPendingTransaction(): ?array
    {
        try {
            $contexts = $this->getBaseQuery()
                ->where('created_at', '>=', Carbon::now()->subMinutes(5)) // 5 min expiry for pending tx
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            foreach ($contexts as $context) {
                $entities = $context->entities ?? [];
                if (isset($entities['pending_transaction'])) {
                    $pending = $entities['pending_transaction'];

                    // Check if not too old (5 minutes)
                    $createdAt = Carbon::parse($pending['created_at'] ?? now());
                    if ($createdAt->diffInMinutes(now()) <= 5) {
                        Log::info('Found pending transaction in context', [
                            'tenant_id' => $this->tenantId,
                            'pending' => $pending,
                        ]);

                        return $pending;
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Error getting pending transaction', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clear pending transaction after it's been processed
     */
    public function clearPendingTransaction(): void
    {
        try {
            $contexts = $this->getBaseQuery()
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            foreach ($contexts as $context) {
                $entities = $context->entities ?? [];
                if (isset($entities['pending_transaction'])) {
                    unset($entities['pending_transaction']);
                    $context->update(['entities' => $entities]);

                    Log::info('Cleared pending transaction from context', [
                        'tenant_id' => $this->tenantId,
                        'context_id' => $context->id,
                    ]);
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to clear pending transaction', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all context for tenant
     */
    public function clearContext(): void
    {
        $this->getBaseQuery()->delete();
    }

    /**
     * Remove expired contexts
     */
    protected function cleanOldContexts(): void
    {
        $this->getBaseQuery()
            ->where('created_at', '<', Carbon::now()->subMinutes(self::CONTEXT_EXPIRY_MINUTES))
            ->delete();
    }

    /**
     * Keep only last N contexts
     */
    protected function pruneContexts(): void
    {
        $count = $this->getBaseQuery()->count();

        if ($count > self::MAX_CONTEXT_ENTRIES) {
            $toDelete = $count - self::MAX_CONTEXT_ENTRIES;

            $this->getBaseQuery()
                ->orderBy('created_at', 'asc')
                ->limit($toDelete)
                ->delete();
        }
    }
}
