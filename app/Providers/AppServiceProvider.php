<?php

namespace App\Providers;

use App\Events\TransactionCreated;
use App\Listeners\SendTransactionNotificationToGroupMembers;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            TransactionCreated::class,
            SendTransactionNotificationToGroupMembers::class,
        );

        // Push notification Android (FCM) untuk SEMUA jalur pembuatan transaksi
        \App\Models\Transaction::observe(\App\Observers\TransactionObserver::class);
        // Jejak audit semua perubahan transaksi (buat/ubah/hapus/pulihkan)
        \App\Models\Transaction::observe(\App\Observers\TransactionAuditObserver::class);
    }
}
