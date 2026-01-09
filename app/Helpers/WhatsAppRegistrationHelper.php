<?php

namespace App\Helpers;

use App\Models\User;
use App\Models\Tenant;
use App\Models\UserWhatsAppNumber;
use App\Models\Subscription;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class WhatsAppRegistrationHelper
{
    /**
     * Check if user is in registration flow
     */
    public static function isInRegistrationFlow(string $phoneNumber): bool
    {
        return Cache::has("wa_reg_flow:{$phoneNumber}");
    }

    /**
     * Get current registration step
     */
    public static function getCurrentStep(string $phoneNumber): ?string
    {
        return Cache::get("wa_reg_flow:{$phoneNumber}");
    }

    /**
     * Get registration data
     */
    public static function getRegistrationData(string $phoneNumber): array
    {
        return Cache::get("wa_reg_data:{$phoneNumber}", []);
    }

    /**
     * Set registration step
     */
    public static function setStep(string $phoneNumber, string $step): void
    {
        Cache::put("wa_reg_flow:{$phoneNumber}", $step, now()->addMinutes(30));
    }

    /**
     * Save registration data
     */
    public static function saveData(string $phoneNumber, array $data): void
    {
        $existing = self::getRegistrationData($phoneNumber);
        $merged = array_merge($existing, $data);
        Cache::put("wa_reg_data:{$phoneNumber}", $merged, now()->addMinutes(30));
    }

    /**
     * Clear registration flow
     */
    public static function clearFlow(string $phoneNumber): void
    {
        Cache::forget("wa_reg_flow:{$phoneNumber}");
        Cache::forget("wa_reg_data:{$phoneNumber}");
    }

    /**
     * Start registration flow
     */
    public static function startFlow(string $phoneNumber): void
    {
        self::setStep($phoneNumber, 'awaiting_name');
        self::saveData($phoneNumber, ['phone' => $phoneNumber]);
    }

    /**
     * Check if message is confirmation (yes/ya/ok/daftar)
     */
    public static function isConfirmation(string $message): bool
    {
        $normalized = strtolower(trim($message));
        $confirmWords = ['ya', 'iya', 'ok', 'oke', 'daftar', 'yes', 'yup', 'yoi', 'boleh', 'mau'];
        
        return in_array($normalized, $confirmWords);
    }

    /**
     * Check if message is rejection (no/tidak/cancel)
     */
    public static function isRejection(string $message): bool
    {
        $normalized = strtolower(trim($message));
        $rejectWords = ['tidak', 'nggak', 'no', 'gak', 'cancel', 'batal', 'engga', 'enggak'];
        
        return in_array($normalized, $rejectWords);
    }

    /**
     * Validate email format
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Generate random password
     */
    public static function generatePassword(): string
    {
        // Generate easy to type password: 3 letters + 3 numbers
        $letters = strtoupper(Str::random(3));
        $numbers = rand(100, 999);
        return $letters . $numbers;
    }

    /**
     * Create user account from registration data
     */
    public static function createAccount(array $data): array
    {
        $password = self::generatePassword();
        
        // Generate slug from name
        $slug = Str::slug($data['name']) . '-' . Str::random(6);
        
        // Create tenant
        $tenant = Tenant::create([
            'name' => $data['name'] . "'s Business",
            'slug' => $slug,
            'is_active' => true,
            'trial_ends_at' => Carbon::now()->addDays(3),
        ]);

        // Create default wallet for new tenant
        \App\Models\Balance::create([
            'tenant_id' => $tenant->id,
            'account_name' => 'Dompet Utama',
            'account_type' => 'cash',
            'currency' => 'IDR',
            'balance' => 0,
            'balance_date' => now(),
            'is_active' => true,
            'is_default' => true,
        ]);

        // Get owner role ID
        $ownerRole = \DB::table('roles')->where('name', 'owner')->first();
        $roleId = $ownerRole ? $ownerRole->id : null;

        // Create user
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'whatsapp_number' => $data['phone'],
            'tenant_id' => $tenant->id,
            'role_id' => $roleId,
            'email_verified_at' => now(), // Auto-verify
        ]);

        // Create pivot table entry for user-tenant relationship
        \DB::table('user_tenants')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => $roleId,
            'is_active' => true,
            'joined_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Create WhatsApp number mapping
        UserWhatsAppNumber::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'whatsapp_number' => $data['phone'],
            'name' => 'Primary Number',
            'is_primary' => true,
            'is_active' => true,
            'is_lid' => false,
        ]);

        // Create free trial subscription
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => 'free',  // Changed from 'trial' to 'free'
            'duration_months' => 1,
            'price' => 0,
            'status' => 'active',
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now()->addDays(3),
            'payment_provider' => 'free_trial',
            'metadata' => [
                'registered_via' => 'whatsapp',
                'registered_at' => Carbon::now()->toIso8601String(),
            ],
        ]);

        return [
            'user' => $user,
            'tenant' => $tenant,
            'password' => $password,
        ];
    }

    /**
     * Get welcome message for unregistered user
     */
    public static function getWelcomeMessage(): string
    {
        return "👋 *Halo!*\n\n"
            . "Sepertinya Anda belum terdaftar di FinWa.\n\n"
            . "Mau daftar sekarang? Gratis trial 3 hari! 🎉\n\n"
            . "Ketik *Ya* untuk daftar atau *Tidak* untuk batal.";
    }

    /**
     * Get ask name message
     */
    public static function getAskNameMessage(): string
    {
        return "✅ Oke, mari kita daftar!\n\n"
            . "Silakan kirim *nama lengkap* Anda:";
    }

    /**
     * Get ask email message
     */
    public static function getAskEmailMessage(string $name): string
    {
        return "Terima kasih, *{$name}*! 👍\n\n"
            . "Sekarang silakan kirim *alamat email* Anda:";
    }

    /**
     * Get success message
     */
    public static function getSuccessMessage(array $result): string
    {
        return "🎉 *Akun Berhasil Dibuat!*\n\n"
            . "📧 Email: *{$result['user']->email}*\n"
            . "🔑 Password: *{$result['password']}*\n\n"
            . "🌐 Login di: https://finwa.web.id/login\n\n"
            . "✨ Trial 3 hari sudah aktif!\n\n"
            . "Sekarang Anda bisa langsung kirim transaksi ke saya. Contoh:\n"
            . "• _beli makan 25rb_\n"   
            . "• _terima gaji 5jt_\n\n"
            . "💡 Ketik *help* untuk panduan singkat\n"
            . "📖 Panduan lengkap: https://finwa.web.id/panduan-umkm\n\n"
            . "Selamat mencoba! 🚀";
    }

    /**
     * Get cancellation message
     */
    public static function getCancellationMessage(): string
    {
        return "Baik, pendaftaran dibatalkan.\n\n"
            . "Jika berubah pikiran, kirim pesan lagi ya! 😊";
    }
}
