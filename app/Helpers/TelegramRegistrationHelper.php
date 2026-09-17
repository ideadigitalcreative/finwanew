<?php

namespace App\Helpers;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTelegramMapping;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TelegramRegistrationHelper - Alur pendaftaran akun baru via Telegram.
 *
 * Berbeda dari WhatsApp: identitas user adalah telegram_chat_id (bukan nomor telepon),
 * sehingga akun dibuat tanpa whatsapp_number dan ditautkan melalui UserTelegramMapping.
 * State langkah disimpan di cache dengan kunci berbasis chat_id.
 */
class TelegramRegistrationHelper
{
    public static function isInRegistrationFlow(int|string $chatId): bool
    {
        return Cache::has("tg_reg_flow:{$chatId}");
    }

    public static function getCurrentStep(int|string $chatId): ?string
    {
        $step = Cache::get("tg_reg_flow:{$chatId}");

        return is_string($step) && $step !== '' ? $step : null;
    }

    public static function getRegistrationData(int|string $chatId): array
    {
        $data = Cache::get("tg_reg_data:{$chatId}", []);

        return is_array($data) ? $data : [];
    }

    public static function setStep(int|string $chatId, string $step): void
    {
        Cache::put("tg_reg_flow:{$chatId}", $step, now()->addHours(24));
    }

    public static function saveData(int|string $chatId, array $data): void
    {
        $existing = self::getRegistrationData($chatId);
        Cache::put("tg_reg_data:{$chatId}", array_merge($existing, $data), now()->addHours(24));
    }

    public static function clearFlow(int|string $chatId): void
    {
        Cache::forget("tg_reg_flow:{$chatId}");
        Cache::forget("tg_reg_data:{$chatId}");
    }

    public static function startFlow(int|string $chatId): void
    {
        self::setStep($chatId, 'awaiting_name');
        self::saveData($chatId, ['chat_id' => (string) $chatId]);
    }

    // Reuse validator generik dari helper WhatsApp agar konsisten.
    public static function isValidEmail(string $email): bool
    {
        return WhatsAppRegistrationHelper::isValidEmail($email);
    }

    public static function isConfirmation(string $message): bool
    {
        return WhatsAppRegistrationHelper::isConfirmation($message);
    }

    public static function isRejection(string $message): bool
    {
        return WhatsAppRegistrationHelper::isRejection($message);
    }

    public static function generatePassword(): string
    {
        return WhatsAppRegistrationHelper::generatePassword();
    }

    /**
     * Buat akun baru dari data registrasi Telegram, lalu tautkan chat Telegram.
     *
     * @param array{name:string,email:string,chat_id:string} $data
     * @param array{username?:?string,first_name?:?string,last_name?:?string} $tg
     * @return array{user:User,tenant:Tenant,password:string}
     */
    public static function createAccount(array $data, array $tg = []): array
    {
        $password = self::generatePassword();
        $slug = Str::slug($data['name']).'-'.Str::random(6);

        $tenant = Tenant::create([
            'name' => $data['name']."'s Business",
            'slug' => $slug,
            'is_active' => true,
            'trial_ends_at' => null,
        ]);

        app(\App\Services\TenantProvisioningService::class)
            ->ensureDefaultWallet($tenant->id, 'telegram_registration');

        $ownerRole = DB::table('roles')->where('name', 'owner')->first();
        $roleId = $ownerRole ? $ownerRole->id : null;

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($password),
            'telegram_chat_id' => $data['chat_id'],
            'telegram_username' => $tg['username'] ?? null,
            'tenant_id' => $tenant->id,
            'role_id' => $roleId,
            'email_verified_at' => now(),
        ]);

        DB::table('user_tenants')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => $roleId,
            'is_active' => true,
            'joined_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        UserTelegramMapping::linkTelegramToUser(
            userId: $user->id,
            tenantId: $tenant->id,
            telegramChatId: $data['chat_id'],
            username: $tg['username'] ?? null,
            firstName: $tg['first_name'] ?? null,
            lastName: $tg['last_name'] ?? null,
        );

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => 'free',
            'duration_months' => 0,
            'price' => 0,
            'status' => 'active',
            'starts_at' => Carbon::now(),
            'ends_at' => null,
            'payment_provider' => 'internal',
            'metadata' => [
                'registered_via' => 'telegram',
                'registered_at' => Carbon::now()->toIso8601String(),
                'is_free_plan' => true,
            ],
        ]);

        app(\App\Services\Category\CategoryManagerService::class)
            ->createCategoriesForTenant($tenant->id);

        return [
            'user' => $user,
            'tenant' => $tenant,
            'password' => $password,
        ];
    }

    public static function getAskNameMessage(): string
    {
        return "👋 *Selamat datang di FinWa!*\n\n"
            ."Saya asisten pencatat keuangan Anda. Pendaftaran singkat dan gratis.\n\n"
            .'📝 *Ketik nama lengkap Anda* (contoh: Budi Santoso)';
    }

    public static function getAskEmailMessage(string $name): string
    {
        return "Terima kasih, *{$name}*! 👍\n\n"
            .'Sekarang silakan kirim *alamat email* Anda:';
    }

    public static function getSuccessMessage(array $result): string
    {
        return "🎉 *Akun Berhasil Dibuat!*\n\n"
            ."📧 Email: *{$result['user']->email}*\n"
            ."🔑 Password: *{$result['password']}*\n\n"
            ."🌐 Login: https://finwa.web.id/login\n\n"
            ."✅ *Paket Gratis aktif!* Akun Telegram ini sudah terhubung.\n\n"
            ."Coba catat transaksi, contoh:\n"
            ."• _beli makan 25rb_\n"
            ."• _terima gaji 5jt_\n\n"
            .'💡 Ketik *help* untuk panduan lengkap';
    }
}
