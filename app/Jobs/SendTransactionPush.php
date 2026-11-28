<?php

namespace App\Jobs;

use App\Services\Push\TransactionPushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kirim notifikasi push untuk transaksi yang terkumpul pada satu kunci
 * (tenant + pencatat) — lihat TransactionPushNotifier.
 */
class SendTransactionPush implements ShouldQueue
{
    use Queueable;

    /** Notifikasi tidak boleh terkirim dua kali. */
    public $tries = 1;

    public $timeout = 60;

    public function __construct(public string $key) {}

    public function handle(TransactionPushNotifier $notifier): void
    {
        $notifier->flush($this->key);
    }
}
