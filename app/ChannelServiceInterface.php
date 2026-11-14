<?php

namespace App;

use App\Models\Message;
use Illuminate\Support\Collection;

interface ChannelServiceInterface
{
    /**
     * Send a text message via the channel
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array;

    /**
     * Send a photo/image message
     */
    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $options = []): array;

    /**
     * Send a document/file message
     */
    public function sendDocument(int|string $chatId, string $document, string $caption = '', ?string $filename = null): array;

    /**
     * Send notification to a user
     */
    public function sendNotification(int $userId, int $tenantId, string $message, array $options = []): array;

    /**
     * Send bulk notifications
     */
    public function sendBulkNotifications(array $targets, string $message, array $options = []): array;

    /**
     * Get user chat ID by user ID and tenant
     */
    public function getChatId(int $userId, int $tenantId): ?string;

    /**
     * Check if user has active channel connection
     */
    public function hasActiveConnection(int $userId, int $tenantId): bool;

    /**
     * Handle incoming webhook
     */
    public function handleWebhook(array $payload): array;

    /**
     * Get channel type identifier
     */
    public function getChannelType(): string;

    /**
     * Get webhook URL for this channel
     */
    public function getWebhookUrl(): string;
}
