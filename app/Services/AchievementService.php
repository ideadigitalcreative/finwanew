<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\UserAchievement;
use App\Models\UserStreak;
use App\Models\Transaction;
use App\Models\Budget;
use App\Models\OcrJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AchievementService
{
    protected int $tenantId;
    
    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }
    
    /**
     * Check and award achievements after a transaction
     */
    public function checkAfterTransaction(): array
    {
        $newAchievements = [];
        
        // Update streak
        $streakResult = $this->updateStreak('daily_record');
        if ($streakResult) {
            // Check streak-based achievements
            $newAchievements = array_merge($newAchievements, $this->checkStreakAchievements());
        }
        
        // Check milestone achievements
        $newAchievements = array_merge($newAchievements, $this->checkMilestoneAchievements());
        
        return $newAchievements;
    }
    
    /**
     * Update daily streak
     */
    public function updateStreak(string $type = 'daily_record'): bool
    {
        $streak = UserStreak::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'streak_type' => $type],
            ['current_streak' => 0, 'longest_streak' => 0]
        );
        
        return $streak->recordActivity();
    }
    
    /**
     * Check and award streak-based achievements
     */
    protected function checkStreakAchievements(): array
    {
        $newAchievements = [];
        
        $streak = UserStreak::where('tenant_id', $this->tenantId)
            ->where('streak_type', 'daily_record')
            ->first();
        
        if (!$streak) {
            return [];
        }
        
        // 7-day streak
        if ($streak->current_streak >= 7) {
            $achievement = $this->awardAchievement('7_day_streak');
            if ($achievement) {
                $newAchievements[] = $achievement;
            }
        }
        
        // 30-day streak
        if ($streak->current_streak >= 30) {
            $achievement = $this->awardAchievement('30_day_streak');
            if ($achievement) {
                $newAchievements[] = $achievement;
            }
        }
        
        return $newAchievements;
    }
    
    /**
     * Check and award milestone achievements
     */
    protected function checkMilestoneAchievements(): array
    {
        $newAchievements = [];
        
        // Count transactions
        $txCount = Transaction::where('tenant_id', $this->tenantId)->count();
        
        // First transaction
        if ($txCount >= 1) {
            $achievement = $this->awardAchievement('first_transaction');
            if ($achievement) {
                $newAchievements[] = $achievement;
            }
        }
        
        // 100 transactions
        if ($txCount >= 100) {
            $achievement = $this->awardAchievement('100_transactions');
            if ($achievement) {
                $newAchievements[] = $achievement;
            }
        }
        
        // OCR count
        $ocrCount = OcrJob::where('tenant_id', $this->tenantId)
            ->where('status', 'completed')
            ->count();
        
        if ($ocrCount >= 10) {
            $achievement = $this->awardAchievement('photo_pro');
            if ($achievement) {
                $newAchievements[] = $achievement;
            }
        }
        
        // Budget count
        $budgetCount = Budget::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->count();
        
        if ($budgetCount >= 1) {
            $achievement = $this->awardAchievement('first_budget');
            if ($achievement) {
                $newAchievements[] = $achievement;
            }
        }
        
        return $newAchievements;
    }
    
    /**
     * Award an achievement to user
     * Returns achievement if newly awarded, null if already has or error
     */
    public function awardAchievement(string $slug): ?Achievement
    {
        try {
            $achievement = Achievement::where('slug', $slug)->first();
            
            if (!$achievement) {
                Log::warning("Achievement not found: {$slug}");
                return null;
            }
            
            // Check if already earned
            $existing = UserAchievement::where('tenant_id', $this->tenantId)
                ->where('achievement_id', $achievement->id)
                ->exists();
            
            if ($existing) {
                return null; // Already has this achievement
            }
            
            // Award achievement
            UserAchievement::create([
                'tenant_id' => $this->tenantId,
                'achievement_id' => $achievement->id,
                'earned_at' => now(),
            ]);
            
            Log::info("Achievement awarded", [
                'tenant_id' => $this->tenantId,
                'achievement' => $slug
            ]);
            
            return $achievement;
            
        } catch (\Exception $e) {
            Log::error("Error awarding achievement", [
                'tenant_id' => $this->tenantId,
                'slug' => $slug,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get all achievements for a user
     */
    public function getUserAchievements(): array
    {
        $earned = UserAchievement::where('tenant_id', $this->tenantId)
            ->with('achievement')
            ->orderBy('earned_at', 'desc')
            ->get();
        
        return $earned->map(function ($ua) {
            return [
                'achievement' => $ua->achievement,
                'earned_at' => $ua->earned_at,
            ];
        })->toArray();
    }
    
    /**
     * Get current streak info
     */
    public function getStreakInfo(): array
    {
        $streak = UserStreak::where('tenant_id', $this->tenantId)
            ->where('streak_type', 'daily_record')
            ->first();
        
        return [
            'current' => $streak?->current_streak ?? 0,
            'longest' => $streak?->longest_streak ?? 0,
            'last_activity' => $streak?->last_activity_date,
        ];
    }
    
    /**
     * Generate achievement summary message for WhatsApp
     */
    public function generateSummaryMessage(): string
    {
        $earned = $this->getUserAchievements();
        $streak = $this->getStreakInfo();
        
        $totalPoints = 0;
        foreach ($earned as $item) {
            $totalPoints += $item['achievement']->points ?? 0;
        }
        
        $message = "🏆 *Achievement Saya*\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        
        // Streak info
        $message .= "🔥 *Streak*\n";
        $message .= "Saat ini: {$streak['current']} hari\n";
        $message .= "Terpanjang: {$streak['longest']} hari\n\n";
        
        // Total points
        $message .= "⭐ *Total Poin:* {$totalPoints}\n\n";
        
        if (count($earned) > 0) {
            $count = count($earned);
            $message .= "🎖️ *Badge Diraih ({$count})*\n";
            foreach ($earned as $item) {
                $a = $item['achievement'];
                $date = Carbon::parse($item['earned_at'])->translatedFormat('d M Y');
                $message .= "{$a->icon} *{$a->name}*\n";
                $message .= "   _{$a->description}_\n";
            }
        } else {
            $message .= "Belum ada badge. Catat transaksi rutin untuk dapat badge! 💪";
        }
        
        return $message;
    }
    
    /**
     * Generate new achievement notification
     */
    public function formatNewAchievementNotification(Achievement $achievement): string
    {
        return "\n\n🎉 *Achievement Unlocked!*\n" .
               "{$achievement->icon} *{$achievement->name}*\n" .
               "_{$achievement->description}_\n" .
               "⭐ +{$achievement->points} poin";
    }
}
