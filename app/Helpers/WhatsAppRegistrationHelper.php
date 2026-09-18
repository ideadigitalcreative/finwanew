<?php

namespace App\Helpers;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppRegistrationHelper
{
    /**
     * Check if user is in registration flow
     */
    public static function isInRegistrationFlow(string $phoneNumber): bool
    {
        if (Cache::has("wa_reg_flow:{$phoneNumber}")) {
            return true;
        }

        return self::recoverFlowFromRecentMessages($phoneNumber) !== null;
    }

    /**
     * Get current registration step
     */
    public static function getCurrentStep(string $phoneNumber): ?string
    {
        $step = Cache::get("wa_reg_flow:{$phoneNumber}");
        if (is_string($step) && $step !== '') {
            return $step;
        }

        $recovered = self::recoverFlowFromRecentMessages($phoneNumber);

        return $recovered['step'] ?? null;
    }

    /**
     * Get registration data
     */
    public static function getRegistrationData(string $phoneNumber): array
    {
        $data = Cache::get("wa_reg_data:{$phoneNumber}", []);
        if (is_array($data) && $data !== []) {
            return $data;
        }

        $recovered = self::recoverFlowFromRecentMessages($phoneNumber);

        return is_array($recovered['data'] ?? null) ? $recovered['data'] : [];
    }

    /**
     * Set registration step
     */
    public static function setStep(string $phoneNumber, string $step): void
    {
        Cache::put("wa_reg_flow:{$phoneNumber}", $step, now()->addHours(24));
    }

    /**
     * Save registration data
     */
    public static function saveData(string $phoneNumber, array $data): void
    {
        $existing = self::getRegistrationData($phoneNumber);
        $merged = array_merge($existing, $data);
        Cache::put("wa_reg_data:{$phoneNumber}", $merged, now()->addHours(24));
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

    protected static function recoverFlowFromRecentMessages(string $phoneNumber): ?array
    {
        $lockKey = "wa_reg_recover_lock:{$phoneNumber}";
        if (! Cache::add($lockKey, true, now()->addSeconds(10))) {
            return null;
        }

        // Do NOT recover registration flow for users who are already registered.
        // This prevents messages like "help" or short words from being mistaken
        // as a registration name and trapping registered users in awaiting_email.
        $candidates = [$phoneNumber];
        if (str_starts_with($phoneNumber, '62') && strlen($phoneNumber) >= 10) {
            $candidates[] = '0'.substr($phoneNumber, 2);
            $candidates[] = substr($phoneNumber, 2);
        } elseif (str_starts_with($phoneNumber, '0') && strlen($phoneNumber) >= 10) {
            $candidates[] = '62'.substr($phoneNumber, 1);
        } elseif (str_starts_with($phoneNumber, '8') && strlen($phoneNumber) >= 9) {
            $candidates[] = '62'.$phoneNumber;
            $candidates[] = '0'.$phoneNumber;
        }

        // If any candidate is already a registered user, skip recovery entirely.
        $uniqueCandidates = array_values(array_unique($candidates));
        $alreadyRegistered = User::whereIn('whatsapp_number', $uniqueCandidates)->exists()
            || \App\Models\UserWhatsAppNumber::whereIn('whatsapp_number', $uniqueCandidates)->where('is_active', true)->exists();
        if ($alreadyRegistered) {
            return null;
        }

        $messages = Message::query()
            ->whereIn('sender_id', array_values(array_unique($candidates)))
            ->where('created_at', '>=', now()->subHours(6))
            ->orderByDesc('id')
            ->limit(6)
            ->get(['content', 'created_at']);

        if ($messages->isEmpty()) {
            return null;
        }

        $texts = $messages->pluck('content')->filter(fn ($v) => is_string($v))->map(fn ($v) => trim($v))->values()->all();
        if ($texts === []) {
            return null;
        }

        $latest = $texts[0] ?? '';
        $prev = $texts[1] ?? '';

        $step = null;
        $data = ['phone' => $phoneNumber];

        if ($latest !== '' && self::isValidEmail($latest)) {
            $step = 'awaiting_email';
            $data['email'] = $latest;

            $nameCandidate = null;
            foreach (array_slice($texts, 1) as $t) {
                $t = trim((string) $t);
                if ($t === '') {
                    continue;
                }
                if (self::isValidEmail($t)) {
                    continue;
                }
                if (self::isConfirmation($t) || self::isRejection($t)) {
                    continue;
                }
                if (preg_match('/\d/', $t)) {
                    continue;
                }
                if (mb_strlen($t) < 3 || mb_strlen($t) > 60) {
                    continue;
                }
                if (preg_match('/[a-zA-Z\p{L}]/u', $t) !== 1) {
                    continue;
                }
                $nameCandidate = $t;
                break;
            }
            if ($nameCandidate !== null) {
                $data['name'] = $nameCandidate;
            }
        } elseif ($latest !== '' && ! self::isConfirmation($latest) && ! self::isRejection($latest) && ! self::isValidEmail($latest)) {
            $looksLikeName = ! preg_match('/\d/', $latest)
                && mb_strlen($latest) >= 3
                && mb_strlen($latest) <= 60
                && preg_match('/[a-zA-Z\p{L}]/u', $latest) === 1;

            if ($looksLikeName) {
                $step = 'awaiting_email';
                $data['name'] = $latest;
            } elseif (self::isConfirmation($latest) || self::isConfirmation($prev)) {
                $step = 'awaiting_name';
            }
        } elseif (self::isConfirmation($latest) || self::isConfirmation($prev)) {
            $step = 'awaiting_name';
        }

        if ($step === null) {
            return null;
        }

        Log::info('Recovered WhatsApp registration flow from recent messages', [
            'phone' => $phoneNumber,
            'step' => $step,
            'has_name' => isset($data['name']),
            'has_email' => isset($data['email']),
        ]);

        self::setStep($phoneNumber, $step);
        self::saveData($phoneNumber, $data);

        return ['step' => $step, 'data' => $data];
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
        // Thoroughly clean the email string
        // Remove zero-width spaces and other invisible characters
        $email = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $email);
        // Remove any other non-printable characters
        $email = preg_replace('/[[:^print:]]/', '', $email);
        $email = trim($email);

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

        return $letters.$numbers;
    }

