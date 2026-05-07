<?php

use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsApp\GreetingService;

it('sends greeting on sapa intent', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('sapa');

    expect($replies)->toHaveCount(1);
    expect($replies[0])->toContain('FinWa');
    expect($replies[0])->toContain('asisten keuangan');
    expect($replies[0])->toContain('help');
});

it('sends help on help intent', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('help');

    expect($replies)->toHaveCount(1);
    expect($replies[0])->toContain('Panduan Lengkap');
    expect($replies[0])->toContain('CATAT PENGELUARAN');
    expect($replies[0])->toContain('SCAN STRUK');
    expect($replies[0])->toContain('VOICE NOTE');
    expect($replies[0])->toContain('BUDGET');
    expect($replies[0])->toContain('TARGET TABUNGAN');
    expect($replies[0])->toContain('PENGINGAT');
});

it('does nothing on unknown intent', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('unknown_intent');

    expect($replies)->toHaveCount(0);
});

it('includes time-based greeting', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('sapa');

    $hour = (int) now()->format('H');
    $expectedGreeting = match (true) {
        $hour >= 5 && $hour < 11 => 'Selamat pagi',
        $hour >= 11 && $hour < 15 => 'Selamat siang',
        $hour >= 15 && $hour < 18 => 'Selamat sore',
        default => 'Selamat malam',
    };

    expect($replies[0])->toContain($expectedGreeting);
});

it('greeting does not contain user name when tenant has no user', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 999;
    // No tenant relationship loaded - should gracefully handle

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('sapa');

    expect($replies)->toHaveCount(1);
    expect($replies[0])->toContain('Selamat');
    expect($replies[0])->toContain('!');
});

it('help contains all major feature sections', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('help');

    $helpText = $replies[0];

    // Check all major sections exist
    expect($helpText)->toContain('CATAT PENGELUARAN');
    expect($helpText)->toContain('SCAN STRUK');
    expect($helpText)->toContain('VOICE NOTE');
    expect($helpText)->toContain('CATAT BANYAK SEKALIGUS');
    expect($helpText)->toContain('CATAT PEMASUKAN');
    expect($helpText)->toContain('DOMPET/REKENING');
    expect($helpText)->toContain('CEK KEUANGAN');
    expect($helpText)->toContain('BUDGET');
    expect($helpText)->toContain('TARGET TABUNGAN');
    expect($helpText)->toContain('STATISTIK');
    expect($helpText)->toContain('PENGINGAT');
    expect($helpText)->toContain('LAPORAN');
    expect($helpText)->toContain('TIPS');
});

it('help contains practical examples', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('help');

    $helpText = $replies[0];

    // Check practical examples
    expect($helpText)->toContain('makan siang 25rb');
    expect($helpText)->toContain('beli bensin 50k');
    expect($helpText)->toContain('gaji bulan ini 8jt');
    expect($helpText)->toContain('tambah dompet BCA');
    expect($helpText)->toContain('set budget makan 500rb');
    expect($helpText)->toContain('set target 10jt untuk liburan');
    expect($helpText)->toContain('ingatkan bayar listrik');
    expect($helpText)->toContain('export pdf');
});

it('help mentions format tips', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('help');

    $helpText = $replies[0];

    expect($helpText)->toContain('25rb');
    expect($helpText)->toContain('50k');
    expect($helpText)->toContain('1.5jt');
    expect($helpText)->toContain('Voice note');
    expect($helpText)->toContain('Foto struk');
});

it('greeting contains call to action', function () {
    $replies = [];
    $message = new Message();
    $message->id = 1;
    $message->tenant_id = 1;

    $service = new GreetingService($message, function ($msg) use (&$replies) {
        $replies[] = $msg;
    });

    $service->handleSpecialIntent('sapa');

    expect($replies[0])->toContain('beli kopi 25rb');
    expect($replies[0])->toContain('gaji bulan ini 5jt');
    expect($replies[0])->toContain('ringkasan bulan ini');
});
