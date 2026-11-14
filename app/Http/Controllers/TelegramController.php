<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class TelegramController extends Controller
{
    /**
     * Display the Telegram connect page.
     */
    public function connect()
    {
        return Inertia::render('Telegram/Connect');
    }

    /**
     * Generate Telegram link token.
     */
    public function generateLink(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }

            // Invalidate existing token if it's still valid
            if (method_exists($user, 'hasValidTelegramLinkToken') && $user->hasValidTelegramLinkToken()) {
                $user->invalidateTelegramLinkToken();
            }

            // Generate new token
            if (!method_exists($user, 'generateTelegramLinkToken')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Method generateTelegramLinkToken not found in User model',
                ], 500);
            }

            // Get bot username from config — wajib terisi agar deep link valid
            $botUsername = config('services.telegram.bot_username');

            if (empty($botUsername)) {
                Log::error('Telegram generate link: bot_username tidak dikonfigurasi', [
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Konfigurasi bot Telegram belum lengkap. Hubungi administrator.',
                ], 503);
            }

            $token = $user->generateTelegramLinkToken();

            // Build deep link URL — telegram.me lebih andal jika t.me diblokir DNS
            $deepLinkUrl = "https://telegram.me/{$botUsername}?start=link_{$token}";
            $alternateDeepLinkUrl = "https://t.me/{$botUsername}?start=link_{$token}";

            Log::info('Telegram link token generated', [
                'user_id' => $user->id,
                'bot_username' => $botUsername,
                'expires_at' => now()->addHour()->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Telegram linking token generated',
                'data' => [
                    'token' => $token,
                    'bot_username' => $botUsername,
                    'deep_link_url' => $deepLinkUrl,
                    'alternate_deep_link_url' => $alternateDeepLinkUrl,
                    'expires_at' => now()->addHour()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating Telegram link token', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Terjadi kesalahan server saat membuat link Telegram. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Check link token and Telegram connection status.
     */
    public function linkStatus(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        // Ambil data terbaru dari DB (setelah webhook menghubungkan Telegram)
        $user->refresh();

        $response = [
            'success' => true,
            'telegram_connected' => $user->hasLinkedTelegram(),
            'telegram_chat_id' => $user->telegram_chat_id,
            'telegram_username' => $user->telegram_username,
            'has_valid_token' => $user->hasValidTelegramLinkToken(),
        ];

        if ($user->hasValidTelegramLinkToken()) {
            $response['expires_at'] = $user->telegram_link_token_created_at->addHour()->toISOString();
        }

        return response()->json($response);
    }
}
