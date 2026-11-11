<?php

use App\Models\Category;
use App\Models\Message;
use App\Services\Category\CategoryManagerService;
use App\Models\OcrJob;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\FinancialQueryService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 13, 9, 0, 0));
    $this->tenant = Tenant::create(['name' => 'Q', 'slug' => 'q', 'is_active' => true]);
    $this->other = Tenant::create(['name' => 'X', 'slug' => 'x', 'is_active' => true]);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    (new CategoryManagerService)->createCategoriesForTenant($this->other->id);
});

afterEach(fn () => Carbon::setTestNow());

function qtx(int $tenantId, string $type, int $amount, string $date, string $status = 'confirmed', string $desc = 'item'): Transaction
{
    return Transaction::create([
        'tenant_id' => $tenantId,
        'category_id' => Category::where('tenant_id', $tenantId)->value('id'),
        'type' => $type,
        'amount' => $amount,
        'transaction_date' => $date,
        'status' => $status,
        'description' => $desc,
        'source' => 'test',
    ]);
}

it('ringkasan bulan lalu hanya menghitung transaksi confirmed milik tenant sendiri', function () {
    qtx($this->tenant->id, 'income', 40000, '2026-09-05');
    qtx($this->tenant->id, 'expense', 92000, '2026-09-10');
    qtx($this->tenant->id, 'expense', 500000, '2026-09-11', 'pending');   // tidak dihitung
    qtx($this->tenant->id, 'expense', 70000, '2026-10-01');              // bulan ini
    qtx($this->other->id, 'expense', 999000, '2026-09-12');              // tenant lain

    $result = (new FinancialQueryService)->answerQuestion($this->tenant->id, 'ringkasan bulan lalu');

    expect($result['success'])->toBeTrue()
        ->and((float) $result['data']['total_income'])->toBe(40000.0)
        ->and((float) $result['data']['total_expense'])->toBe(92000.0)
        ->and($result['answer'])->toContain('Ringkasan Keuangan bulan lalu');
});

it('daftar transaksi menjumlah SEMUA transaksi periode, bukan hanya yang tampil', function () {
    for ($i = 1; $i <= 12; $i++) {
        qtx($this->tenant->id, 'expense', 10000, sprintf('2026-09-%02d', $i), 'confirmed', "belanja {$i}");
    }

    $answer = (new FinancialQueryService)->answerQuestion($this->tenant->id, 'lihat transaksi september')['answer'];

    expect($answer)->toContain('Transaksi September 2026')
        ->and($answer)->toContain('Keluar: Rp 120.000')
        ->and($answer)->toContain('12 transaksi (10 terbaru)');
});

it('periode kosong menjawab tidak ada transaksi', function () {
    $answer = (new FinancialQueryService)->answerQuestion($this->tenant->id, 'rinciannya bulan lalu')['answer'];

    expect($answer)->toContain('Tidak ada transaksi');
});

it('callback OCR untuk job yang sudah selesai diabaikan (tidak diproses dua kali)', function () {
    config(['services.ocr_worker.api_key' => 'ocr-test-key']);

    $channel = \App\Models\Channel::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'whatsapp',
        'channel_account' => '628000',
        'name' => 'C',
        'is_active' => true,
    ]);
    $message = Message::create([
        'tenant_id' => $this->tenant->id,
        'channel_id' => $channel->id,
        'channel' => 'whatsapp',
        'channel_account' => '628000',
        'sender_id' => '628111',
        'message_id' => 'm-1',
        'type' => 'image',
        'content' => 'foto',
        'timestamp' => time(),
    ]);
    $job = OcrJob::create([
        'tenant_id' => $this->tenant->id,
        'message_id' => $message->id,
        'file_path' => 'whatsapp/x.jpg',
        'file_type' => 'image/jpeg',
        'status' => 'completed',
        'extracted_text' => 'teks lama',
    ]);

    $this->postJson("/api/ocr-jobs/{$job->id}/update", [
        'status' => 'completed',
        'extracted_text' => 'teks baru',
        'tenant_id' => $this->other->id,
    ], ['X-Api-Key' => 'ocr-test-key'])
        ->assertOk()
        ->assertJsonPath('message', 'OCR job already completed');

    expect($job->fresh()->extracted_text)->toBe('teks lama')
        ->and($message->fresh()->type)->toBe('image');
});
