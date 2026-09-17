<?php

namespace App\Services\Budget;

use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BudgetRolloverService
{
    /**
     * Process rollover for all eligible budgets whose period has ended.
     *
     * Logic:
     *   1. Find budgets with rollover_enabled=true whose period has ended
     *   2. Calculate leftover = effective_amount - current_spending
     *   3. If leftover > 0, create next period budget with rollover_amount = leftover
     *   4. Deactivate the old budget
     *
     * @return array{processed: int, total_rollover: float}
     */
    public function processRollovers(): array
    {
        $processed = 0;
        $totalRollover = 0;

        // Find all active budgets with rollover enabled whose period has ended
        $budgets = Budget::where('rollover_enabled', true)
            ->where('is_active', true)
            ->get();

        foreach ($budgets as $budget) {
            if (! $budget->isPeriodEnded()) {
                continue;
            }

            $leftover = $budget->getLeftoverAmount();

            // Only rollover positive leftover
            if ($leftover <= 0) {
                // Period ended, no rollover, just deactivate
                $budget->update(['is_active' => false]);
                continue;
            }

            // Calculate next period dates
            $nextStart = $this->getNextPeriodStart($budget);
            $nextEnd = $this->getNextPeriodEnd($budget, $nextStart);

            // Check if next period budget already exists
            $existing = Budget::where('tenant_id', $budget->tenant_id)
                ->where('category_id', $budget->category_id)
                ->where('period', $budget->period)
                ->where('start_date', $nextStart)
                ->first();

            if ($existing) {
                // Merge rollover into existing next-period budget
                $existing->update([
                    'rollover_amount' => (float) $existing->rollover_amount + $leftover,
                    'rollover_enabled' => true,
                ]);
            } else {
                // Create new budget for next period with rollover
                Budget::create([
                    'tenant_id' => $budget->tenant_id,
                    'category_id' => $budget->category_id,
                    'amount' => $budget->amount,
                    'period' => $budget->period,
                    'start_date' => $nextStart,
                    'end_date' => $nextEnd,
                    'is_active' => true,
                    'alert_enabled' => $budget->alert_enabled,
                    'alert_threshold' => $budget->alert_threshold,
                    'rollover_enabled' => true,
                    'rollover_amount' => $leftover,
                ]);
            }

            // Deactivate old budget
            $budget->update(['is_active' => false]);

            $processed++;
            $totalRollover += $leftover;

            Log::info('Budget rollover processed', [
                'budget_id' => $budget->id,
                'category_id' => $budget->category_id,
                'leftover' => $leftover,
                'next_start' => $nextStart->format('Y-m-d'),
            ]);
        }

        return [
            'processed' => $processed,
            'total_rollover' => $totalRollover,
        ];
    }

    /**
     * Calculate the start date for the next period.
     */
    private function getNextPeriodStart(Budget $budget): Carbon
    {
        $endDate = $budget->end_date ?? $budget->getPeriodEndDate();

        return match ($budget->period) {
            'daily' => Carbon::parse($endDate)->addDay()->startOfDay(),
            'weekly' => Carbon::parse($endDate)->addWeek()->startOfWeek(),
            'monthly' => Carbon::parse($endDate)->addMonth()->startOfMonth(),
            'yearly' => Carbon::parse($endDate)->addYear()->startOfYear(),
            default => Carbon::parse($endDate)->addMonth()->startOfMonth(),
        };
    }

    /**
     * Calculate the end date for the next period.
     */
    private function getNextPeriodEnd(Budget $budget, Carbon $start): Carbon
    {
        return match ($budget->period) {
            'daily' => $start->copy()->endOfDay(),
            'weekly' => $start->copy()->endOfWeek(),
            'monthly' => $start->copy()->endOfMonth(),
            'yearly' => $start->copy()->endOfYear(),
            default => $start->copy()->endOfMonth(),
        };
    }
}
