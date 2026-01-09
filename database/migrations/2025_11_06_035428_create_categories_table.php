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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->enum('type', [
                'pendapatan_gaji',
                'pendapatan_bonus',
                'pendapatan_investasi',
                'pendapatan_lainnya',
                'pengeluaran_makanan',
                'pengeluaran_transport',
                'pengeluaran_hunian',
                'pengeluaran_utilitas',
                'pengeluaran_kesehatan',
                'pengeluaran_pendidikan',
                'pengeluaran_belanja',
                'pengeluaran_hiburan',
                'pengeluaran_tagihan',
                'pengeluaran_investasi',
                'pengeluaran_pinjaman',
                'pengeluaran_asuransi',
                'pengeluaran_pajak',
                'pengeluaran_donasi',
                'pengeluaran_lainnya'
            ]);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            
            $table->unique(['tenant_id', 'type', 'slug']);
            $table->index(['tenant_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
