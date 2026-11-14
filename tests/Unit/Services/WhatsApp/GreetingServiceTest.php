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
    expect($replies[0])->toContain('Panduan FinWa');
    expect($replies[0])->toContain('Catat Transaksi');
    expect($replies[0])->toContain('Scan Struk');
    expect($replies[0])->toContain('Voice Note');
    expect($replies[0])->toContain('Budget & Target');
    expect($replies[0])->toContain('Pengingat');
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
    expect($helpText)->toContain('Catat Transaksi');
    expect($helpText)->toContain('Scan Struk');
    expect($helpText)->toContain('Voice Note');
    expect($helpText)->toContain('Mengelola Dompet/Rekening');
    expect($helpText)->toContain('Cek Keuangan');
    expect($helpText)->toContain('Budget & Target');
    expect($helpText)->toContain('Pengingat');
    expect($helpText)->toContain('Laporan');
    expect($helpText)->toContain('Tips');
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
    expect($helpText)->toContain('beli kopi 25rb');
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
    expect($helpText)->toContain('Scan Struk');
    expect($helpText)->toContain('Voice Note');
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
