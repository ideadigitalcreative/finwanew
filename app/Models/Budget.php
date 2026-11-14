<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    /**
     * Cached spending data to avoid N+1 queries.
     * Set via Budget::loadBulkSpending() before looping.
     */
    protected ?float $cachedSpending = null;

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
        'rollover_enabled',
        'rollover_amount',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'alert_enabled' => 'boolean',
        'rollover_enabled' => 'boolean',
        'rollover_amount' => 'decimal:2',
        'metadata' => 'array',
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
     * Get current spending for this budget period.
     * Uses cached value if available (from loadBulkSpending).
     */
    public function getCurrentSpending(): float
    {
        if ($this->cachedSpending !== null) {
            return $this->cachedSpending;
        }

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
     * Set cached spending value (used by bulk loading)
     */
    public function setCachedSpending(float $amount): void
    {
        $this->cachedSpending = $amount;
    }

    /**
     * Bulk-load spending for a collection of budgets in a single query.
     *
     * Usage:
     *   $budgets = Budget::where(...)->with('category')->get();
     *   Budget::loadBulkSpending($budgets);
     *   foreach ($budgets as $budget) {
     *       $budget->getCurrentSpending(); // returns cached value, no query
     *   }
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $budgets
     * @return void
     */
    public static function loadBulkSpending($budgets): void
    {
        if ($budgets->isEmpty()) {
            return;
        }

        $tenantId = $budgets->first()->tenant_id;

        // Collect date ranges per budget (keyed by category_id)
        $categoryRanges = [];
        foreach ($budgets as $budget) {
            $catId = $budget->category_id;
            $startDate = $budget->start_date;
            $endDate = $budget->end_date ?? $budget->getPeriodEndDate();

            // If multiple budgets for same category, use the widest range
            if (isset($categoryRanges[$catId])) {
                $existingStart = $categoryRanges[$catId]['start'];
                $existingEnd = $categoryRanges[$catId]['end'];
                if ($startDate->lt($existingStart)) {
                    $categoryRanges[$catId]['start'] = $startDate;
                }
                if ($endDate->gt($existingEnd)) {
                    $categoryRanges[$catId]['end'] = $endDate;
                }
            } else {
                $categoryRanges[$catId] = ['start' => $startDate, 'end' => $endDate];
            }
        }

        // Single query to get spending for all categories
        $spendings = Transaction::where('tenant_id', $tenantId)
            ->where('type', 'expense')
            ->where(function ($query) use ($categoryRanges) {
                foreach ($categoryRanges as $catId => $range) {
                    $query->orWhere(function ($q) use ($catId, $range) {
                        $q->where('category_id', $catId)
                            ->whereBetween('transaction_date', [$range['start'], $range['end']]);
                    });
                }
            })
            ->selectRaw('category_id, SUM(amount) as total_spending')
            ->groupBy('category_id')
            ->pluck('total_spending', 'category_id');

        // Apply cached values to each budget
        foreach ($budgets as $budget) {
            $budget->setCachedSpending((float) ($spendings[$budget->category_id] ?? 0));
        }
    }

    /**
     * Get the effective budget amount (base + rollover from previous period).
     */
    public function getEffectiveAmount(): float
    {
        return (float) $this->amount + (float) $this->rollover_amount;
    }

    /**
     * Get remaining budget amount (effective amount minus spending).
     */
    public function getRemainingBudget(): float
    {
        return max(0, $this->getEffectiveAmount() - $this->getCurrentSpending());
    }

    /**
     * Get leftover amount after spending (can be negative if over budget).
     */
    public function getLeftoverAmount(): float
    {
        return $this->getEffectiveAmount() - $this->getCurrentSpending();
    }

    /**
     * Check if this budget period has ended.
     */
    public function isPeriodEnded(): bool
    {
        $endDate = $this->end_date ?? $this->getPeriodEndDate();

        return Carbon::now()->gt($endDate);
    }

    /**
     * Get budget usage percentage (against effective amount).
     */
    public function getUsagePercentage(): float
    {
        $effective = $this->getEffectiveAmount();

        if ($effective <= 0) {
            return 0;
        }

        return ($this->getCurrentSpending() / $effective) * 100;
    }

    /**
     * Check if budget is over limit (against effective amount).
     */
    public function isOverBudget(): bool
    {
        return $this->getCurrentSpending() > $this->getEffectiveAmount();
    }

    /**
     * Check if budget alert should be triggered
     */
    public function shouldTriggerAlert(): bool
    {
        if (! $this->alert_enabled) {
            return false;
        }

        return $this->getUsagePercentage() >= $this->alert_threshold;
    }

    /**
     * Get the current alert level based on usage percentage.
     *
     * Levels:
     *   - 'none'     : below threshold
     *   - 'info'     : >= threshold (default 50%) — gentle nudge
     *   - 'warning'  : >= 70% — needs attention
     *   - 'critical' : >= 90% or over budget — urgent
     */
    public function getAlertLevel(): string
    {
        if (! $this->alert_enabled) {
            return 'none';
        }

        $pct = $this->getUsagePercentage();

        if ($pct >= 90) {
            return 'critical';
        }
        if ($pct >= 70) {
            return 'warning';
        }
        if ($pct >= 50) {
            return 'info';
        }

        return 'none';
    }

    /**
     * Get alert color class for UI.
     */
    public function getAlertColor(): string
    {
        return match ($this->getAlertLevel()) {
            'critical' => 'red',
            'warning' => 'orange',
            'info' => 'yellow',
            default => 'emerald',
        };
    }

    /**
     * Get period end date based on period type
     */
    public function getPeriodEndDate(): Carbon
    {
        $start = Carbon::parse($this->start_date);

        return match ($this->period) {
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
