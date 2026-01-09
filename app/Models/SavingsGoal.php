<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class SavingsGoal extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'target_amount',
        'current_amount',
        'deadline',
        'status',
        'icon',
        'metadata',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'deadline' => 'date',
        'metadata' => 'array',
    ];

    /**
     * Get the tenant that owns this goal
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        if ($this->target_amount <= 0) {
            return 0;
        }
        
        return min(100, ($this->current_amount / $this->target_amount) * 100);
    }

    /**
     * Get remaining amount
     */
    public function getRemainingAmount(): float
    {
        return max(0, $this->target_amount - $this->current_amount);
    }

    /**
     * Get days remaining until deadline
     */
    public function getDaysRemaining(): ?int
    {
        if (!$this->deadline) {
            return null;
        }
        
        return max(0, Carbon::now()->diffInDays($this->deadline, false));
    }

    /**
     * Get suggested monthly savings to reach goal
     */
    public function getSuggestedMonthlySavings(): ?float
    {
        $daysRemaining = $this->getDaysRemaining();
        
        if (!$daysRemaining || $daysRemaining <= 0) {
            return null;
        }
        
        $monthsRemaining = ceil($daysRemaining / 30);
        
        if ($monthsRemaining <= 0) {
            return null;
        }
        
        return $this->getRemainingAmount() / $monthsRemaining;
    }

    /**
     * Check if goal is completed
     */
    public function isCompleted(): bool
    {
        return $this->current_amount >= $this->target_amount;
    }

    /**
     * Add savings to goal
     */
    public function addSavings(float $amount): void
    {
        $this->current_amount += $amount;
        
        if ($this->isCompleted() && $this->status === 'active') {
            $this->status = 'completed';
        }
        
        $this->save();
    }

    /**
     * Generate progress bar string
     */
    public function getProgressBar(int $length = 20): string
    {
        $percentage = $this->getProgressPercentage();
        $filled = (int) round(($percentage / 100) * $length);
        $empty = $length - $filled;
        
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . '] ' . round($percentage) . '%';
    }

    /**
     * Scope to get active goals
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
