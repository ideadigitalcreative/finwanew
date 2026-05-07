<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('channel_id')->constrained()->onDelete('cascade');
            $table->string('channel'); // whatsapp, telegram, slack
            $table->string('channel_account');
            $table->string('sender_id'); // User ID from chat platform
            $table->string('message_id')->unique(); // Message ID from chat platform
            $table->enum('type', ['text', 'image', 'audio', 'doc', 'csv']);
            $table->text('content')->nullable(); // Text content or URL
            $table->bigInteger('timestamp'); // Unix epoch timestamp
            $table->json('raw_data')->nullable(); // Original payload
            $table->timestamps();

            $table->index(['tenant_id', 'channel_id', 'timestamp']);
            $table->index(['tenant_id', 'sender_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
