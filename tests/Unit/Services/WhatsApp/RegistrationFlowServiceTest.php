<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsApp\RegistrationFlowService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('tenants', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->string('whatsapp_number')->nullable();
        $table->unsignedBigInteger('tenant_id')->default(1);
        $table->unsignedBigInteger('role_id')->nullable();
        $table->string('lid')->nullable();
        $table->timestamps();
    });

    Schema::create('user_whatsapp_numbers', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('tenant_id');
        $table->string('whatsapp_number');
        $table->string('name')->nullable();
        $table->boolean('is_primary')->default(false);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_lid')->default(false);
        $table->timestamps();
    });

    Schema::create('channels', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('type')->default('whatsapp');
        $table->string('channel_account')->nullable();
        $table->string('name')->nullable();
        $table->string('session_id')->nullable();
        $table->string('session_status')->nullable();
        $table->text('config')->nullable();
        $table->boolean('is_active')->default(true);
        $table->boolean('is_shared_channel')->default(false);
        $table->timestamp('last_activity_at')->nullable();
        $table->timestamps();
    });

    Schema::create('messages', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('channel_id')->nullable();
        $table->string('channel')->nullable();
        $table->string('channel_account')->nullable();
        $table->string('sender_id')->nullable();
        $table->string('message_id')->nullable();
        $table->string('type')->default('text');
        $table->text('content')->nullable();
        $table->integer('timestamp')->nullable();
        $table->text('raw_data')->nullable();
        $table->text('metadata')->nullable();
        $table->string('status')->default('received');
        $table->timestamps();
    });

    Channel::create([
        'id' => 1,
        'tenant_id' => 1,
        'type' => 'whatsapp',
        'channel_account' => 'test_account',
        'name' => 'Test Channel',
        'config' => json_encode(['session_id' => 'wa_1_test']),
        'is_active' => true,
    ]);
});

afterEach(function () {
    Schema::dropIfExists('messages');
    Schema::dropIfExists('channels');
    Schema::dropIfExists('user_whatsapp_numbers');
    Schema::dropIfExists('users');
    Schema::dropIfExists('tenants');
    Cache::flush();
});

function createMessage(array $overrides = []): Message
{
    $defaults = [
        'tenant_id' => 1,
        'sender_id' => '',
        'channel_id' => 1,
        'content' => '',
        'type' => 'text',
        'metadata' => null,
        'status' => 'received',
    ];
    $data = array_merge($defaults, $overrides);

    return Message::create($data);
}

it('returns shouldContinue true for empty sender number', function () {
    $service = new RegistrationFlowService(createMessage(['sender_id' => '']));
    $result = $service->resolve();

    expect($result)->toBe(['handled' => false, 'shouldContinue' => true]);
});

it('finds user by primary whatsapp_number and corrects tenant', function () {
    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => '6281234567890',
        'tenant_id' => 2,
    ]);

    $message = createMessage([
        'sender_id' => '6281234567890',
        'tenant_id' => 1,
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result)->toBe(['handled' => false, 'shouldContinue' => true]);
    expect($message->fresh()->tenant_id)->toBe(2);
});

it('finds user by UserWhatsAppNumber mapping', function () {
    $user = User::create([
        'name' => 'Mapped User',
        'email' => 'mapped@example.com',
        'password' => bcrypt('password'),
        'whatsapp_number' => '6281111222233',
        'tenant_id' => 3,
    ]);

    UserWhatsAppNumber::create([
        'user_id' => $user->id,
        'tenant_id' => 3,
        'whatsapp_number' => '6289999888877',
        'name' => 'Secondary WA',
        'is_active' => true,
    ]);

    $message = createMessage([
        'sender_id' => '6289999888877',
        'tenant_id' => 1,
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result)->toBe(['handled' => false, 'shouldContinue' => true]);
    expect($message->fresh()->tenant_id)->toBe(3);
});

it('handles LID format with challenge for tenant 1', function () {
    $message = createMessage([
        'sender_id' => '0987654321@s.whatsapp.net',
        'tenant_id' => 1,
        'metadata' => json_encode(['original_sender_id' => '0987654321@s.whatsapp.net']),
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result['handled'])->toBeTrue();
    expect($result['shouldContinue'])->toBeFalse();
});

it('handles unregistered Indonesian phone for tenant 1', function () {
    $message = createMessage([
        'sender_id' => '6285556667778',
        'tenant_id' => 1,
        'content' => 'halo',
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result['handled'])->toBeTrue();
    expect($result['shouldContinue'])->toBeFalse();
});

it('does not block non-tenant-1 unregistered phone', function () {
    $message = createMessage([
        'sender_id' => '6289999888877',
        'tenant_id' => 5,
        'content' => 'halo',
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result)->toBe(['handled' => false, 'shouldContinue' => true]);
});

it('does not block non-tenant-1 LID', function () {
    $message = createMessage([
        'sender_id' => '0987654321@s.whatsapp.net',
        'tenant_id' => 5,
        'metadata' => json_encode(['original_sender_id' => '0987654321@s.whatsapp.net']),
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result)->toBe(['handled' => false, 'shouldContinue' => true]);
});

it('resolve returns correct structure', function () {
    $service = new RegistrationFlowService(createMessage(['sender_id' => '']));
    $result = $service->resolve();

    expect($result)->toHaveKeys(['handled', 'shouldContinue']);
    expect($result['handled'])->toBeBool();
    expect($result['shouldContinue'])->toBeBool();
});

it('handles confirmation message for unregistered phone', function () {
    $message = createMessage([
        'sender_id' => '6285556667778',
        'tenant_id' => 1,
        'content' => 'Daftar',
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result['handled'])->toBeTrue();
    expect($result['shouldContinue'])->toBeFalse();
});

it('handles rejection message for unregistered phone', function () {
    $message = createMessage([
        'sender_id' => '6285556667778',
        'tenant_id' => 1,
        'content' => 'Tidak',
    ]);

    $service = new RegistrationFlowService($message);
    $result = $service->resolve();

    expect($result['handled'])->toBeTrue();
    expect($result['shouldContinue'])->toBeFalse();
});
