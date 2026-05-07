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
        Schema::create('user_whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('whatsapp_number'); // Nomor WhatsApp user
            $table->string('name')->nullable(); // Nama/alias untuk nomor ini
            $table->boolean('is_primary')->default(false); // Nomor utama
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'tenant_id']);
            $table->index('whatsapp_number');
            // Ensure unique whatsapp_number per user per tenant
            $table->unique(['user_id', 'tenant_id', 'whatsapp_number'], 'unique_number_per_user_tenant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_whatsapp_numbers');
    }
};
