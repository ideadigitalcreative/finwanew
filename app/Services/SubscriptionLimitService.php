<?php

namespace App\Services;

use App\Models\OcrJob;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UserWhatsAppNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Service untuk mengecek limit berdasarkan paket subscription
 */
class SubscriptionLimitService
{
    const SCAN_LIMIT_MONTHLY = 100;

    const SCAN_LIMIT_DAILY = 10;

    const SCAN_LIMIT_PER_MINUTE = 3;

    /**
     * Cutoff date: subscriptions created ON or AFTER this date
     * will use the new Lite WA limit (2 instead of 5).
     * Existing users before this date keep the old limit (5).
     */
    const NEW_LITE_WA_LIMIT_DATE = '2026-04-25';

    /**
     * Get monthly scan (OCR) limit berdasarkan plan
     */
    public function getMonthlyScanLimit(string $plan): int
    {
        return match (strtolower($plan)) {
            'starter' => 100,
            'growth' => 100,
            'pro' => 300,
            'enterprise' => 999999,
            default => 100,
        };
    }

    /**
     * Get daily scan (OCR) limit berdasarkan plan
     */
    public function getDailyScanLimit(string $plan): int
    {
        return match (strtolower($plan)) {
            'starter' => 10,
            'growth' => 15,
            'pro' => 30,
            'enterprise' => 100,
            default => 10,
        };
    }

    /**
     * Get WhatsApp number limit berdasarkan plan
     * Sesuai rules: Starter(1 WA), Growth/Lite(2 for new, 5 for old), Pro(5), Enterprise(unlimited)
     *
     * @param  Subscription|null  $subscription  Optional: pass subscription to check grandfathering
     */
    public function getWhatsAppNumberLimit(string $plan, ?Subscription $subscription = null): int
    {
        $plan = strtolower($plan);

        if ($plan === 'growth') {
            // Grandfathering: old users (before cutoff) keep 5 WA numbers
            // New users (on or after cutoff) get 2 WA numbers
            if ($subscription && $subscription->created_at) {
                $cutoff = Carbon::parse(self::NEW_LITE_WA_LIMIT_DATE)->startOfDay();
                if ($subscription->created_at->lt($cutoff)) {
                    return 5; // Old user: keep 5
                }
            }

            return 2; // New user or no subscription info: use new limit
        }

        return match ($plan) {
            'free' => 1,
            'starter' => 1,
            'pro' => 5,
            'enterprise' => 999999,
            default => 1,
        };
    }

    /**
     * Get monthly transaction limit berdasarkan plan
     * Free: 50/bulan, Paid plans: unlimited
     */
    public function getMonthlyTransactionLimit(string $plan): int
    {
        return match (strtolower($plan)) {
            'free' => 50,
            default => 999999,
        };
    }

    /**
     * Apakah plan boleh menggunakan fitur OCR (kirim struk/gambar)
     */
    public function canUseOcr(string $plan): bool
    {
        return strtolower($plan) !== 'free';
    }

    // =========================================================================
    // SCAN STRUK RATE LIMITING
    // =========================================================================

    /**
     * Hitung jumlah scan bulan ini (dari tabel ocr_jobs).
     * Jika $userId diisi: kuota per pengguna (1 user = 100/bulan).
     * Jika null: fallback ke seluruh tenant (mis. pengirim belum ter-mapping ke user).
     */
    public function getMonthlyScanCount(int $tenantId, ?int $userId = null): int
    {
        $q = OcrJob::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', Carbon::now()->startOfMonth());

        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->count();
    }

    /**
     * Hitung jumlah scan hari ini untuk tenant / user.
     * Hanya scan yang berhasil (completed) yang dihitung ke kuota.
     */
    public function getDailyScanCount(int $tenantId, ?int $userId = null): int
    {
        $q = OcrJob::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', Carbon::today());

        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->count();
    }

    /**
     * Hitung jumlah scan dalam 1 menit terakhir (via cache untuk performa), per tenant + user.
     */
    public function getMinuteScanCount(int $tenantId, ?int $userId = null): int
    {
        $cacheKey = $this->scanMinuteCacheKey($tenantId, $userId);

        return (int) Cache::get($cacheKey, 0);
    }

    /**
     * Increment counter scan per menit (auto-expire 60 detik)
     */
    public function incrementMinuteScanCount(int $tenantId, ?int $userId = null): void
    {
        $cacheKey = $this->scanMinuteCacheKey($tenantId, $userId);
        if (Cache::has($cacheKey)) {
            Cache::increment($cacheKey);
        } else {
            Cache::put($cacheKey, 1, 60);
        }
    }

