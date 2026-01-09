<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStreak extends Model
{
    protected $fillable = [
        'tenant_id',
        'streak_type',
        'current_streak',
        'longest_streak',
        'last_activity_date',
    ];

    protected $casts = [
        'last_activity_date' => 'date',
    ];

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Update streak based on activity
     */
    public function recordActivity(): bool
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        
        // Already recorded today
        if ($this->last_activity_date && $this->last_activity_date->toDateString() === $today) {
            return false;
        }
        
        // Check if streak continues or resets
        if ($this->last_activity_date && $this->last_activity_date->toDateString() === $yesterday) {
            // Continue streak
            $this->current_streak++;
        } else {
            // Reset streak (gap in days)
            $this->current_streak = 1;
        }
        
        // Update longest streak if needed
        if ($this->current_streak > $this->longest_streak) {
            $this->longest_streak = $this->current_streak;
        }
        
        $this->last_activity_date = $today;
        $this->save();
        
        return true;
    }
}
