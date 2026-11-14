<?php

namespace App\Services\Budget;

use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * BudgetAlertService - Handles budget alert checking and proactive notifications.
 *
 * Budget commands (handleCheckBudget, handleSetBudget, handleAddBudget, handleDeleteBudget)
 * have been consolidated into BudgetCommandHandler.
 */
class BudgetAlertService
{
    /**
     * Check if transaction triggers budget alert.
     * Returns alert message if budget threshold is exceeded.
     */
    public function checkBudgetAlert(Transaction $transaction): ?string
    {
        try {
            $budget = Budget::where('tenant_id', $transaction->tenant_id)
                ->where('category_id', $transaction->category_id)
                ->where('is_active', true)
                ->first();

            if (! $budget) {
                return null;
            }

            $currentSpending = $budget->getCurrentSpending();
            $usagePercentage = $budget->getUsagePercentage();
            $remaining = $budget->getRemainingBudget();
            $alertLevel = $budget->getAlertLevel();

            $categoryName = $transaction->category->name ?? 'Kategori';
            $budgetAmount = number_format($budget->amount, 0, ',', '.');
            $spentAmount = number_format($currentSpending, 0, ',', '.');
            $remainingAmount = number_format($remaining, 0, ',', '.');

            if ($alertLevel === 'critical') {
                $overAmount = $currentSpending - $budget->amount;
                $overFormatted = number_format($overAmount, 0, ',', '.');

                $alert = "\n\n🚨 *BUDGET KRITIS!* 🚨\n".
                    "📁 Kategori: {$categoryName}\n".
                    "💰 Budget: Rp {$budgetAmount}\n".
                    "💸 Terpakai: Rp {$spentAmount}\n".
                    "⚠️ Lebih: Rp {$overFormatted}\n\n".
                    '_Pengeluaran sudah melebihi batas! Segera kurangi._';

                $this->sendBudgetWarning($alert, $transaction, $budget, true);

                return $alert;
            }

            if ($alertLevel === 'warning') {
                $percentText = round($usagePercentage);

                return "\n\n⚠️ *PERINGATAN Budget* ⚠️\n".
                    "📁 {$categoryName}: {$percentText}% terpakai\n".
                    "💰 Budget: Rp {$budgetAmount}\n".
                    "💸 Terpakai: Rp {$spentAmount}\n".
                    "💵 Sisa: Rp {$remainingAmount}\n\n".
                    '_Budget sudah 70%+ terpakai, perlu diwaspadai!_';
            }

            if ($alertLevel === 'info') {
                $percentText = round($usagePercentage);

                return "\n\nℹ️ *Info Budget*\n".
                    "📁 {$categoryName}: {$percentText}% terpakai\n".
                    "💵 Sisa: Rp {$remainingAmount}\n\n".
                    '_Pengeluaran sudah mencapai setengah budget._';
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Error checking budget alert', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send budget warning as a follow-up message.
     */
    public function sendBudgetWarning(string $message, Transaction $transaction, Budget $budget, bool $isOverBudget = false): void
    {
        Log::info('Budget warning triggered', [
            'transaction_id' => $transaction->id,
            'category_id' => $budget->category_id,
            'usage_percentage' => $budget->getUsagePercentage(),
            'is_over_budget' => $isOverBudget,
        ]);
    }

    /**
     * Generate proactive budget health report for a tenant.
     * Called by scheduled command (budget:daily-health-check).
     */
    public static function generateProactiveBudgetAlert(int $tenantId): ?string
    {
        try {
            $budgets = Budget::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('period', 'monthly')
                ->with('category')
                ->get();

            if ($budgets->isEmpty()) {
                return null;
            }

            Budget::loadBulkSpending($budgets);

            $now = \Carbon\Carbon::now('Asia/Jakarta');
            $currentDay = $now->day;
            $daysInMonth = $now->daysInMonth;
            $daysRemaining = $daysInMonth - $currentDay;
            $monthProgress = ($currentDay / $daysInMonth) * 100;

            $alerts = [];

            foreach ($budgets as $budget) {
                if ($budget->amount <= 0) {
                    continue;
                }

                $spending = $budget->getCurrentSpending();
                $usagePercent = ($spending / (float) $budget->amount) * 100;
                $remaining = max(0, (float) $budget->amount - $spending);
                $categoryName = $budget->category->name ?? 'Lainnya';
                $budgetAmount = number_format($budget->amount, 0, ',', '.');
                $spentAmount = number_format($spending, 0, ',', '.');
                $remainingAmount = number_format($remaining, 0, ',', '.');

                if ($usagePercent >= 100) {
                    $over = number_format(abs((float) $budget->amount - $spending), 0, ',', '.');
                    $alerts[] = [
                        'level' => 'critical',
                        'message' => "🚨 *{$categoryName}* sudah melebihi budget!\n".
                            "   Budget: Rp {$budgetAmount}\n".
                            "   Terpakai: Rp {$spentAmount} (lebih Rp {$over})",
                    ];
                } elseif ($usagePercent >= 90) {
                    $alerts[] = [
                        'level' => 'warning',
                        'message' => "⚠️ *{$categoryName}* hampir habis ({$usagePercent}%)\n".
                            "   Budget: Rp {$budgetAmount}\n".
                            "   Terpakai: Rp {$spentAmount}\n".
                            "   Sisa: Rp {$remainingAmount} untuk {$daysRemaining} hari",
                    ];
                } elseif ($usagePercent >= $monthProgress + 15) {
                    $dailyLimit = $daysRemaining > 0 ? $remaining / $daysRemaining : 0;
                    $dailyFormatted = number_format($dailyLimit, 0, ',', '.');
                    $alerts[] = [
                        'level' => 'info',
                        'message' => "📊 *{$categoryName}* — pengeluaran terlalu cepat\n".
                            "   Terpakai: {$usagePercent}% di hari ke-{$currentDay}/{$daysInMonth}\n".
                            "   Sisa: Rp {$remainingAmount} = Rp {$dailyFormatted}/hari",
                    ];
                }
            }

            if (empty($alerts)) {
                return null;
            }

            usort($alerts, function ($a, $b) {
                $order = ['critical' => 0, 'warning' => 1, 'info' => 2];

                return ($order[$a['level']] ?? 3) <=> ($order[$b['level']] ?? 3);
            });

            $message = "📊 *Laporan Kesehatan Budget*\n";
            $message .= '_'.$now->format('d F Y')."_\n";
            $message .= "━━━━━━━━━━━━━━━\n\n";

            foreach ($alerts as $alert) {
                $message .= $alert['message']."\n\n";
            }

            $totalBudget = $budgets->sum('amount');
            $totalSpending = $budgets->sum(function ($b) {
                return $b->getCurrentSpending();
            });
            $totalRemaining = max(0, $totalBudget - $totalSpending);
            $daysLeft = max(1, $daysRemaining);

            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= '💰 Total budget: Rp '.number_format($totalBudget, 0, ',', '.')."\n";
            $message .= '💸 Terpakai: Rp '.number_format($totalSpending, 0, ',', '.')."\n";
            $message .= '💵 Sisa: Rp '.number_format($totalRemaining, 0, ',', '.').' = Rp '.number_format($totalRemaining / $daysLeft, 0, ',', '.')."/hari\n\n";
            $message .= "_Ketik 'cek budget' untuk detail lengkap_";

            return $message;

        } catch (\Exception $e) {
            Log::error('Error generating proactive budget alert', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
