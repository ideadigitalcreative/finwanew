<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

// Google OAuth Routes
Route::get('/auth/google', [\App\Http\Controllers\Auth\SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\SocialAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

Route::get('/checkout', [\App\Http\Controllers\CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout/process', [\App\Http\Controllers\CheckoutController::class, 'process'])->name('checkout.process');

// Legal Pages (Required for Google OAuth)
Route::get('/bantuan', function () {
    return Inertia::render('HelpCenter');
})->name('help-center');

Route::get('/panduan-umkm', function () {
    return Inertia::render('PanduanUMKM');
})->name('panduan-umkm');

Route::get('/privasi', function () {
    return Inertia::render('PrivacyPolicy');
})->name('privacy-policy');

Route::get('/syarat-ketentuan', function () {
    return Inertia::render('TermsConditions');
})->name('terms-conditions');

Route::get('/changelog', function () {
    return Inertia::render('Changelog');
})->name('changelog');

// Super Admin Routes (without tenant middleware)
Route::middleware(['auth', 'verified', 'superadmin'])->prefix('superadmin')->name('superadmin.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\SuperAdmin\SuperAdminController::class, 'index'])->name('dashboard');
    
    // User Management
    Route::get('/users', [\App\Http\Controllers\SuperAdmin\UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [\App\Http\Controllers\SuperAdmin\UserManagementController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [\App\Http\Controllers\SuperAdmin\UserManagementController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [\App\Http\Controllers\SuperAdmin\UserManagementController::class, 'destroy'])->name('users.destroy');
    
    // Subscription Management
    Route::get('/subscriptions', [\App\Http\Controllers\SuperAdmin\SubscriptionManagementController::class, 'index'])->name('subscriptions.index');
    Route::put('/subscriptions/{subscription}', [\App\Http\Controllers\SuperAdmin\SubscriptionManagementController::class, 'update'])->name('subscriptions.update');
    Route::post('/subscriptions/{subscription}/extend', [\App\Http\Controllers\SuperAdmin\SubscriptionManagementController::class, 'extend'])->name('subscriptions.extend');
    Route::post('/subscriptions/{subscription}/upgrade', [\App\Http\Controllers\SuperAdmin\SubscriptionManagementController::class, 'upgrade'])->name('subscriptions.upgrade');
    Route::delete('/subscriptions/{subscription}', [\App\Http\Controllers\SuperAdmin\SubscriptionManagementController::class, 'destroy'])->name('subscriptions.destroy');
    
    // Bank Management
    Route::get('/banks', [\App\Http\Controllers\SuperAdmin\BankManagementController::class, 'index'])->name('banks.index');
    Route::post('/banks', [\App\Http\Controllers\SuperAdmin\BankManagementController::class, 'store'])->name('banks.store');
    Route::put('/banks/{bank}', [\App\Http\Controllers\SuperAdmin\BankManagementController::class, 'update'])->name('banks.update');
    Route::delete('/banks/{bank}', [\App\Http\Controllers\SuperAdmin\BankManagementController::class, 'destroy'])->name('banks.destroy');
    
    // WhatsApp Management (Super Admin)
    Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
        Route::get('/', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'store'])->name('store');
        Route::delete('/sessions/all', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'deleteAllSessions'])->name('delete-all-sessions');
        Route::get('/{channel}/qr', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'getQrCode'])->name('qr');
        Route::get('/{channel}/status', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'getStatus'])->name('status');
        Route::post('/{channel}/reconnect', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'reconnect'])->name('reconnect');
        Route::delete('/{channel}', [\App\Http\Controllers\SuperAdmin\WhatsAppController::class, 'destroy'])->name('destroy');
    });
    
    // Broadcast Messages (Super Admin)
    Route::prefix('broadcast')->name('broadcast.')->group(function () {
        Route::get('/', [\App\Http\Controllers\SuperAdmin\BroadcastController::class, 'index'])->name('index');
        Route::post('/send', [\App\Http\Controllers\SuperAdmin\BroadcastController::class, 'send'])->name('send');
        Route::post('/send-single', [\App\Http\Controllers\SuperAdmin\BroadcastController::class, 'sendSingle'])->name('send-single');
        Route::get('/templates', [\App\Http\Controllers\SuperAdmin\BroadcastController::class, 'getTemplates'])->name('templates');
    });
});

// Subscription Expired Route (accessible without subscription check)
Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('/subscription-expired', [\App\Http\Controllers\SubscriptionExpiredController::class, 'index'])->name('subscription.expired');
});

