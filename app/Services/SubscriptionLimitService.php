<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\UserWhatsAppNumber;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk mengecek limit berdasarkan paket subscription
 */
class SubscriptionLimitService
{
    /**
     * Get WhatsApp number limit berdasarkan plan
     * Sesuai rules: Starter(1 WA), Growth(5), Pro(15), Enterprise(unlimited)
     */
    public function getWhatsAppNumberLimit(string $plan): int
    {
        return match(strtolower($plan)) {
            'free' => 1,
            'starter' => 1,
            'growth' => 5,
            'pro' => 15,
            'enterprise' => 999999, // Unlimited (sangat besar)
            default => 1,
        };
    }

    /**
     * Get current active subscription untuk tenant
     */
    public function getActiveSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get plan dari tenant (dari active subscription atau default)
     */
    public function getTenantPlan(Tenant $tenant): string
    {
        $subscription = $this->getActiveSubscription($tenant);
        return $subscription ? $subscription->plan : 'free';
    }

    /**
     * Get WhatsApp number limit untuk tenant
     */
    public function getTenantWhatsAppNumberLimit(Tenant $tenant): int
    {
        $plan = $this->getTenantPlan($tenant);
        return $this->getWhatsAppNumberLimit($plan);
    }

    /**
     * Get current count of WhatsApp numbers untuk user di tenant
     */
    public function getCurrentWhatsAppNumberCount(int $userId, int $tenantId): int
    {
        return UserWhatsAppNumber::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Check if user can add more WhatsApp numbers
     */
    public function canAddWhatsAppNumber(int $userId, int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $limit = $this->getTenantWhatsAppNumberLimit($tenant);
        $current = $this->getCurrentWhatsAppNumberCount($userId, $tenantId);
        $plan = $this->getTenantPlan($tenant);
        
        $canAdd = $current < $limit;
        
        return [
            'can_add' => $canAdd,
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'plan' => $plan,
            'is_unlimited' => $limit >= 999999,
        ];
    }

    /**
     * Get limit info untuk display di UI
     */
    public function getLimitInfo(int $userId, int $tenantId): array
    {
        $check = $this->canAddWhatsAppNumber($userId, $tenantId);
        
        return [
            'current' => $check['current'],
            'limit' => $check['limit'],
            'remaining' => $check['remaining'],
            'plan' => $check['plan'],
            'can_add' => $check['can_add'],
            'is_unlimited' => $check['is_unlimited'],
            'plan_name' => ucfirst($check['plan']),
        ];
    }
}

