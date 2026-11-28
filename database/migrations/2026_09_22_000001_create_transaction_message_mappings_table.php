<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pemetaan pesan balasan FinWa ke transaksi.
     *
     * Menjawab: "pesan WhatsApp ID mana yang mewakili transaksi mana?"
     * Dipakai Fase 1 fitur edit via reply (docs/RENCANA-IMPLEMENTASI-REPLY-EDIT.md).
     */
    public function up(): void
    {
        if (Schema::hasTable('transaction_message_mappings')) {
            return; // Idempoten.
        }

        Schema::create('transaction_message_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('wa_message_id');                 // ID pesan balasan FinWa (result.key.id)
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->string('wa_sender')->nullable();         // nomor/LID pengirim transaksi
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'wa_message_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_message_mappings');
    }
};
