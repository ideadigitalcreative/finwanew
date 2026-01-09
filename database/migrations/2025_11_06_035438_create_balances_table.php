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
        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('account_name'); // Bank name, Cash, Wallet, etc
            $table->string('account_number')->nullable();
            $table->enum('account_type', ['bank', 'cash', 'wallet', 'investment', 'other']);
            $table->string('currency', 3)->default('IDR');
            $table->decimal('balance', 15, 2)->default(0);
            $table->date('balance_date'); // Last balance update date
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->unique(['tenant_id', 'account_name', 'account_number']);
            $table->index(['tenant_id', 'account_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
