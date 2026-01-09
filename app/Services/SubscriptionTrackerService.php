<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionTrackerService
{
    protected int $tenantId;
    
    // Common subscription keywords
    const SUBSCRIPTION_KEYWORDS = [
        'netflix', 'spotify', 'youtube', 'disney', 'hbo', 'prime', 'amazon',
        'internet', 'wifi', 'indihome', 'biznet', 'firstmedia', 'myrepublic',
        'listrik', 'pln', 'pdam', 'air', 'gas', 'pgn',
        'telkomsel', 'indosat', 'xl', 'tri', 'smartfren', 'pulsa', 'paket data',
        'gym', 'fitness', 'membership',
        'asuransi', 'bpjs', 'insurance',
        'icloud', 'google one', 'dropbox', 'microsoft 365', 'office',
        'vidio', 'viu', 'wetv', 'iqiyi', 'mola',
        'langganan', 'subscription', 'bulanan', 'monthly',
    ];
    
    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }
    
    /**
     * Detect recurring/subscription expenses
     */
    public function detectSubscriptions(): array
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3);
        
        // Get all expenses in last 3 months
        $transactions = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $threeMonthsAgo)
            ->get();
        
        $subscriptions = [];
        
        // Group by description similarity and check recurrence
        $grouped = $this->groupByDescription($transactions);
        
        foreach ($grouped as $key => $txs) {
            // Need at least 2 occurrences to be considered recurring
            if (count($txs) < 2) {
                continue;
            }
            
            // Check if amounts are similar (within 20% variance)
            $amounts = collect($txs)->pluck('amount');
            $avgAmount = $amounts->avg();
            $variance = $amounts->max() - $amounts->min();
            
            if ($avgAmount > 0 && ($variance / $avgAmount) > 0.2) {
                continue; // Too much variance, probably not subscription
            }
            
            // Check if timing is roughly monthly
            $dates = collect($txs)->pluck('transaction_date')->sort();
            $isMonthly = $this->isMonthlyRecurrence($dates);
            
            if ($isMonthly) {
                $description = $txs[0]['description'] ?? $key;
                $subscriptions[] = [
                    'name' => $this->cleanDescription($description),
                    'amount' => round($avgAmount),
                    'frequency' => 'monthly',
                    'occurrences' => count($txs),
                    'last_payment' => $dates->last(),
                    'next_estimated' => Carbon::parse($dates->last())->addMonth(),
                ];
            }
        }
        
        return $subscriptions;
    }
    
    /**
     * Get total monthly subscription cost
     */
    public function getMonthlySubscriptionCost(): float
    {
        $subscriptions = $this->detectSubscriptions();
        return collect($subscriptions)->sum('amount');
    }
    
    /**
     * Generate subscription summary message
     */
    public function generateSummaryMessage(): string
    {
        $subscriptions = $this->detectSubscriptions();
        
        if (empty($subscriptions)) {
            return "📱 *Subscription Tracker*\n\n" .
                   "Tidak terdeteksi pengeluaran berlangganan rutin.\n\n" .
                   "_Catat pengeluaran bulanan (internet, streaming, dll) secara rutin untuk tracking otomatis._";
        }
        
        $totalMonthly = $this->getMonthlySubscriptionCost();
        $totalFormatted = number_format($totalMonthly, 0, ',', '.');
        
        $message = "📱 *Subscription Tracker*\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        $message .= "💰 *Total Bulanan: Rp {$totalFormatted}*\n\n";
        
        foreach ($subscriptions as $index => $sub) {
            $num = $index + 1;
            $amount = number_format($sub['amount'], 0, ',', '.');
            $nextPayment = Carbon::parse($sub['next_estimated'])->translatedFormat('d M');
            
            $message .= "{$num}. *{$sub['name']}*\n";
            $message .= "   💵 Rp {$amount}/bulan\n";
            $message .= "   📅 Perkiraan: {$nextPayment}\n\n";
        }
        
        // Yearly projection
        $yearlyTotal = $totalMonthly * 12;
        $yearlyFormatted = number_format($yearlyTotal, 0, ',', '.');
        $message .= "📊 _Proyeksi tahunan: Rp {$yearlyFormatted}_";
        
        return $message;
    }
    
    /**
     * Check if a transaction looks like a subscription
     */
    public function isSubscriptionExpense(string $description): bool
    {
        $descLower = strtolower($description);
        
        foreach (self::SUBSCRIPTION_KEYWORDS as $keyword) {
            if (str_contains($descLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Group transactions by similar description
     */
    protected function groupByDescription(iterable $transactions): array
    {
        $groups = [];
        
        foreach ($transactions as $tx) {
            $key = $this->normalizeDescription($tx->description ?? '');
            if (empty($key)) {
                continue;
            }
            
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $tx->toArray();
        }
        
        return $groups;
    }
    
    /**
     * Normalize description for grouping
     */
    protected function normalizeDescription(string $description): string
    {
        // Remove numbers, special chars, lowercase
        $normalized = strtolower($description);
        $normalized = preg_replace('/[0-9]+/', '', $normalized);
        $normalized = preg_replace('/[^a-z\s]/', '', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));
        
        return $normalized;
    }
    
    /**
     * Clean description for display
     */
    protected function cleanDescription(string $description): string
    {
        // Capitalize first letter of each word
        return ucwords(strtolower(trim($description)));
    }
    
    /**
     * Check if dates follow monthly pattern
     */
    protected function isMonthlyRecurrence($dates): bool
    {
        if ($dates->count() < 2) {
            return false;
        }
        
        $dates = $dates->map(fn($d) => Carbon::parse($d));
        
        // Check average gap between payments
        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = $dates[$i - 1]->diffInDays($dates[$i]);
        }
        
        $avgGap = collect($gaps)->avg();
        
        // Monthly = 25-35 days average gap
        return $avgGap >= 25 && $avgGap <= 40;
    }
}
