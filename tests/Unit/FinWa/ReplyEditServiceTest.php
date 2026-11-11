<?php

use App\Models\Message;
use App\Services\Transaction\ReplyEditService;

function makeReplyEditService(array $rawData = [], array $attrs = []): ReplyEditService
{
    $message = new Message([
        'content' => $attrs['content'] ?? 'test',
    ]);
    $message->raw_data = $rawData;
    $message->tenant_id = 1;
    $message->sender_id = $attrs['sender_id'] ?? '628111111111';
    $message->metadata = $attrs['metadata'] ?? null;
    $message->message_id = $attrs['message_id'] ?? 'wa-in-1';

    return new ReplyEditService(
        $message,
        fn ($msg) => null,
        $attrs['extract_cb'] ?? null,
        $attrs['create_cb'] ?? null
    );
}

function extractReplyEditAmount(string $text): ?int
{
    $method = new ReflectionMethod(ReplyEditService::class, 'extractAmount');
    $method->setAccessible(true);

    return $method->invoke(makeReplyEditService(), $text);
}

test('extractAmount memahami satuan rb/ribu/k', function () {
    expect(extractReplyEditAmount('25rb'))->toBe(25000)
        ->and(extractReplyEditAmount('25 ribu'))->toBe(25000)
        ->and(extractReplyEditAmount('100k'))->toBe(100000);
});

test('extractAmount memahami satuan jt/juta', function () {
    expect(extractReplyEditAmount('1,5jt'))->toBe(1500000)
        ->and(extractReplyEditAmount('2 juta'))->toBe(2000000);
});

test('extractAmount memahami format rupiah dan titik ribuan', function () {
    expect(extractReplyEditAmount('Rp 30.000'))->toBe(30000)
        ->and(extractReplyEditAmount('30.000'))->toBe(30000)
        ->and(extractReplyEditAmount('30000'))->toBe(30000);
});

test('extractAmount mengembalikan null bila tidak ada nominal', function () {
    expect(extractReplyEditAmount('makan siang'))->toBeNull();
});

test('reply_context null bila bukan balasan', function () {
    expect(makeReplyEditService([])->getReplyContext())->toBeNull()
        ->and(makeReplyEditService(['reply_context' => null])->getReplyContext())->toBeNull()
        ->and(makeReplyEditService(['reply_context' => ['participant' => 'x']])->getReplyContext())->toBeNull();
});

test('reply_context terbaca dari raw_data', function () {
    $ctx = makeReplyEditService([
        'reply_context' => [
            'messageId' => '3EB0ABC123',
            'participant' => '628111111111@s.whatsapp.net',
            'text' => 'Makan siang 30rb',
        ],
    ])->getReplyContext();

    expect($ctx)->toBeArray()
        ->and($ctx['messageId'])->toBe('3EB0ABC123')
        ->and($ctx['text'])->toBe('Makan siang 30rb');
});

test('feature flag reply-edit default aman (disabled + dry-run)', function () {
    expect(config('finwa_reply_edit.enabled'))->toBeFalse()
        ->and(config('finwa_reply_edit.dry_run'))->toBeTrue();
});

test('isDeleteCommand mengenali perintah hapus', function () {
    $method = new \ReflectionMethod(ReplyEditService::class, 'isDeleteCommand');
    $method->setAccessible(true);

    $svc = makeReplyEditService();

    expect($method->invoke($svc, 'hapus'))->toBeTrue()
        ->and($method->invoke($svc, 'Hapus!'))->toBeTrue()
        ->and($method->invoke($svc, 'delete'))->toBeTrue()
        ->and($method->invoke($svc, 'hapus transaksi'))->toBeFalse()  // kalimat lain
        ->and($method->invoke($svc, '25rb'))->toBeFalse()
        ->and($method->invoke($svc, null))->toBeFalse();
});

test('extractDate membaca format tgl DD/MM/YYYY', function () {
    $method = new \ReflectionMethod(ReplyEditService::class, 'extractDate');
    $method->setAccessible(true);

    $svc = makeReplyEditService();

    $date = $method->invoke($svc, 'tgl 21/09/2026');
    expect($date)->not->toBeNull()
        ->and($date->day)->toBe(21)
        ->and($date->month)->toBe(9)
        ->and($date->year)->toBe(2026);
});

