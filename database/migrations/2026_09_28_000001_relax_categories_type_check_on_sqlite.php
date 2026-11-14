<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 0 (Hybrid.md) — harness menemukan: kolom categories.type di SQLite
 * dibuat oleh Blueprint enum() berupa CHECK 19 tipe awal, sedangkan semua
 * migrasi perluasan ENUM hanya dijalankan untuk MySQL (driverName === 'mysql').
 * Akibatnya CategoryManagerService::createCategoriesForTenant selalu gagal
 * di SQLite/tes (CHECK constraint violation pada tipe baru seperti
 * pendapatan_transfer, pengeluaran_modal, debit_internal).
 *
 * Migrasi ini HANYA untuk SQLite: rebuild tabel categories tanpa CHECK.
 * MySQL tidak diubah karena ENUM-nya sudah diperluas oleh migrasi-migrasi
 * add_internal_transfer_types & add_hutang_piutang_category_types.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $hasCheck = DB::select(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'categories'"
        );
        if ($hasCheck === [] || stripos($hasCheck[0]->sql ?? '', 'check') === false) {
            return; // CHECK sudah tidak ada — tidak perlu apa-apa
        }

        DB::statement(<<<'SQL'
            CREATE TABLE categories_relaxed (
                "id" integer primary key autoincrement not null,
                "tenant_id" integer not null,
                "type" varchar not null,
                "name" varchar not null,
                "slug" varchar not null,
                "description" text,
                "icon" varchar,
                "color" varchar,
                "is_system" tinyint(1) not null default '0',
                "created_at" datetime,
                "updated_at" datetime,
                foreign key("tenant_id") references "tenants"("id") on delete cascade
            )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO categories_relaxed
                (id, tenant_id, type, name, slug, description, icon, color, is_system, created_at, updated_at)
            SELECT id, tenant_id, type, name, slug, description, icon, color, is_system, created_at, updated_at
            FROM categories
        SQL);

        DB::statement('DROP TABLE categories');
        DB::statement('ALTER TABLE categories_relaxed RENAME TO categories');
        DB::statement('CREATE UNIQUE INDEX "categories_tenant_type_slug_unique" ON categories (tenant_id, type, slug)');
        DB::statement('CREATE INDEX "categories_tenant_id_type_index" ON categories (tenant_id, type)');
    }

    public function down(): void
    {
        // Tidak dibalikkan: membatasi CHECK ulang akan membuang
        // kemampuan menampung tipe kategori baru di SQLite.
    }
};