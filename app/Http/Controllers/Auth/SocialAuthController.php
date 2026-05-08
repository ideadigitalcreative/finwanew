<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenant;
use App\Services\TenantProvisioningService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

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
            if (! $user->google_id) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ]);
            }

            // Mark email as verified if not already
            if (! $user->hasVerifiedEmail()) {
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
            $baseSlug = Str::slug($googleUser->getName().'-org');
            $slug = $baseSlug;
            $counter = 1;

            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            // Create tenant for the user
            $tenant = Tenant::create([
                'name' => $googleUser->getName()."'s Organization",
                'slug' => $slug,
                'is_active' => true, // Free trial is immediately active
                'trial_ends_at' => null,
            ]);

            // Create default categories for this tenant
            $this->createCategoriesForTenant($tenant->id);

            app(TenantProvisioningService::class)->ensureDefaultWallet($tenant->id, 'google_oauth');

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

            // Create permanent free plan subscription
            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan' => 'free',
                'duration_months' => 0,
                'price' => 0,
                'status' => 'active',
                'starts_at' => Carbon::now(),
                'ends_at' => null,
                'payment_provider' => 'internal',
                'metadata' => [
                    'registered_from' => 'google_oauth',
                    'registered_at' => Carbon::now()->toIso8601String(),
                    'is_free_plan' => true,
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
        app(\App\Services\Category\CategoryManagerService::class)->createCategoriesForTenant($tenantId);
    }
}
