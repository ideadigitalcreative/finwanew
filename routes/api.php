<?php

use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Auth Routes with enhanced rate limiting
Route::prefix('auth')->middleware('throttle:api-auth')->group(function () {
    Route::post('/register', [\App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('/google', [\App\Http\Controllers\Api\AuthController::class, 'googleLogin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
        Route::get('/user', [\App\Http\Controllers\Api\AuthController::class, 'user']);

        // Protected by Subscription Check
        Route::middleware('subscription')->group(function () {
            // Dashboard
            Route::get('/dashboard/summary', [\App\Http\Controllers\Api\DashboardController::class, 'summary']);

            // Transactions
            Route::get('/transactions', [\App\Http\Controllers\Api\TransactionController::class, 'index']);
            Route::post('/transactions', [\App\Http\Controllers\Api\TransactionController::class, 'store']);
            Route::post('/transactions/parse', [\App\Http\Controllers\Api\TransactionController::class, 'parse']);

            // Balances (Reuse BalanceController with tenant fallback)
            Route::get('/balances', [\App\Http\Controllers\Api\BalanceController::class, 'index']);
            Route::post('/balances', [\App\Http\Controllers\Api\BalanceController::class, 'store']);

            // WhatsApp Link (Token-based)
            Route::post('/whatsapp-link/token', [\App\Http\Controllers\Api\WhatsAppLinkController::class, 'generateToken']);
        });
    });
});

// WhatsApp Webhook Routes (protected with API key)
Route::prefix('webhooks/whatsapp')
    ->middleware('api.key:webhook')
    ->group(function () {
        // Routes untuk menerima dari core-api (internal)
        Route::post('/message', [WhatsAppWebhookController::class, 'handleMessage']);
        Route::post('/attachment', [WhatsAppWebhookController::class, 'handleAttachment']);
        Route::post('/status', [WhatsAppWebhookController::class, 'handleStatus']);

        // Route untuk menerima dari wa-blast engine
        Route::post('/from-engine', [\App\Http\Controllers\Webhook\WhatsAppEngineWebhookController::class, 'handleMessage']);

        // Route untuk menerima LID mapping dari gateway (auto-discovery)
        Route::post('/lid-mapping', [\App\Http\Controllers\Webhook\WhatsAppEngineWebhookController::class, 'handleLidMapping']);
    });

// OCR & STT Job Routes
Route::prefix('ocr-jobs')->group(function () {
    Route::post('/{id}/update', [\App\Http\Controllers\Api\OcrJobController::class, 'update']);
});

// File serving for OCR/STT workers (protected with API key)
// Route dengan query parameter (didefinisikan dulu untuk priority)
// URL: /api/files?path=whatsapp/12/2025/11/10/file.jpg
Route::get('/files', [\App\Http\Controllers\Api\FileController::class, 'serveFromRequest'])
    ->name('api.files.serve.query');

// Route dengan path parameter (backward compatible)
// URL: /api/files/whatsapp/12/2025/11/10/file.jpg
Route::get('/files/{path}', [\App\Http\Controllers\Api\FileController::class, 'serve'])
    ->where('path', '.*')
    ->name('api.files.serve');

Route::prefix('stt-jobs')->group(function () {
    Route::post('/{id}/update', [\App\Http\Controllers\Api\SttJobController::class, 'update']);
});

// Telegram Webhook Routes
Route::prefix('webhooks/telegram')->group(function () {
    Route::post('/message', [\App\Http\Controllers\Webhook\TelegramWebhookController::class, 'handleMessage']);
});

// Slack Webhook Routes
Route::prefix('webhooks/slack')->group(function () {
    Route::post('/message', [\App\Http\Controllers\Webhook\SlackWebhookController::class, 'handleMessage']);
});

// Tenant API Routes
Route::prefix('tenants/{tenant}')->group(function () {
    Route::get('/balances', [\App\Http\Controllers\Api\BalanceController::class, 'index']);
    Route::post('/balances', [\App\Http\Controllers\Api\BalanceController::class, 'store']);
    Route::put('/balances/{id}', [\App\Http\Controllers\Api\BalanceController::class, 'update']);
    Route::delete('/balances/{id}', [\App\Http\Controllers\Api\BalanceController::class, 'destroy']);
    Route::get('/cashflow', [\App\Http\Controllers\Api\CashflowController::class, 'index']);
});

// Channel Routes
Route::prefix('channels')->group(function () {
    Route::get('/telegram/{chatId}', [\App\Http\Controllers\Api\ChannelController::class, 'getTelegramChannel']);
    Route::get('/slack/{teamId}', [\App\Http\Controllers\Api\ChannelController::class, 'getSlackChannel']);
    Route::post('/', [\App\Http\Controllers\Api\ChannelController::class, 'store']);
});

// WhatsApp Number Validation (public with rate limiting - 5 requests per minute)
Route::post('/validate-whatsapp', function (\Illuminate\Http\Request $request) {
    $phoneNumber = $request->input('phone_number');

    if (empty($phoneNumber)) {
        return response()->json([
            'success' => false,
            'error' => 'Phone number is required',
        ], 400);
    }

    // Clean phone number for database check
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (substr($cleanNumber, 0, 1) === '0') {
        $cleanNumber = '62'.substr($cleanNumber, 1);
    }
    if (substr($cleanNumber, 0, 2) !== '62') {
        $cleanNumber = '62'.$cleanNumber;
    }

    // Check if number is already registered in database
    $existingUser = \App\Models\User::where('whatsapp_number', $cleanNumber)
        ->orWhere('whatsapp_number', '0'.substr($cleanNumber, 2))
        ->orWhere('whatsapp_number', '+'.$cleanNumber)
        ->first();

    if ($existingUser) {
        return response()->json([
            'success' => true,
            'exists' => true,
            'already_registered' => true,
            'message' => 'Nomor WhatsApp ini sudah terdaftar. Silakan login.',
        ]);
    }

    // Get active WhatsApp session
    $channel = \App\Models\Channel::where('type', 'whatsapp')
        ->where('is_active', true)
        ->first();

    if (! $channel) {
        // If no active session, assume valid (fallback)
        return response()->json([
            'success' => true,
            'exists' => true,
            'already_registered' => false,
            'message' => 'Validation skipped - no active session',
        ]);
    }

    $sessionId = $channel->config['session_id'] ?? null;

    if (! $sessionId) {
        return response()->json([
            'success' => true,
            'exists' => true,
            'already_registered' => false,
            'message' => 'Validation skipped - no session ID',
        ]);
    }

    $whatsAppService = new \App\Services\WhatsAppService;
    $result = $whatsAppService->checkNumber($sessionId, $phoneNumber);

    // Add already_registered flag
    $result['already_registered'] = false;

    return response()->json($result);
})->middleware('throttle:5,1'); // 5 requests per minute per IP