    /**
     * Create user account from registration data
     */
    public static function createAccount(array $data): array
    {
        $password = self::generatePassword();

        // Generate slug from name
        $slug = Str::slug($data['name']).'-'.Str::random(6);

        // Create tenant
        $tenant = Tenant::create([
            'name' => $data['name']."'s Business",
            'slug' => $slug,
            'is_active' => true,
            'trial_ends_at' => null,
        ]);

        app(\App\Services\TenantProvisioningService::class)->ensureDefaultWallet($tenant->id, 'whatsapp_registration');

        // Get owner role ID
        $ownerRole = DB::table('roles')->where('name', 'owner')->first();
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
        DB::table('user_tenants')->insert([
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

        // Create permanent free plan subscription
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
                'registered_via' => 'whatsapp',
                'registered_at' => Carbon::now()->toIso8601String(),
                'is_free_plan' => true,
            ],
        ]);

        // Create default categories for the new tenant
        self::createDefaultCategories($tenant->id);

        return [
            'user' => $user,
            'tenant' => $tenant,
            'password' => $password,
        ];
    }

    /**
     * Create default categories for a new tenant
     */
    private static function createDefaultCategories(int $tenantId): void
    {
        app(\App\Services\Category\CategoryManagerService::class)->createCategoriesForTenant($tenantId);
    }

    /**
     * Get welcome message for unregistered user
     */
    public static function getWelcomeMessage(): string
    {
        return "👋 *Halo!*\n\n"
            ."Sepertinya Anda belum terdaftar di FinWa.\n\n"
            ."Mau daftar sekarang? *Gratis selamanya!* 🎉\n\n"
            .'Ketik *Ya* untuk daftar atau *Tidak* untuk batal.';
    }

    /**
     * Get ask name message
     */
    public static function getAskNameMessage(): string
    {
        return "👋 *Halo!*\n\n"
            ."Saya FinWa, asisten pencatat keuangan Anda.\n\n"
            ."Pendaftaran singkat dan mudah.\n\n"
            .'📝 *Ketik nama lengkap Anda* (contoh: Budi Santoso)';
    }

    /**
     * Get ask email message
     */
    public static function getAskEmailMessage(string $name): string
    {
        return "Terima kasih, *{$name}*! 👍\n\n"
            .'Sekarang silakan kirim *alamat email* Anda:';
    }

    /**
     * Get success message
     */
    public static function getSuccessMessage(array $result): string
    {
        return "🎉 *Akun Berhasil Dibuat!*\n\n"
            ."📧 Email: *{$result['user']->email}*\n"
            ."🔑 Password: *{$result['password']}*\n\n"
            ."🌐 Login: https://finwa.web.id/login\n"
            ."📱 Play Store: https://play.google.com/store/apps/details?id=com.idea.finwa\n\n"
            ."✅ *Paket Gratis aktif!* 50 transaksi/bulan\n\n"
            ."Contoh:\n"
            ."• _beli makan 25rb_\n"
            ."• _terima gaji 5jt_\n\n"
            .'💡 Ketik *help* untuk panduan lengkap';
    }

    /**
     * Get cancellation message
     */
    public static function getCancellationMessage(): string
    {
        return "Baik, pendaftaran dibatalkan.\n\n"
            .'Jika berubah pikiran, kirim pesan lagi ya! 😊';
    }
}
