<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use App\Jobs\ProcessIncomingMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk handle incoming messages dari wa-blast engine
 * dan forward ke core-api sesuai standar payload
 */
class WhatsAppWebhookService
{
    protected $coreApiUrl;
    protected $apiKey;

    public function __construct()
    {
        // Use APP_URL instead of core_api_url for webhook callback
        $this->coreApiUrl = config('app.url', config('services.whatsapp.core_api_url', 'http://localhost:8000'));
        $this->apiKey = config('services.whatsapp.api_key');
    }

    /**
     * Process incoming message from wa-blast and forward to core-api
     * 
     * @param array $waMessageData Message data dari wa-blast engine
     * @param string $sessionId Session ID dari wa-blast
     * @return array
     */
    public function processIncomingMessage(array $waMessageData, string $sessionId): array
    {
        try {
            // Extract tenant_id dari session_id (format: wa_{tenantId}_{channelAccount})
            $sessionParts = explode('_', $sessionId);
            if (count($sessionParts) < 3 || $sessionParts[0] !== 'wa') {
                Log::warning('Invalid session ID format', ['session_id' => $sessionId]);
                return ['success' => false, 'error' => 'Invalid session ID format'];
            }

            $tenantId = (int) $sessionParts[1];
            $channelAccount = $sessionParts[2];

            // Get channel to verify tenant
            $channel = Channel::where('tenant_id', $tenantId)
                ->where('type', 'whatsapp')
                ->where('channel_account', $channelAccount)
                ->first();

            if (!$channel) {
                Log::warning('Channel not found for session', [
                    'session_id' => $sessionId,
                    'tenant_id' => $tenantId,
                    'channel_account' => $channelAccount
                ]);
                return ['success' => false, 'error' => 'Channel not found'];
            }

            // Check if this is a shared channel
            // If yes, route based on sender_id (nomor pengirim)
            // If no, use tenant_id from session (existing behavior)
            $mappingService = new WhatsAppUserMappingService();
            $senderId = $waMessageData['from'] ?? null;
            
            // Skip system notifications (e2e_notification, protocol messages)
            $messageType = $waMessageData['type'] ?? 'chat';
            if (in_array($messageType, ['e2e_notification', 'notification_template', 'protocol'])) {
                Log::debug('Skipping system notification message', [
                    'session_id' => $sessionId,
                    'type' => $messageType,
                    'from' => $senderId
                ]);
                return ['success' => true, 'skipped' => true, 'reason' => 'system_notification'];
            }
            
            $senderId = $waMessageData['from'] ?? null;

            // Log sender info for debugging LID format
            Log::info('Processing incoming message', [
                'session_id' => $sessionId,
                'sender_id' => $senderId,
                'sender_type' => gettype($senderId),
                'session_tenant_id' => $tenantId
            ]);
            
            if ($channel->is_shared_channel && $senderId && is_string($senderId)) {
                // Check if sender is using LID format (WhatsApp Linked ID)
                // LID format: XXXXXXXXXXX@lid - this is NOT a phone number, it's an internal ID
                
                // Safer extraction for originalFrom
                $originalFrom = null;
                if (isset($waMessageData['originalFrom'])) {
                    $originalFrom = $waMessageData['originalFrom'];
                } elseif (isset($waMessageData['raw_data']['originalFrom'])) {
                    $originalFrom = $waMessageData['raw_data']['originalFrom'];
                }

                $isLidFormat = str_contains($senderId, '@lid') || ($originalFrom && is_string($originalFrom) && str_contains($originalFrom, '@lid'));
                
                // Clean the sender ID for better matching
                $cleanedSenderId = $mappingService->cleanPhoneNumber($senderId);
                
                Log::info('Shared channel - attempting to find tenant', [
                    'sender_id' => $senderId,
                    'original_from' => $originalFrom,
                    'cleaned_sender_id' => $cleanedSenderId,
                    'is_lid_format' => $isLidFormat
                ]);
                
                // Shared channel: validate and get tenant from sender's number
                // Single check: getTenantIdFromWhatsAppNumber returns null if not registered
                $actualTenantId = $mappingService->getTenantIdFromWhatsAppNumber($senderId, $tenantId);
                
                // If null, number is not registered
                if ($actualTenantId === null) {
                    // Special handling for LID format
                    // LID (Linked ID) is WhatsApp's internal format that can't be mapped to phone numbers
                    // For LID format, fall back to using the channel's tenant_id instead of rejecting
                    if ($isLidFormat) {
                        Log::info('LID format detected - falling back to channel tenant_id', [
                            'session_id' => $sessionId,
                            'channel_id' => $channel->id,
                            'sender_id' => $senderId,
                            'fallback_tenant_id' => $tenantId
                        ]);
                        // Use the channel's tenant_id (from session) for LID format
                        $actualTenantId = $tenantId;
                    } else {
                        // Normal phone number not registered - send unregistered reply
                        $this->sendUnregisteredNumberReply($channel, $senderId, $sessionId, $originalFrom);
                        
                        Log::warning('Message rejected - WhatsApp number not registered', [
                            'session_id' => $sessionId,
                            'channel_id' => $channel->id,
                            'sender_id' => $senderId,
                            'original_from' => $originalFrom,
                            'cleaned_sender_id' => $cleanedSenderId,
                            'is_lid_format' => $isLidFormat
                        ]);

                        
                        return [
                            'success' => false,
                            'error' => 'WhatsApp number not registered',
                            'rejected' => true
                        ];
                    }
                }
                
                Log::info('Shared channel detected - routing by sender_id', [
                    'session_id' => $sessionId,
                    'channel_id' => $channel->id,
                    'sender_id' => $senderId,
                    'cleaned_sender_id' => $cleanedSenderId,
                    'original_tenant_id' => $tenantId,
                    'routed_tenant_id' => $actualTenantId,
                    'was_lid_fallback' => $isLidFormat && $actualTenantId === $tenantId
                ]);
                
                $tenantId = $actualTenantId;
            }

            // Check subscription status for this tenant
            $subscriptionValid = $this->checkSubscriptionStatus($tenantId);
            if (!$subscriptionValid) {
                // Subscription expired - send notification and reject message
                $senderId = $waMessageData['from'] ?? null;
                // Get originalFrom - this is the original LID if sender used LID format
                // Gateway may have already resolved LID to @c.us, but we need the original
                $originalFrom = $waMessageData['originalFrom'] ?? $waMessageData['raw_data']['originalFrom'] ?? null;
                
                if ($senderId) {
                    $this->sendExpiredSubscriptionReply($channel, $senderId, $sessionId, $originalFrom);
                }
                
                Log::warning('Message rejected - Subscription expired', [
                    'session_id' => $sessionId,
                    'tenant_id' => $tenantId,
                    'sender_id' => $senderId,
                    'original_from' => $originalFrom
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Subscription expired',
                    'rejected' => true
                ];
            }


            // Store channel_id in payload
            $channelId = $channel->id;

            // Extract message data
            $messageId = $waMessageData['id']['_serialized'] ?? $waMessageData['id'] ?? null;
            $senderId = $waMessageData['from'] ?? null;
            $timestamp = isset($waMessageData['timestamp']) 
                ? (int) ($waMessageData['timestamp'] * 1000) // Convert to milliseconds
                : time() * 1000;

            // Determine message type and content
            // Map WhatsApp-web.js types to database types
            $waType = $waMessageData['type'] ?? 'chat';
            $content = $waMessageData['body'] ?? '';
            
            // Valid database types: 'text', 'image', 'audio', 'doc', 'csv'
            // WhatsApp-web.js types: 'chat', 'image', 'video', 'audio', 'document', 'ptt', 'sticker', etc.
            $type = 'text'; // Default
            
            // Check if message has media (priority check)
            if (isset($waMessageData['hasMedia']) && $waMessageData['hasMedia']) {
                // Handle media - content should be URL if media was uploaded
                if (isset($waMessageData['mimetype'])) {
                    if (str_starts_with($waMessageData['mimetype'], 'image/')) {
                        $type = 'image';
                        // If body contains URL (from media upload), use it
                        if (!empty($waMessageData['body']) && (str_starts_with($waMessageData['body'], 'http://') || str_starts_with($waMessageData['body'], 'https://'))) {
                            $content = $waMessageData['body'];
                        } else {
                            // Log warning if media URL is missing
                            Log::warning('Image message received but media URL is missing', [
                                'message_id' => $messageId,
                                'hasMedia' => true,
                                'mimetype' => $waMessageData['mimetype'],
                                'body' => substr($waMessageData['body'] ?? '', 0, 100)
                            ]);
                        }
                    } elseif (str_starts_with($waMessageData['mimetype'], 'audio/')) {
                        $type = 'audio';
                        if (!empty($waMessageData['body']) && (str_starts_with($waMessageData['body'], 'http://') || str_starts_with($waMessageData['body'], 'https://'))) {
                            $content = $waMessageData['body'];
                        }
                    } elseif (str_contains($waMessageData['mimetype'], 'pdf')) {
                        $type = 'doc';
                        if (!empty($waMessageData['body']) && (str_starts_with($waMessageData['body'], 'http://') || str_starts_with($waMessageData['body'], 'https://'))) {
                            $content = $waMessageData['body'];
                        }
                    } elseif (str_contains($waMessageData['mimetype'], 'csv') || 
                              str_contains($waMessageData['mimetype'], 'spreadsheet') ||
                              str_contains($waMessageData['mimetype'], 'excel')) {
                        $type = 'csv';
                        if (!empty($waMessageData['body']) && (str_starts_with($waMessageData['body'], 'http://') || str_starts_with($waMessageData['body'], 'https://'))) {
                            $content = $waMessageData['body'];
                        }
                    } else {
                        $type = 'doc';
                        if (!empty($waMessageData['body']) && (str_starts_with($waMessageData['body'], 'http://') || str_starts_with($waMessageData['body'], 'https://'))) {
                            $content = $waMessageData['body'];
                        }
                    }
                } else {
                    // No mimetype but hasMedia is true, try to infer from waType
                    $typeMap = [
                        'image' => 'image',
                        'video' => 'image', // Video as image for now
                        'audio' => 'audio',
                        'ptt' => 'audio', // Voice note
                        'document' => 'doc',
                        'sticker' => 'image'
                    ];
                    $type = $typeMap[$waType] ?? 'text';
                }
            } else {
                // No media, map WhatsApp types to database types
                $typeMap = [
                    'chat' => 'text',
                    'text' => 'text',
                    'image' => 'image',
                    'audio' => 'audio',
                    'document' => 'doc',
                    'video' => 'image', // Treat video as image
                    'sticker' => 'image',
                    'ptt' => 'audio'
                ];
                $type = $typeMap[$waType] ?? 'text';
            }

            // Check if we have original LID (for reply fallback)
            $originalFrom = $waMessageData['originalFrom'] ?? null;

            // Prepare payload sesuai standar
            $payload = [
                'tenant_id' => $tenantId,
                'channel_id' => $channelId,
                'channel' => 'whatsapp',
                'channel_account' => $channelAccount,
                'sender_id' => $senderId,
                'message_id' => $messageId,
                'type' => $type,
                'content' => $content,
                'timestamp' => $timestamp,
                'original_sender_id' => $originalFrom, // Store original LID for reply fallback
                'raw_data' => [
                    'from' => $senderId,
                    'originalFrom' => $originalFrom,
                    'to' => $waMessageData['to'] ?? null,
                    'isGroup' => $waMessageData['isGroup'] ?? false,
                    'isForwarded' => $waMessageData['isForwarded'] ?? false,
                    'isStarred' => $waMessageData['isStarred'] ?? false,
                    'hasMedia' => $waMessageData['hasMedia'] ?? false,
                    'mimetype' => $waMessageData['mimetype'] ?? null,
                    'location' => $waMessageData['location'] ?? null,
                    'contact' => $waMessageData['contact'] ?? null,
                    'mentions' => $waMessageData['mentionedIds'] ?? []
                ]
            ];

            // Forward to core-api webhook
            return $this->forwardToCoreApi($payload);

        } catch (\Exception $e) {
            Log::error('Error processing incoming WhatsApp message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'data' => $waMessageData
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create Message directly in database and dispatch job
     * (No need to forward to another endpoint, we're already in core-api)
     */
    protected function forwardToCoreApi(array $payload): array
    {
        try {
            // Prepare metadata for LID fallback
            $metadata = [];
            if (!empty($payload['original_sender_id'])) {
                $metadata['original_sender_id'] = $payload['original_sender_id'];
            }
            
            // Create Message directly
            $message = Message::create([
                'tenant_id' => $payload['tenant_id'],
                'channel_id' => $payload['channel_id'] ?? null,
                'channel' => $payload['channel'],
                'channel_account' => $payload['channel_account'],
                'sender_id' => $payload['sender_id'],
                'message_id' => $payload['message_id'],
                'type' => $payload['type'],
                'content' => $payload['content'],
                'status' => 'received',
                'timestamp' => $payload['timestamp'],
                'metadata' => $metadata,
                'raw_data' => $payload['raw_data'] ?? []
            ]);

            // Update channel last activity
            if ($message->channel_id) {
                $channel = Channel::find($message->channel_id);
                if ($channel) {
                    $channel->update(['last_activity_at' => now()]);
                }
            }

            // Dispatch job to database queue
            // This returns response immediately to avoid WhatsApp gateway timeout
            // Queue workers (already running) will process the job quickly
            ProcessIncomingMessage::dispatch($message)->onConnection('database');

            Log::info('Message created and job queued', [
                'message_id' => $message->id,
                'tenant_id' => $payload['tenant_id']
            ]);

            return [
                'success' => true,
                'data' => [
                    'message_id' => $message->id
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error creating message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send reply to unregistered WhatsApp number
     * Rate limited: only sends once per 5 minutes per phone number
     * 
     * @param Channel $channel
     * @param string $senderId
     * @param string $sessionId
     * @param string|null $originalFrom Original LID if gateway already resolved it
     */
    protected function sendUnregisteredNumberReply(Channel $channel, string $senderId, string $sessionId, ?string $originalFrom = null): void
    {
        try {
            $config = $channel->config ?? [];
            $channelSessionId = $config['session_id'] ?? $sessionId;
            
            // Handle LID format - WhatsApp Linked ID needs special handling
            // Check BOTH senderId AND originalFrom for LID format
            $isLidFormat = str_contains($senderId, '@lid');
            $hasOriginalLid = $originalFrom && str_contains($originalFrom, '@lid');
            
            // Use originalFrom if it contains LID (even if senderId was already resolved to @c.us)
            $targetLid = null;
            if ($hasOriginalLid) {
                $targetLid = $originalFrom;
                $isLidFormat = true;
            } elseif ($isLidFormat) {
                $targetLid = $senderId;
            }
            
            // Clean phone number - strip @c.us, @g.us, and @lid suffixes
            $phoneNumber = preg_replace('/@(c\.us|g\.us|lid)$/', '', $senderId);
            
            // Rate limiting: Check if we already sent a warning to this sender recently
            $cacheKey = "unregistered_warning:{$senderId}";
            if (Cache::has($cacheKey)) {
                Log::info('Skipping unregistered number reply - rate limited', [
                    'sender_id' => $senderId,
                    'phone_number' => $phoneNumber,
                    'is_lid' => $isLidFormat,
                    'original_from' => $originalFrom
                ]);
                return;
            }
            
            // Set cache for 5 minutes to prevent duplicate messages
            Cache::put($cacheKey, true, now()->addMinutes(5));
            
            $message = "*[PERINGATAN] Nomor WhatsApp Tidak Terdaftar*\n\n" .
                "Nomor WhatsApp Anda belum terdaftar di sistem.\n\n" .
                "*Belum punya akun?*\n" .
                "Daftar & coba GRATIS 30 hari: https://finwa.web.id/checkout?plan=free\n\n" .
                "*Sudah punya akun?*\n" .
                "1. Login ke: https://finwa.web.id/login\n" .
                "2. Buka menu WhatsApp\n" .
                "3. Daftarkan nomor WhatsApp Anda\n\n" .
                "Setelah nomor terdaftar, Anda dapat langsung menggunakan layanan ini.";
            
            $whatsappService = new WhatsAppService();
            
            // CRITICAL: When sender uses LID format, send directly to LID
            if ($isLidFormat && $targetLid) {
                Log::info('Sending unregistered reply to LID directly', [
                    'sender_id' => $senderId,
                    'sending_to' => $targetLid,
                    'original_from' => $originalFrom
                ]);
                $result = $whatsappService->sendMessageToLid($channelSessionId, $targetLid, $message);
            } else {
                // Normal phone number - clean and send
                $result = $whatsappService->sendMessage($channelSessionId, $phoneNumber, $message);
            }

            
            if ($result['success']) {
                Log::info('Unregistered number reply sent', [
                    'sender_id' => $senderId,
                    'phone_number' => $phoneNumber,
                    'is_lid' => $isLidFormat
                ]);
            } else {
                Log::warning('Failed to send unregistered number reply', [
                    'sender_id' => $senderId,
                    'is_lid' => $isLidFormat,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending unregistered number reply', [
                'sender_id' => $senderId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if tenant has active subscription
     * 
     * @param int $tenantId
     * @return bool
     */
    protected function checkSubscriptionStatus(int $tenantId): bool
    {
        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return false;
        }

        // Check for active subscription
        $hasActiveSubscription = \App\Models\Subscription::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();

        // Check if in trial period
        $isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();

        return $hasActiveSubscription || $isInTrial;
    }

    /**
     * Send reply for expired subscription
     * Rate limited: only sends once per minute per phone number to prevent spam
     * 
     * @param Channel $channel
     * @param string $senderId
     * @param string $sessionId
     * @param string|null $originalFrom Original LID if gateway already resolved it
     */
    protected function sendExpiredSubscriptionReply(Channel $channel, string $senderId, string $sessionId, ?string $originalFrom = null): void
    {
        try {
            $channelSessionId = "wa_{$channel->tenant_id}_{$channel->channel_account}";
            
            // Handle LID format - WhatsApp Linked ID needs special handling
            // Check BOTH senderId AND originalFrom for LID format
            // Gateway may have already resolved LID to @c.us in senderId, but originalFrom still has the LID
            $isLidFormat = str_contains($senderId, '@lid');
            $hasOriginalLid = $originalFrom && str_contains($originalFrom, '@lid');
            
            // Use originalFrom if it contains LID (even if senderId was already resolved to @c.us)
            $targetLid = null;
            if ($hasOriginalLid) {
                $targetLid = $originalFrom;
                $isLidFormat = true; // Override - treat as LID format
            } elseif ($isLidFormat) {
                $targetLid = $senderId;
            }
            
            // Clean phone number - strip @c.us and @lid suffixes
            $phoneNumber = preg_replace('/@(c\.us|lid)$/', '', $senderId);
            
            // Rate limiting: Check if we already sent a warning to this sender recently
            $cacheKey = "expired_subscription_reply:{$senderId}";
            if (Cache::has($cacheKey)) {
                Log::info('Skipping expired subscription reply - rate limited', [
                    'sender_id' => $senderId,
                    'phone_number' => $phoneNumber,
                    'is_lid' => $isLidFormat,
                    'original_from' => $originalFrom,
                    'tenant_id' => $channel->tenant_id
                ]);
                return;
            }

            
            // Set cache for 1 minute to prevent duplicate messages
            // Short duration so user can re-try after a minute
            Cache::put($cacheKey, true, now()->addMinute());
            
            // Get tenant details
            $tenant = \App\Models\Tenant::find($channel->tenant_id);
            
            // Get subscription details for better messaging
            $subscription = \App\Models\Subscription::where('tenant_id', $channel->tenant_id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            // Get plan name
            $planName = 'Free Trial';
            if ($subscription && $subscription->plan) {
                $planName = ucfirst($subscription->plan->name ?? 'Free Trial');
            }
            
            $message = "⚠️ *Status Akun FinWa*\n\n";
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "📊 *Detail Akun Anda:*\n";
            $message .= "🏷️ Paket: *{$planName}*\n";
            $message .= " Status: *TIDAK AKTIF*\n";
            $message .= "━━━━━━━━━━━━━━━\n\n";
            
            $message .= "Masa trial/langganan Anda telah berakhir.\n";
            $message .= "Semua fitur FinWa tidak dapat digunakan.\n\n";
            
            $message .= "🔓 *Untuk mengaktifkan kembali:*\n\n";
            $message .= "1️⃣ *Perpanjang Langganan*\n";
            $message .= "   🌐 " . config('app.url') . "/subscriptions\n\n";
            $message .= "2️⃣ *Hubungi Admin*\n";
            $message .= "   📱 WA: 6285242766676\n";
            $message .= "   📧 Email: finwa.help@gmail.com\n\n";
            
            $message .= "_Terima kasih telah menggunakan FinWa!_ 💙";

            $whatsappService = new WhatsAppService();
            
            // CRITICAL: When sender uses LID format, send directly to LID
            // The "phone number" in LID format is NOT a real phone number - it's an internal WhatsApp ID
            // Cleaning it (adding 62 prefix) would break the send
            if ($isLidFormat && $targetLid) {
                // Send directly to LID - don't clean as phone number
                Log::info('Sending expired subscription reply to LID directly', [
                    'sender_id' => $senderId,
                    'sending_to' => $targetLid,
                    'original_from' => $originalFrom,
                    'tenant_id' => $channel->tenant_id
                ]);
                $result = $whatsappService->sendMessageToLid($channelSessionId, $targetLid, $message);
            } else {
                // Normal phone number - clean and send

                $result = $whatsappService->sendMessage($channelSessionId, $phoneNumber, $message);
            }
            
            if ($result['success']) {
                Log::info('Expired subscription reply sent', [
                    'sender_id' => $senderId,
                    'phone_number' => $phoneNumber,
                    'is_lid' => $isLidFormat,
                    'original_lid' => $originalLid,
                    'tenant_id' => $channel->tenant_id,
                    'plan' => $planName,
                    'expired_date' => $expiredDate
                ]);
            } else {
                Log::warning('Failed to send expired subscription reply', [
                    'sender_id' => $senderId,
                    'is_lid' => $isLidFormat,
                    'original_lid' => $originalLid,
                    'tenant_id' => $channel->tenant_id,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending expired subscription reply', [
                'sender_id' => $senderId,
                'tenant_id' => $channel->tenant_id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get webhook URL for wa-blast engine
     * This should be called from wa-blast to setup webhook callback
     */
    public function getWebhookUrl(): string
    {
        return "{$this->coreApiUrl}/api/webhooks/whatsapp/from-engine";
    }
}