    protected function scanMinuteCacheKey(int $tenantId, ?int $userId): string
    {
        $suffix = $userId !== null ? 'u'.$userId : 'all';

        return "scan_minute:{$tenantId}:{$suffix}";
    }

    /**
     * Check apakah boleh melakukan scan struk.
     * Cek 3 level: per menit (3), per hari (10), per bulan (100) — per user jika user teridentifikasi.
     *
     * @return array{can_scan: bool, reason: string|null, monthly: int, daily: int, minute: int}
     */
    public function canScanReceipt(int $tenantId, ?int $userId = null): array
    {
        $minuteCount = $this->getMinuteScanCount($tenantId, $userId);
        if ($minuteCount >= self::SCAN_LIMIT_PER_MINUTE) {
            return [
                'can_scan' => false,
                'reason' => 'rate_limit',
                'monthly' => $this->getMonthlyScanCount($tenantId, $userId),
                'daily' => $this->getDailyScanCount($tenantId, $userId),
                'minute' => $minuteCount,
            ];
        }

        $plan = $this->getTenantPlanById($tenantId);
        $monthlyLimit = $this->getMonthlyScanLimit($plan);
        $dailyLimit = $this->getDailyScanLimit($plan);

        $dailyCount = $this->getDailyScanCount($tenantId, $userId);
        if ($dailyCount >= $dailyLimit) {
            return [
                'can_scan' => false,
                'reason' => 'daily_limit',
                'monthly' => $this->getMonthlyScanCount($tenantId, $userId),
                'daily' => $dailyCount,
                'limit_daily' => $dailyLimit,
                'minute' => $minuteCount,
            ];
        }

        $monthlyCount = $this->getMonthlyScanCount($tenantId, $userId);
        if ($monthlyCount >= $monthlyLimit) {
            return [
                'can_scan' => false,
                'reason' => 'monthly_limit',
                'monthly' => $monthlyCount,
                'limit_monthly' => $monthlyLimit,
                'daily' => $dailyCount,
                'minute' => $minuteCount,
            ];
        }

        return [
            'can_scan' => true,
            'reason' => null,
            'monthly' => $monthlyCount,
            'daily' => $dailyCount,
            'minute' => $minuteCount,
        ];
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
     * Get plan dari tenant_id (shortcut)
     */
    public function getTenantPlanById(int $tenantId): string
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return 'free';
        }

        return $this->getTenantPlan($tenant);
    }

    /**
     * Hitung jumlah transaksi bulan ini untuk tenant
     */
    public function getMonthlyTransactionCount(int $tenantId): int
    {
        return Transaction::where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->where('created_at', '<=', Carbon::now()->endOfMonth())
            ->count();
    }

    /**
     * Check apakah tenant masih bisa membuat transaksi (belum mencapai limit bulanan)
     */
    public function canCreateTransaction(int $tenantId): array
    {
        $plan = $this->getTenantPlanById($tenantId);
        $limit = $this->getMonthlyTransactionLimit($plan);
        $current = $this->getMonthlyTransactionCount($tenantId);
        $canCreate = $current < $limit;

        return [
            'can_create' => $canCreate,
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'plan' => $plan,
            'is_unlimited' => $limit >= 999999,
        ];
    }

    /**
     * Check apakah tenant boleh menggunakan OCR/struk
     */
    public function canTenantUseOcr(int $tenantId): bool
    {
        $plan = $this->getTenantPlanById($tenantId);

        return $this->canUseOcr($plan);
    }

    /**
     * Get WhatsApp number limit untuk tenant
     * Passes the active subscription for grandfathering check
     */
    public function getTenantWhatsAppNumberLimit(Tenant $tenant): int
    {
        $subscription = $this->getActiveSubscription($tenant);
        $plan = $subscription ? $subscription->plan : 'free';

        return $this->getWhatsAppNumberLimit($plan, $subscription);
    }

    /**
     * Get current count of WhatsApp numbers untuk user di tenant
     */
    public function getCurrentWhatsAppNumberCount(int $userId, int $tenantId): int
    {
        return UserWhatsAppNumber::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_lid', false) // Don't count technical LIDs towards the subscription limit
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
        $txCheck = $this->canCreateTransaction($tenantId);

        return [
            'current' => $check['current'],
            'limit' => $check['limit'],
            'remaining' => $check['remaining'],
            'plan' => $check['plan'],
            'can_add' => $check['can_add'],
            'is_unlimited' => $check['is_unlimited'],
            'plan_name' => ucfirst($check['plan']),
            'transaction_current' => $txCheck['current'],
            'transaction_limit' => $txCheck['limit'],
            'transaction_remaining' => $txCheck['remaining'],
            'transaction_is_unlimited' => $txCheck['is_unlimited'],
            'can_use_ocr' => $this->canUseOcr($check['plan']),
        ];
    }
}
