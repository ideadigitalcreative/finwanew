<?php

/**
 * Bug produksi 20/10/2026 (Haerul): membalas (quote) konfirmasi "Masuk dana 2jt" dengan "hapus"
 *   → bot menawarkan menghapus transaksi LAIN ("ada uang masuk", nominal sama)
 *   → "ya" → "(dry-run) Perintah hapus tercatat di log — belum diterapkan."
 *
 * Perbaikan:
 *  - dry-run = bayangan (tidak terlihat user), bukan konfirmasi palsu
 *  - pemetaan ke transaksi yang sudah dihapus → beri tahu, jangan cari transaksi lain
 *  - fuzzy wajib cocok nominal + JENIS + DESKRIPSI (kata template pesan bot diabaikan)
 *  - teks: bisa dipulihkan dengan "kembalikan"
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\Ledger\TransactionLedger;
use App\Services\WhatsAppService;
use App\Support\SenderIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const HAERUL = '6281300000077';

/** Kirim pesan; $quote = [id pesan bot yang dibalas, teksnya]. Mengembalikan balasan bot. */
function reSend(string $text, ?array $quote = null): string
{
    $GLOBALS['re_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000123', 'sender_id' => HAERUL,
        'message_id' => 're-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
        'raw_data' => $quote ? ['reply_context' => ['messageId' => $quote[0], 'participant' => null, 'text' => $quote[1]]] : null,
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", array_column($GLOBALS['re_replies'], 'text'));
}

/** Pesan bot terakhir yang memuat $needle → [wa id, teks]. */
function reBotMessage(string $needle): array
{
    foreach (array_reverse($GLOBALS['re_all']) as $m) {
        if (str_contains($m['text'], $needle)) {
            return [$m['id'], $m['text']];
        }
    }
    throw new RuntimeException("Pesan bot berisi '{$needle}' tidak ditemukan");
}

function reIncome(string $desc, int $amount): Transaction
{
    return app(TransactionLedger::class)->record([
        'tenant_id' => test()->tenant->id, 'balance_id' => test()->main->id,
        'category_id' => Category::where('tenant_id', test()->tenant->id)->where('type', 'pendapatan_lainnya')->value('id'),
        'type' => 'income', 'amount' => $amount, 'description' => $desc, 'transaction_date' => now()->toDateString(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    SenderIdentity::flush();
    config([
        'services.finwa_ai.enabled' => false, 'finwa_push.enabled' => false,
        'finwa_reply_edit.enabled' => true, 'finwa_reply_edit.dry_run' => false,
    ]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $GLOBALS['re_all'] = [];
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $id = 'BOT'.strtoupper(substr(md5(uniqid('', true)), 0, 12));
        $GLOBALS['re_replies'][] = ['id' => $id, 'text' => $text];
        $GLOBALS['re_all'][] = ['id' => $id, 'text' => $text];

        return ['success' => true, 'data' => ['messageId' => $id]];
    });

    $this->tenant = Tenant::create(['name' => 'Haerul', 'slug' => 'haerul', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000123',
        'name' => 'FinWa', 'config' => ['session_id' => 'wa_haerul'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Haerul', 'email' => 'haerul@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => HAERUL, 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->main = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
});

it('balas "hapus" ke konfirmasi → menghapus transaksi YANG DIBALAS, bisa dikembalikan', function () {
    $other = reIncome('ada uang masuk', 2_000_000); // transaksi lain bernominal sama
    reSend('Masuk dana 2jt');
    reSend('2');
    $target = Transaction::where('tenant_id', $this->tenant->id)->where('description', 'like', 'Masuk dana%')->sole();
    $confirmation = reBotMessage('Masuk dana');

    $ask = reSend('hapus', $confirmation);
    expect($ask)->toContain('Hapus transaksi ini?')
        ->toContain('Masuk dana')->not->toContain('ada uang masuk')
        ->toContain('💰 *Pemasukan*')
        ->toContain('kembalikan')
        ->not->toContain('tidak bisa dibatalkan');

    $done = reSend('ya');
    expect($done)->not->toContain('dry-run')->toContain('kembalikan')
        ->and(Transaction::find($target->id))->toBeNull()
        ->and(Transaction::find($other->id))->not->toBeNull()
        ->and((float) $this->main->fresh()->balance)->toBe(2_000_000.0);

    reSend('kembalikan');
    expect(Transaction::find($target->id))->not->toBeNull()
        ->and((float) $this->main->fresh()->balance)->toBe(4_000_000.0);
});

it('membalas konfirmasi transaksi yang SUDAH dihapus → diberi tahu, transaksi lain tidak disentuh', function () {
    $other = reIncome('ada uang masuk', 2_000_000);
    reSend('Masuk dana 2jt');
    reSend('2');
    $target = Transaction::where('tenant_id', $this->tenant->id)->where('description', 'like', 'Masuk dana%')->sole();
    $confirmation = reBotMessage('Masuk dana');
    app(TransactionLedger::class)->delete($target);

    $reply = reSend('hapus', $confirmation);
    expect($reply)->toContain('sudah dihapus sebelumnya')->toContain('kembalikan')
        ->not->toContain('Hapus transaksi ini?')
        ->and(Transaction::find($other->id))->not->toBeNull();
});

it('tanpa pemetaan (fuzzy): kata "Masuk" di judul pesan bot tidak membuat transaksi lain dianggap sama', function () {
    $other = reIncome('ada uang masuk', 2_000_000);
    $target = reIncome('Masuk dana 2jt', 2_000_000);
    $quoted = "✅ *Siap, Sudah Masuk! ✅*\n\n💰 *Pemasukan*\n💵 Rp 2.000.000\n💵 Pendapatan Lainnya • _Masuk dana_\n👛 Sisa saldo Dompet Utama: Rp 4.000.000";

    $ask = reSend('hapus', ['TIDAK-ADA-PEMETAAN', $quoted]);
    expect($ask)->toContain('Masuk dana')->not->toContain('ada uang masuk');

    reSend('ya');
    expect(Transaction::find($target->id))->toBeNull()
        ->and(Transaction::find($other->id))->not->toBeNull();
});

it('fuzzy: hanya nominal yang sama TIDAK cukup → tidak menawarkan menghapus transaksi lain', function () {
    reIncome('ada uang masuk', 2_000_000);
    $quoted = "✅ *Siap, Sudah Masuk! ✅*\n\n💰 *Pemasukan*\n💵 Rp 2.000.000\n💵 Pendapatan Lainnya • _Masuk dana_";

    // Tidak jatuh ke "hapus transaksi terakhir" (yang justru transaksi lain itu)
    $reply = reSend('hapus', ['TIDAK-ADA-PEMETAAN', $quoted]);
    expect($reply)->toContain('tidak ditemukan')
        ->not->toContain('ada uang masuk')
        ->not->toContain('Hapus transaksi ini?');

    reSend('ya');
    expect(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('fuzzy: jenis harus cocok (pesan "Pengeluaran" tidak menunjuk pemasukan)', function () {
    reIncome('kopi', 25_000);
    $quoted = "✅ *Tercatat!*\n\n💸 *Pengeluaran*\n💵 Rp 25.000\n🍔 Makanan & Minuman • _kopi_";

    expect(reSend('hapus', ['TIDAK-ADA-PEMETAAN', $quoted]))->not->toContain('Hapus transaksi ini?');
});

it('dry-run aktif → reply-edit tidak terlihat (tidak ada konfirmasi palsu "dry-run")', function () {
    config(['finwa_reply_edit.dry_run' => true]);
    reSend('Masuk dana 2jt');
    reSend('2');
    $confirmation = reBotMessage('Masuk dana');

    $reply = reSend('hapus', $confirmation);
    reSend('ya');

    expect(implode("\n", array_column($GLOBALS['re_all'], 'text')))->not->toContain('dry-run')
        ->and($reply)->toContain('Hapus transaksi ini?'); // alur hapus biasa (dengan konfirmasi)
});
