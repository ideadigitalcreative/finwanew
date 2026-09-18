<?php

use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

test('TelegramService can get bot info with getMe', function () {
    Http::fake([
        'api.telegram.org/bot*/getMe' => Http::response([
            'ok' => true,
            'result' => [
                'id' => 123456789,
                'username' => 'finwa_bot',
                'first_name' => 'FinWa Bot',
            ],
        ], 200),
    ]);

    $service = new TelegramService();
    $result = $service->getMe();

    expect($result['success'])->toBeTrue();
    expect($result['data']['result']['username'])->toBe('finwa_bot');
});

test('TelegramService sendMessage with throttle', function () {
    Cache::forget('telegram_throttle:987654321');

    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 1,
                'chat' => ['id' => 987654321],
            ],
        ], 200),
    ]);

    $service = new TelegramService();
    $result = $service->sendMessage(987654321, 'Hello Telegram!');

    expect($result['success'])->toBeTrue();
    expect($result['data']['result']['message_id'])->toBe(1);
});

test('TelegramService handles API error gracefully', function () {
    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad request: chat not found',
        ], 400),
    ]);

    $service = new TelegramService();
    $result = $service->sendMessage(999999999, 'Test message');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->not->toBeEmpty();
});

test('TelegramService throttles messages per chat_id', function () {
    Cache::forget('telegram_throttle:111222333');

    $startTime = microtime(true);

    Http::fake([
        'api.telegram.org/bot*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1],
        ], 200),
    ]);

    $service = new TelegramService();

    // Send first message
    $service->sendMessage(111222333, 'First');
    $firstTime = microtime(true);

    // Send second message immediately (should be throttled)
    $service->sendMessage(111222333, 'Second');
    $secondTime = microtime(true);

    $elapsed = $secondTime - $firstTime;

    // Should have waited at least 0.05 seconds (throttle interval)
    expect($elapsed)->toBeGreaterThanOrEqual(0.04);
});

test('TelegramService can send photo with URL', function () {
    Http::fake([
        'api.telegram.org/bot*/sendPhoto' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 2,
                'photo' => [['file_id' => 'photo123']],
            ],
        ], 200),
    ]);

    $service = new TelegramService();
    $result = $service->sendPhoto(
        987654321,
        'https://example.com/photo.jpg',
        'Test photo caption'
    );

    expect($result['success'])->toBeTrue();
});

test('TelegramService can set webhook with secret token', function () {
    Http::fake([
        'api.telegram.org/bot*/setWebhook' => Http::response([
            'ok' => true,
            'result' => [
                'url' => 'https://api.finwa.web.id/api/webhooks/telegram/message',
                'has_custom_certificate' => false,
            ],
        ], 200),
    ]);

    $service = new TelegramService();
    $result = $service->setWebhook(
        'https://api.finwa.web.id/api/webhooks/telegram/message',
        'test-secret-token'
    );

    expect($result['success'])->toBeTrue();
});

test('TelegramService answerCallbackQuery', function () {
    Http::fake([
        'api.telegram.org/bot*/answerCallbackQuery' => Http::response([
            'ok' => true,
            'result' => true,
        ], 200),
    ]);

    $service = new TelegramService();
    $result = $service->answerCallbackQuery(
        '1234567890123456789',
        'Operation completed!',
        false
    );

    expect($result['success'])->toBeTrue();
});

test('TelegramService sendChatAction', function () {
    Http::fake([
        'api.telegram.org/bot*/sendChatAction' => Http::response([
            'ok' => true,
            'result' => true,
        ], 200),
    ]);

    $service = new TelegramService();
    $result = $service->sendChatAction(987654321, 'typing');

    expect($result['success'])->toBeTrue();
});
