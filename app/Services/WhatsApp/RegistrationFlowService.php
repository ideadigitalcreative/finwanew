<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Models\UserLidMapping;
use App\Models\UserTelegramMapping;
use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use App\Helpers\TelegramRegistrationHelper as TgReg;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RegistrationFlowService - Handles tenant routing, LID detection, and registration flow
 *
 * MOVED FROM: ProcessIncomingMessage::handle() — registration/LID block (~350 lines)
 */
class RegistrationFlowService
{
    protected Message $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    /**
     * Resolve tenant routing and handle registration flow.
     *
     * @return array{handled: bool, shouldContinue: bool}
     *   - handled=true, shouldContinue=false → message consumed (registration/LID), stop processing
     *   - handled=false → continue to processTextMessage etc.
     */
    public function resolve(): array
    {
        // TELEGRAM: gunakan resolusi khusus (via UserTelegramMapping), jangan
        // pernah masuk ke alur registrasi WhatsApp yang berbasis nomor telepon.
        if ($this->isTelegram()) {
            return $this->resolveTelegram();
        }

        $senderNumber = preg_replace('/[^0-9]/', '', $this->message->sender_id);

        if (empty($senderNumber)) {
            return ['handled' => false, 'shouldContinue' => true];
        }

        $correctTenantId = null;

        // 1. Check Primary User Registration (Highest Priority)
        $user = User::where('whatsapp_number', $senderNumber)->first();
        if ($user) {
            $correctTenantId = $user->tenant_id;
        } else {
            Log::warning('User NOT found in primary registration', [
                'searched_number' => $senderNumber
            ]);
        }

        // 2. Check UserWhatsAppNumber Mapping
        if (!$correctTenantId) {
            $mapping = UserWhatsAppNumber::where('whatsapp_number', $senderNumber)
                ->where('is_active', true)
                ->first();
            if ($mapping) {
                $correctTenantId = $mapping->tenant_id;
            }
        }

        // 3. Check UserLidMapping
        if (!$correctTenantId) {
            $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
            $originalLid = $metadata['original_sender_id'] ?? $this->message->sender_id;
            $cleanLid = preg_replace('/[^0-9]/', '', $originalLid); // strip non-numeric
            $lidMapping = UserLidMapping::where('lid', $cleanLid)->first();
            if ($lidMapping) {
                $correctTenantId = $lidMapping->tenant_id;
                Log::info('Found tenant from UserLidMapping in RegistrationFlowService', [
                    'sender_number' => $senderNumber,
                    'original_lid' => $originalLid,
                    'clean_lid' => $cleanLid,
                    'tenant_id' => $correctTenantId,
                ]);
            }
        }

        // 4. SECURITY & AUTO-LINKING
        if (!$correctTenantId) {
            $isLID = !preg_match('/^628[0-9]{8,13}$/', $senderNumber);

            $metadata = is_array($this->message->metadata) ? $this->message->metadata : json_decode($this->message->metadata ?? '{}', true);
            $originalLid = $metadata['original_sender_id'] ?? $this->message->sender_id;

            if ($isLID && $this->message->tenant_id == 1) {
                $existingLidMapping = UserWhatsAppNumber::where('whatsapp_number', $senderNumber)->first();

                if (!$existingLidMapping) {
                    // DO NOT AUTO-LINK - Too dangerous
                }
            }

            if (!$correctTenantId && $isLID) {
                $result = $this->handleLidUser($senderNumber, $originalLid);
                if ($result['handled']) {
                    return $result;
                }
            }

            // CASE B: REGULAR PHONE NUMBER (Unregistered)
            if (!$correctTenantId && !$isLID && $this->message->tenant_id == 1) {
                $result = $this->handleUnregisteredPhone($senderNumber);
                if ($result['handled']) {
                    return $result;
                }
            }
        }

        // 4. Apply Correction if Mismatch Detected
        if ($correctTenantId && $correctTenantId != $this->message->tenant_id) {
            Log::warning('CORRECTING TENANT ROUTING', [
                'message_id' => $this->message->id,
                'sender' => $senderNumber,
                'wrong_tenant_id' => $this->message->tenant_id,
                'correct_tenant_id' => $correctTenantId
            ]);

            $this->message->tenant_id = $correctTenantId;
            $this->message->save();
            $this->message->refresh();
        }

        return ['handled' => false, 'shouldContinue' => true];
    }

    /**
     * Apakah pesan ini berasal dari channel Telegram?
     */
    protected function isTelegram(): bool
    {
        return $this->message->channel === 'telegram';
    }

