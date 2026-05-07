<?php

namespace App\Services;

use App\Jobs\ProcessIncomingMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
     * @param  array  $waMessageData  Message data dari wa-blast engine
     * @param  string  $sessionId  Session ID dari wa-blast
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

            if (! $channel) {
                Log::warning('Channel not found for session', [
                    'session_id' => $sessionId,
                    'tenant_id' => $tenantId,
                    'channel_account' => $channelAccount,
                ]);

                return ['success' => false, 'error' => 'Channel not found'];
            }

            // Check if this is a shared channel
            // If yes, route based on sender_id (nomor pengirim)
            // If no, use tenant_id from session (existing behavior)
            $mappingService = new WhatsAppUserMappingService;
            $senderId = $waMessageData['from'] ?? null;

            // Skip system notifications (e2e_notification, protocol messages)
            $messageType = $waMessageData['type'] ?? 'chat';
            if (in_array($messageType, ['e2e_notification', 'notification_template', 'protocol'])) {
                Log::debug('Skipping system notification message', [
                    'session_id' => $sessionId,
                    'type' => $messageType,
                    'from' => $senderId,
                ]);

                return ['success' => true, 'skipped' => true, 'reason' => 'system_notification'];
            }

            $senderId = $waMessageData['from'] ?? null;

            // Extract message_id early (from wa-blast payload)
            $messageId = $waMessageData['id']['_serialized'] ?? $waMessageData['id'] ?? null;

            // Log sender info for debugging LID format
            Log::info('Processing incoming message', [
                'session_id' => $sessionId,
                'message_id' => $messageId,
                'sender_id' => $senderId,
                'session_tenant_id' => $tenantId,
            ]);

            // Early global deduplication based on unique message ID
            if ($messageId) {
                if (! Cache::add("wa_msg_proc_lock:{$messageId}", true, now()->addMinutes(10))) {
                    Log::info('Global duplicate message skipped (ID already processing)', ['message_id' => $messageId]);

                    return ['success' => true, 'duplicate' => true, 'reason' => 'global_dedup'];
                }
            }

            // Force routing check enabled to fix issue where users are read as default tenant 1
            // This ensures Premium Users are correctly mapped to their own tenants
            if ($senderId && is_string($senderId)) {
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

                // ================================================================================
                // EARLY HIGH-PRIORITY REGISTRATION INTENT DETECTION
                // ================================================================================
                $rawBody = $waMessageData['body'] ?? '';
                $rawBodyLower = strtolower(trim($rawBody));
                $rawBodyClean = str_replace('*', '', $rawBodyLower);

                $isExplicitReg = str_contains($rawBodyClean, 'ketik daftar untuk mulai') ||
                                 str_contains($rawBodyClean, 'halo kak, saya mau daftar finwa') ||
                                 str_contains($rawBodyClean, 'daftar finwa') ||
                                 trim($rawBodyClean) === 'daftar' ||
                                 trim($rawBodyClean) === 'registrasi';

                if ($isExplicitReg) {
                    $cleanPhone = $mappingService->cleanPhoneNumber($senderId);
                    $originalFromLid = ($originalFrom && str_contains($originalFrom, '@lid')) ? $mappingService->cleanPhoneNumber($originalFrom) : null;
                    $regKey = $originalFromLid ?? $cleanPhone;

                    // Deduplication (15s)
                    $lockKey = $messageId ? "wa_reg_reply:msg:{$messageId}" : 'wa_reg_reply:hash:'.md5($regKey.'|'.$rawBodyClean);
                    if (Cache::add($lockKey, true, now()->addSeconds(15))) {
                        Log::info('High-priority registration intent (EARLY) detected', ['phone' => $cleanPhone, 'reg_key' => $regKey]);

                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($regKey);
                        // Also store the actual phone if available for later account creation
                        if ($regKey !== $cleanPhone) {
                            \App\Helpers\WhatsAppRegistrationHelper::saveData($regKey, ['phone' => $cleanPhone]);
                        }
                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();

                        $whatsappService = new \App\Services\WhatsAppService;
                        $channelSessionId = "wa_{$tenantId}_{$channelAccount}";

                        try {
                            if ($isLidFormat) {
                                $targetLid = $originalFrom ?? $senderId;
                                // Ensure target has @lid suffix if it's an LID
                                if (! str_contains($targetLid, '@lid') && ! str_contains($targetLid, '@c.us')) {
                                    $targetLid .= '@lid';
                                }
                                $whatsappService->sendMessageToLid($channelSessionId, $targetLid, $replyMessage);
                            } else {
                                $whatsappService->sendMessage($channelSessionId, $cleanPhone, $replyMessage);
                            }

                            return ['success' => true, 'handled' => 'registration_start_high_priority_early'];
                        } catch (\Exception $e) {
                            Log::error('Failed to send early registration prompt', ['error' => $e->getMessage()]);
                        }
                    } else {
                        return ['success' => true, 'handled' => 'registration_start_high_priority_early_dedup'];
                    }
                }

                // Clean the sender ID for better matching
                $cleanedSenderId = $mappingService->cleanPhoneNumber($senderId);

                Log::info('Shared channel - attempting to find tenant', [
                    'sender_id' => $senderId,
                    'original_from' => $originalFrom,
                    'cleaned_sender_id' => $cleanedSenderId,
                    'is_lid_format' => $isLidFormat,
                ]);

                // --------------------------------------------------------------------------------
                // PRIORITY: HANDLE DEVICE LINKING (LID)
                // --------------------------------------------------------------------------------
                $msgBodyRaw = trim($waMessageData['body'] ?? '');

                // 1. Token-based linking (NEW): LINK ABC-123
                if (preg_match('/^link\s+([A-Z0-9]{3}-[A-Z0-9]{3})$/i', $msgBodyRaw, $tokenMatches)) {
                    $tokenValue = strtoupper($tokenMatches[1]);
                    $linkToken = \App\Models\DeviceLinkToken::where('token', $tokenValue)
                        ->where('expires_at', '>', now())
                        ->first();

                    if ($linkToken) {
                        $existingUser = $linkToken->user;
                        $cleanPhone = $existingUser->whatsapp_number;

                        // Link LID
                        $lidToSave = str_replace(['@lid', '@c.us'], '', $originalFrom ?? $senderId);
                        \App\Models\UserLidMapping::linkLidToUser($lidToSave, $existingUser->id, $existingUser->tenant_id, $cleanPhone);

                        // Cleanup token
                        $linkToken->delete();

                        $successMessage = "✅ *Perangkat Terhubung!*\n\n".
                                          "Halo {$existingUser->name},\n".
                                          "Perangkat ini berhasil dihubungkan via kode verifikasi.\n\n".
                                          'Sekarang Anda dapat melanjutkan aktivitas.';

                        $this->sendReplyMessage($tenantId, $channelAccount, $senderId, $originalFrom, $successMessage);

                        return ['success' => true, 'handled' => 'lid_linked_by_token'];
                    } else {
                        $this->sendReplyMessage($tenantId, $channelAccount, $senderId, $originalFrom, "❌ *Kode Tidak Valid*\n\nKode verifikasi salah atau sudah kedaluwarsa. Silakan ambil kode baru di Dashboard Web.");

                        return ['success' => false, 'handled' => 'link_token_invalid'];
                    }
                }

                // 2. Phone-based linking (LEGACY/FALLBACK): LINK 08xxx
                if (preg_match('/^link\s+(\d{10,15})$/i', $msgBodyRaw, $linkMatches)) {
                    $phoneToLink = $linkMatches[1];
                    Log::info('Intercepting LINK command', ['phone' => $phoneToLink, 'sender' => $senderId]);

                    // Use helper to find user
                    $existingUser = $this->findUserByPhoneNumber($phoneToLink);
                    $cleanPhone = $mappingService->cleanPhoneNumber($phoneToLink); // Needed for linking

                    if ($existingUser) {
                        // CHECK SUBSCRIPTION BEFORE LINKING - Fix for expired users linking devices
                        $userTenantSubscriptionValid = $this->checkSubscriptionStatus($existingUser->tenant_id);

                        if (! $userTenantSubscriptionValid) {
                            // Subscription expired - cannot link device
                            Log::warning('Device linking rejected (priority handler) - subscription expired', [
                                'user_id' => $existingUser->id,
                                'tenant_id' => $existingUser->tenant_id,
                                'phone_to_link' => $phoneToLink,
                            ]);

                            $expiredMessage = "⚠️ *Langganan Tidak Aktif*\n\n".
                                              "Akun untuk nomor *{$phoneToLink}* memiliki langganan yang sudah tidak aktif.\n\n".
                                              "Anda tidak dapat menghubungkan perangkat baru hingga langganan diperpanjang.\n\n".
                                              "🔓 *Untuk mengaktifkan kembali:*\n".
                                              '1️⃣ Perpanjang di: '.config('app.url')."/subscriptions\n".
                                              "2️⃣ Hubungi Admin: 6285242766676\n\n".
                                              '_Terima kasih telah menggunakan FinWa!_ 💙';

                            $this->sendReplyMessage($tenantId, $channelAccount, $senderId, $originalFrom, $expiredMessage);

                            return ['success' => false, 'handled' => 'link_subscription_expired', 'rejected' => true];
                        }

                        // Link LID
                        $lidToSave = str_replace(['@lid', '@c.us'], '', $originalFrom ?? $senderId);

                        \App\Models\UserLidMapping::linkLidToUser(
                            $lidToSave,
                            $existingUser->id,
                            $existingUser->tenant_id,
                            $cleanPhone
                        );

                        $successMessage = "✅ *Perangkat Terhubung!*\n\n".
                                          "Halo {$existingUser->name},\n".
                                          "Perangkat ini berhasil dihubungkan ke akun Anda ({$existingUser->whatsapp_number}).\n\n".
                                          'Sekarang Anda dapat melanjutkan aktivitas.';

                        $this->sendReplyMessage($tenantId, $channelAccount, $senderId, $originalFrom, $successMessage);

                        return ['success' => true, 'handled' => 'lid_linked'];
                    } else {
                        // Not found
                        $errorMessage = "❌ *Nomor Tidak Ditemukan*\n\n".
                                        "Kami mencari nomor: *{$phoneToLink}* (dan variasinya)\n".
                                        "namun tidak menemukan data yang cocok.\n\n".
                                        'Ketik *DAFTAR* untuk buat akun baru.';

                        $this->sendReplyMessage($tenantId, $channelAccount, $senderId, $originalFrom, $errorMessage);

                        return ['success' => false, 'handled' => 'link_phone_not_found'];
                    }
                }

                // 1. Resolve tenant from standard phone number or LID mapping
                $actualTenantId = $mappingService->getTenantIdFromWhatsAppNumber($senderId, $tenantId);

                // FALLBACK: Try mapping using originalFrom (LID)
                if ($actualTenantId === null && ! empty($originalFrom)) {
                    $actualTenantId = $mappingService->getTenantIdFromWhatsAppNumber($originalFrom, $tenantId);
                }

                // 2. SAFE AUTO-LINKING: If still not recognized but we have a verified phone sender
                // that matches an existing user perfectly, we can safely link the LID.
                // This prevents users like Rendy from being disconnected when their LID changes.
                if ($actualTenantId === null && ! empty($senderId) && ! str_contains($senderId, '@lid')) {
                    $cleanSenderPhone = $mappingService->cleanPhoneNumber($senderId);
                    $matchingUser = User::where('whatsapp_number', $cleanSenderPhone)->first();

                    if ($matchingUser) {
                        Log::info('Safe Auto-Linking triggered for existing user', [
                            'user_id' => $matchingUser->id,
                            'phone' => $cleanSenderPhone,
                            'new_lid' => $originalFrom,
                        ]);

                        $lidToSave = str_replace(['@lid', '@c.us'], '', $originalFrom ?? $senderId);

                        \App\Models\UserLidMapping::linkLidToUser(
                            $lidToSave,
                            $matchingUser->id,
                            $matchingUser->tenant_id,
                            $cleanSenderPhone
                        );

                        $actualTenantId = $matchingUser->tenant_id;
                    }
                }

                // ================================================================================
                // DISABLED: Silent auto-linking and rescue logic are disabled to prevent
                // LID swapping and identity race conditions.
                // Every user MUST now explicitly use the 'LINK' command for device association.
                // ================================================================================

                // ================================================================================
                // FALLBACK: LID not recognized and no phone-based match found.
                // Instead of blindly guessing which user this LID belongs to (which caused
                // cross-contamination bugs like LID swaps between users who registered
                // close together), we now prompt the user to verify by sending their
                // phone number. This triggers the LINK flow (line ~133) which safely
                // matches the LID to the correct user.
                // ================================================================================
                // Check if message is a registration intent BEFORE showing "Unrecognized LID" prompt
                $msgBodyLower = strtolower(trim($waMessageData['body'] ?? ''));
                $isRegIntent = preg_match('/\b(daftar|registrasi|reg|join|mau daftar|ingin daftar|finwa)\b/', $msgBodyLower) ||
                               str_contains($msgBodyLower, 'ketik daftar untuk mulai') ||
                               str_contains($msgBodyLower, 'halo kak, saya mau daftar finwa') ||
                               str_contains($msgBodyLower, 'halo admin saya butuh bantuan');

                // Get clean phone for registration flow check
                $cleanSenderPhone = $mappingService->cleanPhoneNumber($senderId);
                $cleanOriginalFrom = $originalFrom ? $mappingService->cleanPhoneNumber($originalFrom) : null;

                // Check registration flow using both IDs to be safe
                $isInRegFlow = \App\Helpers\WhatsAppRegistrationHelper::isInRegistrationFlow($cleanSenderPhone) ||
                              ($cleanOriginalFrom && \App\Helpers\WhatsAppRegistrationHelper::isInRegistrationFlow($cleanOriginalFrom));

                if ($isInRegFlow) {
                    Log::info('User detected in registration flow', [
                        'sender_id' => $senderId,
                        'original_from' => $originalFrom,
                        'clean_sender' => $cleanSenderPhone,
                        'clean_original' => $cleanOriginalFrom,
                    ]);
                }

                // ================================================================
                // FIX: Empty body from unrecognized LID → start registration
                // When a NEW user sends a message (e.g. from landing page wa.me link)
                // but the body is empty due to LID decryption failure, start the
                // registration flow immediately. This is MUCH better UX than showing
                // "device not connected" to a brand new user.
                // Sending the reply also establishes the Signal session key, so
                // subsequent messages from this LID will be decryptable.
                // ================================================================
                if ($actualTenantId === null && ! empty($originalFrom) && empty(trim($waMessageData['body'] ?? '')) && ! $isInRegFlow) {
                    // Check if there's a pending DeviceLinkToken — if so, this is an existing
                    // user trying to verify (not a new user). Let the rescue block below handle it.
                    $hasPendingToken = \App\Models\DeviceLinkToken::where('expires_at', '>', now())->exists();

                    if (! $hasPendingToken) {
                        $cleanPhone = $mappingService->cleanPhoneNumber($senderId);

                        Log::info('Empty body from unrecognized LID - starting registration flow', [
                            'sender_id' => $senderId,
                            'original_from' => $originalFrom,
                            'clean_phone' => $cleanPhone,
                        ]);

                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($cleanPhone);
                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();

                        $whatsappService = new \App\Services\WhatsAppService;
                        $channelSessionId = "wa_{$tenantId}_{$channelAccount}";

                        try {
                            $whatsappService->sendMessageToLid($channelSessionId, $originalFrom, $replyMessage);
                        } catch (\Exception $e) {
                            Log::error('Failed to send registration prompt to LID', ['error' => $e->getMessage()]);
                        }

                        return ['success' => true, 'handled' => 'registration_start_empty_body_lid'];
                    }
                    // else: has pending token, fall through to rescue block below
                }

                if ($actualTenantId === null && ! empty($originalFrom) && ! $isRegIntent && ! $isInRegFlow) {
                    $lidValue = str_replace('@lid', '', $originalFrom);

                    // ================================================================
                    // RESCUE ATTEMPT: Try to auto-link unrecognized LID before prompting
                    // Case 1: senderId contains phone number (e.g. 628xxx@c.us)
                    //         that matches a registered user
                    // Case 2: There's an active DeviceLinkToken for a user,
                    //         meaning they JUST received a verification message
                    //         and are trying to reply (but body is empty due to
                    //         LID decryption failure)
                    // ================================================================
                    $rescueSuccess = false;

                    // Case 1: Try phone from senderId (non-LID format)
                    if (! str_contains($senderId, '@lid')) {
                        $senderPhone = $mappingService->cleanPhoneNumber($senderId);
                        $matchUser = User::where('whatsapp_number', $senderPhone)->first();
                        if (! $matchUser) {
                            $matchWaNum = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $senderPhone)
                                ->where('is_active', true)->first();
                            $matchUser = $matchWaNum ? $matchWaNum->user : null;
                        }
                        if ($matchUser) {
                            $lidToSave = str_replace(['@lid', '@c.us'], '', $originalFrom);
                            \App\Models\UserLidMapping::linkLidToUser(
                                $lidToSave, $matchUser->id, $matchUser->tenant_id, $senderPhone
                            );
                            $actualTenantId = $matchUser->tenant_id;
                            $rescueSuccess = true;
                            Log::info('Rescue auto-link: matched via senderId phone', [
                                'user_id' => $matchUser->id, 'lid' => $lidToSave, 'phone' => $senderPhone,
                            ]);
                        }
                    }

                    // Case 2: Check for pending DeviceLinkToken (user just added WA number from dashboard)
                    if (! $rescueSuccess) {
                        $pendingTokens = \App\Models\DeviceLinkToken::where('expires_at', '>', now())
                            ->with('user')
                            ->get();

                        foreach ($pendingTokens as $pt) {
                            if ($pt->user) {
                                // Check if this token's user has no LID mapping yet
                                $hasLid = \App\Models\UserLidMapping::where('user_id', $pt->user->id)->exists();
                                if (! $hasLid) {
                                    // This user has a pending token and no LID - likely the sender
                                    // Only auto-link if there's exactly ONE pending token without LID
                                    // to avoid ambiguity
                                    $candidateCount = $pendingTokens->filter(function ($t) {
                                        return $t->user && ! \App\Models\UserLidMapping::where('user_id', $t->user->id)->exists();
                                    })->count();

                                    if ($candidateCount === 1) {
                                        $lidToSave = str_replace(['@lid', '@c.us'], '', $originalFrom);
                                        \App\Models\UserLidMapping::linkLidToUser(
                                            $lidToSave, $pt->user->id, $pt->user->tenant_id, $pt->user->whatsapp_number
                                        );
                                        $pt->delete(); // Consume the token
                                        $actualTenantId = $pt->user->tenant_id;
                                        $rescueSuccess = true;

                                        Log::info('Rescue auto-link: matched via pending DeviceLinkToken', [
                                            'user_id' => $pt->user->id,
                                            'lid' => $lidToSave,
                                            'token' => $pt->token,
                                        ]);

                                        // Send confirmation
                                        $successMsg = "✅ *Perangkat Terhubung!*\n\n".
                                            "Halo {$pt->user->name},\n".
                                            "Perangkat ini berhasil dihubungkan secara otomatis.\n\n".
                                            'Sekarang Anda dapat melanjutkan aktivitas.';
                                        $this->sendReplyMessage($tenantId, $channelAccount, $senderId, $originalFrom, $successMsg);

                                        return ['success' => true, 'handled' => 'lid_auto_linked_via_token'];
                                    }
                                }
                            }
                        }
                    }

                    // If rescue failed, show the standard prompt
                    if (! $rescueSuccess) {
                        Log::info('Unrecognized LID - prompting user to send phone number for verification', [
                            'lid' => $lidValue,
                            'sender_id' => $senderId,
                        ]);

                        $verifyMessage = "👋 *Selamat datang di FinWa!*\n\n".
                            "Perangkat Anda belum terhubung ke akun FinWa.\n\n".
                            "*Cara Menghubungkan:*\n".
                            "1️⃣ **Paling Mudah**: Klik tombol 'Hubungkan WhatsApp' di Dashboard Web.\n".
                            "2️⃣ **Ketik Kode**: Masukkan kode unik dari Dashboard (Contoh: `LINK ABC-123`)\n".
                            "3️⃣ **Ketik Nomor**: Ketik `LINK` diikuti nomor HP terdaftar (Contoh: `LINK 081234567890`)\n\n".
                            'Jika belum punya akun, ketik *DAFTAR* untuk mendaftar.';

                        $whatsappService = new \App\Services\WhatsAppService;
                        $channelSessionId = "wa_{$tenantId}_{$channelAccount}";

                        try {
                            $whatsappService->sendMessageToLid($channelSessionId, $originalFrom, $verifyMessage);
                        } catch (\Exception $e) {
                            Log::error('Failed to send LID verification prompt', ['error' => $e->getMessage()]);
                        }

                        return ['success' => true, 'handled' => 'lid_verification_prompt'];
                    }
                }

                // If STILL null after rescue check, proceed with registration flow
                if ($actualTenantId === null) {
                    // Determine the best registration key (LID preferred for continuity)
                    $cleanSenderPhone = $mappingService->cleanPhoneNumber($senderId);
                    $cleanOriginalFrom = $originalFrom ? $mappingService->cleanPhoneNumber($originalFrom) : null;

                    // Priority for regKey:
                    // 1. If we are already in flow with OriginalFrom (LID)
                    // 2. If we are already in flow with SenderPhone
                    // 3. New flow: use LID if available, else Phone
                    $regKey = null;
                    if ($cleanOriginalFrom && \App\Helpers\WhatsAppRegistrationHelper::isInRegistrationFlow($cleanOriginalFrom)) {
                        $regKey = $cleanOriginalFrom;
                    } elseif (\App\Helpers\WhatsAppRegistrationHelper::isInRegistrationFlow($cleanSenderPhone)) {
                        $regKey = $cleanSenderPhone;
                    } else {
                        // New flow - prefer LID if available to establish stable identity
                        $regKey = $cleanOriginalFrom ?? $cleanSenderPhone;
                    }

                    $rawMsgBody = trim($waMessageData['body'] ?? '');
                    $msgBody = strtolower($rawMsgBody);
                    $replyMessage = null;
                    $handled = null;

                    // ================================================================================
                    // SPECIAL HANDLING: First-time chat via Admin WA link
                    // ================================================================================
                    $isAdminHelpEntryMessage = str_contains($msgBody, 'halo admin saya butuh bantuan');
                    if ($isAdminHelpEntryMessage) {
                        Log::info('Detected admin help entry message - starting WA registration flow', ['key' => $regKey, 'body' => $msgBody]);
                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($regKey);
                        if ($regKey !== $cleanSenderPhone) {
                            \App\Helpers\WhatsAppRegistrationHelper::saveData($regKey, ['phone' => $cleanSenderPhone]);
                        }
                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();
                        $handled = 'registration_start_admin_help';
                    }
                    // ================================================================================
                    // Default WhatsApp registration message
                    // ================================================================================
                    elseif (str_contains($msgBody, 'ketik daftar untuk mulai') || str_contains($msgBody, 'halo kak, saya mau daftar finwa')) {
                        Log::info('Detected default WhatsApp registration message', ['key' => $regKey, 'body' => $msgBody]);
                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($regKey);
                        if ($regKey !== $cleanSenderPhone) {
                            \App\Helpers\WhatsAppRegistrationHelper::saveData($regKey, ['phone' => $cleanSenderPhone]);
                        }
                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();
                        $handled = 'registration_start_wa_default';
                    }
                    // Keywords that indicate intent to register
                    elseif (preg_match('/\b(daftar|registrasi|reg|join|mau daftar|ingin daftar|finwa)\b/', $msgBody) || in_array($msgBody, ['ya', 'mau'])) {
                        Log::info('Starting WhatsApp registration flow', ['key' => $regKey, 'trigger' => $msgBody]);
                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($regKey);
                        if ($regKey !== $cleanSenderPhone) {
                            \App\Helpers\WhatsAppRegistrationHelper::saveData($regKey, ['phone' => $cleanSenderPhone]);
                        }
                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();
                        $handled = 'registration_start';
                    }
                    // Already in registration flow
                    elseif (\App\Helpers\WhatsAppRegistrationHelper::isInRegistrationFlow($regKey)) {
                        Log::info('Continuing WhatsApp registration flow', ['key' => $regKey]);
                        $replyMessage = $this->handleRegistrationStep($regKey, $rawMsgBody);
                        $handled = 'registration_step';
                    }
                    // First contact (any other message from unregistered number)
                    else {
                        Log::info('First message from unregistered number - starting registration flow', ['key' => $regKey, 'body' => $msgBody]);
                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($regKey);
                        if ($regKey !== $cleanSenderPhone) {
                            \App\Helpers\WhatsAppRegistrationHelper::saveData($regKey, ['phone' => $cleanSenderPhone]);
                        }
                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();
                        $handled = 'registration_start_first_contact';
                    }

                    // Single send with deduplication
                    if ($replyMessage !== null) {
                        $lockKey = $messageId ? "wa_reg_reply:msg:{$messageId}" : 'wa_reg_reply:hash:'.md5($regKey.'|'.$msgBody);

                        if (! Cache::add($lockKey, true, now()->addSeconds(15))) {
                            Log::info('Skipping duplicate registration reply (lock exists)', ['key' => $regKey, 'msg_id' => $messageId]);

                            return ['success' => true, 'handled' => $handled.'_dedup'];
                        }

                        $whatsappService = new \App\Services\WhatsAppService;
                        $channelSessionId = "wa_{$tenantId}_{$channelAccount}";
                        if ($isLidFormat && $originalFrom) {
                            $whatsappService->sendMessageToLid($channelSessionId, $originalFrom, $replyMessage);
                        } else {
                            $whatsappService->sendMessage($channelSessionId, $cleanSenderPhone, $replyMessage);
                        }

                        // ================================================================
                        // AUTO-LINK LID after registration completion
                        // ================================================================
                        if ($isLidFormat && $originalFrom) {
                            // Get actual phone from registration data
                            $regData = \App\Helpers\WhatsAppRegistrationHelper::getRegistrationData($regKey);
                            $actualPhone = $regData['phone'] ?? $cleanSenderPhone;

                            $justCreatedUser = User::where('whatsapp_number', $actualPhone)->first();
                            if ($justCreatedUser) {
                                $lidToSave = str_replace(['@lid', '@c.us'], '', $originalFrom);
                                $existingLid = \App\Models\UserLidMapping::where('lid', $lidToSave)->first();

                                if (! $existingLid) {
                                    \App\Models\UserLidMapping::linkLidToUser(
                                        $lidToSave,
                                        $justCreatedUser->id,
                                        $justCreatedUser->tenant_id,
                                        $actualPhone
                                    );

                                    Log::info('Auto-linked LID after registration/message flow', [
                                        'user_id' => $justCreatedUser->id,
                                        'tenant_id' => $justCreatedUser->tenant_id,
                                        'lid' => $lidToSave,
                                        'phone' => $actualPhone,
                                        'handled' => $handled,
                                    ]);
                                }
                            }
                        }

                        return ['success' => true, 'handled' => $handled];
                    }

                    Log::warning('Message rejected - WhatsApp number not registered', [
                        'session_id' => $sessionId,
                        'channel_id' => $channel->id,
                        'sender_id' => $senderId,
                        'original_from' => $originalFrom,
                        'cleaned_sender_id' => $cleanedSenderId,
                        'is_lid_format' => $isLidFormat,
                    ]);

                    return [
                        'success' => false,
                        'error' => 'WhatsApp number not registered',
                        'rejected' => true,
                    ];
                }

                Log::info('Shared channel detected - routing by sender_id', [
                    'session_id' => $sessionId,
                    'channel_id' => $channel->id,
                    'sender_id' => $senderId,
                    'cleaned_sender_id' => $cleanedSenderId,
                    'original_tenant_id' => $tenantId,
                    'routed_tenant_id' => $actualTenantId,
                    'was_lid_fallback' => $isLidFormat && $actualTenantId === $tenantId,
                ]);

                $tenantId = $actualTenantId;
            }

            // Check subscription status for this tenant
            $subscriptionValid = $this->checkSubscriptionStatus($tenantId);

            // Get message body for intent checking
            $msgBody = trim($waMessageData['body'] ?? '');
            $currentSenderId = $waMessageData['from'] ?? '';
            $currentOriginalFrom = $waMessageData['originalFrom'] ?? $waMessageData['raw_data']['originalFrom'] ?? null;

            Log::info('Checking intent for user', [
                'body' => $msgBody,
                'sender' => $currentSenderId,
            ]);

            $allowKeywords = ['daftar', 'registrasi', 'reg', 'join', 'mau daftar', 'bayar', 'renew', 'perpanjang', 'help', 'info', 'link'];
            $isIntentAllowed = false;

            foreach ($allowKeywords as $keyword) {
                // Use stripos for case-insensitive check
                if (stripos($msgBody, $keyword) !== false) {
                    $isIntentAllowed = true;
                    Log::info('Intent allowed detected', ['keyword' => $keyword]);
                    break;
                }
            }

            if (! $subscriptionValid) {
                // If allowed intent (daftar/help), handle it (Logic exists above)
                if ($isIntentAllowed) {
                    // Start registration flow if intent is registration
                    // Check if it's registration
                    $regKeywords = ['daftar', 'registrasi', 'reg', 'join', 'mau daftar'];
                    $isReg = false;
                    foreach ($regKeywords as $k) {
                        if (stripos($msgBody, $k) !== false) {
                            $isReg = true;
                            break;
                        }
                    }

                    if ($isReg) {
                        // Start registration flow
                        $cleanPhone = $mappingService->cleanPhoneNumber($senderId);
                        \App\Helpers\WhatsAppRegistrationHelper::startFlow($cleanPhone);

                        $replyMessage = \App\Helpers\WhatsAppRegistrationHelper::getAskNameMessage();

                        $whatsappService = new \App\Services\WhatsAppService;
                        $channelSessionId = "wa_{$tenantId}_{$channelAccount}";

                        $rawOriginalFrom = $waMessageData['raw_data']['originalFrom'] ?? null;
                        $finalOriginalFrom = $originalFrom ?? $rawOriginalFrom;
                        $isLidFormat = str_contains($senderId, '@lid') || ($finalOriginalFrom && str_contains($finalOriginalFrom, '@lid'));

                        Log::info('Starting registration flow for expired user (intent)', ['phone' => $cleanPhone]);

                        try {
                            if ($isLidFormat && $finalOriginalFrom) {
                                $whatsappService->sendMessageToLid($channelSessionId, $finalOriginalFrom, $replyMessage);
                            } else {
                                $whatsappService->sendMessage($channelSessionId, $cleanPhone, $replyMessage);
                            }

                            return ['success' => true, 'handled' => 'registration_start_expired'];
                        } catch (\Exception $e) {
                            Log::error('Failed to send registration reply', ['error' => $e->getMessage()]);

                            return ['success' => false, 'error' => 'Failed to send reply'];
                        }
                    }
                }

                // FALLBACK: If user is stranger (not in DB), treat as Unregistered, not Expired.
                // This happens if Admin Tenant is expired but public user wants to register.

                // Ensure senderId is valid
                if (empty($senderId)) {
                    $senderId = $waMessageData['from'] ?? null;
                }

                $isRegisteredUser = false;
                if ($senderId) {
                    $isRegisteredUser = $mappingService->isWhatsAppNumberRegistered($senderId);
                }

                if (! $isRegisteredUser) {
                    // Extract fresh from original data to avoid scope issues
                    $currentSenderId = $waMessageData['from'] ?? '';
                    $currentOriginalFrom = $waMessageData['originalFrom'] ?? $waMessageData['raw_data']['originalFrom'] ?? null;

                    if (! $currentSenderId) {
                        Log::error('Missing sender ID in unregistered fallback', ['data' => $waMessageData]);

                        return ['success' => false, 'error' => 'Missing sender ID'];
                    }

                    Log::info('Expired tenant but User is unknown - treating as registration/unregistered', [
                        'sender' => $currentSenderId,
                        'tenant_id' => $tenantId,
                    ]);

                    // Send unregistered reply (which prompts to register)
                    $this->sendUnregisteredNumberReply($channel, $currentSenderId, $sessionId, $currentOriginalFrom);

                    return [
                        'success' => false,
                        'error' => 'User not registered (Tenant expired)',
                        'rejected' => true,
                        'handled' => 'unregistered_fallback',
                    ];
                }

                // Real Expired User
                // Subscription expired - send notification and reject message
                $senderId = $waMessageData['from'] ?? null;
                $originalFrom = $waMessageData['originalFrom'] ?? $waMessageData['raw_data']['originalFrom'] ?? null;

                if ($senderId) {
                    $this->sendExpiredSubscriptionReply($channel, $senderId, $sessionId, $originalFrom);
                }

                Log::warning('Message rejected - Subscription expired', [
                    'session_id' => $sessionId,
                    'tenant_id' => $tenantId,
                    'sender_id' => $senderId,
                ]);

                return [
                    'success' => false,
                    'error' => 'Subscription expired',
                    'rejected' => true,
                ];
            }

            // Store channel_id in payload
            $channelId = $channel->id;

            // Extract message data
            // (messageId already extracted early at top of method)
            $senderId = $waMessageData['from'] ?? null;
            $timestamp = isset($waMessageData['timestamp'])
                ? (int) ($waMessageData['timestamp'] * 1000) // Convert to milliseconds
                : time() * 1000;

            // Determine message type and content
            // Map WhatsApp-web.js types to database types
            $waType = $waMessageData['type'] ?? 'chat';
            $content = is_string($waMessageData['body'] ?? null) ? (string) $waMessageData['body'] : '';
            $mediaUrl = null;
            $mediaPath = null;
            $mediaFilename = null;

            // Determine initial type based on waType
            $typeMap = [
                'chat' => 'text',
                'text' => 'text',
                'image' => 'image',
                'audio' => 'audio',
                'document' => 'doc',
                'video' => 'image', // Treat video as image
                'sticker' => 'image',
                'ptt' => 'audio',
            ];
            $type = $typeMap[$waType] ?? 'text';

            // ================================================================================
            // EARLY DETECTION: Empty message body from LID users
            // WhatsApp gateway sometimes fails to decrypt message body from Linked ID devices.
            // When this happens, the body field is missing/empty. We detect this early and
            // send a helpful reply so the user knows to resend.
            // ================================================================================
            $hasOriginalFromLid = ! empty($waMessageData['originalFrom']) && str_contains($waMessageData['originalFrom'], '@lid');
            $isSenderLid = str_contains($senderId ?? '', '@lid');
            $isLidUser = $hasOriginalFromLid || $isSenderLid;

            if (empty($content) && $isLidUser && $type !== 'image' && ! ($waMessageData['hasMedia'] ?? false)) {
                Log::warning('Empty message body from LID user - gateway decryption issue', [
                    'session_id' => $sessionId,
                    'sender_id' => $senderId,
                    'original_from' => $waMessageData['originalFrom'] ?? null,
                    'tenant_id' => $tenantId,
                    'message_id' => $messageId,
                    'wa_type' => $waType,
                ]);

                // ================================================================
                // FIX: Do NOT forward empty-body LID messages to the job queue.
                // WhatsApp Linked Devices often send the same message twice:
                //   1) With body (decrypted OK) → processed correctly
                //   2) Without body (decryption failure) → this duplicate
                // Forwarding #2 caused ProcessIncomingMessage to fire
                // maybeNotifyEmptyLidBody(), sending a confusing "FinWa tidak
                // menerima isi teks pesan ini" ALONGSIDE the real response.
                // We now silently discard empty-body LID text messages.
                // ================================================================
                return [
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'empty_body_lid_text_discarded',
                ];
            }

            // Check if message has media (priority check)
            if (isset($waMessageData['hasMedia']) && $waMessageData['hasMedia']) {
                // Handle media - content should be URL if media was uploaded
                if (isset($waMessageData['mimetype'])) {
                    if (str_starts_with($waMessageData['mimetype'], 'image/')) {
                        $type = 'image';
                        // If body contains URL (from media upload), use it
                        if (! empty($content) && $this->isLikelyMediaReference($content)) {
                            $content = $this->normalizeIncomingMediaReference($content);
                            $mediaUrl = $content;
                        } else {
                            $fallback = $this->extractIncomingMediaReference($waMessageData, $tenantId, $messageId);
                            if (! empty($fallback['content'])) {
                                $content = $fallback['content'];
                                $mediaUrl = $fallback['media_url'] ?? $content;
                                $mediaPath = $fallback['media_path'] ?? null;
                                $mediaFilename = $fallback['filename'] ?? null;
                            }
                            // Log warning if media URL is missing
                            if (empty($mediaUrl) && empty($content)) {
                                Log::warning('Image message received but media reference is missing', [
                                'message_id' => $messageId,
                                'hasMedia' => true,
                                'mimetype' => $waMessageData['mimetype'],
                                'body_preview' => substr((string) ($waMessageData['body'] ?? ''), 0, 100),
                                'keys' => array_keys($waMessageData),
                                'raw_keys' => isset($waMessageData['raw_data']) && is_array($waMessageData['raw_data']) ? array_keys($waMessageData['raw_data']) : null,
                            ]);
                            }
                        }
                    } elseif (str_starts_with($waMessageData['mimetype'], 'audio/')) {
                        $type = 'audio';
                        if (! empty($content) && $this->isLikelyMediaReference($content)) {
                            $content = $this->normalizeIncomingMediaReference($content);
                            $mediaUrl = $content;
                        } else {
                            $fallback = $this->extractIncomingMediaReference($waMessageData, $tenantId, $messageId);
                            if (! empty($fallback['content'])) {
                                $content = $fallback['content'];
                                $mediaUrl = $fallback['media_url'] ?? $content;
                                $mediaPath = $fallback['media_path'] ?? null;
                                $mediaFilename = $fallback['filename'] ?? null;
                            }
                        }
                    } elseif (str_contains($waMessageData['mimetype'], 'pdf')) {
                        $type = 'doc';
                        if (! empty($content) && $this->isLikelyMediaReference($content)) {
                            $content = $this->normalizeIncomingMediaReference($content);
                            $mediaUrl = $content;
                        } else {
                            $fallback = $this->extractIncomingMediaReference($waMessageData, $tenantId, $messageId);
                            if (! empty($fallback['content'])) {
                                $content = $fallback['content'];
                                $mediaUrl = $fallback['media_url'] ?? $content;
                                $mediaPath = $fallback['media_path'] ?? null;
                                $mediaFilename = $fallback['filename'] ?? null;
                            }
                        }
                    } elseif (str_contains($waMessageData['mimetype'], 'csv') ||
                              str_contains($waMessageData['mimetype'], 'spreadsheet') ||
                              str_contains($waMessageData['mimetype'], 'excel')) {
                        $type = 'csv';
                        if (! empty($content) && $this->isLikelyMediaReference($content)) {
                            $content = $this->normalizeIncomingMediaReference($content);
                            $mediaUrl = $content;
                        } else {
                            $fallback = $this->extractIncomingMediaReference($waMessageData, $tenantId, $messageId);
                            if (! empty($fallback['content'])) {
                                $content = $fallback['content'];
                                $mediaUrl = $fallback['media_url'] ?? $content;
                                $mediaPath = $fallback['media_path'] ?? null;
                                $mediaFilename = $fallback['filename'] ?? null;
                            }
                        }
                    } else {
                        $type = 'doc';
                        if (! empty($content) && $this->isLikelyMediaReference($content)) {
                            $content = $this->normalizeIncomingMediaReference($content);
                            $mediaUrl = $content;
                        } else {
                            $fallback = $this->extractIncomingMediaReference($waMessageData, $tenantId, $messageId);
                            if (! empty($fallback['content'])) {
                                $content = $fallback['content'];
                                $mediaUrl = $fallback['media_url'] ?? $content;
                                $mediaPath = $fallback['media_path'] ?? null;
                                $mediaFilename = $fallback['filename'] ?? null;
                            }
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
                        'sticker' => 'image',
                    ];
                    $type = $typeMap[$waType] ?? 'text';
                }

                if ($mediaUrl === null && in_array($type, ['image', 'audio', 'doc', 'csv'], true)) {
                    if (! empty($content) && $this->isLikelyMediaReference($content)) {
                        $content = $this->normalizeIncomingMediaReference($content);
                        $mediaUrl = $content;
                    } else {
                        $fallback = $this->extractIncomingMediaReference($waMessageData, $tenantId, $messageId);
                        if (! empty($fallback['content'])) {
                            $content = $fallback['content'];
                            $mediaUrl = $fallback['media_url'] ?? $content;
                            $mediaPath = $fallback['media_path'] ?? null;
                            $mediaFilename = $fallback['filename'] ?? null;
                        }
                    }
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
                    'ptt' => 'audio',
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
                    'author' => $waMessageData['author'] ?? null, // Actual sender in groups
                    'isForwarded' => $waMessageData['isForwarded'] ?? false,
                    'isStarred' => $waMessageData['isStarred'] ?? false,
                    'hasMedia' => $waMessageData['hasMedia'] ?? false,
                    'mimetype' => $waMessageData['mimetype'] ?? null,
                    'media_url' => $mediaUrl,
                    'media_path' => $mediaPath,
                    'filename' => $mediaFilename,
                    'location' => $waMessageData['location'] ?? null,
                    'contact' => $waMessageData['contact'] ?? null,
                    'mentions' => $waMessageData['mentionedIds'] ?? [],
                ],
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
                'data' => $waMessageData,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
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
            if (! empty($payload['original_sender_id'])) {
                $metadata['original_sender_id'] = $payload['original_sender_id'];
            }
            if (! empty($payload['raw_data']['author'])) {
                $metadata['author'] = $payload['raw_data']['author'];
            }
            if (! empty($payload['raw_data']['isGroup'])) {
                $metadata['is_group'] = $payload['raw_data']['isGroup'];
            }
            if (! empty($payload['raw_data']['mimetype'])) {
                $metadata['mimetype'] = $payload['raw_data']['mimetype'];
            }
            if (! empty($payload['raw_data']['media_url'])) {
                $metadata['media_url'] = $payload['raw_data']['media_url'];
            }
            if (! empty($payload['raw_data']['media_path'])) {
                $metadata['media_path'] = $payload['raw_data']['media_path'];
            }
            if (! empty($payload['raw_data']['filename'])) {
                $metadata['filename'] = $payload['raw_data']['filename'];
            }

            try {
                // Create or Update Message record (handle duplicates gracefully)
                $message = Message::updateOrCreate(
                    ['message_id' => $payload['message_id']],
                    [
                        'tenant_id' => $payload['tenant_id'],
                        'channel_id' => $payload['channel_id'] ?? null,
                        'channel' => $payload['channel'],
                        'channel_account' => $payload['channel_account'],
                        'sender_id' => $payload['sender_id'],
                        'type' => $payload['type'],
                        'content' => $payload['content'],
                        'status' => 'received',
                        'timestamp' => $payload['timestamp'],
                        'metadata' => $metadata,
                        'raw_data' => $payload['raw_data'] ?? [],
                    ]
                );
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle race condition where two requests try to insert same message_id at once
                // Check for SQLSTATE 23000 or MySQL Error 1062
                $errorCode = $e->errorInfo[1] ?? 0;
                if ($e->getCode() === '23000' || $errorCode === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    Log::info('Duplicate message entry caught via Exception', ['message_id' => $payload['message_id']]);
                    $message = Message::where('message_id', $payload['message_id'])->first();

                    if (! $message) {
                        // This shouldn't happen if we caught a duplicate entry, but safety first
                        // Try to find by other unique keys if possible, or just re-throw
                        Log::error('Duplicate entry caught but message not found in DB', ['message_id' => $payload['message_id']]);
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            // Update channel last activity
            if ($message->channel_id) {
                $channel = Channel::find($message->channel_id);
                if ($channel) {
                    $channel->update(['last_activity_at' => now()]);
                }
            }

            // Only dispatch job if it's a NEW message to prevent duplicate processing
            if ($message->wasRecentlyCreated) {
                // Dispatch job to database queue
                // This returns response immediately to avoid WhatsApp gateway timeout
                // Queue workers (already running) will process the job quickly
                ProcessIncomingMessage::dispatch($message)->onConnection('database');

                Log::info('Message created and job queued', [
                    'message_id' => $message->id,
                    'tenant_id' => $payload['tenant_id'],
                ]);
            } else {
                Log::info('Duplicate message received, skipping job dispatch', [
                    'message_id' => $message->id,
                    'status' => $message->status,
                ]);
            }

            return [
                'success' => true,
                'data' => [
                    'message_id' => $message->id,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error creating message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send reply to unregistered WhatsApp number
     * Rate limited: only sends once per 5 minutes per phone number
     *
     * @param  string|null  $originalFrom  Original LID if gateway already resolved it
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
                    'original_from' => $originalFrom,
                ]);

                return;
            }

            // Set cache for 5 minutes to prevent duplicate messages
            Cache::put($cacheKey, true, now()->addMinutes(5));

            $message = "👋 *Selamat Datang di FinWa!*\n\n".
                "Untuk memulai, FinWa perlu menghubungkan perangkat ini ke akun Anda.\n\n".
                "*Caranya sangat mudah:*\n".
                "Balas dengan nomor HP yang Anda daftarkan.\n".
                "Contoh: `6285159205506`\n\n".
                '💡 *Tips:* Gunakan format 62xxx (tanpa tanda +)';

            $whatsappService = new WhatsAppService;

            // CRITICAL: When sender uses LID format, send directly to LID
            if ($isLidFormat && $targetLid) {
                Log::info('Sending unregistered reply to LID directly', [
                    'sender_id' => $senderId,
                    'sending_to' => $targetLid,
                    'original_from' => $originalFrom,
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
                    'is_lid' => $isLidFormat,
                ]);
            } else {
                Log::warning('Failed to send unregistered number reply', [
                    'sender_id' => $senderId,
                    'is_lid' => $isLidFormat,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending unregistered number reply', [
                'sender_id' => $senderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if tenant has active subscription
     */
    protected function checkSubscriptionStatus(int $tenantId): bool
    {
        $tenant = \App\Models\Tenant::find($tenantId);
        if (! $tenant) {
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
     * @param  string|null  $originalFrom  Original LID if gateway already resolved it
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
                    'tenant_id' => $channel->tenant_id,
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
            $message .= '   🌐 '.config('app.url')."/subscriptions\n\n";
            $message .= "2️⃣ *Hubungi Admin*\n";
            $message .= "   📱 WA: 6285242766676\n";
            $message .= "   📧 Email: finwa.help@gmail.com\n\n";

            $message .= '_Terima kasih telah menggunakan FinWa!_ 💙';

            $whatsappService = new WhatsAppService;

            // CRITICAL: When sender uses LID format, send directly to LID
            // The "phone number" in LID format is NOT a real phone number - it's an internal WhatsApp ID
            // Cleaning it (adding 62 prefix) would break the send
            if ($isLidFormat && $targetLid) {
                // Send directly to LID - don't clean as phone number
                Log::info('Sending expired subscription reply to LID directly', [
                    'sender_id' => $senderId,
                    'sending_to' => $targetLid,
                    'original_from' => $originalFrom,
                    'tenant_id' => $channel->tenant_id,
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
                    'original_from' => $originalFrom,
                    'tenant_id' => $channel->tenant_id,
                    'plan' => $planName,
                    'expired_at' => ($subscription->ends_at ?? $tenant->trial_ends_at ?? null),
                ]);
            } else {
                Log::warning('Failed to send expired subscription reply', [
                    'sender_id' => $senderId,
                    'is_lid' => $isLidFormat,
                    'original_from' => $originalFrom,
                    'tenant_id' => $channel->tenant_id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error sending expired subscription reply', [
                'sender_id' => $senderId,
                'tenant_id' => $channel->tenant_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle registration flow step
     */
    protected function handleRegistrationStep(string $phoneNumber, string $message): ?string
    {
        // Clean the message: remove zero-width spaces and other invisible characters
        // Use a safer preg_replace that doesn't return null on invalid UTF-8 if possible,
        // or handle the null case.
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $message);
        $message = ($cleaned === null) ? $message : $cleaned;
        $message = trim($message);

        $step = \App\Helpers\WhatsAppRegistrationHelper::getCurrentStep($phoneNumber);

        Log::info('Handling registration step', [
            'phone' => $phoneNumber,
            'step' => $step,
            'message_len' => strlen($message),
            'message_preview' => substr($message, 0, 20)
        ]);

        switch ($step) {
            case 'awaiting_name':
                // Clean the name thoroughly
                $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $message);
                $message = ($cleaned === null) ? $message : $cleaned;
                $message = preg_replace('/[[:^print:]]/', '', $message);
                $message = trim($message);

                // Temporarily disabled validation to fix registration block
                /*
                if (mb_strlen($message) < 3) {
                    return 'Maaf, nama terlalu pendek. Silakan kirim nama lengkap Anda:';
                }
                */

                // Save name and move to next step
                \App\Helpers\WhatsAppRegistrationHelper::saveData($phoneNumber, ['name' => $message]);
                \App\Helpers\WhatsAppRegistrationHelper::setStep($phoneNumber, 'awaiting_email');

                return \App\Helpers\WhatsAppRegistrationHelper::getAskEmailMessage($message);

            case 'awaiting_email':
                // Clean the email thoroughly
                $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $message);
                $email = ($cleaned === null) ? $message : $cleaned;
                $email = preg_replace('/[[:^print:]]/', '', $email);
                $email = trim($email);

                // Validate email
                if (! \App\Helpers\WhatsAppRegistrationHelper::isValidEmail($email)) {
                    return 'Maaf, format email tidak valid. Silakan kirim alamat email yang benar (contoh: nama@email.com):';
                }

                // Check if email already exists
                if (\App\Models\User::where('email', $email)->exists()) {
                    return 'Maaf, email tersebut sudah terdaftar. Silakan gunakan email lain:';
                }

                // Save email
                \App\Helpers\WhatsAppRegistrationHelper::saveData($phoneNumber, ['email' => $email]);

                // Process registration immediately
                try {
                    $data = \App\Helpers\WhatsAppRegistrationHelper::getRegistrationData($phoneNumber);
                    $result = \App\Helpers\WhatsAppRegistrationHelper::createAccount($data);

                    // Clear flow *before* returning success message to avoid loops
                    \App\Helpers\WhatsAppRegistrationHelper::clearFlow($phoneNumber);

                    return \App\Helpers\WhatsAppRegistrationHelper::getSuccessMessage($result);
                } catch (\Exception $e) {
                    Log::error('Registration Error', ['error' => $e->getMessage(), 'phone' => $phoneNumber]);

                    return 'Maaf, terjadi kesalahan saat membuat akun. Silakan coba lagi nanti atau hubungi admin.';
                }

            default:
                return null;
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

    /**
     * Find user by phone number using fuzzy search
     */
    protected function findUserByPhoneNumber(string $phoneNumber): ?\App\Models\User
    {
        $mappingService = new WhatsAppUserMappingService;

        return $mappingService->findUserByWhatsAppNumber($phoneNumber);
    }

    /**
     * Send reply message handling LID automatically
     */
    protected function sendReplyMessage(int $tenantId, string $channelAccount, string $target, ?string $originalFrom, string $message): void
    {
        $whatsappService = new \App\Services\WhatsAppService;
        $channelSessionId = "wa_{$tenantId}_{$channelAccount}";

        // Determine final target
        // If originalFrom is LID, use it (highest priority for reply)
        $finalTarget = $target;
        if ($originalFrom && str_contains($originalFrom, '@lid')) {
            $finalTarget = $originalFrom;
        } elseif (str_contains($target, '@lid')) {
            $finalTarget = $target;
        }

        if (str_contains($finalTarget, '@lid')) {
            $whatsappService->sendMessageToLid($channelSessionId, $finalTarget, $message);
        } else {
            $whatsappService->sendMessage($channelSessionId, $finalTarget, $message);
        }
    }

    /**
     * Helper to perform auto-linking of LID to user
     */
    protected function performAutoLink($user, $lidValue, $originalFrom, $tenantId, $channelAccount, $waMessageData, $phone = null): void
    {
        $phoneToLink = $phone ?? $user->whatsapp_number;

        // AUTO-LINK this user!
        \App\Models\UserLidMapping::linkLidToUser(
            $lidValue,
            $user->id,
            $user->tenant_id,
            $phoneToLink
        );

        \App\Models\UserWhatsAppNumber::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'whatsapp_number' => $lidValue,
            'name' => 'Auto-linked LID (Fallback)',
            'is_primary' => false,
            'is_active' => true,
            'is_lid' => true,
        ]);

        Log::info('Fallback auto-linked LID to user', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'user_phone' => $phoneToLink,
            'lid' => $lidValue,
        ]);

        // If message body is empty (due to decryption failure) or it's a new connection, send helpful response
        $msgBody = trim($waMessageData['body'] ?? '');
        if (empty($msgBody) || strtolower($msgBody) === 'hai' || strtolower($msgBody) === 'halo') {
            $whatsappService = new \App\Services\WhatsAppService;
            $channelSessionId = "wa_{$tenantId}_{$channelAccount}";

            // Send welcome message since this is a new link
            $welcomeMessage = "👋 *Hai {$user->name}!*\n\n".
                "Akun Anda sudah terhubung. ✅\n\n".
                "Silakan kirim pesan lagi untuk memulai, misalnya:\n".
                "• _\"makan siang 25rb\"_\n".
                "• _\"gaji 5jt\"_\n".
                "• Kirim foto struk 📸\n\n".
                'Ketik *help* untuk panduan lengkap.';

            $whatsappService->sendMessageToLid($channelSessionId, $originalFrom, $welcomeMessage);

            Log::info('Sent welcome message after auto-link', [
                'user_id' => $user->id,
                'lid' => $lidValue,
                'message_body' => $msgBody,
            ]);
        }
    }

    protected function isLikelyMediaReference(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            return true;
        }

        if (str_starts_with($value, '/storage/') || str_starts_with($value, 'storage/')) {
            return true;
        }

        if (str_starts_with($value, '/api/files') || str_starts_with($value, 'api/files')) {
            return true;
        }

        return false;
    }

    protected function normalizeIncomingMediaReference(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (preg_match('/^https?:\/\//i', $value)) {
            return $value;
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            return $value;
        }

        if (str_starts_with($value, 'storage/')) {
            return $base.'/'.ltrim($value, '/');
        }

        if (str_starts_with($value, 'api/files')) {
            return $base.'/'.ltrim($value, '/');
        }

        if (str_starts_with($value, '/storage/') || str_starts_with($value, '/api/files')) {
            return $base.$value;
        }

        return $value;
    }

    protected function extractIncomingMediaReference(array $waMessageData, int $tenantId, ?string $messageId): array
    {
        $urlCandidates = [
            $waMessageData['mediaUrl'] ?? null,
            $waMessageData['media_url'] ?? null,
            $waMessageData['url'] ?? null,
            $waMessageData['fileUrl'] ?? null,
            $waMessageData['raw_data']['mediaUrl'] ?? null,
            $waMessageData['raw_data']['media_url'] ?? null,
            $waMessageData['raw_data']['url'] ?? null,
            $waMessageData['raw_data']['fileUrl'] ?? null,
            $waMessageData['raw_data']['file_url'] ?? null,
            $waMessageData['raw_data']['image']['url'] ?? null,
            $waMessageData['raw_data']['document']['url'] ?? null,
            $waMessageData['raw_data']['audio']['url'] ?? null,
        ];

        foreach ($urlCandidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($this->isLikelyMediaReference($candidate) || filter_var($candidate, FILTER_VALIDATE_URL)) {
                $normalized = $this->normalizeIncomingMediaReference($candidate);

                return [
                    'content' => $normalized,
                    'media_url' => $normalized,
                    'media_path' => null,
                    'filename' => $this->extractIncomingFilename($waMessageData),
                ];
            }
        }

        $base64 = $this->extractIncomingBase64($waMessageData);
        if (! $base64) {
            return [];
        }

        $mimeType = $this->extractIncomingMimeType($waMessageData) ?? 'application/octet-stream';
        $decoded = $this->decodeBase64Payload($base64);
        if ($decoded === null) {
            Log::warning('Media base64 present but failed to decode', [
                'message_id' => $messageId,
                'tenant_id' => $tenantId,
                'mimetype' => $mimeType,
                'base64_len' => strlen($base64),
            ]);

            return [];
        }

        $filename = $this->extractIncomingFilename($waMessageData) ?? ('media_'.($messageId ? preg_replace('/\W+/', '', (string) $messageId) : uniqid()));
        $extension = $this->extensionFromMimeType($mimeType);
        $safeFilename = $this->sanitizeFilename($filename);
        if ($extension && ! str_ends_with(strtolower($safeFilename), '.'.strtolower($extension))) {
            $safeFilename .= '.'.$extension;
        }

        $path = "whatsapp/{$tenantId}/".date('Y/m/d').'/'.uniqid().'_'.$safeFilename;
        Storage::disk('public')->put($path, $decoded);

        return [
            'content' => $path,
            'media_url' => $path,
            'media_path' => $path,
            'filename' => $safeFilename,
        ];
    }

    protected function extractIncomingBase64(array $waMessageData): ?string
    {
        $candidates = [
            $waMessageData['file_data'] ?? null,
            $waMessageData['fileData'] ?? null,
            $waMessageData['base64'] ?? null,
            $waMessageData['data'] ?? null,
            $waMessageData['mediaBase64'] ?? null,
            $waMessageData['media_data'] ?? null,
            $waMessageData['raw_data']['file_data'] ?? null,
            $waMessageData['raw_data']['fileData'] ?? null,
            $waMessageData['raw_data']['base64'] ?? null,
            $waMessageData['raw_data']['data'] ?? null,
            $waMessageData['raw_data']['mediaBase64'] ?? null,
            $waMessageData['raw_data']['media_data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_array($candidate)) {
                foreach (['file_data', 'fileData', 'base64', 'data'] as $key) {
                    if (isset($candidate[$key]) && is_string($candidate[$key]) && trim($candidate[$key]) !== '') {
                        return trim($candidate[$key]);
                    }
                }
            }
        }

        foreach (['media', 'image', 'document', 'audio'] as $top) {
            if (! isset($waMessageData[$top])) {
                continue;
            }
            $obj = $waMessageData[$top];
            if (is_array($obj)) {
                foreach (['file_data', 'fileData', 'base64', 'data'] as $key) {
                    if (isset($obj[$key]) && is_string($obj[$key]) && trim($obj[$key]) !== '') {
                        return trim($obj[$key]);
                    }
                }
            }
        }

        if (isset($waMessageData['raw_data']) && is_array($waMessageData['raw_data'])) {
            foreach (['media', 'image', 'document', 'audio'] as $top) {
                if (! isset($waMessageData['raw_data'][$top])) {
                    continue;
                }
                $obj = $waMessageData['raw_data'][$top];
                if (is_array($obj)) {
                    foreach (['file_data', 'fileData', 'base64', 'data'] as $key) {
                        if (isset($obj[$key]) && is_string($obj[$key]) && trim($obj[$key]) !== '') {
                            return trim($obj[$key]);
                        }
                    }
                }
            }
        }

        return null;
    }

    protected function decodeBase64Payload(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'data:') && str_contains($value, 'base64,')) {
            $parts = explode('base64,', $value, 2);
            $value = $parts[1] ?? '';
        }

        $value = preg_replace('/\s+/', '', $value) ?? $value;
        if ($value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }

    protected function extractIncomingFilename(array $waMessageData): ?string
    {
        $candidates = [
            $waMessageData['filename'] ?? null,
            $waMessageData['fileName'] ?? null,
            $waMessageData['raw_data']['filename'] ?? null,
            $waMessageData['raw_data']['fileName'] ?? null,
            $waMessageData['raw_data']['file_name'] ?? null,
            $waMessageData['raw_data']['document']['filename'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function extractIncomingMimeType(array $waMessageData): ?string
    {
        $candidates = [
            $waMessageData['mimetype'] ?? null,
            $waMessageData['mime_type'] ?? null,
            $waMessageData['raw_data']['mimetype'] ?? null,
            $waMessageData['raw_data']['mime_type'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function extensionFromMimeType(string $mimeType): ?string
    {
        $mimeType = strtolower(trim($mimeType));
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
        ];

        return $map[$mimeType] ?? null;
    }

    protected function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return 'media';
        }

        $filename = preg_replace('/[^\w.\-]+/u', '_', $filename) ?? $filename;
        $filename = ltrim($filename, '._-');
        $filename = $filename === '' ? 'media' : $filename;

        return $filename;
    }
}
