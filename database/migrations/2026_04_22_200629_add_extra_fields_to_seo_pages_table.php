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
        Schema::table('seo_pages', function (Blueprint $table) {
            // Idempoten: kolom bisa sudah ada bila tabel dibuat di luar migrasi.
            if (! Schema::hasColumn('seo_pages', 'thumbnail')) {
                $table->string('thumbnail')->nullable()->after('h1');
            }
            if (! Schema::hasColumn('seo_pages', 'category')) {
                $table->string('category')->nullable()->after('thumbnail');
            }
            if (! Schema::hasColumn('seo_pages', 'tags')) {
                $table->json('tags')->nullable()->after('category');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropColumn(['thumbnail', 'category', 'tags']);
        });
    }
};
