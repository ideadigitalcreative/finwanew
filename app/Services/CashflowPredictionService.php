<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CashflowPredictionService
{
    public function predict(int $tenantId): ?array
    {
        $now = Carbon::now('Asia/Jakarta');
        $startOfMonth = $now->copy()->startOfMonth();
        $currentDay = $now->day;
        $daysInMonth = $now->daysInMonth;
        $remainingDays = $daysInMonth - $currentDay;

        $totalIncome = Transaction::where('tenant_id', $tenantId)
            ->where('type', 'income')
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startOfMonth, $now])
            ->sum('amount');

        $totalExpense = Transaction::where('tenant_id', $tenantId)
            ->where('type', 'expense')
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startOfMonth, $now])
            ->sum('amount');

        $activeDays = Transaction::where('tenant_id', $tenantId)
            ->where('type', 'expense')
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startOfMonth, $now])
            ->distinct()
            ->count(DB::raw('DATE(transaction_date)'));

        $totalExpense = (float) $totalExpense;
        $totalIncome = (float) $totalIncome;
        $activeDays = max(1, $activeDays);
        $dailyAvg = $totalExpense / $activeDays;
        $predictedExpense = $dailyAvg * $daysInMonth;
        $predictedIncome = $currentDay > 0 ? ($totalIncome / $currentDay) * $daysInMonth : $totalIncome;

        $totalBudget = Budget::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->sum('amount');
        $totalBudget = (float) $totalBudget;

        $safeDailyLimit = $remainingDays > 0
            ? max(0, ($totalBudget - $totalExpense)) / $remainingDays
            : 0;

        $status = 'no_budget';
        $statusMessage = '';

        if ($totalBudget > 0) {
            if ($predictedExpense <= $totalBudget) {
                $buffer = $totalBudget - $predictedExpense;
                $status = 'on_track';
                $statusMessage = '✅ On track! Masih ada buffer Rp '.number_format($buffer, 0, ',', '.').'.';
            } elseif ($predictedExpense <= $totalBudget * 1.2) {
                $excess = $predictedExpense - $totalBudget;
                $status = 'warning';
                $statusMessage = '⚠️ Prediksi melebihi budget Rp '.number_format($excess, 0, ',', '.')."!\n\n💡 Kurangi Rp ".number_format($excess / max(1, $remainingDays), 0, ',', '.').'/hari untuk tetap on track.';
            } else {
                $excess = $predictedExpense - $totalBudget;
                $status = 'danger';
                $statusMessage = '🚨 Prediksi jauh melebihi budget Rp '.number_format($excess, 0, ',', '.')."!\n\n💡 Perlu kurangi Rp ".number_format($excess / max(1, $remainingDays), 0, ',', '.').'/hari untuk stay on track.';
            }
        } else {
            $statusMessage = '💡 Atur budget untuk kontrol pengeluaran lebih baik.';
        }

        return [
            'current_day' => $currentDay,
            'days_in_month' => $daysInMonth,
            'remaining_days' => $remainingDays,
            'month_progress' => round(($currentDay / $daysInMonth) * 100),
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'daily_avg_expense' => round($dailyAvg),
            'predicted_expense' => round($predictedExpense),
            'predicted_income' => round($predictedIncome),
            'total_budget' => $totalBudget,
            'safe_daily_limit' => round($safeDailyLimit),
            'status' => $status,
            'status_message' => $statusMessage,
        ];
    }

    public function formatMessage(array $data): string
    {
        $message = "📊 *Laporan Pertengahan Bulan*\n";
        $message .= '_'.Carbon::now('Asia/Jakarta')->format('d F Y')."_\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";

        $message .= "📅 Hari ke-{$data['current_day']} dari {$data['days_in_month']} hari ({$data['month_progress']}%)\n";
        $message .= '💰 Pengeluaran: Rp '.number_format($data['total_expense'], 0, ',', '.')."\n";

        if ($data['total_income'] > 0) {
            $message .= '📈 Pemasukan: Rp '.number_format($data['total_income'], 0, ',', '.')."\n";
        }

        $message .= '📊 Rata-rata harian: Rp '.number_format($data['daily_avg_expense'], 0, ',', '.')."\n";

        if ($data['total_budget'] > 0) {
            $message .= '🎯 Budget: Rp '.number_format($data['total_budget'], 0, ',', '.')."\n";
        }

        $message .= '📈 Prediksi akhir bulan: Rp '.number_format($data['predicted_expense'], 0, ',', '.')."\n\n";

        $message .= $data['status_message']."\n\n";

        if ($data['safe_daily_limit'] > 0 && $data['remaining_days'] > 0) {
            $message .= '💡 Batas aman: Rp '.number_format($data['safe_daily_limit'], 0, ',', '.')."/hari untuk {$data['remaining_days']} hari ke depan\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━\n";
        $message .= "_Ketik 'cek budget' untuk detail_";

        return $message;
    }
}
