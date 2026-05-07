<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantProvisioningService
{
    public const DEFAULT_WALLET_NAME = 'Dompet Utama';

    /**
     * Ensure the tenant has an active default wallet: create Dompet Utama if there are none,
     * or set is_default on the first active wallet if missing, and heal blank account_name.
     */
    public function ensureDefaultWallet(int $tenantId, string $createdVia = 'unknown'): Balance
    {
        return DB::transaction(function () use ($tenantId, $createdVia) {
            $base = Balance::where('tenant_id', $tenantId)->where('is_active', true);

            $defaultWallet = (clone $base)->where('is_default', true)->first();
            if ($defaultWallet) {
                $this->healEmptyAccountName($defaultWallet);

                return $defaultWallet;
            }

            $firstActive = (clone $base)->orderBy('id')->first();
            if ($firstActive) {
                $firstActive->is_default = true;
                $this->healEmptyAccountName($firstActive);
                $firstActive->save();

                Log::info('Tenant default wallet flag set on existing wallet', [
                    'tenant_id' => $tenantId,
                    'balance_id' => $firstActive->id,
                    'created_via' => $createdVia,
                ]);

                return $firstActive;
            }

            $balance = Balance::create([
                'tenant_id' => $tenantId,
                'account_name' => self::DEFAULT_WALLET_NAME,
                'account_number' => null,
                'account_type' => 'cash',
                'currency' => 'IDR',
                'balance' => 0,
                'balance_date' => now()->toDateString(),
                'is_active' => true,
                'is_default' => true,
                'metadata' => [
                    'created_via' => $createdVia,
                    'provisioned_at' => now()->toIso8601String(),
                ],
            ]);

            Log::info('Tenant default wallet created', [
                'tenant_id' => $tenantId,
                'balance_id' => $balance->id,
                'created_via' => $createdVia,
            ]);

            return $balance;
        });
    }

    /**
     * Remove a balance row permanently. Deletes all transactions linked to this wallet (same behavior WA & web).
     * Ensures the tenant still has an active default wallet when possible.
     */
    public function permanentlyDeleteBalance(Balance $balance): void
    {
        DB::transaction(function () use ($balance) {
            $tenantId = $balance->tenant_id;
            $balanceId = $balance->id;

            $deletedTx = Transaction::where('tenant_id', $tenantId)
                ->where('balance_id', $balanceId)
                ->delete();

            if ($deletedTx > 0) {
                Log::info('Transactions removed with deleted wallet', [
                    'tenant_id' => $tenantId,
                    'balance_id' => $balanceId,
                    'deleted_transactions' => $deletedTx,
                ]);
            }

            $balance->delete();

            $activeIds = Balance::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id');

            if ($activeIds->isEmpty()) {
                $this->ensureDefaultWallet($tenantId, 'balance_permanent_delete_restore');

                return;
            }

            $hasDefault = Balance::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('is_default', true)
                ->exists();

            if (! $hasDefault) {
                Balance::where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->update(['is_default' => false]);
                Balance::where('id', $activeIds->first())
                    ->update(['is_default' => true]);
            }
        });
    }

    /**
     * True if ensureDefaultWallet would only return without creating (tenant already has active default with non-empty name).
     */
    public function tenantNeedsDefaultWalletProvisioning(int $tenantId): bool
    {
        $base = Balance::where('tenant_id', $tenantId)->where('is_active', true);

        if (! (clone $base)->exists()) {
            return true;
        }

        $defaultWallet = (clone $base)->where('is_default', true)->first();
        if (! $defaultWallet) {
            return true;
        }

        return trim((string) $defaultWallet->account_name) === '';
    }

    private function healEmptyAccountName(Balance $balance): void
    {
        if (trim((string) $balance->account_name) !== '') {
            return;
        }

        $balance->account_name = self::DEFAULT_WALLET_NAME;
        $balance->save();
    }
}
