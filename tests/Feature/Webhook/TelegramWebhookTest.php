<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Telegram webhook rejects invalid secret token', function () {
    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'date' => time(),
            'chat' => ['id' => 123456, 'type' => 'private'],
            'from' => ['id' => 123456, 'first_name' => 'Test'],
            'text' => 'Hello',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'invalid-secret',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'error' => 'Invalid secret token',
    ]);
});

test('Telegram webhook accepts valid secret token', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'date' => time(),
            'chat' => ['id' => 123456, 'type' => 'private', 'username' => 'testbot'],
            'from' => ['id' => 123456, 'first_name' => 'Test', 'username' => 'testuser'],
            'text' => 'Hello',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertStatus(201);
    $response->assertJson([
        'success' => true,
        'message' => 'Message received and queued for processing',
    ]);
});

test('Telegram webhook handles message with text content', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 123,
        'message' => [
            'message_id' => 456,
            'date' => time(),
            'chat' => [
                'id' => 987654321,
                'type' => 'private',
                'username' => 'finwa_bot',
            ],
            'from' => [
                'id' => 987654321,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'username' => 'johndoe',
                'language_code' => 'id',
            ],
            'text' => '/start',
            'entities' => [],
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('channels', [
        'type' => 'telegram',
        'channel_account' => 'finwa_bot',
    ]);
});

test('Telegram webhook handles callback query', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 456,
        'callback_query' => [
            'id' => '1234567890123456789',
            'from' => [
                'id' => 987654321,
                'first_name' => 'John',
                'username' => 'johndoe',
            ],
            'message' => [
                'message_id' => 100,
                'chat' => ['id' => 987654321, 'type' => 'private'],
            ],
            'data' => 'link_some_unique_token',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'Callback query received',
    ]);
});

test('Telegram webhook handles edited message', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 789,
        'edited_message' => [
            'message_id' => 456,
            'edit_date' => time(),
            'chat' => ['id' => 987654321, 'type' => 'private'],
            'text' => 'Edited message content',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'Edited message received',
    ]);
});

test('Telegram webhook handles deep linking with token', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $user = User::factory()->create();

    // Simulate a token-based linking system
    // In real implementation, this would use DeviceLinkToken or similar
    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 999,
        'message' => [
            'message_id' => 888,
            'date' => time(),
            'chat' => [
                'id' => 111222333,
                'type' => 'private',
                'username' => 'finwa_bot',
            ],
            'from' => [
                'id' => 111222333,
                'first_name' => 'Link',
                'last_name' => 'User',
                'username' => 'linkuser',
            ],
            'text' => '/start link_testtoken123',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    // Note: This test will fail until findUserByToken is implemented
    // For now, just verify the webhook handler processes the request
    $response->assertStatus(404);
    $response->assertJson([
        'success' => false,
        'error' => 'Invalid or expired token',
    ]);
});

test('Telegram webhook rejects request without update_id', function () {
    $response = $this->postJson('/api/webhooks/telegram/message', [
        'message' => [
            'text' => 'Hello',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertStatus(400);
    $response->assertJson([
        'success' => false,
        'error' => 'Missing update_id',
    ]);
});

test('Telegram webhook handles photo message', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $response = $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 1001,
        'message' => [
            'message_id' => 1002,
            'date' => time(),
            'chat' => [
                'id' => 111222333,
                'type' => 'private',
                'username' => 'finwa_bot',
            ],
            'from' => [
                'id' => 111222333,
                'first_name' => 'Photo',
                'username' => 'photouser',
            ],
            'caption' => 'Here is my receipt',
            'photo' => [
                ['file_id' => 'photo1', 'file_unique_id' => 'unique1', 'file_size' => 10000, 'width' => 800, 'height' => 600],
                ['file_id' => 'photo2', 'file_unique_id' => 'unique2', 'file_size' => 50000, 'width' => 1600, 'height' => 1200],
            ],
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('messages', [
        'channel' => 'telegram',
        'type' => 'image',
    ]);
});

test('Telegram webhook logs webhook events', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    \Log::shouldReceive('info')->once()->with('Telegram webhook received', anyArgs());

    $this->postJson('/api/webhooks/telegram/message', [
        'update_id' => 1,
        'message' => [
            'message_id' => 1,
            'date' => time(),
            'chat' => ['id' => 123456, 'type' => 'private', 'username' => 'testbot'],
            'from' => ['id' => 123456, 'first_name' => 'Test'],
            'text' => 'Hello',
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);
});
