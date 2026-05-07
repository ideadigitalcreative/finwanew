<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_keyword_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('cluster_name');
            $table->string('primary_keyword');
            $table->json('secondary_keywords');
            $table->enum('avg_intent', [
                'informational',
                'transactional',
                'comparison',
                'navigational',
            ]);
            $table->enum('estimated_volume', ['low', 'medium', 'high'])
                ->default('medium');
            $table->string('niche')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keyword_clusters');
    }
};