test('extractDate menolak tanggal tanpa kata kunci & tanpa keyword', function () {
    $method = new \ReflectionMethod(ReplyEditService::class, 'extractDate');
    $method->setAccessible(true);

    $svc = makeReplyEditService();

    // Tanpa kata kunci "tgl" → tidak dianggap tanggal edit (anti false positive,
    // konsisten dengan Bug #1 "KNO-PEN 22/09/2026")
    expect($method->invoke($svc, 'AirAsia KNO-PEN 22/09/2026'))->toBeNull();
    expect($method->invoke($svc, 'makan siang'))->toBeNull();
    expect($method->invoke($svc, null))->toBeNull();
});

test('extractDate menolak tanggal jauh di masa depan', function () {
    $method = new \ReflectionMethod(ReplyEditService::class, 'extractDate');
    $method->setAccessible(true);

    $svc = makeReplyEditService();

    $future = now()->addYears(2)->format('d/m/Y');
    expect($method->invoke($svc, 'tgl '.$future))->toBeNull();
});

test('tenantAllowed canary: kosong = semua, diisi = difilter', function () {
    $method = new \ReflectionMethod(ReplyEditService::class, 'tenantAllowed');
    $method->setAccessible(true);

    $svc = makeReplyEditService(); // tenant_id = 1

    config(['finwa_reply_edit.allowed_tenants' => []]);
    expect($method->invoke($svc))->toBeTrue();

    config(['finwa_reply_edit.allowed_tenants' => [1, 5]]);
    expect($method->invoke($svc))->toBeTrue();

    config(['finwa_reply_edit.allowed_tenants' => [5, 9]]);
    expect($method->invoke($svc))->toBeFalse();
});

test('rateLimitOk membatasi jumlah aksi per pengirim', function () {
    $method = new \ReflectionMethod(ReplyEditService::class, 'rateLimitOk');
    $method->setAccessible(true);

    $replies = [];
    $svc = new ReplyEditService(
        (function () {
            $m = new Message(['content' => 'test']);
            $m->tenant_id = 1;
            $m->sender_id = '628111111111';

            return $m;
        })(),
        function ($text) use (&$replies) {
            $replies[] = $text;
        }
    );

    config(['finwa_reply_edit.rate_limit_max' => 3, 'finwa_reply_edit.rate_limit_decay' => 60]);

    expect($method->invoke($svc))->toBeTrue()
        ->and($method->invoke($svc))->toBeTrue()
        ->and($method->invoke($svc))->toBeTrue()
        ->and($method->invoke($svc))->toBeFalse()  // ke-4 → ditolak
        ->and($method->invoke($svc))->toBeFalse()
        ->and($replies)->toHaveCount(1)            // notifikasi hanya sekali
        ->and($replies[0])->toContain('Terlalu banyak');

    config(['finwa_reply_edit.rate_limit_max' => 0]); // reset (nonaktif)
    expect($method->invoke($svc))->toBeTrue();
});

test('tokenize membuang kata umum dan kata terlalu pendek', function () {
    $method = new ReflectionMethod(ReplyEditService::class, 'tokenize');
    $method->setAccessible(true);

    $tokens = $method->invoke(makeReplyEditService(), 'Bayar makan siang di kantor Rp 30rb');

    expect($tokens)->toContain('kantor')
        ->and($tokens)->not->toContain('makan')   // stopword
        ->and($tokens)->not->toContain('di')      // kata < 3 huruf/stopword
        ->and($tokens)->not->toContain('rp');     // stopword
});

test('groupSenderAllowed mengizinkan non-grup', function () {
    $method = new ReflectionMethod(ReplyEditService::class, 'groupSenderAllowed');
    $method->setAccessible(true);

    $svc = makeReplyEditService();
    expect($method->invoke($svc, ['participant' => '62899999'], null))->toBeTrue();
});

