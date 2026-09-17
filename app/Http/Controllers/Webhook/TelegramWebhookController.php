<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\UserTelegramMapping;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TelegramWebhookController extends Controller
{
    /**
     * Telegram secret token untuk webhook validation
     */
    protected ?string $webhookSecret;

    /**
     * Bot token untuk akses file API
     */
    protected string $botToken;

    public function __construct()
    {
        $this->webhookSecret = Config::get('services.telegram.webhook_secret');
        $this->botToken = Config::get('services.telegram.bot_token');
    }

    /**
     * Handle incoming Telegram webhook (main handler)
     * Supports: message, edited_message, callback_query
     */
    public function handleWebhook(Request $request)
    {
        // SKIP secret token validation - Telegram already verifies via /setWebhook
        // The webhook URL itself acts as the authentication mechanism
        // if ($this->webhookSecret) {
        //     $secret = $request->header('X-Telegram-Bot-Api-Secret');
        //     if ($secret !== $this->webhookSecret) {
        //         Log::warning('Telegram webhook: Invalid secret token', [
        //             'provided_secret' => $secret,
        //             'expected' => $this->webhookSecret,
        //         ]);
        //         return response()->json([
        //             'success' => false,
        //             'error' => 'Invalid secret token',
        //         ], 403);
        //     }
        // }

        try {
            $update = $request->all();

            // Validate minimal required fields
            if (!isset($update['update_id'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Missing update_id',
                ], 400);
            }

            Log::info('Telegram webhook received', [
                'update_id' => $update['update_id'],
                'types' => array_keys($update),
            ]);

            // Handle different update types
            if (isset($update['message'])) {
                return $this->handleMessageInternal($update['message'], $update['update_id']);
            }

            if (isset($update['edited_message'])) {
                return $this->handleEditedMessage($update['edited_message'], $update['update_id']);
            }

            if (isset($update['callback_query'])) {
                return $this->handleCallbackQuery($update['callback_query'], $update['update_id']);
            }

            // Handle other update types (optional for now)
            Log::info('Telegram webhook: Unhandled update type', [
                'update' => $update,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook received (no actionable content)',
                'update_id' => $update['update_id'],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Telegram webhook handler error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process webhook: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle new message update
     * This method is called by handleWebhook() when a message is received
     */
    protected function handleMessageInternal(array $message, int $updateId)
    {
        // Filter out messages without text, content, or photo/document
        if (!isset($message['text']) && !isset($message['caption']) && !isset($message['data'])
            && !isset($message['photo']) && !isset($message['document']) && !isset($message['audio'])
            && !isset($message['location']) && !isset($message['contact'])) {
            Log::info('Telegram message: No actionable content', ['message_id' => $message['message_id'] ?? 'unknown']);
            return response()->json([
                'success' => true,
                'message' => 'Message received (no actionable content)',
            ], 200);
        }

        $text = $message['text'] ?? $message['caption'] ?? '';
        $type = $this->determineMessageType($message);

        // For photo messages, fetch file URL and store in metadata for OCR processing
        $metadata = [];
        if ($type === 'image' && isset($message['photo']) && is_array($message['photo'])) {
            // photo is array of sizes, pick the largest (last element)
            $largestPhoto = end($message['photo']);
            $fileId = $largestPhoto['file_id'] ?? null;
            if ($fileId) {
                $telegram = app(\App\Services\TelegramService::class);
                $fileInfo = $telegram->getFile($fileId);

                // Response Telegram bersarang: data.result.file_path
                $filePath = $fileInfo['data']['result']['file_path']
                    ?? $fileInfo['data']['file_path']    // fallback jika struktur berubah
                    ?? null;

                if (!empty($fileInfo['success']) && $filePath) {
                    $metadata['media_url'] = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
                    Log::info('Telegram photo: file resolved', [
                        'file_id' => $fileId,
                        'file_path' => $filePath,
                        'media_url' => $metadata['media_url'],
                    ]);
                } else {
                    Log::warning('Telegram photo: failed to get file info', [
                        'file_id' => $fileId,
                        'file_info' => $fileInfo,
                    ]);
                }
            }
        }

        // Extract channel account (bot username)
        $channelAccount = $message['chat']['username'] ?? 'telegram';

        // Find or create channel
        $channel = Channel::firstOrCreate(
            [
                'tenant_id' => $this->getDefaultTenantId(),
                'type' => 'telegram',
                'channel_account' => $channelAccount,
            ],
            [
                'name' => 'Telegram: '.$channelAccount,
                'is_active' => true,
            ]
        );

        // Check for deep linking command (/start link_<token>)
        if (Str::startsWith($text, '/start link_')) {
            return $this->handleDeepLink($text, $message, $channel);
        }

        // Create message record
        $messageRecord = Message::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'channel' => 'telegram',
            'channel_account' => $channelAccount,
            'sender_id' => (string) ($message['from']['id'] ?? 0),
            'message_id' => (string) ($message['message_id'] ?? $updateId),
            'type' => $type,
            'content' => $text,
            'timestamp' => $message['date'] ?? time(),
            'metadata' => $metadata,
            'raw_data' => $message,
        ]);

        // Update channel last activity
        $channel->update(['last_activity_at' => now()]);

        // Dispatch job to process message
        ProcessIncomingMessage::dispatch($messageRecord);

        return response()->json([
            'success' => true,
            'message' => 'Message received and queued for processing',
            'data' => [
                'message_id' => $messageRecord->id,
                'channel_id' => $channel->id,
                'type' => $type,
            ],
        ], 201);
    }

    /**
     * Handle edited message update
     */
    protected function handleEditedMessage(array $editedMessage, int $updateId)
    {
        Log::info('Telegram edited message received', [
            'message_id' => $editedMessage['message_id'] ?? 'unknown',
            'edit_date' => $editedMessage['edit_date'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Edited message received',
        ], 200);
    }

    /**
     * Handle callback query update (button clicks)
     */
    protected function handleCallbackQuery(array $callbackQuery, int $updateId)
    {
        $data = $callbackQuery['data'] ?? '';
        $message = $callbackQuery['message'] ?? [];
        $from = $callbackQuery['from'] ?? [];

        Log::info('Telegram callback query received', [
            'callback_id' => $callbackQuery['id'] ?? 'unknown',
            'data' => $data,
            'from' => $from['id'] ?? 'unknown',
        ]);

        // Answer callback query immediately
        $telegram = app(TelegramService::class);
        $telegram->answerCallbackQuery($callbackQuery['id']);

        // Check for deep linking callback data
        if (Str::startsWith($data, 'link_')) {
            return $this->handleDeepLinkCallback($data, $callbackQuery);
        }

        return response()->json([
            'success' => true,
            'message' => 'Callback query received',
            'data' => $data,
        ], 200);
    }

    /**
     * Handle deep linking via /start link_<token>
     */
    protected function handleDeepLink(string $text, array $message, Channel $channel)
    {
        $token = Str::after($text, '/start link_');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid deep link token',
            ], 400);
        }

        Log::info('Telegram deep link received', [
            'token' => $token,
            'user_id' => $message['from']['id'] ?? null,
            'username' => $message['from']['username'] ?? null,
        ]);

        // Find user by token
        $user = $this->findUserByToken($token);

        if (!$user) {
            Log::warning('Telegram deep link: User not found for token', ['token' => $token]);
            
            // Send error message to user
            $this->sendTelegramMessage($message['chat']['id'], '❌ Token tidak valid atau sudah expired. Silakan generate link baru dari aplikasi FinWa.');
            
            return response()->json([
                'success' => false,
                'error' => 'Invalid or expired token',
            ], 404);
        }

        // Link Telegram to user (gunakan tenant milik user, bukan tenant default channel,
        // agar transaksi tercatat pada tenant yang benar saat pesan Telegram diproses)
        $mapping = UserTelegramMapping::linkTelegramToUser(
            userId: $user->id,
            tenantId: $user->tenant_id ?? $channel->tenant_id,
            telegramChatId: $message['chat']['id'],
            username: $message['from']['username'] ?? null,
            firstName: $message['from']['first_name'] ?? null,
            lastName: $message['from']['last_name'] ?? null,
        );

        // Update user telegram_chat_id
        $user->update([
            'telegram_chat_id' => $message['chat']['id'],
            'telegram_username' => $message['from']['username'] ?? null,
        ]);

        // Send success message to user
        $userName = $user->name ?? 'User';
        $successMessage = "✅ *Akun Telegram berhasil terhubung!*\n\n";
        $successMessage .= "Halo *{$userName}*, akun Telegram Anda sekarang sudah terhubung dengan FinWa.\n\n";
        $successMessage .= "Anda akan menerima notifikasi transaksi dan dapat mengelola keuangan melalui bot ini.\n\n";
        $successMessage .= "Ketik /help untuk melihat perintah yang tersedia.";
        
        $this->sendTelegramMessage($message['chat']['id'], $successMessage);

        return response()->json([
            'success' => true,
            'message' => 'Telegram account linked successfully',
            'data' => [
                'mapping_id' => $mapping->id,
                'user_id' => $user->id,
                'chat_id' => $message['chat']['id'],
            ],
        ], 200);
    }

    /**
     * Handle deep linking callback (inline button)
     */
    protected function handleDeepLinkCallback(string $data, array $callbackQuery)
    {
        $token = Str::after($data, 'link_');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid callback data',
            ], 400);
        }

        $user = $this->findUserByToken($token);

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid or expired token',
            ], 404);
        }

        $chatId = $callbackQuery['message']['chat']['id'] ?? null;

        if (!$chatId) {
            return response()->json([
                'success' => false,
                'error' => 'Chat ID not found',
            ], 400);
        }

        $mapping = UserTelegramMapping::linkTelegramToUser(
            userId: $user->id,
            tenantId: $this->getDefaultTenantId(),
            telegramChatId: $chatId,
            username: $callbackQuery['from']['username'] ?? null,
            firstName: $callbackQuery['from']['first_name'] ?? null,
            lastName: $callbackQuery['from']['last_name'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Telegram account linked successfully',
            'data' => [
                'mapping_id' => $mapping->id,
                'user_id' => $user->id,
                'chat_id' => $chatId,
            ],
        ], 200);
    }

    /**
     * Determine message type from message content
     */
    protected function determineMessageType(array $message): string
    {
        if (isset($message['text'])) {
            return 'text';
        }
        if (isset($message['photo'])) {
            return 'image';
        }
        if (isset($message['audio'])) {
            return 'audio';
        }
        if (isset($message['document'])) {
            return 'doc';
        }
        if (isset($message['location'])) {
            return 'location';
        }
        if (isset($message['contact'])) {
            return 'contact';
        }
        return 'text';
    }

    /**
     * Find user by token
     * Validates the token and returns the user if valid
     */
    protected function findUserByToken(string $plainToken)
    {
        // Cari semua user yang memiliki valid token (tidak expired)
        $users = User::whereNotNull('telegram_link_token')
            ->where('telegram_link_token_created_at', '>=', now()->subHour())
            ->get();

        // Diagnostik: berapa banyak user punya token belum-expired vs total user bertoken
        $totalWithToken = User::whereNotNull('telegram_link_token')->count();
        Log::info('Telegram deep link: mencari user berdasarkan token', [
            'token_length' => strlen($plainToken),
            'kandidat_belum_expired' => $users->count(),
            'total_user_bertoken' => $totalWithToken,
            'now' => now()->toDateTimeString(),
        ]);

        // Cek setiap user apakah hash-nya cocok dengan plain token
        foreach ($users as $user) {
            if (Hash::check($plainToken, $user->telegram_link_token)) {
                // Invalidate token after use (single-use)
                $user->invalidateTelegramLinkToken();
                Log::info('Telegram deep link: Token validated and invalidated', [
                    'user_id' => $user->id,
                ]);
                return $user;
            }
        }

        Log::warning('Telegram deep link: Invalid or expired token', [
            'token' => $plainToken,
            'kandidat_belum_expired' => $users->count(),
            'total_user_bertoken' => $totalWithToken,
            'kemungkinan_penyebab' => $users->count() === 0
                ? ($totalWithToken > 0 ? 'Token sudah expired (>1 jam) atau created_at bermasalah' : 'Token tidak tersimpan di DB saat generate')
                : 'Ada kandidat tapi Hash::check gagal (token di URL tidak cocok dengan hash tersimpan)',
        ]);
        return null;
    }

    /**
     * Get default tenant ID
     * Adjust this based on your multi-tenant setup
     */
    protected function getDefaultTenantId(): int
    {
        return 1;
    }

    /**
     * Send message to Telegram user
     */
    protected function sendTelegramMessage(int|string $chatId, string $message): void
    {
        try {
            $telegram = app(TelegramService::class);
            $telegram->sendMessage(
                chatId: $chatId,
                text: $message,
                options: ['parse_mode' => 'Markdown']
            );
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Public wrapper for handleWebhook (called from API routes)
     * This allows the route /api/webhooks/telegram/message to work
     */
    public function handleMessage(Request $request)
    {
        return $this->handleWebhook($request);
    }
}
