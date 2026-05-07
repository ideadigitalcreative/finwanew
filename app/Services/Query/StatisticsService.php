<?php

namespace App\Services\Query;

use App\Models\Balance;
use App\Models\Message;
use App\Models\Transaction;
use App\Services\AchievementService;
use App\Services\SpendingInsightService;
use Illuminate\Support\Facades\Log;

/**
 * StatisticsService - Handles statistics, insights, and achievements queries
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class StatisticsService
{
    protected Message $message;

    protected $sendReplyCallback;

    /**
     * Constructor
     *
     * @param  Message  $message  The message being processed
     * @param  callable  $sendReplyCallback  Callback to send reply via WhatsApp
     */
    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    /**
     * Send reply via callback
     */
    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Handle check statistics request (cek statistik)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckStatistics()
     * LINES: 7951-8027
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckStatistics(): void
    {
        try {
            $reply = "📈 *Statistik Keuangan Bulan Ini*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();

            // Get transactions this month
            $transactions = Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
                ->with('category')
                ->get();

            $totalIncome = $transactions->where('type', 'income')->sum('amount');
            $totalExpense = $transactions->where('type', 'expense')->sum('amount');
            $netFlow = $totalIncome - $totalExpense;

            // Overview
            $reply .= '💰 *Pemasukan*: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
            $reply .= '💸 *Pengeluaran*: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
            $netEmoji = $netFlow >= 0 ? '📈' : '📉';
            $netLabel = $netFlow >= 0 ? 'Surplus' : 'Defisit';
            $reply .= "{$netEmoji} *{$netLabel}*: Rp ".number_format(abs($netFlow), 0, ',', '.')."\n\n";

            // Top 3 expense categories
            $reply .= "🏆 *Top 3 Pengeluaran*\n";
            $expensesByCategory = $transactions
                ->where('type', 'expense')
                ->groupBy('category_id')
                ->map(fn ($items) => [
                    'name' => $items->first()->category->name ?? 'Lainnya',
                    'total' => $items->sum('amount'),
                ])
                ->sortByDesc('total')
                ->take(3)
                ->values();

            $rank = 1;
            foreach ($expensesByCategory as $cat) {
                $medal = match ($rank) {
                    1 => '🥇',
                    2 => '🥈',
                    3 => '🥉',
                    default => '•'
                };
                $reply .= "{$medal} {$cat['name']}: Rp ".number_format($cat['total'], 0, ',', '.')."\n";
                $rank++;
            }

            if ($expensesByCategory->isEmpty()) {
                $reply .= "Belum ada data pengeluaran.\n";
            }

            // Average daily spending
            $daysInMonth = now()->day;
            $avgDaily = $daysInMonth > 0 ? $totalExpense / $daysInMonth : 0;
            $reply .= "\n📅 *Rata-rata harian*: Rp ".number_format($avgDaily, 0, ',', '.')."\n";

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error checking statistics', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat statistik*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle check target request (cek target)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckTarget()
     * LINES: 8029-8063
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckTarget(): void
    {
        try {
            $reply = "🎯 *Target Tabungan Anda*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            // Get total balance as savings progress
            $totalBalance = Balance::where('tenant_id', $this->message->tenant_id)
                ->sum('current_balance');

            $reply .= '💰 *Tabungan saat ini*: Rp '.number_format($totalBalance, 0, ',', '.')."\n\n";

            // Note about setting targets
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "💡 *Set target:*\n";
            $reply .= "_\"set target 10jt untuk liburan\"_\n";
            $reply .= '_"mau nabung 5jt bulan ini"_';

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error checking target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat target*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle check insight request (cek insight, analisis spending)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckInsight()
     * LINES: 5722-5749
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckInsight(): void
    {
        try {
            $insightService = new SpendingInsightService($this->message->tenant_id);
            $report = $insightService->generateInsightReport();

            $this->sendReply($report);

            Log::info('Insight report generated', [
                'message_id' => $this->message->id,
                'tenant_id' => $this->message->tenant_id,
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating insight report', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat insight*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle check achievements request (lihat achievement, badge saya)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckAchievements()
     * LINES: 5751-5816
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckAchievements(): void
    {
        try {
            $achievementService = new AchievementService($this->message->tenant_id);

            // Get user achievements
            $earned = $achievementService->getUserAchievements();
            $streak = $achievementService->getStreakInfo();

            // Calculate total points
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

            // Earned badges
            $count = count($earned);
            if ($count > 0) {
                $message .= "🎖️ *Badge Diraih ($count)*\n";
                foreach ($earned as $item) {
                    $a = $item['achievement'];
                    $message .= "{$a->icon} *{$a->name}*\n";
                    $message .= "   _{$a->description}_\n";
                }
            } else {
                $message .= "Belum ada badge.\n\n";
                $message .= "💡 *Cara dapat badge:*\n";
                $message .= "• Catat transaksi tiap hari → 🔥 Week Warrior\n";
                $message .= "• Set budget pertama → 🎯 Budget Beginner\n";
                $message .= "• Scan 10 struk → 📸 Photo Pro\n";
            }

            $this->sendReply($message);

            Log::info('Achievement report generated', [
                'message_id' => $this->message->id,
                'tenant_id' => $this->message->tenant_id,
                'badges_count' => $count,
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating achievement report', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat achievement*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }
}
