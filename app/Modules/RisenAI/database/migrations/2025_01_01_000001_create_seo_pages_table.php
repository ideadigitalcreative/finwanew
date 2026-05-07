<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('meta_description', 160)->nullable();
            $table->string('h1')->nullable();
            $table->longText('content_json');
            $table->string('primary_keyword')->nullable();
            $table->enum('intent', [
                'informational',
                'transactional',
                'comparison',
                'navigational',
            ]);
            $table->enum('status', [
                'draft',
                'review',
                'published',
                'archived',
            ])->default('draft');
            $table->integer('intent_score')->nullable();
            $table->string('service_var')->nullable();
            $table->string('location_var')->nullable();
            $table->string('cluster_id')->nullable();
            $table->json('schema_markup')->nullable();
            $table->json('internal_links')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'intent']);
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
