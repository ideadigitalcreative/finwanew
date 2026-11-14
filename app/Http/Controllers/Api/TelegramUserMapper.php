<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTelegramMapping;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TelegramUserMapper extends Controller
{
    protected TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Handle user first contact dengan bot.
     * Ini adalah "linking" point antara user Laravel dan Telegram chat.
     * 
     * @param int|string $chatId Telegram chat ID
     * @return array
     */
    public function linkUserFromTelegram(int|string $chatId): array
    {
        // Cek apakah chat_id sudah terdaftar
        $existingMapping = UserTelegramMapping::findByTelegramChatId($chatId);
        
        if ($existingMapping && $existingMapping->is_active) {
            return [
                'status' => 'already_linked',
                'message' => 'Akun Telegram ini sudah terhubung dengan user.',
                'user_id' => $existingMapping->user_id,
                'user_name' => $existingMapping->user->name ?? null,
            ];
        }

        // Cari user yang belum punya telegram_chat_id
        $unlinkedUser = User::whereNull('telegram_chat_id')
            ->where('tenant_id', $existingMapping->tenant_id ?? 1)
            ->first();

        if (!$unlinkedUser) {
            return [
                'status' => 'no_unlinked_user',
                'message' => 'Tidak ada user yang tersedia untuk di-link.',
            ];
        }

        // Link user ke Telegram
        $mapping = UserTelegramMapping::linkTelegramToUser(
            userId: $unlinkedUser->id,
            tenantId: $unlinkedUser->tenant_id,
            telegramChatId: $chatId,
            username: null,
            firstName: $unlinkedUser->name,
            lastName: ''
        );

        // Update user dengan telegram_chat_id
        $unlinkedUser->update([
            'telegram_chat_id' => $chatId,
            'telegram_username' => null,
        ]);

        return [
            'status' => 'linked',
            'message' => 'Akun Telegram berhasil di-link dengan user.',
            'user_id' => $unlinkedUser->id,
            'user_name' => $unlinkedUser->name,
            'telegram_chat_id' => $chatId,
        ];
    }

    /**
     * Get user by Telegram chat ID
     */
    public function getUserByTelegramChatId(int|string $chatId): ?User
    {
        $mapping = UserTelegramMapping::findByTelegramChatId($chatId);
        
        if (!$mapping || !$mapping->is_active) {
            return null;
        }

        return $mapping->user;
    }

    /**
     * Send notification ke user Telegram
     */
    public function sendNotificationToUser(int $userId, string $message, array $options = []): array
    {
        $user = User::find($userId);
        
        if (!$user || !$user->telegram_chat_id) {
            return [
                'success' => false,
                'error' => 'User tidak punya Telegram chat_id',
            ];
        }

        $result = $this->telegram->sendMessage(
            chatId: $user->telegram_chat_id,
            text: $message,
            options: $options
        );

        return $result;
    }

    /**
     * Send notification to all active Telegram users
     */
    public function sendBroadcastToTelegramUsers(string $message, int $tenantId = null): array
    {
        $mappings = UserTelegramMapping::getActiveMappingsForTenant($tenantId ?? 1);
        
        $results = [];
        foreach ($mappings as $mapping) {
            $result = $this->telegram->sendMessage(
                chatId: $mapping->telegram_chat_id,
                text: $message
            );
            $results[] = [
                'user_id' => $mapping->user_id,
                'chat_id' => $mapping->telegram_chat_id,
                'success' => $result['success'],
            ];
        }

        return [
            'total_sent' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Generate Telegram linking token untuk user
     * Returns deep link URL yang bisa dibagikan ke user
     */
    public function generateLinkToken(): array
    {
        $user = auth()->user();

        if (!$user) {
            return [
                'success' => false,
                'error' => 'Unauthorized',
            ];
        }

        // Invalidate existing token if it's still valid
        if ($user->hasValidTelegramLinkToken()) {
            $user->invalidateTelegramLinkToken();
        }

        // Generate new token
        $token = $user->generateTelegramLinkToken();

        // Get bot username from config
        $botUsername = config('services.telegram.bot_username', 'YourBot');

        // Build deep link URL — telegram.me lebih andal jika t.me diblokir DNS
        $deepLinkUrl = "https://telegram.me/{$botUsername}?start=link_{$token}";

        return [
            'success' => true,
            'message' => 'Telegram linking token generated',
            'data' => [
                'token' => $token, // Return plain token for the URL
                'deep_link_url' => $deepLinkUrl,
                'expires_at' => now()->addHour()->toISOString(),
            ],
        ];
    }

    /**
     * Check if current user has valid Telegram link token
     */
    public function checkLinkTokenStatus(): array
    {
        $user = auth()->user();

        if (!$user) {
            return [
                'success' => false,
                'error' => 'Unauthorized',
            ];
        }

        if ($user->hasValidTelegramLinkToken()) {
            return [
                'success' => true,
                'has_valid_token' => true,
                'expires_at' => $user->telegram_link_token_created_at->addHour()->toISOString(),
            ];
        }

        return [
            'success' => true,
            'has_valid_token' => false,
        ];
    }
}
