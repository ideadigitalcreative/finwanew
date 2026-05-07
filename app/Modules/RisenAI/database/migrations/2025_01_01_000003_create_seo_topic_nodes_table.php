<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_topic_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->enum('type', ['pillar', 'cluster', 'supporting']);
            $table->string('url_slug')->nullable();
            $table->json('connected_nodes')->nullable();
            $table->json('target_keywords')->nullable();
            $table->integer('semantic_score')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_topic_nodes');
    }
};
