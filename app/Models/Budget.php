<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Budget extends Model
{
    protected $fillable = [
        'tenant_id',
        'category_id',
        'amount',
        'period',
        'start_date',
        'end_date',
        'is_active',
        'alert_enabled',
        'alert_threshold',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'alert_enabled' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Get the tenant that owns the budget
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the category for this budget
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get current spending for this budget period
     */
    public function getCurrentSpending(): float
    {
        $startDate = $this->start_date;
        $endDate = $this->end_date ?? $this->getPeriodEndDate();

        $spending = Transaction::where('tenant_id', $this->tenant_id)
            ->where('category_id', $this->category_id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount');

        return (float) $spending;
    }

    /**
     * Get remaining budget amount
     */
    public function getRemainingBudget(): float
    {
        return max(0, (float) $this->amount - $this->getCurrentSpending());
    }

    /**
     * Get budget usage percentage
     */
    public function getUsagePercentage(): float
    {
        if ($this->amount <= 0) {
            return 0;
        }

        return ($this->getCurrentSpending() / (float) $this->amount) * 100;
    }

    /**
     * Check if budget is over limit
     */
    public function isOverBudget(): bool
    {
        return $this->getCurrentSpending() > (float) $this->amount;
    }

    /**
     * Check if budget alert should be triggered
     */
    public function shouldTriggerAlert(): bool
    {
        if (!$this->alert_enabled) {
            return false;
        }

        return $this->getUsagePercentage() >= $this->alert_threshold;
    }

    /**
     * Get period end date based on period type
     */
    protected function getPeriodEndDate(): Carbon
    {
        $start = Carbon::parse($this->start_date);

        return match($this->period) {
            'daily' => $start->copy()->endOfDay(),
            'weekly' => $start->copy()->endOfWeek(),
            'monthly' => $start->copy()->endOfMonth(),
            'yearly' => $start->copy()->endOfYear(),
            default => $start->copy()->endOfMonth(),
        };
    }

    /**
     * Scope to get active budgets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get budgets for specific period
     */
    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
