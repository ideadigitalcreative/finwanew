<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetGlobal extends Model
{
    protected $table = 'budget_globals';

    protected $fillable = [
        'tenant_id',
        'amount',
        'period',
        'start_date',
        'end_date',
        'is_active',
        'alert_enabled',
        'alert_threshold',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'alert_enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get total spending for this global budget period (all categories).
     */
    public function getCurrentSpending(): float
    {
        $startDate = $this->start_date;
        $endDate = $this->end_date ?? $this->getPeriodEndDate();

        return (float) Transaction::where('tenant_id', $this->tenant_id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');
    }

    /**
     * Get remaining budget.
     */
    public function getRemainingBudget(): float
    {
        return max(0, (float) $this->amount - $this->getCurrentSpending());
    }

    /**
     * Get usage percentage.
     */
    public function getUsagePercentage(): float
    {
        if ($this->amount <= 0) {
            return 0;
        }

        return ($this->getCurrentSpending() / (float) $this->amount) * 100;
    }

    /**
     * Check if over budget.
     */
    public function isOverBudget(): bool
    {
        return $this->getCurrentSpending() > (float) $this->amount;
    }

    /**
     * Check if should trigger alert.
     */
    public function shouldTriggerAlert(): bool
    {
        return $this->getUsagePercentage() >= $this->alert_threshold;
    }

    /**
     * Get end date based on period.
     */
    public function getPeriodEndDate(): Carbon
    {
        return match ($this->period) {
            'monthly' => $this->start_date->copy()->endOfMonth(),
            'yearly' => $this->start_date->copy()->endOfYear(),
            default => $this->start_date->copy()->endOfMonth(),
        };
    }
}
