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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['once', 'daily', 'weekly', 'monthly'])->default('monthly');
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('category_type')->nullable();
            $table->date('reminder_date')->nullable(); // For once type
            $table->tinyInteger('reminder_day')->nullable(); // 1-31 for monthly, 0-6 for weekly
            $table->string('reminder_time', 5)->default('08:00'); // HH:MM format
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index('next_send_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