    /**
     * Resolusi user untuk pesan Telegram.
     *
     * sender_id pada pesan Telegram adalah chat/user id Telegram (bukan nomor telepon),
     * sehingga pencarian by whatsapp_number tidak berlaku. Kita cari mapping via
     * UserTelegramMapping lalu koreksi tenant_id agar pipeline transaksi berjalan
     * atas nama tenant yang benar.
     */
    protected function resolveTelegram(): array
    {
        $chatId = $this->message->sender_id;

        $mapping = UserTelegramMapping::where('telegram_chat_id', $chatId)
            ->where('is_active', true)
            ->first();

        // Belum tertaut: jalankan alur pendaftaran akun baru via Telegram.
        if (! $mapping) {
            return $this->handleTelegramRegistration($chatId);
        }

        // Koreksi tenant bila berbeda, agar transaksi tercatat pada tenant yang benar.
        if ($mapping->tenant_id && $mapping->tenant_id != $this->message->tenant_id) {
            $this->message->tenant_id = $mapping->tenant_id;
            $this->message->save();
            $this->message->refresh();
        }

        return ['handled' => false, 'shouldContinue' => true];
    }

    /**
     * Kirim balasan teks langsung ke chat Telegram.
     */
    protected function sendTelegramReply(string $text): void
    {
        try {
            app(TelegramService::class)->sendMessage(
                $this->message->sender_id,
                $text,
                ['parse_mode' => 'Markdown']
            );
        } catch (\Exception $e) {
            Log::error('Gagal mengirim balasan Telegram: ' . $e->getMessage(), [
                'chat_id' => $this->message->sender_id,
            ]);
        }
    }

    /**
     * Alur pendaftaran akun baru via Telegram untuk chat yang belum tertaut.
     */
    protected function handleTelegramRegistration(int|string $chatId): array
    {
        $text = trim($this->message->content ?? '');

        // 1. Sedang dalam alur pendaftaran → proses langkah berikutnya.
        if (TgReg::isInRegistrationFlow($chatId)) {
            $this->processTelegramRegistrationStep($chatId, $text);

            return ['handled' => true, 'shouldContinue' => false];
        }

        // 2. Pemicu pendaftaran: /start, /start register, "daftar", atau konfirmasi.
        $isTrigger = Str::startsWith($text, '/start')
            || TgReg::isConfirmation($text)
            || strtolower($text) === 'daftar';

        if ($isTrigger) {
            TgReg::startFlow($chatId);
            $this->sendTelegramReply(TgReg::getAskNameMessage());

            return ['handled' => true, 'shouldContinue' => false];
        }

        // 3. Pesan lain dari user yang belum terdaftar → sambutan + ajakan daftar.
        $this->sendTelegramReply(
            "👋 *Halo!* Anda belum terdaftar di FinWa.\n\n" .
            "Ketik *daftar* untuk membuat akun gratis, atau tekan /start."
        );

        return ['handled' => true, 'shouldContinue' => false];
    }

    /**
     * Proses satu langkah pendaftaran Telegram (nama → email → buat akun).
     */
    protected function processTelegramRegistrationStep(int|string $chatId, string $text): void
    {
        $step = TgReg::getCurrentStep($chatId);

        // Abaikan pemicu /start berulang saat sudah di dalam alur.
        if (Str::startsWith($text, '/start')) {
            $this->sendTelegramReply(
                $step === 'awaiting_email'
                    ? 'Silakan kirim *alamat email* Anda:'
                    : TgReg::getAskNameMessage()
            );

            return;
        }

        try {
            switch ($step) {
                case 'awaiting_name':
                    $cleanName = trim(preg_replace('/[[:^print:]]/', '', $text) ?? $text);

                    if (mb_strlen($cleanName) < 2) {
                        $this->sendTelegramReply('Nama terlalu pendek. Silakan kirim *nama lengkap* Anda:');

                        return;
                    }

                    TgReg::saveData($chatId, ['name' => $cleanName]);
                    TgReg::setStep($chatId, 'awaiting_email');
                    $this->sendTelegramReply(TgReg::getAskEmailMessage($cleanName));

                    return;

                case 'awaiting_email':
                    $email = trim(preg_replace('/[[:^print:]]/', '', $text) ?? $text);

                    if (! TgReg::isValidEmail($email)) {
                        $this->sendTelegramReply(
                            "❌ Email tidak valid.\n\nSilakan kirim email yang benar (contoh: nama@gmail.com):"
                        );

                        return;
                    }

                    if (User::where('email', $email)->exists()) {
                        $this->sendTelegramReply(
                            "❌ Email sudah terdaftar.\n\nGunakan email lain, atau login di https://finwa.web.id"
                        );

                        return;
                    }

                    TgReg::saveData($chatId, ['email' => $email]);
                    $regData = TgReg::getRegistrationData($chatId);

                    if (empty($regData['name'])) {
                        TgReg::setStep($chatId, 'awaiting_name');
                        $this->sendTelegramReply(TgReg::getAskNameMessage());

                        return;
                    }

                    $raw = is_array($this->message->raw_data)
                        ? $this->message->raw_data
                        : json_decode($this->message->raw_data ?? '{}', true);
                    $from = $raw['from'] ?? [];

                    $result = TgReg::createAccount($regData, [
                        'username' => $from['username'] ?? null,
                        'first_name' => $from['first_name'] ?? null,
                        'last_name' => $from['last_name'] ?? null,
                    ]);

                    $this->sendTelegramReply(TgReg::getSuccessMessage($result));
                    TgReg::clearFlow($chatId);

                    return;
            }
        } catch (\Exception $e) {
            Log::error('Telegram Registration Error: ' . $e->getMessage(), ['chat_id' => $chatId]);
            TgReg::clearFlow($chatId);
            $this->sendTelegramReply('❌ Terjadi kesalahan saat mendaftar. Silakan coba lagi dengan ketik *daftar*.');
        }
    }

