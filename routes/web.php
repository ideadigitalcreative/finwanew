<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function (Request $request) {
    // Buka dari PWA (manifest lama atau bookmark) → langsung ke alur app, bukan landing
    if ($request->query('source') === 'pwa') {
        return redirect()->route('pwa.start');
    }

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/reports/{tenantId}/download/{filename}', function (int $tenantId, string $filename) {
    $rawFilename = $filename;
    $filename = trim($filename);
    $filename = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $filename) ?? $filename;
    $filename = trim($filename, " \t\n\r\0\x0B`'\"");
    $filename = rtrim($filename, ".,)>]");
    $filename = basename($filename);

    if (! preg_match('/\.pdf$/i', $filename)) {
        Log::warning('Report download invalid filename', [
            'tenant_id' => $tenantId,
            'raw_filename' => $rawFilename,
            'normalized_filename' => $filename,
        ]);
        abort(404);
    }
    $relativePath = "reports/{$tenantId}/{$filename}";

    $disk = Storage::disk('public');
    $fullPath = null;

    if ($disk->exists($relativePath)) {
        $fullPath = $disk->path($relativePath);
    } else {
        $fallbackPaths = [
            storage_path('app/public/'.$relativePath),
            public_path('storage/'.$relativePath),
            public_path($relativePath),
        ];

        foreach ($fallbackPaths as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                $fullPath = $candidate;
                break;
            }
        }
    }

    if (! $fullPath) {
        Log::warning('Report download not found', [
            'tenant_id' => $tenantId,
            'filename' => $filename,
            'relative_path' => $relativePath,
            'disk_root' => $disk->path(''),
        ]);
        abort(404);
    }

    if (! is_readable($fullPath)) {
        Log::warning('Report download not readable', [
            'tenant_id' => $tenantId,
            'filename' => $filename,
            'relative_path' => $relativePath,
            'full_path' => $fullPath,
        ]);
        abort(403);
    }

    return response()->file($fullPath, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->whereNumber('tenantId')->where('filename', '[^/]+');

/**
 * Entry khusus PWA: login jika belum auth, dashboard (atau superadmin) jika sudah.
 * Path memakai /finwa/launch (bukan /pwa/...) — banyak server/Nginx mem-blok atau salah arahkan URI /pwa/*.
 */
Route::get('/finwa/launch', function () {
    if (! Auth::check()) {
        return redirect('/login?source=pwa');
    }

    $user = Auth::user();
    if ($user instanceof \App\Models\User && $user->isSuperAdmin()) {
        return redirect()->route('superadmin.dashboard');
    }

    return redirect()->route('dashboard');
})->name('pwa.start');

/** Manifest lama / bookmark yang masih menunjuk ke /pwa/start */
Route::get('/pwa/start', function (Request $request) {
    $q = $request->getQueryString();
    $target = '/finwa/launch'.($q !== null && $q !== '' ? '?'.$q : '');

    return redirect()->to($target, 301);
});

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

Route::get('/tentang-kami', function () {
    return Inertia::render('AboutUs');
})->name('about-us');

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

    Route::get('/gemini-settings', [\App\Http\Controllers\SuperAdmin\GeminiSettingsController::class, 'index'])->name('gemini-settings.index');
    Route::put('/gemini-settings', [\App\Http\Controllers\SuperAdmin\GeminiSettingsController::class, 'update'])->name('gemini-settings.update');

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
        Route::post('/parse', [\App\Http\Controllers\TransactionController::class, 'parse'])->name('transactions.parse');
        Route::post('/parse-receipt', [\App\Http\Controllers\TransactionController::class, 'parseReceipt'])->name('transactions.parse-receipt');
        Route::post('/store-json', [\App\Http\Controllers\TransactionController::class, 'storeJson'])->name('transactions.store-json');
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

    Route::get('/hutang-piutang', [\App\Http\Controllers\DebtReceivableController::class, 'index'])->name('debt-receivable.index');

    Route::prefix('budgets')->group(function () {
        Route::get('/', [\App\Http\Controllers\BudgetController::class, 'index'])->name('budgets.index');
        Route::post('/', [\App\Http\Controllers\BudgetController::class, 'store'])->name('budgets.store');
        Route::put('/{budget}', [\App\Http\Controllers\BudgetController::class, 'update'])->name('budgets.update');
        Route::delete('/{budget}', [\App\Http\Controllers\BudgetController::class, 'destroy'])->name('budgets.destroy');
        Route::get('/summary', [\App\Http\Controllers\BudgetController::class, 'summary'])->name('budgets.summary');
        Route::post('/{budget}/toggle', [\App\Http\Controllers\BudgetController::class, 'toggle'])->name('budgets.toggle');
    });

    // File serving for authenticated users
    Route::get('/files', function (\Illuminate\Http\Request $request) {
        $path = $request->query('path');
        if (empty($path)) {
            abort(404, 'Path is required');
        }

        $decodedPath = $path;
        $maxIterations = 5;
        $iteration = 0;
        while (str_contains($decodedPath, '%') && $iteration < $maxIterations) {
            $newDecoded = urldecode($decodedPath);
            if ($newDecoded === $decodedPath) {
                break;
            }
            $decodedPath = $newDecoded;
            $iteration++;
        }

        $decodedPath = trim($decodedPath, '/');

        // Security check
        if (! str_starts_with($decodedPath, 'whatsapp/')) {
            abort(403, 'Invalid file path');
        }

        $fullPath = storage_path('app/public/'.$decodedPath);
        if (! file_exists($fullPath)) {
            abort(404, 'File not found');
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
            'Content-Disposition' => 'inline',
        ]);
    })->name('files.serve');
});

require __DIR__.'/settings.php';

require base_path('routes/risen-ai.php');
