<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BalanceService
{
    /**
     * Find or create balance account by name
     */
    public function findOrCreateBalance(int $tenantId, ?string $accountName, ?string $accountType = null): ?Balance
    {
        if (! $accountName) {
            return null;
        }

        // Normalize account name (remove "saldo" prefix if present, normalize bank names)
        $normalizedName = $this->normalizeAccountName($accountName);

        // Try to find existing balance (exact match)
        $balance = Balance::where('tenant_id', $tenantId)
            ->where('account_name', $normalizedName)
            ->where('is_active', true)
            ->first();

        if ($balance) {
            return $balance;
        }

        // If not found, try to find similar account name (case insensitive)
        $balance = Balance::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(account_name) = ?', [strtolower($normalizedName)])
            ->where('is_active', true)
            ->first();

        if ($balance) {
            return $balance;
        }

        // Try fuzzy match for bank names (e.g., "BCA" should match "Bank BCA")
        $accountNameLower = strtolower($normalizedName);
        $fuzzyMatches = [
            'bca' => 'Bank BCA',
            'mandiri' => 'Bank Mandiri',
            'bni' => 'Bank BNI',
            'bri' => 'Bank BRI',
        ];

        foreach ($fuzzyMatches as $key => $bankName) {
            if (str_contains($accountNameLower, $key) || str_contains($accountNameLower, strtolower($bankName))) {
                $balance = Balance::where('tenant_id', $tenantId)
                    ->where(function ($query) use ($bankName, $key) {
                        $query->where('account_name', $bankName)
                            ->orWhereRaw('LOWER(account_name) LIKE ?', ['%'.$key.'%']);
                    })
                    ->where('is_active', true)
                    ->first();

                if ($balance) {
                    return $balance;
                }
                // Use normalized bank name for creation
                $normalizedName = $bankName;
                break;
            }
        }

        // If still not found and accountType provided, create new balance with 0 balance
        if ($accountType) {
            try {
                $balance = Balance::create([
                    'tenant_id' => $tenantId,
                    'account_name' => $normalizedName, // Use normalized name
                    'account_type' => $accountType,
                    'currency' => 'IDR',
                    'balance' => 0,
                    'balance_date' => now(),
                    'is_active' => true,
                ]);

                Log::info('Auto-created balance account', [
                    'tenant_id' => $tenantId,
                    'account_name' => $normalizedName,
                    'original_account_name' => $accountName,
                    'account_type' => $accountType,
                ]);

                return $balance;
            } catch (\Exception $e) {
                Log::error('Failed to create balance account', [
                    'tenant_id' => $tenantId,
                    'account_name' => $normalizedName,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * Normalize account name (remove prefixes, normalize bank names)
     */
    protected function normalizeAccountName(string $accountName): string
    {
        $name = trim($accountName);
        $nameLower = strtolower($name);

        // Remove common prefixes
        $nameLower = str_replace(['saldo', 'bank'], '', $nameLower);
        $nameLower = trim($nameLower);

        // Normalize bank names
        if ($nameLower === 'bca' || str_starts_with($nameLower, 'bca')) {
            return 'Bank BCA';
        }
        if ($nameLower === 'mandiri' || str_starts_with($nameLower, 'mandiri')) {
            return 'Bank Mandiri';
        }
        if ($nameLower === 'bni' || str_starts_with($nameLower, 'bni')) {
            return 'Bank BNI';
        }
        if ($nameLower === 'bri' || str_starts_with($nameLower, 'bri')) {
            return 'Bank BRI';
        }
        if (in_array($nameLower, ['cash', 'tunai'])) {
            return 'Cash';
        }

        // If it already contains "Bank" or matches known format
        if (in_array($nameLower, ['cash', 'tunai', 'gopay', 'ovo', 'dana'])) {
            return ucwords($nameLower);
        }

        return ucwords($nameLower);
    }

    /**
     * Get default balance account for tenant
     */
    public function getDefaultBalance(int $tenantId): ?Balance
    {
        // First, try to find balance with is_default = true
        $balance = Balance::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if ($balance) {
            return $balance;
        }

        // Fallback: use first active balance
        $balance = Balance::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('account_type')
            ->orderBy('account_name')
            ->first();

        if ($balance) {
            return $balance;
        }

        // No active wallet: provision Dompet Utama so chat/dashboard stay in sync
        return app(TenantProvisioningService::class)->ensureDefaultWallet($tenantId, 'balance_service_get_default');
    }

    /**
     * Set default balance for tenant (only one can be default)
     */
    public function setDefaultBalance(int $tenantId, int $balanceId): bool
    {
        try {
            DB::transaction(function () use ($tenantId, $balanceId) {
                // Unset all other default balances for this tenant
                Balance::where('tenant_id', $tenantId)
                    ->where('id', '!=', $balanceId)
                    ->update(['is_default' => false]);

                // Set this balance as default
                $balance = Balance::where('tenant_id', $tenantId)
                    ->where('id', $balanceId)
                    ->where('is_active', true)
                    ->firstOrFail();

                $balance->update(['is_default' => true]);

                Log::info('Dompet utama berhasil diatur', [
                    'tenant_id' => $tenantId,
                    'balance_id' => $balanceId,
                    'account_name' => $balance->account_name,
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to set default balance', [
                'tenant_id' => $tenantId,
                'balance_id' => $balanceId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update balance based on transaction
     */
    public function updateBalanceFromTransaction(Transaction $transaction): void
    {
        if (! $transaction->balance_id) {
            return;
        }

        try {
            DB::transaction(function () use ($transaction) {
                $balance = Balance::find($transaction->balance_id);

                if (! $balance) {
                    Log::warning('Balance not found for transaction', [
                        'transaction_id' => $transaction->id,
                        'balance_id' => $transaction->balance_id,
                    ]);

                    return;
                }

                // Calculate new balance
                if ($transaction->type === 'income' || $transaction->type === 'kredit_internal') {
                    $balance->balance += $transaction->amount;
                } elseif ($transaction->type === 'expense' || $transaction->type === 'debit_internal') {
                    $balance->balance -= $transaction->amount;
                }

                // Ensure balance doesn't go negative (optional check)
                if ($balance->balance < 0) {
                    Log::warning('Balance would go negative', [
                        'balance_id' => $balance->id,
                        'account_name' => $balance->account_name,
                        'current_balance' => $balance->balance + ($transaction->type === 'income' ? -$transaction->amount : $transaction->amount),
                        'transaction_amount' => $transaction->amount,
                        'transaction_type' => $transaction->type,
                    ]);
                    // Still update, but log warning
                }

                $balance->balance_date = $transaction->transaction_date;
                $balance->save();

                Log::info('Balance updated from transaction', [
                    'balance_id' => $balance->id,
                    'account_name' => $balance->account_name,
                    'transaction_id' => $transaction->id,
                    'transaction_type' => $transaction->type,
                    'transaction_amount' => $transaction->amount,
                    'new_balance' => $balance->balance,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to update balance from transaction', [
                'transaction_id' => $transaction->id,
                'balance_id' => $transaction->balance_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reverse balance update (when transaction is deleted or updated)
     */
    public function reverseBalanceUpdate(Transaction $transaction): void
    {
        if (! $transaction->balance_id) {
            return;
        }

        try {
            DB::transaction(function () use ($transaction) {
                $balance = Balance::find($transaction->balance_id);

                if (! $balance) {
                    return;
                }

                // Reverse the transaction
                if ($transaction->type === 'income' || $transaction->type === 'kredit_internal') {
                    $balance->balance -= $transaction->amount;
                } elseif ($transaction->type === 'expense' || $transaction->type === 'debit_internal') {
                    $balance->balance += $transaction->amount;
                }

                $balance->save();

                Log::info('Balance reversed from transaction', [
                    'balance_id' => $balance->id,
                    'account_name' => $balance->account_name,
                    'transaction_id' => $transaction->id,
                    'new_balance' => $balance->balance,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to reverse balance update', [
                'transaction_id' => $transaction->id,
                'balance_id' => $transaction->balance_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine account type from account name
     */
    public function determineAccountType(?string $accountName): ?string
    {
        if (! $accountName) {
            return null;
        }

        $accountNameLower = strtolower($accountName);

        if (str_contains($accountNameLower, 'bank') ||
            str_contains($accountNameLower, 'bca') ||
            str_contains($accountNameLower, 'mandiri') ||
            str_contains($accountNameLower, 'bni') ||
            str_contains($accountNameLower, 'bri')) {
            return 'bank';
        }

        if (str_contains($accountNameLower, 'cash') ||
            str_contains($accountNameLower, 'tunai')) {
            return 'cash';
        }

        if (str_contains($accountNameLower, 'gopay') ||
            str_contains($accountNameLower, 'ovo') ||
            str_contains($accountNameLower, 'dana') ||
            str_contains($accountNameLower, 'linkaja') ||
            str_contains($accountNameLower, 'wallet')) {
            return 'wallet';
        }

        if (str_contains($accountNameLower, 'investasi') ||
            str_contains($accountNameLower, 'saham') ||
            str_contains($accountNameLower, 'reksadana')) {
            return 'investment';
        }

        return 'other';
    }
}
