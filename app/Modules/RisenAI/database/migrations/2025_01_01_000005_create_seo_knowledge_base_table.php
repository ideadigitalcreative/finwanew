<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->string('category')->index(); // feature, company, target, faq, pricing
            $table->string('topic')->index();
            $table->text('content');
            $table->string('keywords')->nullable(); // comma separated keywords for matching
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_knowledge_base');
    }
};
