<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\Push\TransactionPushNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Satu titik pusat untuk SEMUA jalur pencatatan (WhatsApp, batch, struk,
 * dashboard, API, Telegram) — tidak perlu menyisipkan kode di ±24 tempat.
 */
class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        try {
            app(TransactionPushNotifier::class)->queue($transaction, auth()->id());
        } catch (\Throwable $e) {
            // Notifikasi gagal TIDAK boleh menggagalkan pencatatan transaksi
            Log::warning('Push: gagal menjadwalkan notifikasi', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
