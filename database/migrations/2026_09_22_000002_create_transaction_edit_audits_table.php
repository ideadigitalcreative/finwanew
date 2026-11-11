<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit setiap perubahan transaksi via reply.
     *
     * Menyimpan nilai lama, nilai baru, cara target ditentukan, dan siapa
     * yang memicu — wajib untuk audit fitur keuangan.
     */
    public function up(): void
    {
        if (Schema::hasTable('transaction_edit_audits')) {
            return; // Idempoten.
        }

        Schema::create('transaction_edit_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('old_values');
            $table->json('new_values');
            $table->string('resolve_method');                // mapping | fuzzy | manual_pick | keyword
            $table->string('wa_message_id')->nullable();     // pesan reply yang memicu edit
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_edit_audits');
    }
};