    protected function handleLidUser(string $senderNumber, string $originalLid): array
    {
        $text = trim($this->message->content ?? '');

        // If it's a LINK command (either token or phone), don't handle here - let the dedicated LINK handler process it
        if (preg_match('/^link\s+/i', $text)) {
            return ['handled' => false, 'shouldContinue' => true];
        }

        if (RegHelper::isConfirmation($text) || RegHelper::isInRegistrationFlow($senderNumber)) {
            if (RegHelper::isInRegistrationFlow($senderNumber)) {
                $result = $this->processLidRegistrationStep($senderNumber, $text, $originalLid);
                if ($result) {
                    return ['handled' => true, 'shouldContinue' => false];
                }
            }

            RegHelper::startFlow($senderNumber);

            try {
                $sessId = $this->getSessionId();
                app(\App\Services\WhatsAppService::class)->sendMessage(
                    $sessId, $this->message->sender_id,
                    RegHelper::getAskNameMessage(),
                    'text', $originalLid
                );
            } catch (\Exception $e) {
                Log::error("Failed to send LID registration start: " . $e->getMessage());
            }

            return ['handled' => true, 'shouldContinue' => false];
        }

        // Normalize phone number input
        $tryPhone = preg_replace('/[^0-9]/', '', $text);
        if (preg_match('/^0/', $tryPhone)) {
            $tryPhone = '62' . substr($tryPhone, 1);
        } elseif (preg_match('/^8/', $tryPhone) && strlen($tryPhone) >= 9) {
            $tryPhone = '62' . $tryPhone;
        }

        // IF VERIFICATION ATTEMPT
        if (preg_match('/^62[89][0-9]{8,12}$/', $tryPhone) && strlen($text) < 20) {
            $targetUser = User::where('whatsapp_number', $tryPhone)->first();

            if ($targetUser) {
                UserWhatsAppNumber::create([
                    'user_id' => $targetUser->id,
                    'tenant_id' => $targetUser->tenant_id,
                    'whatsapp_number' => $senderNumber,
                    'name' => 'LID - ' . substr($senderNumber, 0, 8),
                    'is_primary' => false,
                    'is_active' => true,
                    'is_lid' => true
                ]);

                try {
                    $sessId = $this->getSessionId();
                    app(\App\Services\WhatsAppService::class)->sendMessage(
                        $sessId, $this->message->sender_id,
                        "✅ *Akun Terhubung!*\nAnda telah terhubung ke akun *{$targetUser->name}*.\n\nSilakan kirim ulang transaksi Anda.",
                        'text', $originalLid
                    );
                } catch (\Exception $e) {
                    Log::error("Failed to send LID link success: " . $e->getMessage());
                }

                $this->message->tenant_id = $targetUser->tenant_id;
                $this->message->save();
                return ['handled' => true, 'shouldContinue' => false];
            } else {
                try {
                    $sessId = $this->getSessionId();
                    app(\App\Services\WhatsAppService::class)->sendMessage(
                        $sessId, $this->message->sender_id,
                        "❌ Nomor WA *$tryPhone* belum terdaftar.\nSilakan daftar di website terlebih dahulu.",
                        'text', $originalLid
                    );
                } catch (\Exception $e) {
                    Log::error("Failed to send LID not found: " . $e->getMessage());
                }
                return ['handled' => true, 'shouldContinue' => false];
            }
        }

        // IF UNKNOWN LID -> SEND CHALLENGE with registration option
        if ($this->message->tenant_id == 1) {
            try {
                $sessId = $this->getSessionId();
                app(\App\Services\WhatsAppService::class)->sendMessage(
                    $sessId, $this->message->sender_id,
                    "👋 Halo! Anda belum terdaftar.\n\nKetik *DAFTAR* untuk buat akun gratis ✅",
                    'text', $originalLid
                );
            } catch (\Exception $e) {
                Log::error("Failed to send LID challenge: " . $e->getMessage());
            }
            return ['handled' => true, 'shouldContinue' => false];
        }

        return ['handled' => false, 'shouldContinue' => true];
    }

