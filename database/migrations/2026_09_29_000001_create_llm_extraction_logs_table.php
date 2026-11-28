<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log ekstraksi LLM hybrid (Hybrid.md Fase 4).
     *
     * Append-only: pesan mentah, hasil LLM, hasil validator, keputusan
     * akhir & alasan override — untuk audit + dataset perbaikan prompt.
     * Tabel ini TIDAK MENYIMPAN API key (§6A keamanan).
     */
    public function up(): void
    {
        if (Schema::hasTable('llm_extraction_logs')) {
            return; // Idempoten.
        }

        Schema::create('llm_extraction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('raw_message');
            $table->json('llm_result')->nullable();        // hasil LLM (status/intent/amount/confidence/raw)
            $table->json('validator_result')->nullable();  // hasil validator (passed/reasons/nilai final)
            $table->string('decision', 32);                // intercepted | not_intercepted | rejected | unavailable
            $table->json('override_reasons')->nullable();  // alasan override (amount_mismatch, dst.)
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['decision', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_extraction_logs');
    }
};
