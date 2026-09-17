<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('savings_goal_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['deposit', 'withdrawal'])->default('deposit');
            $table->decimal('amount', 15, 2);
            $table->string('note')->nullable();
            $table->timestamp('transaction_date')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'savings_goal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
    }
};