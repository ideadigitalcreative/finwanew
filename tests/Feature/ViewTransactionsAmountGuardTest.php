<?php

/**
 * "Pengeluaran hari ini makanan 29rb" dulu tertangkap kata kunci 'pengeluaran hari ini'
 * di fast path daftar transaksi, sehingga dibalas "Belum ada transaksi 7 hari terakhir"
 * dan transaksinya tidak tercatat. Pesan bernominal sekarang dicatat sebagai transaksi.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function viewGuardSend(string $text): string
{
    $GLOBALS['view_guard_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000444', 'sender_id' => '6281800000044',
        'message_id' => 'vg-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['view_guard_replies']);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['view_guard_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['view_guard_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'ViewGuard', 'slug' => 'view-guard', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000444',
        'name' => 'ViewGuard', 'config' => ['session_id' => 'wa_vg'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'ViewGuard', 'email' => 'vg@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000044', 'tenant_id' => $this->tenant->id,
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash',
        'currency' => 'IDR', 'balance' => 1_000_000, 'balance_date' => now()->toDateString(),
        'is_active' => true, 'is_default' => true,
    ]);
});

it('mencatat "pengeluaran hari ini makanan 29rb" sebagai pengeluaran', function () {
    $reply = viewGuardSend('Pengeluaran hari ini makanan 29rb');

    expect($reply)->not->toContain('Belum ada transaksi 7 hari');
    expect(Transaction::where('tenant_id', $this->tenant->id)
        ->where('type', 'expense')->where('amount', 29000)->exists())->toBeTrue();
});

it('"pengeluaran hari ini" tanpa nominal tetap dianggap pertanyaan, bukan transaksi', function () {
    $reply = viewGuardSend('pengeluaran hari ini');

    expect($reply)->toContain('hari ini')
        ->and($reply)->not->toContain('Format Tidak Dikenali');
    expect(Transaction::where('tenant_id', $this->tenant->id)->exists())->toBeFalse();
});
