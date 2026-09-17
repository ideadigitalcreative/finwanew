<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_globals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('period', ['monthly', 'yearly'])->default('monthly');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('alert_enabled')->default(true);
            $table->integer('alert_threshold')->default(80);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period', 'is_active']);
            $table->index(['tenant_id', 'is_active']);
            $table->index('start_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_globals');
    }
};
