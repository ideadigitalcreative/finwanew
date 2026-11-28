<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap C — fondasi saldo:
 *  - transactions.deleted_at : hapus = soft delete, bisa "kembalikan"
 *  - transaction_audits      : jejak semua perubahan (buat/ubah/hapus/pulihkan)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'deleted_at')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->softDeletes();
                $table->index(['tenant_id', 'deleted_at']);
            });
        }

        if (! Schema::hasTable('transaction_audits')) {
            Schema::create('transaction_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                // Tanpa foreign key: jejak tetap ada walau transaksi dihapus permanen
                $table->unsignedBigInteger('transaction_id')->index();
                $table->string('action', 20);          // created|updated|deleted|restored|force_deleted
                $table->string('via', 40)->nullable(); // whatsapp|dashboard|api|system|reconcile|...
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('message_id')->nullable();
                $table->json('changes')->nullable();   // {field: [lama, baru]}
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_audits');

        if (Schema::hasColumn('transactions', 'deleted_at')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'deleted_at']);
                $table->dropSoftDeletes();
            });
        }
    }
};
