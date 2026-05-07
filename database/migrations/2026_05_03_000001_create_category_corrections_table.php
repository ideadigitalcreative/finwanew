<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->text('original_text');
            $table->string('original_category', 50);
            $table->string('corrected_category', 50);
            $table->string('merchant', 100)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->integer('frequency')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'original_category']);
            $table->index(['tenant_id', 'merchant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_corrections');
    }
};
