<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserLidMapping;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk mapping nomor WhatsApp ke user/tenant
 * Digunakan untuk routing pesan dari shared channel ke user yang sesuai
 */
class WhatsAppUserMappingService
{
    /**
     * Clean phone number untuk matching
     * Format: hapus semua karakter non-numeric, normalisasi format
     * Supports: @c.us, @g.us, @lid (WhatsApp Linked ID format)
     */
    public function cleanPhoneNumber(string $phoneNumber): string
    {
        // Remove @c.us, @g.us, @lid suffix (WhatsApp formats)
        $cleaned = str_replace(['@c.us', '@g.us', '@lid'], '', $phoneNumber);

        // Handle LID format (WhatsApp Linked ID) - format: XXXXXXXXXXX@lid
        // LID numbers may have different format, extract numeric part
        if (preg_match('/^(\d+)/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $cleaned);

        // If the number is too long (LID format can have extra digits), try to extract phone number
        // Indonesian numbers are typically 10-13 digits starting with 62 or 08
        if (strlen($cleaned) > 15) {
            // Try to extract Indonesian number pattern (62XXXXXXXXXX or 08XXXXXXXXXX)
            if (preg_match('/(62\d{9,12})/', $cleaned, $matches)) {
                $cleaned = $matches[1];
            } elseif (preg_match('/(0\d{9,12})/', $cleaned, $matches)) {
                $cleaned = '62'.substr($matches[1], 1);
            }
        }

        // Remove leading + if exists
        $cleaned = ltrim($cleaned, '+');

        // If starts with 0, replace with 62
        if (strlen($cleaned) >= 10 && substr($cleaned, 0, 1) === '0') {
            $cleaned = '62'.substr($cleaned, 1);
        }

        // If doesn't start with 62 and looks like Indonesian number, add 62
        // Only add 62 if the number is reasonable length (10-13 digits without country code)
        if (strlen($cleaned) >= 9 && strlen($cleaned) <= 13 && substr($cleaned, 0, 2) !== '62') {
            $cleaned = '62'.$cleaned;
        }

        return $cleaned;
    }

    /**
     * Find user by WhatsApp number
     * Returns user dengan tenant_id yang sesuai
     * Support multiple numbers dari tabel user_whatsapp_numbers
     */
    public function findUserByWhatsAppNumber(string $phoneNumber): ?User
    {
        $cleanedNumber = $this->cleanPhoneNumber($phoneNumber);

        // Check if this is a LID format (Linked ID from Baileys)
        // LID typically doesn't start with country code and may have different length
        $isLikelyLid = ! str_starts_with($cleanedNumber, '62') && strlen($cleanedNumber) > 10;

        if ($isLikelyLid) {
            // Try to find from LID mapping first
            $lidMapping = UserLidMapping::findByLid($cleanedNumber);
            if ($lidMapping) {
                $user = User::find($lidMapping->user_id);
                if ($user) {
                    Log::info('User found by LID mapping', [
                        'user_id' => $user->id,
                        'lid' => $cleanedNumber,
                        'phone_number' => $lidMapping->phone_number,
                        'tenant_id' => $lidMapping->tenant_id,
                    ]);

                    return $user;
                }
            }
        }

        // First, try to find from user_whatsapp_numbers table (new system)
        $userWhatsAppNumber = \App\Models\UserWhatsAppNumber::where('is_active', true)
            ->get()
            ->first(function ($number) use ($cleanedNumber) {
                $numberCleaned = $this->cleanPhoneNumber($number->whatsapp_number);

                return $numberCleaned === $cleanedNumber;
            });

        if ($userWhatsAppNumber) {
            $user = User::find($userWhatsAppNumber->user_id);
            if ($user) {
                Log::info('User found by WhatsApp number (from user_whatsapp_numbers)', [
                    'user_id' => $user->id,
                    'phone_number' => $phoneNumber,
                    'cleaned_number' => $cleanedNumber,
                    'user_whatsapp_number_id' => $userWhatsAppNumber->id,
                    'tenant_id' => $userWhatsAppNumber->tenant_id,
                ]);

                return $user;
            }
        }

        // Fallback: Cari user berdasarkan whatsapp_number field (old system)
        $user = User::whereNotNull('whatsapp_number')
            ->get()
            ->first(function ($user) use ($cleanedNumber) {
                if (! $user->whatsapp_number) {
                    return false;
                }

                $userNumber = $this->cleanPhoneNumber($user->whatsapp_number);

                return $userNumber === $cleanedNumber;
            });

        if ($user) {
            Log::info('User found by WhatsApp number (from users.whatsapp_number)', [
                'user_id' => $user->id,
                'phone_number' => $phoneNumber,
                'cleaned_number' => $cleanedNumber,
                'user_whatsapp_number' => $user->whatsapp_number,
                'tenant_id' => $user->tenant_id,
            ]);
        } else {
            Log::warning('User not found by WhatsApp number', [
                'phone_number' => $phoneNumber,
                'cleaned_number' => $cleanedNumber,
            ]);
        }

        return $user;
    }

    /**
     * Check if WhatsApp number is registered
     * Returns true if number exists in user_whatsapp_numbers or users.whatsapp_number
     */
    public function isWhatsAppNumberRegistered(string $phoneNumber): bool
    {
        $cleanedNumber = $this->cleanPhoneNumber($phoneNumber);

        // Check in user_whatsapp_numbers table (new system)
        $userWhatsAppNumber = \App\Models\UserWhatsAppNumber::where('is_active', true)
            ->get()
            ->first(function ($number) use ($cleanedNumber) {
                $numberCleaned = $this->cleanPhoneNumber($number->whatsapp_number);

                return $numberCleaned === $cleanedNumber;
            });

        if ($userWhatsAppNumber) {
            return true;
        }

        // Check in users.whatsapp_number (old system)
        $user = User::whereNotNull('whatsapp_number')
            ->get()
            ->first(function ($user) use ($cleanedNumber) {
                if (! $user->whatsapp_number) {
                    return false;
                }
                $userNumber = $this->cleanPhoneNumber($user->whatsapp_number);

                return $userNumber === $cleanedNumber;
            });

        return $user !== null;
    }

    /**
     * Get tenant_id dari nomor WhatsApp
     * Priority:
     * 1. UserWhatsAppNumber dengan nomor yang match (dari tabel user_whatsapp_numbers)
     * 2. User dengan whatsapp_number yang match (dari field users.whatsapp_number)
     * 3. Returns null jika tidak ditemukan (untuk validasi)
     */
    public function getTenantIdFromWhatsAppNumber(string $phoneNumber, ?int $defaultTenantId = null): ?int
    {
        $cleanedNumber = $this->cleanPhoneNumber($phoneNumber);

        // First, try to find from user_whatsapp_numbers table (new system)
        $userWhatsAppNumber = \App\Models\UserWhatsAppNumber::where('is_active', true)
            ->get()
            ->first(function ($number) use ($cleanedNumber) {
                $numberCleaned = $this->cleanPhoneNumber($number->whatsapp_number);

                return $numberCleaned === $cleanedNumber;
            });

        if ($userWhatsAppNumber) {
            Log::info('Tenant found from user_whatsapp_numbers', [
                'phone_number' => $phoneNumber,
                'cleaned_number' => $cleanedNumber,
                'tenant_id' => $userWhatsAppNumber->tenant_id,
                'user_id' => $userWhatsAppNumber->user_id,
            ]);

            return $userWhatsAppNumber->tenant_id;
        }

        // Fallback: try from user model
        $user = $this->findUserByWhatsAppNumber($phoneNumber);

        if ($user) {
            // Jika user punya tenant_id, gunakan itu
            if ($user->tenant_id) {
                return $user->tenant_id;
            }

            // Jika user punya active tenants, ambil yang pertama
            if (method_exists($user, 'activeTenants')) {
                $activeTenant = $user->activeTenants()->first();
                if ($activeTenant) {
                    return $activeTenant->id;
                }
            }
        }

        // Return null jika tidak ditemukan (untuk validasi)
        Log::warning('WhatsApp number not registered', [
            'phone_number' => $phoneNumber,
            'cleaned_number' => $cleanedNumber,
        ]);

        return null;
    }

    /**
     * Check if channel is shared channel
     */
    public function isSharedChannel(int $channelId): bool
    {
        $channel = \App\Models\Channel::find($channelId);

        return $channel && $channel->is_shared_channel === true;
    }

    /**
     * Get shared WhatsApp channel (admin channel)
     * Returns first active shared WhatsApp channel
     */
    public function getSharedWhatsAppChannel(): ?\App\Models\Channel
    {
        return \App\Models\Channel::where('type', 'whatsapp')
            ->where('is_shared_channel', true)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(config, '$.session_status')) = 'connected' THEN 0 ELSE 1 END")
            ->orderBy('id', 'desc')
            ->first();
    }
}
