<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpgradeUserSubscription extends Command
{
    protected $signature = 'subscription:upgrade 
                            {email : Email user/tenant} 
                            {plan=growth : Plan (starter,growth,pro,enterprise)} 
                            {--months=1 : Durasi bulan (1-12)} 
                            {--active : Langsung aktifkan status}
                            {--force : Tanpa konfirmasi}';

    protected $description = 'Upgrade subscription user dari trial/free ke plan berbayar (manual oleh super admin)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $plan = strtolower($this->argument('plan'));
        $months = (int) $this->option('months');
        $setActive = $this->option('active');

        $allowedPlans = ['starter', 'growth', 'pro', 'enterprise'];
        if (! in_array($plan, $allowedPlans)) {
            $this->error('Plan harus salah satu: '.implode(', ', $allowedPlans));

            return 1;
        }
        if ($months < 1 || $months > 12) {
            $this->error('Durasi months harus 1-12.');

            return 1;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("User dengan email '{$email}' tidak ditemukan.");

            return 1;
        }

        $tenant = $user->tenant;
        if (! $tenant) {
            $this->error('User tidak punya tenant.');

            return 1;
        }

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'pending'])
            ->orderByDesc('created_at')
            ->first();

        if (! $subscription) {
            $subscription = Subscription::where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $subscription) {
            $this->error("Tidak ada subscription untuk tenant '{$tenant->name}' (id: {$tenant->id}).");

            return 1;
        }

        $monthlyPrice = 20000; // growth default
        $subtotal = $monthlyPrice * $months;
        $discountPercent = match (true) {
            $months >= 12 => 15,
            $months >= 6 => 10,
            $months >= 3 => 5,
            default => 0,
        };
        $price = round($subtotal - ($subtotal * $discountPercent) / 100, 2);

        $newStatus = $setActive ? 'active' : $subscription->status;
        $startsAt = $subscription->ends_at && $subscription->ends_at->isFuture()
            ? $subscription->ends_at
            : Carbon::now();
        $endsAt = (clone $startsAt)->addMonths($months);

        $this->info("Tenant: {$tenant->name} (id: {$tenant->id})");
        $this->info("Subscription id: {$subscription->id}, plan saat ini: {$subscription->plan}");
        $this->info("Akan diubah ke: plan={$plan}, months={$months}, price={$price}, status={$newStatus}");
        if (! $this->option('force') && ! $this->confirm('Lanjutkan?')) {
            return 0;
        }

        DB::transaction(function () use ($subscription, $plan, $months, $price, $newStatus, $startsAt, $endsAt, $tenant) {
            $metadata = $subscription->metadata ?? [];
            $metadata['upgraded_at'] = Carbon::now()->toIso8601String();
            $metadata['upgraded_from'] = $subscription->plan;

            $subscription->update([
                'plan' => $plan,
                'duration_months' => $months,
                'price' => $price,
                'status' => $newStatus,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'payment_provider' => $subscription->payment_provider ?: 'manual',
                'metadata' => $metadata,
            ]);

            if ($newStatus === 'active') {
                $tenant->update(['is_active' => true]);
                Subscription::where('tenant_id', $tenant->id)
                    ->where('id', '!=', $subscription->id)
                    ->where('status', 'active')
                    ->update(['status' => 'cancelled']);
            }
        });

        $this->info("Subscription berhasil diupgrade ke {$plan}.");

        return 0;
    }
}
