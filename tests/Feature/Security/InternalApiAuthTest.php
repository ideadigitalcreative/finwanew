<?php

/**
 * Prioritas 1 — endpoint service-to-service wajib API key.
 * Tanpa key, siapa pun bisa membaca/mengubah dompet tenant lain.
 */

use App\Models\Balance;
use App\Models\Tenant;

beforeEach(function () {
    config([
        'services.internal.api_key' => 'internal-test-key',
        'services.ocr_worker.api_key' => 'ocr-test-key',
        'services.stt_worker.api_key' => 'stt-test-key',
    ]);

    $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'is_active' => true]);
    $this->balance = Balance::create([
        'tenant_id' => $this->tenant->id,
        'account_name' => 'Cash',
        'account_type' => 'cash',
        'currency' => 'IDR',
        'balance' => 100000,
        'balance_date' => now()->toDateString(),
        'is_active' => true,
    ]);
});

it('menolak endpoint internal tanpa API key', function (string $method, string $uri) {
    $uri = str_replace(['{tenant}', '{balance}'], [$this->tenant->id, $this->balance->id], $uri);

    $this->json($method, $uri, [])->assertStatus(401);
})->with([
    'list dompet' => ['GET', '/api/tenants/{tenant}/balances'],
    'tambah dompet' => ['POST', '/api/tenants/{tenant}/balances'],
    'ubah dompet' => ['PUT', '/api/tenants/{tenant}/balances/{balance}'],
    'hapus dompet' => ['DELETE', '/api/tenants/{tenant}/balances/{balance}'],
    'cashflow' => ['GET', '/api/tenants/{tenant}/cashflow'],
    'channel slack' => ['GET', '/api/channels/slack/T123'],
    'buat channel' => ['POST', '/api/channels'],
    'webhook slack' => ['POST', '/api/webhooks/slack/message'],
    'callback ocr' => ['POST', '/api/ocr-jobs/1/update'],
    'callback stt' => ['POST', '/api/stt-jobs/1/update'],
]);

it('menolak API key yang salah', function () {
    $this->getJson("/api/tenants/{$this->tenant->id}/balances", ['X-Api-Key' => 'salah'])
        ->assertStatus(401);
});

it('tidak bisa mengubah saldo tenant tanpa API key', function () {
    $this->putJson("/api/tenants/{$this->tenant->id}/balances/{$this->balance->id}", ['balance' => 999])
        ->assertStatus(401);

    expect((float) $this->balance->fresh()->balance)->toBe(100000.0);
});

it('mengizinkan endpoint internal dengan API key yang benar', function () {
    $this->getJson("/api/tenants/{$this->tenant->id}/balances", ['X-Api-Key' => 'internal-test-key'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.account_name', 'Cash');
});

it('file worker menolak tanpa key dan path traversal', function () {
    $this->get('/api/files?path=whatsapp/x.jpg')->assertStatus(401);

    $this->get('/api/files?path=whatsapp/../../.env', ['X-API-Key' => 'ocr-test-key'])
        ->assertStatus(403);
});