// Protected routes that require active subscription
Route::middleware(['auth', 'verified', 'tenant', 'subscription'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    
    Route::prefix('transactions')->group(function () {
        Route::get('/', [\App\Http\Controllers\TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/{transaction}', [\App\Http\Controllers\TransactionController::class, 'show'])->name('transactions.show');
        Route::patch('/{transaction}/status', [\App\Http\Controllers\TransactionController::class, 'updateStatus'])->name('transactions.update-status');
        Route::put('/{transaction}', [\App\Http\Controllers\TransactionController::class, 'update'])->name('transactions.update');
        Route::delete('/{transaction}', [\App\Http\Controllers\TransactionController::class, 'destroy'])->name('transactions.destroy');
    });
    
    Route::prefix('export')->group(function () {
        Route::post('/transactions', [\App\Http\Controllers\ExportController::class, 'exportTransactions'])->name('export.transactions');
    });
    
    Route::prefix('import')->group(function () {
        Route::post('/transactions', [\App\Http\Controllers\ImportController::class, 'import'])->name('import.transactions');
    });
    
    Route::prefix('tenants')->group(function () {
        Route::get('/', [\App\Http\Controllers\TenantController::class, 'index'])->name('tenants.index');
        Route::post('/{tenant}/switch', [\App\Http\Controllers\TenantController::class, 'switch'])->name('tenants.switch');
        
        Route::prefix('invitations')->group(function () {
            Route::get('/', [\App\Http\Controllers\TenantInvitationController::class, 'index'])->name('tenant-invitations.index');
            Route::post('/', [\App\Http\Controllers\TenantInvitationController::class, 'store'])->name('tenant-invitations.store');
            Route::delete('/{invitation}', [\App\Http\Controllers\TenantInvitationController::class, 'destroy'])->name('tenant-invitations.destroy');
        });
    });
    
    Route::get('/invitations/accept/{token}', [\App\Http\Controllers\TenantInvitationController::class, 'accept'])->name('tenant-invitations.accept');
    
    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [\App\Http\Controllers\SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/new', [\App\Http\Controllers\SubscriptionController::class, 'wizard'])->name('subscriptions.wizard');
        Route::post('/', [\App\Http\Controllers\SubscriptionController::class, 'request'])->name('subscriptions.request');
        Route::patch('/{subscription}', [\App\Http\Controllers\SubscriptionController::class, 'update'])->name('subscriptions.update');
        Route::post('/{subscription}/upload-payment-proof', [\App\Http\Controllers\SubscriptionController::class, 'uploadPaymentProof'])->name('subscriptions.upload-payment-proof');
    });
    
    Route::prefix('whatsapp')->group(function () {
        Route::get('/', [\App\Http\Controllers\WhatsAppChannelController::class, 'index'])->name('whatsapp.index');
        Route::post('/', [\App\Http\Controllers\WhatsAppChannelController::class, 'store'])->name('whatsapp.store');
        Route::delete('/sessions/all', [\App\Http\Controllers\WhatsAppChannelController::class, 'deleteAllSessions'])->name('whatsapp.delete-all-sessions');
        Route::get('/{channel}/qr', [\App\Http\Controllers\WhatsAppChannelController::class, 'getQrCode'])->name('whatsapp.qr');
        Route::get('/{channel}/status', [\App\Http\Controllers\WhatsAppChannelController::class, 'getStatus'])->name('whatsapp.status');
        Route::post('/{channel}/reconnect', [\App\Http\Controllers\WhatsAppChannelController::class, 'reconnect'])->name('whatsapp.reconnect');
        Route::delete('/{channel}', [\App\Http\Controllers\WhatsAppChannelController::class, 'destroy'])->name('whatsapp.destroy');
        
        // User WhatsApp numbers management
        Route::post('/numbers', [\App\Http\Controllers\WhatsAppChannelController::class, 'storeNumber'])->name('whatsapp.numbers.store');
        Route::put('/numbers/{userWhatsAppNumber}', [App\Http\Controllers\WhatsAppChannelController::class, 'updateNumber'])->name('whatsapp.numbers.update');
        Route::delete('/numbers/{userWhatsAppNumber}', [App\Http\Controllers\WhatsAppChannelController::class, 'destroyNumber'])->name('whatsapp.numbers.destroy');
        Route::post('/numbers/{userWhatsAppNumber}/primary', [App\Http\Controllers\WhatsAppChannelController::class, 'setPrimaryNumber'])->name('whatsapp.numbers.set-primary');
    });
    
    Route::prefix('balances')->group(function () {
        Route::get('/', [\App\Http\Controllers\BalanceController::class, 'index'])->name('balances.index');
        Route::post('/', [\App\Http\Controllers\BalanceController::class, 'store'])->name('balances.store');
        Route::put('/{id}', [\App\Http\Controllers\BalanceController::class, 'update'])->name('balances.update');
        Route::post('/{id}/set-default', [\App\Http\Controllers\BalanceController::class, 'setDefault'])->name('balances.set-default');
        Route::delete('/{id}', [\App\Http\Controllers\BalanceController::class, 'destroy'])->name('balances.destroy');
    });
    
    Route::prefix('budgets')->group(function () {
        Route::get('/', [\App\Http\Controllers\BudgetController::class, 'index'])->name('budgets.index');
        Route::post('/', [\App\Http\Controllers\BudgetController::class, 'store'])->name('budgets.store');
        Route::put('/{budget}', [\App\Http\Controllers\BudgetController::class, 'update'])->name('budgets.update');
        Route::delete('/{budget}', [\App\Http\Controllers\BudgetController::class, 'destroy'])->name('budgets.destroy');
        Route::get('/summary', [\App\Http\Controllers\BudgetController::class, 'summary'])->name('budgets.summary');
        Route::post('/{budget}/toggle', [\App\Http\Controllers\BudgetController::class, 'toggle'])->name('budgets.toggle');
    });
});

require __DIR__.'/settings.php';
