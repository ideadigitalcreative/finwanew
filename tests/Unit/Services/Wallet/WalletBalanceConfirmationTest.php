<?php

use App\Models\Balance;
use App\Models\Message;
use App\Services\ConversationContextService;
use App\Services\Transaction\TransactionExtractorService;
use App\Services\Wallet\WalletCommandService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('tenants', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->boolean('is_active')->default(true);
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

    Schema::create('balances', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('account_name');
        $table->string('account_number')->nullable();
        $table->string('account_type')->default('cash');
        $table->string('currency', 3)->default('IDR');
        $table->decimal('balance', 15, 2)->default(0);
        $table->date('balance_date');
        $table->boolean('is_active')->default(true);
        $table->boolean('is_default')->default(false);
        $table->json('metadata')->nullable();
        $table->timestamps();

        $table->unique(['tenant_id', 'account_name', 'account_number']);
    });

    Schema::create('conversation_contexts', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->text('message');
        $table->string('intent')->nullable();
        $table->json('entities')->nullable();
        $table->string('response_type')->nullable();
        $table->timestamps();

        $table->index(['tenant_id', 'created_at']);
    });

    // Tahap C: ubah saldo dicatat sebagai baris penyesuaian lewat TransactionLedger
    Schema::create('categories', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('type');
        $table->string('name');
        $table->string('slug')->nullable();
        $table->text('description')->nullable();
        $table->string('icon')->nullable();
        $table->string('color')->nullable();
        $table->boolean('is_system')->default(false);
        $table->timestamps();
    });

    Schema::create('transactions', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('category_id');
        $table->unsignedBigInteger('message_id')->nullable();
        $table->unsignedBigInteger('balance_id')->nullable();
        $table->unsignedBigInteger('user_whatsapp_number_id')->nullable();
        $table->string('type');
        $table->decimal('amount', 15, 2);
        $table->date('transaction_date');
        $table->string('source')->nullable();
        $table->text('description')->nullable();
        $table->string('merchant')->nullable();
        $table->string('reference_number')->nullable();
        $table->decimal('confidence_score', 3, 2)->nullable();
        $table->string('status')->default('confirmed');
        $table->unsignedBigInteger('reviewed_by')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->json('metadata')->nullable();
        $table->unsignedBigInteger('linked_transaction_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('transaction_audits', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('transaction_id');
        $table->string('action', 20);
        $table->string('via', 40)->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('message_id')->nullable();
        $table->json('changes')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    DB::table('tenants')->insert([
        'id' => 1,
        'name' => 'Test Tenant',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function () {
    Schema::dropIfExists('transaction_audits');
    Schema::dropIfExists('transactions');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('conversation_contexts');
    Schema::dropIfExists('balances');
    Schema::dropIfExists('messages');
    Schema::dropIfExists('tenants');
    Cache::flush();
});

function walletBalanceTestMessage(array $overrides = []): Message
{
    return Message::create(array_merge([
        'tenant_id' => 1,
        'sender_id' => '628111111111',
        'channel_id' => 1,
        'content' => '',
        'type' => 'text',
        'metadata' => null,
        'status' => 'received',
    ], $overrides));
}

/**
 * Buat service dengan rekaman balasan (replies) + extractor amount produksi.
 */
function walletBalanceTestService(array &$replies): WalletCommandService
{
    $extractor = new TransactionExtractorService;

    return new WalletCommandService(
        walletBalanceTestMessage(),
        function (string $text) use (&$replies): void {
            $replies[] = $text;
        },
        fn (string $txt) => $extractor->extractAmountFromText($txt)
    );
}

function walletBalanceTestPrimary(float $amount = 100000): Balance
{
    return Balance::create([
        'tenant_id' => 1,
        'account_name' => 'Dompet Utama',
        'account_number' => null,
        'account_type' => 'cash',
        'currency' => 'IDR',
        'balance' => $amount,
        'balance_date' => now()->toDateString(),
        'is_active' => true,
        'is_default' => true,
        'metadata' => null,
    ]);
}

function walletBalanceTestContext(): ConversationContextService
{
    return new ConversationContextService(1, '628111111111');
}

it('mengenali kata generik yang bukan nama dompet', function () {
    foreach (['awal', 'total', 'saldo', 'bank', 'semua', ''] as $name) {
        expect(WalletCommandService::isGenericWalletName($name))->toBeTrue();
    }

    foreach (['BCA', 'Dompet Utama', 'Gopay', 'Dana'] as $name) {
        expect(WalletCommandService::isGenericWalletName($name))->toBeFalse();
    }
});

it('minta konfirmasi untuk "Saldo awal 3215000" alih-alih error dompet', function () {
    walletBalanceTestPrimary(100000);
    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleSetWalletBalance('Saldo awal 3215000');

    expect($replies)->toHaveCount(1);
    expect($replies[0])
        ->toContain('bukan nama dompet')
        ->toContain('3.215.000')
        ->toContain('Dompet Utama');

    $pending = walletBalanceTestContext()->getPendingWalletBalanceConfirmation();
    expect($pending)->not->toBeNull()
        ->and($pending['operation'])->toBe('set')
        ->and((float) $pending['amount'])->toBe(3215000.0);

    // Saldo belum dieksekusi sebelum user konfirmasi.
    expect((float) Balance::first()->balance)->toBe(100000.0);
});

it('memproses pola chat export "[26/9, 14.45] Ghoni: Saldo awal 3215000"', function () {
    walletBalanceTestPrimary(100000);
    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleSetWalletBalance('[26/9, 14.45] Ghoni: Saldo awal 3215000');

    expect($replies[0])->toContain('bukan nama dompet')->toContain('3.215.000');
});

it('men-set saldo Dompet Utama setelah user balas Ya', function () {
    walletBalanceTestPrimary(100000);
    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleSetWalletBalance('Saldo awal 3215000');
    $handled = $service->handlePendingWalletBalanceConfirmation('Ya');

    expect($handled)->toBeTrue();
    expect((float) Balance::first()->balance)->toBe(3215000.0);
    expect(end($replies))->toContain('Saldo Berhasil Diubah');
    expect(walletBalanceTestContext()->getPendingWalletBalanceConfirmation())->toBeNull();
});

it('menambah saldo saat user memilih Tambah', function () {
    walletBalanceTestPrimary(100000);
    walletBalanceTestContext()->storePendingWalletBalanceConfirmation([
        'operation' => 'set',
        'amount' => 50000,
        'wallet_name' => 'awal',
        'original_message' => 'Saldo awal 50000',
    ]);

    $replies = [];
    $service = walletBalanceTestService($replies);
    $handled = $service->handlePendingWalletBalanceConfirmation('Tambah');

    expect($handled)->toBeTrue();
    expect((float) Balance::first()->balance)->toBe(150000.0);
    expect(end($replies))->toContain('Ditambah');
});

it('membatalkan perubahan saat user balas Batal', function () {
    walletBalanceTestPrimary(100000);
    walletBalanceTestContext()->storePendingWalletBalanceConfirmation([
        'operation' => 'set',
        'amount' => 3215000,
        'wallet_name' => 'awal',
        'original_message' => 'Saldo awal 3215000',
    ]);

    $replies = [];
    $service = walletBalanceTestService($replies);
    $handled = $service->handlePendingWalletBalanceConfirmation('Batal');

    expect($handled)->toBeTrue();
    expect((float) Balance::first()->balance)->toBe(100000.0);
    expect(end($replies))->toContain('Dibatalkan');
    expect(walletBalanceTestContext()->getPendingWalletBalanceConfirmation())->toBeNull();
});

it('meneruskan pesan biasa ke alur normal saat bukan jawaban konfirmasi', function () {
    walletBalanceTestPrimary(100000);
    walletBalanceTestContext()->storePendingWalletBalanceConfirmation([
        'operation' => 'set',
        'amount' => 3215000,
        'wallet_name' => 'awal',
        'original_message' => 'Saldo awal 3215000',
    ]);

    $replies = [];
    $service = walletBalanceTestService($replies);
    $handled = $service->handlePendingWalletBalanceConfirmation('beli kopi 25rb');

    expect($handled)->toBeFalse();
    expect($replies)->toBeEmpty();
    expect(walletBalanceTestContext()->getPendingWalletBalanceConfirmation())->not->toBeNull();
});

it('membuat Dompet Utama otomatis bila belum ada dompet sama sekali', function () {
    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleSetWalletBalance('Saldo awal 3215000');
    expect(Balance::count())->toBe(1);

    $service->handlePendingWalletBalanceConfirmation('Ya');
    expect(Balance::count())->toBe(1);
    expect((float) Balance::first()->balance)->toBe(3215000.0);
    expect(Balance::first()->account_name)->toBe('Dompet Utama');
});

it('minta konfirmasi untuk "tambah saldo awal 500rb" (alur adjust)', function () {
    walletBalanceTestPrimary(100000);
    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleAdjustWalletBalance('tambah saldo awal 500rb', 'increase');

    expect($replies[0])->toContain('bukan nama dompet')->toContain('500.000');
    expect((float) Balance::first()->balance)->toBe(100000.0);

    $service->handlePendingWalletBalanceConfirmation('Ya');
    expect((float) Balance::first()->balance)->toBe(600000.0);
});

it('menolak multi-baris penuh nama generik dengan saran nama dompet', function () {
    walletBalanceTestPrimary(100000);
    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleMultipleSetWalletBalance("Saldo awal 3215000\nSaldo total 500000");

    expect(end($replies))->toContain('bukan nama dompet');
    expect(end($replies))->not->toContain('tidak ditemukan');
    expect((float) Balance::first()->balance)->toBe(100000.0);
});

it('update dompet valid + baris generik dalam satu pesan multi-baris', function () {
    walletBalanceTestPrimary(100000);
    Balance::create([
        'tenant_id' => 1,
        'account_name' => 'BCA',
        'account_number' => null,
        'account_type' => 'bank',
        'currency' => 'IDR',
        'balance' => 50000,
        'balance_date' => now()->toDateString(),
        'is_active' => true,
        'is_default' => false,
        'metadata' => null,
    ]);

    $replies = [];
    $service = walletBalanceTestService($replies);

    $service->handleMultipleSetWalletBalance("Update saldo BCA 200000\nSaldo awal 3215000");

    expect((float) Balance::where('account_name', 'BCA')->value('balance'))->toBe(200000.0);
    expect($replies[0])->toContain('bukan nama dompet');
    expect(walletBalanceTestContext()->getPendingWalletBalanceConfirmation())->not->toBeNull();

    $service->handlePendingWalletBalanceConfirmation('Ya');
    expect((float) Balance::where('account_name', 'Dompet Utama')->value('balance'))->toBe(3215000.0);
});

it('hanya satu konfirmasi aktif dalam satu waktu (eksklusivitas state)', function () {
    $ctx = walletBalanceTestContext();

    $ctx->storePendingConfirmation([
        'original_message' => 'makan siang 25000',
        'description' => 'makan siang',
        'amount' => 25000,
        'type' => 'expense',
    ]);
    $ctx->storePendingWalletBalanceConfirmation([
        'operation' => 'set',
        'amount' => 3215000,
        'wallet_name' => 'awal',
        'original_message' => 'Saldo awal 3215000',
    ]);

    expect($ctx->getPendingConfirmation())->toBeNull();
    expect($ctx->getPendingWalletBalanceConfirmation())->not->toBeNull();

    $ctx->storePendingConfirmation([
        'original_message' => 'beli pulsa 50000',
        'description' => 'beli pulsa',
        'amount' => 50000,
        'type' => 'expense',
    ]);

    expect($ctx->getPendingWalletBalanceConfirmation())->toBeNull();
    expect($ctx->getPendingConfirmation())->not->toBeNull();
});
