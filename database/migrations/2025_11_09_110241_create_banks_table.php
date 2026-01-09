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
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nama bank (e.g., BCA, Mandiri, BNI)
            $table->string('account_number'); // Nomor rekening
            $table->string('account_name'); // Nama pemilik rekening
            $table->text('description')->nullable(); // Keterangan tambahan
            $table->boolean('is_active')->default(true); // Status aktif/tidak aktif
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
