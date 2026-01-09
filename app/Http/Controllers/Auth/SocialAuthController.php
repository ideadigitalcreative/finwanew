<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Role;
use App\Models\UserTenant;
use App\Models\Subscription;
use App\Models\Category;
use App\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Carbon\Carbon;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google.
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            Log::error('Google OAuth failed', ['error' => $e->getMessage()]);
            return redirect()->route('login')->with('error', 'Gagal autentikasi dengan Google. Silakan coba lagi.');
        }

        // Check if user already exists
        $user = User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            // Update Google ID if not set
            if (!$user->google_id) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ]);
            }

            // Mark email as verified if not already
            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            Auth::login($user, true);

            // Set current tenant in session
            if ($user->tenant_id) {
                session(['current_tenant_id' => $user->tenant_id]);
            }

            return redirect()->intended('/dashboard');
        }

        // Create new user with transaction
        $user = DB::transaction(function () use ($googleUser) {
            // Generate unique slug for tenant
            $baseSlug = Str::slug($googleUser->getName() . "-org");
            $slug = $baseSlug;
            $counter = 1;
            
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            // Create tenant for the user
            $tenant = Tenant::create([
                'name' => $googleUser->getName() . "'s Organization",
                'slug' => $slug,
                'is_active' => true, // Free trial is immediately active
            ]);

            // Create default categories for this tenant
            $this->createCategoriesForTenant($tenant->id);

            // Get or create Owner role for this tenant
            $ownerRole = Role::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => 'owner'
                ],
                [
                    'name' => 'Owner',
                    'permissions' => ['*'], // Full access
                    'description' => 'Tenant owner with full access',
                    'is_system' => true
                ]
            );

            // Create user
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'password' => Hash::make(Str::random(24)),
                'email_verified_at' => now(),
                'tenant_id' => $tenant->id,
                'role_id' => $ownerRole->id,
            ]);

            // Create user_tenant relationship
            UserTenant::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'role_id' => $ownerRole->id,
                'is_active' => true,
            ]);

            // Create FREE trial subscription (3 days)
            $startsAt = Carbon::now();
            $endsAt = Carbon::now()->addDays(3);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan' => 'free',
                'duration_months' => 1,
                'price' => 0,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'payment_provider' => 'internal',
                'metadata' => [
                    'registered_from' => 'google_oauth',
                    'registered_at' => Carbon::now()->toIso8601String(),
                    'is_trial' => true,
                ],
            ]);

            // Set current tenant in session
            session(['current_tenant_id' => $tenant->id]);

            return $user;
        });

        Auth::login($user, true);

        return redirect()->intended('/dashboard');
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
            ['type' => 'pengeluaran_asuransi', 'name' => 'Asuransi', 'slug' => 'asuransi', 'icon' => '🛡️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_pajak', 'name' => 'Pajak', 'slug' => 'pajak', 'icon' => '📊', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_donasi', 'name' => 'Donasi', 'slug' => 'donasi', 'icon' => '❤️', 'color' => '#ef4444'],
            ['type' => 'pengeluaran_lainnya', 'name' => 'Pengeluaran Lainnya', 'slug' => 'pengeluaran-lainnya', 'icon' => '📝', 'color' => '#ef4444'],
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
