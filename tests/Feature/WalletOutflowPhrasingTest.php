<?php

/**
 * Bug produksi 26/10/2026:
 *  - "Saldo keluar 500k dari dompet utama" → "Dompet 'keluar' tidak ditemukan"
 *    (terbaca perintah set saldo dompet "keluar").
 *  - "Tarik saldo 500k dari dompet utama" → dicatat PEMASUKAN Pendapatan Lainnya
 *    & saldo Dompet Utama bertambah. Keduanya harusnya PENGELUARAN dari Dompet Utama.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function outflowSend(string $text): string
{
    $GLOBALS['outflow_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000666', 'sender_id' => '6281400000066',
        'message_id' => 'outflow-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['outflow_replies']);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['outflow_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['outflow_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Outflow', 'slug' => 'outflow', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000666',
        'name' => 'Outflow', 'config' => ['session_id' => 'wa_outflow'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Syaifudin', 'email' => 'outflow@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281400000066', 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->utama = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 817000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
    $this->brimo = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Brimo Tabungan', 'account_type' => 'bank', 'currency' => 'IDR',
        'balance' => 2000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => false,
    ]);
});

it('mencatat pengeluaran dari dompet yang disebut', function (string $text) {
    $reply = outflowSend($text);

    $txs = Transaction::where('tenant_id', $this->tenant->id)->get();
    expect($reply)->not->toContain('tidak ditemukan')
        ->and($txs)->toHaveCount(1)
        ->and($txs[0]->type)->toBe('expense')
        ->and((int) $txs[0]->amount)->toBe(500000)
        ->and($txs[0]->balance_id)->toBe($this->utama->id)
        ->and((int) $this->utama->fresh()->balance)->toBe(317000)
        ->and((int) $this->brimo->fresh()->balance)->toBe(2000000)
        ->and($reply)->toContain('Pengeluaran');
})->with([
    'Saldo keluar 500k dari dompet utama',
    'Tarik saldo 500k dari dompet utama',
]);

it('"saldo masuk" dicatat pemasukan, bukan set saldo dompet "masuk"', function () {
    $reply = outflowSend('Saldo masuk 200rb ke dompet utama');

    $txs = Transaction::where('tenant_id', $this->tenant->id)->get();
    expect($reply)->not->toContain('tidak ditemukan')
        ->and($txs)->toHaveCount(1)
        ->and($txs[0]->type)->toBe('income')
        ->and((int) $this->utama->fresh()->balance)->toBe(1017000);
});

it('set saldo dompet biasa tetap berfungsi', function () {
    outflowSend('saldo brimo tabungan 2.500.000');

    expect((int) $this->brimo->fresh()->balance)->toBe(2500000);
});

it('mendeteksi tipe frasa tarik/saldo keluar', function (string $text, string $type) {
    expect((new App\Services\Transaction\TransactionTypeDetector)->detect($text))->toBe($type);
})->with([
    ['tarik saldo 500k dari dompet utama', 'expense'],
    ['tarik tunai 300rb', 'expense'],
    ['saldo keluar 500k dari dompet utama', 'expense'],
    ['saldo masuk 200rb', 'income'],
    ['tarik saldo shopee 1.2jt', 'income'],
    ['narik grab 150rb', 'income'],
]);
