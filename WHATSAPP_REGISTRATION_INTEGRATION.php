<?php

/**
 * INTEGRATION GUIDE: WhatsApp Registration Flow
 * 
 * Add this code to ProcessIncomingMessage.php at line 230 (before the existing unregistered user handling)
 */

// Import at top of file
use App\Helpers\WhatsAppRegistrationHelper as RegHelper;

// Replace the section starting at line 230 with this:

// CASE B: REGULAR PHONE NUMBER (Unregistered) - Handle registration flow
if (!$correctTenantId && !$isLID && $this->message->tenant_id == 1) {
    $messageText = trim($this->message->content ?? '');
    
    // Check if user is in registration flow
    if (RegHelper::isInRegistrationFlow($senderNumber)) {
        $currentStep = RegHelper::getCurrentStep($senderNumber);
        $regData = RegHelper::getRegistrationData($senderNumber);
        
        try {
            $ch = \App\Models\Channel::find($this->message->channel_id);
            $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
            $whatsappService = app(\App\Services\WhatsAppService::class);
            
            switch ($currentStep) {
                case 'awaiting_name':
                    // User sent their name
                    if (strlen($messageText) < 3) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "Nama terlalu pendek. Silakan kirim nama lengkap Anda:",
                            'text'
                        );
                        return;
                    }
                    
                    // Save name and ask for email
                    RegHelper::saveData($senderNumber, ['name' => $messageText]);
                    RegHelper::setStep($senderNumber, 'awaiting_email');
                    
                    $whatsappService->sendMessage(
                        $sessId, $this->message->sender_id,
                        RegHelper::getAskEmailMessage($messageText),
                        'text'
                    );
                    return;
                    
                case 'awaiting_email':
                    // User sent their email
                    if (!RegHelper::isValidEmail($messageText)) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "❌ Email tidak valid.\n\nSilakan kirim email yang benar (contoh: nama@gmail.com):",
                            'text'
                        );
                        return;
                    }
                    
                    // Check if email already exists
                    if (\App\Models\User::where('email', $messageText)->exists()) {
                        $whatsappService->sendMessage(
                            $sessId, $this->message->sender_id,
                            "❌ Email sudah terdaftar.\n\nSilakan gunakan email lain atau login di https://finwa.web.id",
                            'text'
                        );
                        RegHelper::clearFlow($senderNumber);
                        return;
                    }
                    
                    // Save email and create account
                    RegHelper::saveData($senderNumber, ['email' => $messageText]);
                    $regData = RegHelper::getRegistrationData($senderNumber);
                    
                    // Create account
                    $result = RegHelper::createAccount($regData);
                    
                    // Send success message
                    $whatsappService->sendMessage(
                        $sessId, $this->message->sender_id,
                        RegHelper::getSuccessMessage($result),
                        'text'
                    );
                    
                    // Clear registration flow
                    RegHelper::clearFlow($senderNumber);
                    
                    Log::info("WhatsApp Registration Success", [
                        'phone' => $senderNumber,
                        'email' => $result['user']->email,
                        'user_id' => $result['user']->id,
                    ]);
                    
                    return;
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp Registration Error: " . $e->getMessage());
            RegHelper::clearFlow($senderNumber);
        }
        
        return;
    }
    
    // Not in registration flow - check if user wants to register
    if (RegHelper::isConfirmation($messageText)) {
        // User wants to register
        RegHelper::startFlow($senderNumber);
        
        try {
            $ch = \App\Models\Channel::find($this->message->channel_id);
            $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
            
            app(\App\Services\WhatsAppService::class)->sendMessage(
                $sessId, $this->message->sender_id,
                RegHelper::getAskNameMessage(),
                'text'
            );
        } catch (\Exception $e) {
            Log::error("Failed to send registration start: " . $e->getMessage());
        }
        
        return;
    }
    
    if (RegHelper::isRejection($messageText)) {
        // User rejected registration
        try {
            $ch = \App\Models\Channel::find($this->message->channel_id);
            $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
            
            app(\App\Services\WhatsAppService::class)->sendMessage(
                $sessId, $this->message->sender_id,
                RegHelper::getCancellationMessage(),
                'text'
            );
        } catch (\Exception $e) {
            Log::error("Failed to send cancellation: " . $e->getMessage());
        }
        
        return;
    }
    
    // First time unregistered user - send welcome message
    try {
        $ch = \App\Models\Channel::find($this->message->channel_id);
        $sessId = $ch->config['session_id'] ?? "wa_{$ch->tenant_id}_{$ch->channel_account}";
        
        app(\App\Services\WhatsAppService::class)->sendMessage(
            $sessId, $this->message->sender_id,
            RegHelper::getWelcomeMessage(),
            'text'
        );
    } catch (\Exception $e) {
        Log::error("Failed to send welcome message: " . $e->getMessage());
    }
    
    return; // Don't process further
}
