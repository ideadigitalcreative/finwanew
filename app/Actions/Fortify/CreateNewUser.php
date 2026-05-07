<?php

namespace App\Actions\Fortify;

use App\Models\Category;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenant;
use App\Services\TenantProvisioningService;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // If plan or price is present, it means registration from checkout, so whatsapp_number is required
        $whatsappRules = ['string', 'max:20', 'regex:/^62\d{9,13}$/'];
        if (isset($input['plan']) || isset($input['price'])) {
            $whatsappRules[] = 'required';
        } else {
            $whatsappRules[] = 'nullable';
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'whatsapp_number' => $whatsappRules,
            'password' => $this->passwordRules(),
            'tenant_name' => ['nullable', 'string', 'max:255'], // Optional: untuk join existing tenant
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'], // Optional: untuk join existing tenant
            'plan' => ['nullable', 'string', Rule::in(['free', 'starter', 'growth', 'pro', 'enterprise'])],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        $isCheckout = isset($input['plan']) && isset($input['price']);
        $isFreePlan = $isCheckout && ($input['plan'] === 'free');

        return DB::transaction(function () use ($input, $isCheckout, $isFreePlan) {
            // Create new tenant for user (multi-tenant support)
            // Set is_active to false if registration includes subscription (pending payment)
            $isActive = ! ($isCheckout && ! $isFreePlan);

            // Generate unique slug
            $baseSlug = Str::slug($input['tenant_name'] ?? $input['name'].'-org');
            $slug = $baseSlug;
            $counter = 1;

            // Ensure slug is unique
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            $tenant = Tenant::create([
                'name' => $input['tenant_name'] ?? $input['name']."'s Organization",
                'slug' => $slug,
                'is_active' => $isActive,
                'trial_ends_at' => Carbon::now()->addDays(3),
            ]);

            // Create default categories for this tenant
            $this->createCategoriesForTenant($tenant->id);

            app(TenantProvisioningService::class)->ensureDefaultWallet($tenant->id, 'fortify_registration');

            // Get or create Owner role for this tenant
            $ownerRole = Role::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => 'owner',
                ],
                [
                    'name' => 'Owner',
                    'permissions' => ['*'], // Full access
                    'description' => 'Tenant owner with full access',
                    'is_system' => true,
                ]
            );

            // Create user
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'whatsapp_number' => $input['whatsapp_number'] ?? null,
                'password' => $input['password'],
                'tenant_id' => $tenant->id, // Keep for backward compatibility
                'role_id' => $ownerRole->id, // Keep for backward compatibility
            ]);

            // Create user_tenant relationship
            UserTenant::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'role_id' => $ownerRole->id,
                'is_active' => true,
            ]);

            // Create subscription if plan, duration_months, and price are provided (from checkout)
            $subscription = null;
            if ($isCheckout && isset($input['duration_months'])) {
                $startsAt = Carbon::now();
                // Free plan: permanent (ends_at = null), paid plans: based on duration
                $endsAt = $isFreePlan ? null : Carbon::now()->addMonths($input['duration_months']);

                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan' => $input['plan'] ?? 'growth',
                    'duration_months' => $isFreePlan ? 0 : $input['duration_months'],
                    'price' => $input['price'],
                    'status' => $isFreePlan ? 'active' : 'pending',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'payment_provider' => $isFreePlan ? 'internal' : 'manual',
                    'payment_reference' => null,
                    'metadata' => array_filter([
                        'registered_from' => 'checkout',
                        'registered_at' => Carbon::now()->toIso8601String(),
                        'is_free_plan' => $isFreePlan ? true : null,
                    ]),
                ]);
            }

            // Set current tenant in session
            session(['current_tenant_id' => $tenant->id]);

            // Send WhatsApp notification after registration (async, non-blocking)
            // Use register_shutdown_function to send after response is sent to user
            if ($user->whatsapp_number) {
                register_shutdown_function(function () use ($user, $tenant, $subscription) {
                    try {
                        $notificationService = app(WhatsAppNotificationService::class);
                        $notificationService->sendRegistrationNotification($user, $tenant, $subscription);
                    } catch (\Exception $e) {
                        // Log error but don't fail registration
                        Log::error('Failed to send registration notification', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            return $user;
        });
    }

    /**
     * Create default categories for tenant
     */
    private function createCategoriesForTenant(int $tenantId): void
    {
        $categories = [
            // Pendapatan
            ['type' => 'pendapatan_gaji', 'name' => 'Gaji', 'slug' => 'gaji', 'icon' => '💰', 'color' => '#10b981'],
            ['type' => 'pendapatan_bonus', 'name' => 'Bonus', 'slug' => 'bonus', 'icon' => '🎁', 'color' => '#10b981'],
            ['type' => 'pendapatan_investasi', 'name' => 'Investasi', 'slug' => 'investasi', 'icon' => '📈', 'color' => '#10b981'],
            ['type' => 'pendapatan_transfer', 'name' => 'Transfer Masuk', 'slug' => 'transfer-masuk', 'icon' => '📥', 'color' => '#10b981'],
            ['type' => 'pendapatan_lainnya', 'name' => 'Pendapatan Lainnya', 'slug' => 'pendapatan-lainnya', 'icon' => '💵', 'color' => '#10b981'],

            // Pengeluaran
            ['type' => 'pengeluaran_makanan', 'name' => 'Makanan & Minuman', 'slug' => 'makanan-minuman', 'icon' => '🍽️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_transport', 'name' => 'Transport', 'slug' => 'transport', 'icon' => '🚗', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_hunian', 'name' => 'Hunian', 'slug' => 'hunian', 'icon' => '🏠', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_utilitas', 'name' => 'Utilitas', 'slug' => 'utilitas', 'icon' => '⚡', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_kesehatan', 'name' => 'Kesehatan', 'slug' => 'kesehatan', 'icon' => '🏥', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pendidikan', 'name' => 'Pendidikan', 'slug' => 'pendidikan', 'icon' => '📚', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_belanja', 'name' => 'Belanja', 'slug' => 'belanja', 'icon' => '🛒', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_hiburan', 'name' => 'Hiburan', 'slug' => 'hiburan', 'icon' => '🎬', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pulsa_token', 'name' => 'Pulsa & Token', 'slug' => 'pulsa-token', 'icon' => '📱', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_tagihan', 'name' => 'Tagihan', 'slug' => 'tagihan', 'icon' => '📄', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_investasi', 'name' => 'Investasi', 'slug' => 'investasi-pengeluaran', 'icon' => '💼', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pinjaman', 'name' => 'Pinjaman', 'slug' => 'pinjaman', 'icon' => '💳', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_cicilan', 'name' => 'Cicilan', 'slug' => 'cicilan', 'icon' => '🏦', 'color' => '#dc2626'],
            ['type' => 'pengeluaran_asuransi', 'name' => 'Asuransi', 'slug' => 'asuransi', 'icon' => '🛡️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pajak', 'name' => 'Pajak', 'slug' => 'pajak', 'icon' => '📊', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_operasional', 'name' => 'Operasional', 'slug' => 'operasional', 'icon' => '⚙️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_transfer', 'name' => 'Transfer Keluar', 'slug' => 'transfer-keluar', 'icon' => '📤', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_donasi', 'name' => 'Donasi', 'slug' => 'donasi', 'icon' => '❤️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_lainnya', 'name' => 'Pengeluaran Lainnya', 'slug' => 'pengeluaran-lainnya', 'icon' => '📝', 'color' => '#ef4444'],
            // Special category for Gaji Karyawan (often used in business)
            ['type' => 'pengeluaran_gaji', 'name' => 'Gaji Karyawan', 'slug' => 'gaji-karyawan', 'icon' => '👷', 'color' => '#ef4444'],
        ];

        $descriptions = [
            'pendapatan_gaji' => 'Pendapatan dari gaji bulanan',
            'pendapatan_bonus' => 'Pendapatan dari bonus atau komisi',
            'pendapatan_investasi' => 'Pendapatan dari investasi',
            'pendapatan_lainnya' => 'Pendapatan lainnya',
            'pengeluaran_makanan' => 'Pengeluaran untuk makanan dan minuman',
            'pengeluaran_transport' => 'Pengeluaran untuk transportasi',
            'pengeluaran_hunian' => 'Pengeluaran untuk tempat tinggal',
            'pengeluaran_utilitas' => 'Pengeluaran untuk listrik, air, internet, dll',
            'pengeluaran_kesehatan' => 'Pengeluaran untuk kesehatan dan pengobatan',
            'pengeluaran_pendidikan' => 'Pengeluaran untuk pendidikan',
            'pengeluaran_belanja' => 'Pengeluaran untuk belanja kebutuhan',
            'pengeluaran_hiburan' => 'Pengeluaran untuk hiburan dan rekreasi',
            'pengeluaran_pulsa_token' => 'Pengeluaran untuk pulsa, token listrik, voucher, dan sejenisnya',
            'pengeluaran_tagihan' => 'Pengeluaran untuk tagihan rutin',
            'pengeluaran_investasi' => 'Pengeluaran untuk investasi',
            'pengeluaran_pinjaman' => 'Pengeluaran untuk pembayaran pinjaman',
            'pengeluaran_cicilan' => 'Pengeluaran untuk cicilan dan angsuran (motor, mobil, rumah, KPR, dll)',
            'pengeluaran_asuransi' => 'Pengeluaran untuk asuransi',
            'pengeluaran_pajak' => 'Pengeluaran untuk pajak',
            'pengeluaran_donasi' => 'Pengeluaran untuk donasi dan sumbangan',
            'pengeluaran_lainnya' => 'Pengeluaran lainnya',
        ];

        foreach ($categories as $categoryData) {
            Category::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'type' => $categoryData['type'],
                    'slug' => $categoryData['slug'],
                ],
                [
                    'name' => $categoryData['name'],
                    'description' => $descriptions[$categoryData['type']] ?? '',
                    'icon' => $categoryData['icon'],
                    'color' => $categoryData['color'],
                    'is_system' => true,
                ]
            );
        }
    }
}
