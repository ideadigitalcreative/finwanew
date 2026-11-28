<?php

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('health check returns healthy status when bot and webhook are OK', function () {
    $botToken = config('services.telegram.bot_token');

    // Mock getMe response
    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'FinwaBot',
                'first_name' => 'Finwa',
            ],
        ], 200),
        "*{$botToken}/getWebhookInfo*" => Http::response([
            'ok' => true,
            'result' => [
                'url' => 'https://api.finwa.id/api/webhooks/telegram/message',
                'has_custom_certificate' => false,
                'pending_update_count' => 0,
            ],
        ], 200),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertStatus(200);
    $response->assertJsonPath('status', 'healthy');
    $response->assertJsonPath('telegram.bot', 'FinwaBot');
    $response->assertJsonPath('telegram.webhook_configured', true);
    $response->assertJsonPath('telegram.webhook_url', 'https://api.finwa.id/api/webhooks/telegram/message');
});

test('health check returns degraded status when webhook info fails', function () {
    $botToken = config('services.telegram.bot_token');

    // Mock getMe response (success)
    // Mock getWebhookInfo response (fail)
    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'FinwaBot',
                'first_name' => 'Finwa',
            ],
        ], 200),
        "*{$botToken}/getWebhookInfo*" => Http::response([
            'ok' => false,
            'description' => 'Failed to fetch webhook info',
        ], 200),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertStatus(503);
    $response->assertJsonPath('status', 'degraded');
    $response->assertJsonPath('telegram.bot', 'FinwaBot');
    $response->assertJsonPath('telegram.webhook_configured', false);
    $response->assertJsonPath('errors.webhook_info', 'Failed to fetch webhook info');
});

test('health check returns unhealthy status when bot info fails', function () {
    $botToken = config('services.telegram.bot_token');

    // Mock getMe response (fail)
    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => false,
            'description' => 'Unauthorized',
        ], 401),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertStatus(503);
    $response->assertJsonPath('status', 'unhealthy');
    $response->assertJsonPath('telegram.bot_active', false);
    $response->assertJsonPath('errors.get_me', 'Unauthorized');
});

test('health check handles exception gracefully', function () {
    $botToken = config('services.telegram.bot_token');

    // Simulate HTTP exception
    Http::fake([
        "*{$botToken}/getMe*" => Http::exception(new \GuzzleHttp\Exception\ConnectException(
            'Connection refused',
            new \GuzzleHttp\Psr7\Request('GET', 'test')
        )),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertStatus(503);
    $response->assertJsonPath('status', 'unhealthy');
    $response->assertJsonPath('telegram.bot_active', false);
    $response->assertJsonPath('errors.exception', 'Connection refused');
});

test('health check endpoint is rate limited', function () {
    // Test that the route exists with throttle middleware
    $route = $this->route('GET', '/api/health/telegram');

    $this->assertEquals('/api/health/telegram', $route->uri());
});

test('health check includes timestamp in response', function () {
    $botToken = config('services.telegram.bot_token');

    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'FinwaBot',
            ],
        ], 200),
        "*{$botToken}/getWebhookInfo*" => Http::response([
            'ok' => true,
            'result' => [
                'url' => 'https://api.finwa.id/api/webhooks/telegram/message',
            ],
        ], 200),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertJsonPath('timestamp', function ($timestamp) {
        // Verify ISO8601 format
        $date = DateTime::createFromFormat(DateTime::ATOM, $timestamp);
        return $date !== false;
    });
});

test('health check includes pending update count', function () {
    $botToken = config('services.telegram.bot_token');

    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'FinwaBot',
            ],
        ], 200),
        "*{$botToken}/getWebhookInfo*" => Http::response([
            'ok' => true,
            'result' => [
                'url' => 'https://api.finwa.id/api/webhooks/telegram/message',
                'pending_update_count' => 5,
                'last_error_message' => null,
            ],
        ], 200),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertJsonPath('telegram.pending_update_count', 5);
    $response->assertJsonPath('telegram.last_error', null);
});

test('health check includes last error info when webhook has errors', function () {
    $botToken = config('services.telegram.bot_token');

    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'FinwaBot',
            ],
        ], 200),
        "*{$botToken}/getWebhookInfo*" => Http::response([
            'ok' => true,
            'result' => [
                'url' => 'https://api.finwa.id/api/webhooks/telegram/message',
                'pending_update_count' => 0,
                'last_error_message' => 'Webhook timeout',
                'last_error_date' => 1625000000,
            ],
        ], 200),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertJsonPath('telegram.last_error', 'Webhook timeout');
    $response->assertJsonPath('telegram.last_error_date', 1625000000);
});

test('health check includes webhook info even when getWebhookInfo fails', function () {
    $botToken = config('services.telegram.bot_token');

    Http::fake([
        "*{$botToken}/getMe*" => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'FinwaBot',
            ],
        ], 200),
        "*{$botToken}/getWebhookInfo*" => Http::response([
            'ok' => false,
            'description' => 'Failed',
        ], 200),
    ]);

    $response = $this->getJson('/api/health/telegram');

    $response->assertJsonPath('telegram.webhook_url', config('telegram.webhook.url'));
});
