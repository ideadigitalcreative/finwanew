<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('recipient_user_id')->constrained('users')->onDelete('cascade');
            $table->string('channel');         // 'whatsapp' | 'telegram'
            $table->string('status');          // 'sent' | 'failed' | 'skipped'
            $table->text('message')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['transaction_id', 'recipient_user_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_notifications');
    }
};
