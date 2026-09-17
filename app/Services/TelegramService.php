<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Laravel\Pulse\Facades\Pulse;

class TelegramService
{
    protected string $botToken;
    protected string $apiUrl;

    /**
     * Timestamp pesan terakhir per chat_id (epoch microsecond).
     * Dipakai untuk throttle agar tidak melebihi rate limit Telegram (30 msg/sec).
     *
     * @var array<string, float>
     */
    protected array $lastSendAt = [];

    /**
     * Minimum jeda antar pesan per chat (detik).
     * Telegram rate limit: 30 msg/sec, kita gunakan 0.05s (20 msg/sec) untuk safety.
     */
    protected const THROTTLE_MIN_INTERVAL = 0.05;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        
        if (empty($this->botToken)) {
            throw new \RuntimeException(
                "TELEGRAM_BOT_TOKEN tidak dikonfigurasi. " .
                "Pastikan .env berisi TELEGRAM_BOT_TOKEN dan jalankan 'php artisan config:clear'"
            );
        }
        
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    // ===== SET WEBHOOK METHODS =====

    public function setWebhook(string $url, ?string $secretToken = null, int $maxConnections = 100, bool $dropPendingUpdates = false): array
    {
        $payload = [
            'url' => $url,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            'max_connections' => $maxConnections,
        ];

        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        // Buang antrean update lama/basi (mis. update yang sempat gagal saat webhook diblokir).
        // Mencegah Telegram me-retry update dengan token yang sudah kedaluwarsa/tertimpa.
        if ($dropPendingUpdates) {
            $payload['drop_pending_updates'] = true;
        }

        return $this->post('setWebhook', $payload);
    }

    public function deleteWebhook(): array
    {
        return $this->post('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->post('getWebhookInfo');
    }

    public function getMe(): array
    {
        return $this->post('getMe');
    }

    // ===== SEND MESSAGE METHODS =====

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $this->throttle($chatId);

        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $options);

        $start = microtime(true);
        $result = $this->post('sendMessage', $payload);
        $duration = (microtime(true) - $start) * 1000;

        $this->recordMetrics('send_message', $result['success'], $duration);

        return $result;
    }

    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $options = []): array
    {
        $this->throttle($chatId);

        $payload = array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ], $options);

        $start = microtime(true);
        $result = $this->postMultipart('sendPhoto', $payload);
        $duration = (microtime(true) - $start) * 1000;

        $this->recordMetrics('send_photo', $result['success'], $duration);

        return $result;
    }

    public function sendDocument(int|string $chatId, string $document, string $caption = '', ?string $filename = null): array
    {
        $this->throttle($chatId);

        $payload = [
            'chat_id' => $chatId,
            'document' => $document,
            'caption' => $caption,
        ];

        if ($filename) {
            $payload['filename'] = $filename;
        }

        $start = microtime(true);
        $result = $this->postMultipart('sendDocument', $payload);
        $duration = (microtime(true) - $start) * 1000;

        $this->recordMetrics('send_document', $result['success'], $duration);

        return $result;
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): array
    {
        return $this->post('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    /**
     * Get file info from Telegram (returns file_path to construct download URL).
     * Usage: $result = $telegram->getFile($fileId);
     * Download URL: "https://api.telegram.org/file/bot{$botToken}/{$result['file_path']}"
     */
    public function getFile(string $fileId): array
    {
        return $this->post('getFile', ['file_id' => $fileId]);
    }

    // ===== KEYBOARD & INTERACTION =====

    public function sendMessageWithKeyboard(
        int|string $chatId,
        string $text,
        array $keyboard,
        bool $inline = true
    ): array {
        $replyMarkup = $inline
            ? ['inline_keyboard' => $keyboard]
            : ['keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => true];

        return $this->sendMessage($chatId, $text, ['reply_markup' => json_encode($replyMarkup)]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array
    {
        return $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    // ===== USER MANAGEMENT =====

    public function getChat(int|string $chatId): array
    {
        return $this->post('getChat', ['chat_id' => $chatId]);
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->post('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    // ===== INTERNAL HELPERS =====

    protected function post(string $method, array $data = []): array
    {
        try {
            $response = Http::timeout(30)
                ->post("{$this->apiUrl}/{$method}", $data);

            $result = $response->json();

            if (!$response->successful()) {
                Log::warning("Telegram API error: {$method}", [
                    'status' => $response->status(),
                    'error' => $result['description'] ?? 'Unknown',
                    'parameters' => $data,
                ]);
            }

            return ['success' => $response->successful(), 'data' => $result];
        } catch (\Exception $e) {
            Log::error("Telegram API exception: {$method}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function postMultipart(string $method, array $data): array
    {
        try {
            $response = Http::timeout(60);

            // Handle file attachment (file_id, URL, atau file path)
            if (isset($data['photo']) && !str_starts_with($data['photo'], 'https://') && !str_starts_with($data['photo'], 'file_id=')) {
                $response = $response->attach('photo', file_get_contents($data['photo']), basename($data['photo']));
            }

            if (isset($data['document']) && !str_starts_with($data['document'], 'https://') && !str_starts_with($data['document'], 'file_id=')) {
                $filename = $data['filename'] ?? basename($data['document']);
                $response = $response->attach('document', file_get_contents($data['document']), $filename);
            }

            $response = $response->post("{$this->apiUrl}/{$method}", $data);

            return ['success' => $response->successful(), 'data' => $response->json()];
        } catch (\Exception $e) {
            Log::error("Telegram multipart error: {$method}", [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function throttle(int|string $chatId): void
    {
        $key = "telegram_throttle:{$chatId}";
        $lastSent = Cache::get($key, 0);
        $elapsed = microtime(true) - $lastSent;
        $minInterval = self::THROTTLE_MIN_INTERVAL;

        if ($elapsed < $minInterval) {
            usleep(($minInterval - $elapsed) * 1_000_000);
        }
        Cache::put($key, microtime(true), 60);
    }

    /**
     * Record metrics for Telegram operations using Laravel Pulse.
     */
    protected function recordMetrics(string $operation, bool $success, float $durationMs): void
    {
        if (! class_exists(Pulse::class)) {
            return;
        }

        // Increment message count
        Pulse::counter('telegram_messages', ['operation' => $operation, 'success' => $success ? '1' : '0'])->increment();

        // Record duration histogram
        Pulse::gauge('telegram_duration_ms', ['operation' => $operation])->record($durationMs);
    }
}
