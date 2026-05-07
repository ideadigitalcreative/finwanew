<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Achievements table - Badge definitions
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->string('icon')->default('🏆');
            $table->enum('type', ['streak', 'milestone', 'budget', 'savings', 'special'])->default('milestone');
            $table->json('criteria')->nullable(); // e.g., {"streak_days": 7} or {"transactions_count": 100}
            $table->integer('points')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // User achievements - Pivot table
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('achievement_id')->constrained()->onDelete('cascade');
            $table->timestamp('earned_at');
            $table->json('metadata')->nullable(); // Extra info about how it was earned
            $table->timestamps();

            $table->unique(['tenant_id', 'achievement_id']);
        });

        // User streaks - Track consecutive days
        Schema::create('user_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('streak_type')->default('daily_record'); // daily_record, budget_adherence, etc.
            $table->integer('current_streak')->default(0);
            $table->integer('longest_streak')->default(0);
            $table->date('last_activity_date')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'streak_type']);
        });

        // Seed default achievements
        $this->seedDefaultAchievements();
    }

    protected function seedDefaultAchievements(): void
    {
        $achievements = [
            [
                'slug' => 'first_transaction',
                'name' => 'First Step',
                'description' => 'Catat transaksi pertama Anda',
                'icon' => '🎉',
                'type' => 'milestone',
                'criteria' => json_encode(['transactions_count' => 1]),
                'points' => 5,
            ],
            [
                'slug' => '7_day_streak',
                'name' => 'Week Warrior',
                'description' => 'Catat transaksi 7 hari berturut-turut',
                'icon' => '🔥',
                'type' => 'streak',
                'criteria' => json_encode(['streak_days' => 7]),
                'points' => 20,
            ],
            [
                'slug' => '30_day_streak',
                'name' => 'Monthly Master',
                'description' => 'Catat transaksi 30 hari berturut-turut',
                'icon' => '⭐',
                'type' => 'streak',
                'criteria' => json_encode(['streak_days' => 30]),
                'points' => 100,
            ],
            [
                'slug' => 'budget_master',
                'name' => 'Budget Master',
                'description' => 'Tidak over budget selama 1 bulan penuh',
                'icon' => '💰',
                'type' => 'budget',
                'criteria' => json_encode(['budget_adherence_days' => 30]),
                'points' => 50,
            ],
            [
                'slug' => 'photo_pro',
                'name' => 'Photo Pro',
                'description' => 'Scan 10 struk dengan OCR',
                'icon' => '📸',
                'type' => 'milestone',
                'criteria' => json_encode(['ocr_count' => 10]),
                'points' => 15,
            ],
            [
                'slug' => '100_transactions',
                'name' => 'Century Club',
                'description' => 'Catat 100 transaksi',
                'icon' => '💯',
                'type' => 'milestone',
                'criteria' => json_encode(['transactions_count' => 100]),
                'points' => 30,
            ],
            [
                'slug' => 'first_budget',
                'name' => 'Budget Beginner',
                'description' => 'Set budget kategori pertama',
                'icon' => '🎯',
                'type' => 'budget',
                'criteria' => json_encode(['budgets_count' => 1]),
                'points' => 10,
            ],
            [
                'slug' => 'money_saver',
                'name' => 'Money Saver',
                'description' => 'Hemat 20% dari bulan sebelumnya',
                'icon' => '📉',
                'type' => 'savings',
                'criteria' => json_encode(['savings_percentage' => 20]),
                'points' => 25,
            ],
        ];

        foreach ($achievements as $achievement) {
            \DB::table('achievements')->insert(array_merge($achievement, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_streaks');
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');
    }
};
