<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Channel;
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
            
            if (!$channel) {
                Log::error('Channel not found for reply', [
                    'message_id' => $this->message->id,
                    'channel_id' => $this->message->channel_id
                ]);
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

            // Send via WhatsApp service
            $whatsappService = app(WhatsAppService::class);
            $whatsappService->sendMessage($sessionId, $recipient, $text, 'text', $originalLid);

        } catch (\Exception $e) {
            Log::error('Failed to send reply', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
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
            
            if (!$channel) {
                Log::error('Channel not found for document send', [
                    'message_id' => $this->message->id
                ]);
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
                'error' => $e->getMessage()
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
            
            if (!$channel) {
                Log::error('Channel not found for image send', [
                    'message_id' => $this->message->id
                ]);
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
                'error' => $e->getMessage()
            ]);
        }
    }
}
