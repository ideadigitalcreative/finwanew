<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;

class TelegramHealthController extends Controller
{
    public function check(): JsonResponse
    {
        $telegram = app(TelegramService::class);

        try {
            $me = $telegram->getMe();
            $webhookInfo = $telegram->getWebhookInfo();

            $botOk = $me['success'] ?? false;

            // Struktur respons Telegram: ['data']['result'][...]
            $meResult = $me['data']['result'] ?? [];
            $webhookResult = $webhookInfo['data']['result'] ?? [];

            // Webhook dianggap terkonfigurasi hanya jika API call sukses DAN url tidak kosong
            $webhookUrl = $webhookResult['url'] ?? null;
            $webhookOk = ($webhookInfo['success'] ?? false) && !empty($webhookUrl);

            $status = ($botOk && $webhookOk) ? 'healthy' : 'degraded';
            $statusCode = $status === 'healthy' ? 200 : 503;

            $response = [
                'status' => $status,
                'timestamp' => now()->toIso8601String(),
                'telegram' => [
                    'bot_active' => $botOk,
                    'webhook_configured' => $webhookOk,
                ],
            ];

            if ($botOk && !empty($meResult)) {
                $response['telegram']['bot'] = $meResult['username'] ?? null;
                $response['telegram']['bot_id'] = $meResult['id'] ?? null;
            }

            if (($webhookInfo['success'] ?? false) && !empty($webhookResult)) {
                $response['telegram']['webhook_url'] = $webhookUrl ?: config('services.telegram.webhook_url');
                $response['telegram']['has_custom_certificate'] = $webhookResult['has_custom_certificate'] ?? false;
                $response['telegram']['pending_update_count'] = $webhookResult['pending_update_count'] ?? 0;
                $response['telegram']['last_error'] = $webhookResult['last_error_message'] ?? null;
                $response['telegram']['last_error_date'] = $webhookResult['last_error_date'] ?? null;
            } else {
                $response['telegram']['webhook_url'] = config('services.telegram.webhook_url');
            }

            if (!$botOk) {
                $response['errors'] = [
                    'get_me' => $me['data']['description'] ?? $me['error'] ?? 'Failed to fetch bot info',
                ];
            }

            if (!$webhookOk) {
                $response['errors']['webhook_info'] = empty($webhookUrl)
                    ? 'Webhook URL belum dipasang. Jalankan: php artisan app:telegram-setup-webhook'
                    : ($webhookInfo['data']['description'] ?? $webhookInfo['error'] ?? 'Failed to fetch webhook info');
            }

            return response()->json($response, $statusCode);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'unhealthy',
                'timestamp' => now()->toIso8601String(),
                'telegram' => [
                    'bot_active' => false,
                    'webhook_configured' => false,
                ],
                'errors' => [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ], 503);
        }
    }
}
