<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MessageReplyService
{
    protected Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    /**
     * Send a reply message to the user
     */
    public function sendReply(string $text, ?string $replyToMessageId = null): void
    {
        try {
            $channel = Channel::find($this->message->channel_id);

            if (! $channel) {
                Log::error('Channel not found for reply', [
                    'message_id' => $this->message->id,
                    'channel_id' => $this->message->channel_id,
                ]);

                return;
            }

            // Telegram channel: kirim balasan via Telegram Bot API
            if ($this->isTelegramChannel($channel)) {
                $this->sendTelegramText($text);

                return;
            }

            $sessionId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";

            // Get recipient - use sender_id for reply
            $recipient = $this->message->sender_id;

            // Check for originalLid in metadata for LID routing
            $metadata = is_array($this->message->metadata)
                ? $this->message->metadata
                : json_decode($this->message->metadata ?? '{}', true);
            $originalLid = $metadata['original_sender_id'] ?? null;
            $replyToMessageId = $replyToMessageId ?? $this->message->message_id;

            // Send via WhatsApp service (simulateTyping default true sudah cukup untuk auto-reply interaktif)
            $whatsappService = app(WhatsAppService::class);
            $whatsappService->sendMessage($sessionId, $recipient, $text, 'text', $originalLid, true, null, $replyToMessageId);

            // Mark that a reply was sent to this sender (used to suppress
            // duplicate empty-body LID notifications in ProcessIncomingMessage)
            $senderDigits = preg_replace('/\D/', '', $recipient);
            $replyMarkerKey = "wa_reply_sent:{$this->message->tenant_id}:{$senderDigits}";
            Cache::put($replyMarkerKey, true, now()->addSeconds(30));

        } catch (\Exception $e) {
            Log::error('Failed to send reply', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a document/file to the user
     */
    public function sendDocument(string $filePath, string $caption = '', ?string $filename = null): void
    {
        try {
            $channel = Channel::find($this->message->channel_id);

            if (! $channel) {
                Log::error('Channel not found for document send', [
                    'message_id' => $this->message->id,
                ]);

                return;
            }

            // Telegram channel: kirim dokumen via Telegram Bot API
            if ($this->isTelegramChannel($channel)) {
                $chatId = $this->telegramChatId();
                if ($chatId !== null) {
                    app(\App\Services\TelegramService::class)
                        ->sendDocument($chatId, $filePath, $caption, $filename);
                }

                return;
            }

            $sessionId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
            $recipient = $this->message->sender_id;

            // Check for originalLid in metadata
            $metadata = is_array($this->message->metadata)
                ? $this->message->metadata
                : json_decode($this->message->metadata ?? '{}', true);
            $originalLid = $metadata['original_sender_id'] ?? null;

            $whatsappService = app(WhatsAppService::class);
            $whatsappService->sendDocument($sessionId, $recipient, $filePath, $caption, $filename, $originalLid);

        } catch (\Exception $e) {
            Log::error('Failed to send document', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send an image to the user
     */
    public function sendImage(string $imagePath, string $caption = ''): void
    {
        try {
            $channel = Channel::find($this->message->channel_id);

            if (! $channel) {
                Log::error('Channel not found for image send', [
                    'message_id' => $this->message->id,
                ]);

                return;
            }

            // Telegram channel: kirim gambar via Telegram Bot API
            if ($this->isTelegramChannel($channel)) {
                $chatId = $this->telegramChatId();
                if ($chatId !== null) {
                    app(\App\Services\TelegramService::class)
                        ->sendPhoto($chatId, $imagePath, $caption);
                }

                return;
            }

            $sessionId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
            $recipient = $this->message->sender_id;

            $metadata = is_array($this->message->metadata)
                ? $this->message->metadata
                : json_decode($this->message->metadata ?? '{}', true);
            $originalLid = $metadata['original_sender_id'] ?? null;

            $whatsappService = app(WhatsAppService::class);
            $whatsappService->sendImage($sessionId, $recipient, $imagePath, $caption, $originalLid);

        } catch (\Exception $e) {
            Log::error('Failed to send image', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apakah channel pesan ini bertipe Telegram?
     */
    protected function isTelegramChannel(Channel $channel): bool
    {
        return $channel->type === 'telegram' || $this->message->channel === 'telegram';
    }

    /**
     * Ambil chat_id Telegram untuk balasan.
     * Pada chat privat, sender_id (Telegram user id) == chat id.
     * Untuk lebih aman, coba ambil dari raw_data terlebih dahulu.
     */
    protected function telegramChatId(): int|string|null
    {
        $raw = is_array($this->message->raw_data)
            ? $this->message->raw_data
            : json_decode($this->message->raw_data ?? '{}', true);

        $chatId = $raw['chat']['id'] ?? $this->message->sender_id;

        return $chatId !== null && $chatId !== '' ? $chatId : null;
    }

    /**
     * Kirim balasan teks ke Telegram.
     * Menggunakan Markdown agar format *tebal* dari template WhatsApp tetap rapi.
     * Jika Markdown gagal (mis. karakter tidak seimbang), fallback ke teks polos.
     */
    protected function sendTelegramText(string $text): void
    {
        $chatId = $this->telegramChatId();
        if ($chatId === null) {
            Log::error('Telegram reply: chat_id tidak ditemukan', [
                'message_id' => $this->message->id,
            ]);

            return;
        }

        $telegram = app(\App\Services\TelegramService::class);

        $result = $telegram->sendMessage($chatId, $text, ['parse_mode' => 'Markdown']);

        // Fallback: jika Markdown ditolak (karakter tidak seimbang), kirim teks polos
        if (! ($result['success'] ?? false)) {
            $telegram->sendMessage($chatId, $text, ['parse_mode' => '']);
        }
    }
}