    protected function processLidRegistrationStep(string $senderNumber, string $messageText, string $originalLid): bool
    {
        $currentStep = RegHelper::getCurrentStep($senderNumber);

        try {
            $sessId = $this->getSessionId();
            $whatsappService = app(\App\Services\WhatsAppService::class);

            switch ($currentStep) {
                case 'awaiting_name':
                    // Clean message thoroughly from invisible/non-printable characters
                    $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $messageText);
                    $cleanName = ($cleaned === null) ? $messageText : $cleaned;
                    $cleanName = preg_replace('/[[:^print:]]/', '', $cleanName);
                    $cleanName = trim($cleanName);

                    /*
                    if (mb_strlen($cleanName) < 3) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "Nama terlalu pendek. Silakan kirim nama lengkap Anda:",
                            'text', $originalLid
                        );
                        return true;
                    }
                    */

                    RegHelper::saveData($senderNumber, ['name' => $cleanName]);
                    RegHelper::setStep($senderNumber, 'awaiting_email');

                    $whatsappService->sendMessage(
                        $sessId, $this->message->sender_id,
                        RegHelper::getAskEmailMessage($cleanName),
                        'text', $originalLid
                    );
                    return true;

                case 'awaiting_email':
                    // Clean email thoroughly
                    $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $messageText);
                    $email = ($cleaned === null) ? $messageText : $cleaned;
                    $email = preg_replace('/[[:^print:]]/', '', $email);
                    $email = trim($email);

                    if (!RegHelper::isValidEmail($email)) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "❌ Email tidak valid.\n\nSilakan kirim email yang benar (contoh: nama@gmail.com):",
                            'text', $originalLid
                        );
                        return true;
                    }

                    if (User::where('email', $email)->exists()) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "❌ Email sudah terdaftar.\n\nSilakan gunakan email lain atau login di https://finwa.web.id",
                            'text', $originalLid
                        );
                        RegHelper::clearFlow($senderNumber);
                        return true;
                    }

                    RegHelper::saveData($senderNumber, ['email' => $email]);
                    $regData = RegHelper::getRegistrationData($senderNumber);
                    if (empty($regData['name'])) {
                        RegHelper::setStep($senderNumber, 'awaiting_name');
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            RegHelper::getAskNameMessage(),
                            'text', $originalLid
                        );
                        return true;
                    }
                    $result = RegHelper::createAccount($regData);

                    $existingLidMapping = UserWhatsAppNumber::where('user_id', $result['user']->id)
                        ->where('whatsapp_number', $senderNumber)
                        ->first();

                    if (!$existingLidMapping) {
                        UserWhatsAppNumber::create([
                            'user_id' => $result['user']->id,
                            'tenant_id' => $result['tenant']->id,
                            'whatsapp_number' => $senderNumber,
                            'name' => 'LID - Registered',
                            'is_primary' => false,
                            'is_active' => true,
                            'is_lid' => true
                        ]);
                    }

                    try {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            RegHelper::getSuccessMessage($result),
                            'text', $originalLid
                        );
                    } catch (\Exception $sendError) {
                        Log::error("Failed to send success message to LID: " . $sendError->getMessage(), [
                            'lid' => $senderNumber,
                            'email' => $result['user']->email,
                        ]);
                    }

                    RegHelper::clearFlow($senderNumber);
                    return true;
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp LID Registration Error: " . $e->getMessage());
            RegHelper::clearFlow($senderNumber);
        }

        return true;
    }

    protected function handleUnregisteredPhone(string $senderNumber): array
    {
        $messageText = trim($this->message->content ?? '');

        if (RegHelper::isInRegistrationFlow($senderNumber)) {
            $result = $this->processPhoneRegistrationStep($senderNumber, $messageText);
            if ($result) {
                return ['handled' => true, 'shouldContinue' => false];
            }
        }

        if (RegHelper::isConfirmation($messageText)) {
            RegHelper::startFlow($senderNumber);

            try {
                $sessId = $this->getSessionId();
                app(\App\Services\WhatsAppService::class)->sendMessage(
                    $sessId, $this->message->sender_id,
                    RegHelper::getAskNameMessage(),
                    'text'
                );
            } catch (\Exception $e) {
                Log::error("Failed to send registration start: " . $e->getMessage());
            }

            return ['handled' => true, 'shouldContinue' => false];
        }

        if (RegHelper::isRejection($messageText)) {
            try {
                $sessId = $this->getSessionId();
                app(\App\Services\WhatsAppService::class)->sendMessage(
                    $sessId, $this->message->sender_id,
                    RegHelper::getCancellationMessage(),
                    'text'
                );
            } catch (\Exception $e) {
                Log::error("Failed to send cancellation: " . $e->getMessage());
            }

            return ['handled' => true, 'shouldContinue' => false];
        }

        // First time unregistered user - send welcome message
        try {
            $sessId = $this->getSessionId();
            app(\App\Services\WhatsAppService::class)->sendMessage(
                $sessId, $this->message->sender_id,
                RegHelper::getWelcomeMessage(),
                'text'
            );
        } catch (\Exception $e) {
            Log::error("Failed to send welcome message: " . $e->getMessage());
        }

        return ['handled' => true, 'shouldContinue' => false];
    }

    protected function processPhoneRegistrationStep(string $senderNumber, string $messageText): bool
    {
        $currentStep = RegHelper::getCurrentStep($senderNumber);

        try {
            $sessId = $this->getSessionId();
            $whatsappService = app(\App\Services\WhatsAppService::class);

            switch ($currentStep) {
                case 'awaiting_name':
                    // Clean message thoroughly from invisible/non-printable characters
                    $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $messageText);
                    $cleanName = ($cleaned === null) ? $messageText : $cleaned;
                    $cleanName = preg_replace('/[[:^print:]]/', '', $cleanName);
                    $cleanName = trim($cleanName);

                    /*
                    if (mb_strlen($cleanName) < 3) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "Nama terlalu pendek. Silakan kirim nama lengkap Anda:",
                            'text'
                        );
                        return true;
                    }
                    */

                    RegHelper::saveData($senderNumber, ['name' => $cleanName]);
                    RegHelper::setStep($senderNumber, 'awaiting_email');

                    $whatsappService->sendMessage(
                        $sessId, $this->message->sender_id,
                        RegHelper::getAskEmailMessage($cleanName),
                        'text'
                    );
                    return true;

                case 'awaiting_email':
                    // Clean email thoroughly
                    $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $messageText);
                    $email = ($cleaned === null) ? $messageText : $cleaned;
                    $email = preg_replace('/[[:^print:]]/', '', $email);
                    $email = trim($email);

                    if (!RegHelper::isValidEmail($email)) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "❌ Email tidak valid.\n\nSilakan kirim email yang benar (contoh: nama@gmail.com):",
                            'text'
                        );
                        return true;
                    }

                    if (User::where('email', $email)->exists()) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "❌ Email sudah terdaftar.\n\nSilakan gunakan email lain atau login di https://finwa.web.id",
                            'text'
                        );
                        RegHelper::clearFlow($senderNumber);
                        return true;
                    }

                    RegHelper::saveData($senderNumber, ['email' => $email]);
                    $regData = RegHelper::getRegistrationData($senderNumber);
                    if (empty($regData['name'])) {
                        RegHelper::setStep($senderNumber, 'awaiting_name');
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            RegHelper::getAskNameMessage(),
                            'text'
                        );
                        return true;
                    }
                    $result = RegHelper::createAccount($regData);

                    try {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            RegHelper::getSuccessMessage($result),
                            'text'
                        );
                    } catch (\Exception $sendError) {
                        Log::error("Failed to send success message: " . $sendError->getMessage(), [
                            'phone' => $senderNumber,
                            'email' => $result['user']->email,
                        ]);
                    }

                    RegHelper::clearFlow($senderNumber);
                    return true;
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp Registration Error: " . $e->getMessage());
            RegHelper::clearFlow($senderNumber);
        }

        return true;
    }

    protected function getSessionId(): string
    {
        $ch = Channel::find($this->message->channel_id);
        return $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
    }
}
