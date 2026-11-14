<?php

namespace App;

use App\Models\Channel;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class ChannelServiceResolverService implements ChannelServiceInterface
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Resolve the correct channel service based on channel type
     */
    protected function resolveService(string $channelType): ChannelServiceInterface
    {
        return match ($channelType) {
            'whatsapp' => $this->app->make(WhatsAppService::class),
            'telegram' => $this->app->make(TelegramService::class),
            default => throw new \InvalidArgumentException("Unsupported channel type: {$channelType}"),
        };
    }

    /**
     * Send a text message via the channel
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        // For direct chat_id, we need to determine the channel type
        // This is a simplified version - in production, you'd look up the channel first
        return $this->app->make(TelegramService::class)->sendMessage($chatId, $text, $options);
    }

    /**
     * Send a photo/image message
     */
    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $options = []): array
    {
        return $this->app->make(TelegramService::class)->sendPhoto($chatId, $photo, $caption, $options);
    }

    /**
     * Send a document/file message
     */
    public function sendDocument(int|string $chatId, string $document, string $caption = '', ?string $filename = null): array
    {
        return $this->app->make(TelegramService::class)->sendDocument($chatId, $document, $caption, $filename);
    }

    /**
     * Send notification to a user
     */
    public function sendNotification(int $userId, int $tenantId, string $message, array $options = []): array
    {
        // Try Telegram first (primary channel), then WhatsApp as fallback
        $telegramChatId = $this->getChatId($userId, $tenantId);
        
        if ($telegramChatId) {
            $result = $this->app->make(TelegramService::class)->sendMessage($telegramChatId, $message, $options);
            if ($result['success'] ?? false) {
                return $result;
            }
        }

        // Fallback to WhatsApp
        Log::info('Telegram notification failed, trying WhatsApp fallback', [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ]);

        // Implement WhatsApp fallback logic here
        return [
            'success' => false,
            'error' => 'No active channel connection found',
        ];
    }

    /**
     * Send bulk notifications
     */
    public function sendBulkNotifications(array $targets, string $message, array $options = []): array
    {
        $results = [];
        
        foreach ($targets as $target) {
            $userId = $target['user_id'] ?? null;
            $tenantId = $target['tenant_id'] ?? 1;
            $chatId = $target['chat_id'] ?? $this->getChatId($userId, $tenantId);
            
            if ($chatId) {
                $results[] = $this->sendMessage($chatId, $message, $options);
            }
        }

        return [
            'total' => count($targets),
            'success' => count(array_filter($results, fn($r) => $r['success'] ?? false)),
            'failed' => count(array_filter($results, fn($r) => !($r['success'] ?? false))),
            'results' => $results,
        ];
    }

    /**
     * Get user chat ID by user ID and tenant
     */
    public function getChatId(int $userId, int $tenantId): ?string
    {
        $mapping = \App\Models\UserTelegramMapping::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        return $mapping?->telegram_chat_id;
    }

    /**
     * Check if user has active channel connection
     */
    public function hasActiveConnection(int $userId, int $tenantId): bool
    {
        return \App\Models\UserTelegramMapping::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Handle incoming webhook
     */
    public function handleWebhook(array $payload): array
    {
        $channelType = $payload['channel'] ?? 'telegram';
        $service = $this->resolveService($channelType);
        return $service->handleWebhook($payload);
    }

    /**
     * Get channel type identifier
     */
    public function getChannelType(): string
    {
        return 'telegram';
    }

    /**
     * Get webhook URL for this channel
     */
    public function getWebhookUrl(): string
    {
        return config('services.telegram.webhook_url') ?: '';
    }
}
