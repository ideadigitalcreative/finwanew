<?php

use App\Models\User;
use App\Models\UserTelegramMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('telegram link status returns disconnected for user without telegram', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('telegram.link-status'));

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'telegram_connected' => false,
        'telegram_chat_id' => null,
        'has_valid_token' => false,
    ]);
});

test('telegram link status returns connected after user links telegram', function () {
    $user = User::factory()->create([
        'telegram_chat_id' => 111222333,
        'telegram_username' => 'testuser',
    ]);

    $response = $this->actingAs($user)->getJson(route('telegram.link-status'));

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'telegram_connected' => true,
        'telegram_chat_id' => 111222333,
        'telegram_username' => 'testuser',
    ]);
});

test('telegram webhook deep link updates user telegram_chat_id', function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);

    $user = User::factory()->create();
    $plainToken = 'testtoken1234567890123456789012';

    $user->forceFill([
        'telegram_link_token' => Hash::make($plainToken),
        'telegram_link_token_created_at' => now(),
    ])->save();

    $this->mock(\App\Services\TelegramService::class, function ($mock) {
        $mock->shouldReceive('sendMessage')->once();
    });

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
            'text' => '/start link_'.$plainToken,
        ],
    ], [
        'X-Telegram-Bot-Api-Secret' => 'test-secret',
    ]);

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'message' => 'Telegram account linked successfully',
    ]);

    $user->refresh();

    expect($user->telegram_chat_id)->toBe(111222333);
    expect($user->telegram_username)->toBe('linkuser');
    expect($user->telegram_link_token)->toBeNull();

    $this->assertDatabaseHas('user_telegram_mappings', [
        'user_id' => $user->id,
        'telegram_chat_id' => 111222333,
        'telegram_username' => 'linkuser',
    ]);
});

test('inertia shared auth includes telegram fields when connected', function () {
    $user = User::factory()->create([
        'telegram_chat_id' => 987654321,
        'telegram_username' => 'finwa_user',
    ]);

    $response = $this->actingAs($user)->get(route('telegram.connect'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Telegram/Connect')
        ->where('auth.user.telegram_chat_id', 987654321)
        ->where('auth.user.telegram_username', 'finwa_user')
    );
});
