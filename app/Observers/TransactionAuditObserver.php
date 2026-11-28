<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\TransactionAudit;
use App\Services\Ledger\AuditContext;
use Illuminate\Support\Facades\Log;

/**
 * Jejak audit untuk SEMUA perubahan transaksi lewat Eloquent (jalur apa pun).
 * Gagal menulis audit tidak boleh menggagalkan pencatatan.
 */
class TransactionAuditObserver
{
    private const TRACKED = [
        'type', 'amount', 'transaction_date', 'description', 'category_id', 'balance_id',
        'status', 'merchant', 'linked_transaction_id', 'user_whatsapp_number_id',
    ];

    public function created(Transaction $transaction): void
    {
        $this->write($transaction, 'created', [
            'type' => [null, $transaction->type],
            'amount' => [null, (float) $transaction->amount],
            'balance_id' => [null, $transaction->balance_id],
        ]);
    }

    public function updated(Transaction $transaction): void
    {
        $changes = [];
        foreach (array_keys($transaction->getChanges()) as $field) {
            if (! in_array($field, self::TRACKED, true)) {
                continue;
            }
            $old = $transaction->getOriginal($field);
            $new = $transaction->getAttribute($field);
            $changes[$field] = $field === 'amount'
                ? [$old === null ? null : (float) $old, $new === null ? null : (float) $new]
                : [$this->plain($old), $this->plain($new)];
        }
        if ($changes !== []) {
            $this->write($transaction, 'updated', $changes);
        }
    }

    public function deleted(Transaction $transaction): void
    {
        $this->write($transaction, $transaction->isForceDeleting() ? 'force_deleted' : 'deleted', [
            'amount' => [(float) $transaction->amount, null],
        ]);
    }

    public function restored(Transaction $transaction): void
    {
        $this->write($transaction, 'restored', [
            'amount' => [null, (float) $transaction->amount],
        ]);
    }

    private function write(Transaction $transaction, string $action, array $changes): void
    {
        try {
            $ctx = AuditContext::current();
            TransactionAudit::create([
                'tenant_id' => $transaction->tenant_id,
                'transaction_id' => $transaction->id,
                'action' => $action,
                'via' => $ctx['via'],
                'user_id' => $ctx['user_id'],
                'message_id' => $ctx['message_id'] ?? $transaction->message_id,
                'changes' => $changes,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit transaksi gagal ditulis', [
                'transaction_id' => $transaction->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_numeric($value) && ! is_int($value) ? (float) $value : $value;
    }
}
