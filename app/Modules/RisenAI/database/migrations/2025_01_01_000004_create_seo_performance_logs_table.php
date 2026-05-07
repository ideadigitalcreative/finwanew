<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_performance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_page_id')
                ->constrained('seo_pages')
                ->onDelete('cascade');
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->decimal('ctr', 5, 2)->default(0);
            $table->decimal('avg_position', 5, 1)->default(0);
            $table->enum('action_taken', [
                'none', 'title_test', 'rewrite', 'merge', 'deleted',
            ])->default('none');
            $table->date('recorded_date');
            $table->timestamps();
            $table->index(['seo_page_id', 'recorded_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_performance_logs');
    }
};