test('groupSenderAllowed menolak participant beda di grup', function () {
    $method = new ReflectionMethod(ReplyEditService::class, 'groupSenderAllowed');
    $method->setAccessible(true);

    $svc = makeReplyEditService([], [
        'metadata' => ['is_group' => true],
        'sender_id' => '628111111111',
    ]);

    // participant != sender, dan knownSender juga != sender → tolak
    expect($method->invoke($svc, ['participant' => '628999999999'], '628888888888'))->toBeFalse();

    // participant == sender → izinkan
    expect($method->invoke($svc, ['participant' => '628111111111@s.whatsapp.net'], null))->toBeTrue();

    // tanpa participant tapi wa_sender pemetaan == sender → izinkan
    expect($method->invoke($svc, ['participant' => null], '628111111111'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Ops — Daftar riwayat bernomor & create via reply
|--------------------------------------------------------------------------
*/

test('storeListPayload & getListPayload menyimpan urutan transaksi', function () {
    ReplyEditService::storeListPayload(1, 'WA-OUT-1', [11, 22, 33]);

    expect(ReplyEditService::getListPayload(1, 'WA-OUT-1'))->toBe([11, 22, 33])
        ->and(ReplyEditService::getListPayload(1, 'WA-TIDAK-ADA'))->toBeNull()
        ->and(ReplyEditService::getListPayload(2, 'WA-OUT-1'))->toBeNull(); // isolasi tenant
});

test('startCreateFromReply menolak pesan tanpa nominal', function () {
    $svc = makeReplyEditService([], [
        'content' => 'makan siang enak sekali',
        'extract_cb' => fn ($t) => null,
        'create_cb' => fn ($d, $r) => null,
    ]);

    $method = new \ReflectionMethod(ReplyEditService::class, 'startCreateFromReply');
    $method->setAccessible(true);

    expect($method->invoke($svc, ['messageId' => 'X']))->toBeFalse();
});

test('startCreateFromReply menolang ketika callback belum dipasang', function () {
    $svc = makeReplyEditService([], ['content' => 'beli kue 30rb']);

    $method = new \ReflectionMethod(ReplyEditService::class, 'startCreateFromReply');
    $method->setAccessible(true);

    expect($method->invoke($svc, ['messageId' => 'X']))->toBeFalse();
});

test('startCreateFromReply memicu preview bila ekstraksi valid', function () {
    $replies = [];
    $message = new Message(['content' => 'beli kue 30rb']);
    $message->tenant_id = 1;
    $message->sender_id = '628111111111';
    $message->message_id = 'wa-in-1';

    $svc = new ReplyEditService(
        $message,
        function ($txt) use (&$replies) {
            $replies[] = $txt;
        },
        fn ($t) => [
            'type' => 'expense',
            'amount' => 30000,
            'category_type' => 'pengeluaran_lainnya',
            'description' => $t,
            'transaction_date' => now()->toDateString(),
        ],
        fn ($d, $r) => null
    );

    $method = new \ReflectionMethod(ReplyEditService::class, 'startCreateFromReply');
    $method->setAccessible(true);

    expect($method->invoke($svc, ['messageId' => 'X']))->toBeTrue()
        ->and($replies)->toHaveCount(1)
        ->and($replies[0])->toContain('Catat transaksi baru')
        ->and($replies[0])->toContain('30.000')
        ->and($replies[0])->toContain('Ya');
});

test('startCreateFromReply menolak hasil ekstraksi ambiguous', function () {
    $replies = [];
    $message = new Message(['content' => 'dapat bonus 100rb']);
    $message->tenant_id = 1;
    $message->sender_id = '628111111111';
    $message->message_id = 'wa-in-1';

    $svc = new ReplyEditService(
        $message,
        function ($txt) use (&$replies) {
            $replies[] = $txt;
        },
        fn ($t) => ['type' => 'ambiguous', 'amount' => 100000],
        fn ($d, $r) => null
    );

    $method = new \ReflectionMethod(ReplyEditService::class, 'startCreateFromReply');
    $method->setAccessible(true);

    expect($method->invoke($svc, ['messageId' => 'X']))->toBeFalse()
        ->and($replies)->toBeEmpty();
});

test('startCreatePreview menyimpan state confirm_create dengan TTL', function () {
    config(['finwa_reply_edit.confirmation_ttl' => 300]);

    $replies = [];
    $message = new Message(['content' => 'beli kue 30rb']);
    $message->tenant_id = 7;
    $message->sender_id = '628111111111';
    $message->message_id = 'wa-in-1';

    $svc = new ReplyEditService($message, function ($txt) use (&$replies) {
        $replies[] = $txt;
    });

    $method = new \ReflectionMethod(ReplyEditService::class, 'startCreatePreview');
    $method->setAccessible(true);
    $method->invoke($svc, [
        'type' => 'expense',
        'amount' => 30000,
        'description' => 'beli kue 30rb',
        'transaction_date' => now()->toDateString(),
    ]);

    $state = cache()->get('finwa_reply_edit_state:7:628111111111');

    expect($state['mode'])->toBe('confirm_create')
        ->and($state['row']['amount'])->toBe(30000)
        ->and($state['expires_at'])->toBeGreaterThan(now()->timestamp)
        ->and($replies[0])->toContain('Catat transaksi baru');
});

test('mode state baru dikenali dispatcher handle()', function () {
    $source = file_get_contents(app_path('Services/Transaction/ReplyEditService.php'));

    expect($source)->toContain("mode === 'list_pick'")
        ->and($source)->toContain("mode === 'await_change'")
        ->and($source)->toContain("mode === 'confirm_create'");
});
