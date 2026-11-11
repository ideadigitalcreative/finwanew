<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tahap C — "Saldo awal" lama tercatat sebagai PEMASUKAN (type=income) sehingga
 * ikut terhitung di laporan pemasukan. Ubah jadi baris saldo awal
 * (kredit_internal + metadata.kind=opening_balance). Efek saldo sama-sama (+),
 * jadi saldo dompet TIDAK berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('transactions')
            ->where('source', 'opening_balance_command')
            ->where('type', 'income')
            ->get(['id', 'tenant_id', 'metadata']);

        foreach ($rows as $row) {
            $categoryId = DB::table('categories')
                ->where('tenant_id', $row->tenant_id)
                ->where('type', 'kredit_internal')
                ->value('id');
            if (! $categoryId) {
                $categoryId = DB::table('categories')->insertGetId([
                    'tenant_id' => $row->tenant_id,
                    'type' => 'kredit_internal',
                    'name' => 'Kredit Antar Dompet',
                    'slug' => 'kredit-internal',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // metadata lama kadang tersimpan sebagai string JSON ganda
            $meta = json_decode((string) $row->metadata, true);
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            $meta = is_array($meta) ? $meta : [];
            $meta['kind'] = 'opening_balance';
            $meta['converted_from'] = 'income';

            DB::table('transactions')->where('id', $row->id)->update([
                'type' => 'kredit_internal',
                'category_id' => $categoryId,
                'metadata' => json_encode($meta),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: menjadikannya pemasukan lagi akan merusak laporan.
    }
};
