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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->string('disk')->default('s3'); // s3, r2, local
            $table->string('path'); // Full path in storage
            $table->string('original_name');
            $table->string('mime_type');
            $table->integer('size'); // in bytes
            $table->enum('type', ['image', 'audio', 'document', 'spreadsheet', 'other']);
            $table->string('signed_url')->nullable(); // Temporary signed URL
            $table->timestamp('signed_url_expires_at')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            
            $table->index(['tenant_id', 'message_id']);
            $table->index(['type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
