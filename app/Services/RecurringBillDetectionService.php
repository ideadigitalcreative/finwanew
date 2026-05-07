<?php

namespace App\Services;

use App\Models\Reminder;
use App\Models\Transaction;
use Carbon\Carbon;

class RecurringBillDetectionService
{
    protected int $minOccurrences;

    protected int $lookbackMonths;

    public function __construct(int $minOccurrences = 2, int $lookbackMonths = 3)
    {
        $this->minOccurrences = $minOccurrences;
        $this->lookbackMonths = $lookbackMonths;
    }

    public function analyze(int $tenantId): array
    {
        $startDate = Carbon::now('Asia/Jakarta')
            ->subMonths($this->lookbackMonths)
            ->startOfMonth();

        $transactions = Transaction::where('tenant_id', $tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $startDate)
            ->whereNotNull('description')
            ->orderBy('transaction_date')
            ->get(['id', 'description', 'amount', 'transaction_date', 'category_id']);

        if ($transactions->count() < $this->minOccurrences) {
            return [];
        }

        $groups = $this->groupTransactions($transactions);
        $patterns = $this->detectPatterns($groups);
        $created = $this->createReminders($tenantId, $patterns);

        return $created;
    }

    protected function groupTransactions($transactions): array
    {
        $groups = [];

        foreach ($transactions as $tx) {
            $normalized = $this->normalizeDescription($tx->description);

            if (strlen($normalized) < 3) {
                continue;
            }

            $matched = false;
            foreach ($groups as $key => &$group) {
                $keyNormalized = $this->normalizeDescription($key);

                if ($keyNormalized === $normalized || $this->isFuzzyMatch($keyNormalized, $normalized)) {
                    $group['transactions'][] = $tx;
                    $matched = true;
                    break;
                }
            }
            unset($group);

            if (! $matched) {
                $groups[$tx->description] = [
                    'canonical' => $tx->description,
                    'transactions' => [$tx],
                ];
            }
        }

        return array_filter($groups, function ($g) {
            return count($g['transactions']) >= $this->minOccurrences;
        });
    }

    protected function normalizeDescription(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\d[\d.,]*\s*(rb|ribu|k|jt|juta|m|million)?/i', '', $text);
        $text = preg_replace('/rp\.?\s*/i', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    protected function isFuzzyMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (strlen($a) < 3 || strlen($b) < 3) {
            return false;
        }

        similar_text($a, $b, $percent);

        if ($percent >= 70) {
            $longer = strlen($a) > strlen($b) ? $a : $b;
            $shorter = strlen($a) > strlen($b) ? $b : $a;

            if (str_contains($longer, $shorter)) {
                return true;
            }

            return $percent >= 75;
        }

        return false;
    }

    protected function detectPatterns(array $groups): array
    {
        $patterns = [];

        foreach ($groups as $key => $group) {
            $txs = $group['transactions'];
            $count = count($txs);

            $amounts = $txs->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values();
            $avgAmount = $amounts->avg();

            $dates = $txs->pluck('transaction_date')
                ->map(fn ($d) => Carbon::parse($d))
                ->sortBy('timestamp')
                ->values();

            $intervals = [];
            $days = [];

            for ($i = 1; $i < $dates->count(); $i++) {
                $diff = $dates[$i]->diffInDays($dates[$i - 1]);
                $intervals[] = $diff;
            }
            $intervals = array_filter($intervals, fn ($v) => $v > 0);

            foreach ($dates as $date) {
                $days[] = $date->day;
            }

            $avgInterval = ! empty($intervals) ? array_sum($intervals) / count($intervals) : 0;

            $mostCommonDay = $this->getMostFrequent($days);

            $type = $this->classifyInterval($avgInterval, $count);
            $confidence = $this->calculateConfidence($count, $intervals, $avgInterval);

            if ($type === null) {
                continue;
            }

            $patterns[] = [
                'canonical' => $group['canonical'],
                'normalized' => $this->normalizeDescription($group['canonical']),
                'avg_amount' => round($avgAmount),
                'avg_interval' => round($avgInterval),
                'type' => $type,
                'day' => $mostCommonDay,
                'occurrences' => $count,
                'confidence' => $confidence,
                'category_id' => $txs[0]->category_id,
            ];
        }

        usort($patterns, fn ($a, $b) => $b['occurrences'] <=> $a['occurrences']);

        return $patterns;
    }

    protected function classifyInterval(float $avgInterval, int $count): ?string
    {
        if ($avgInterval <= 0) {
            return null;
        }

        if ($avgInterval >= 25 && $avgInterval <= 35) {
            return 'monthly';
        }

        if ($avgInterval >= 6 && $avgInterval <= 8) {
            return 'weekly';
        }

        if ($avgInterval >= 85 && $avgInterval <= 95) {
            return 'monthly';
        }

        if ($count >= 3) {
            $cv = 0;
            if ($avgInterval > 0) {
                $lowerBound = $avgInterval * 0.7;
                $upperBound = $avgInterval * 1.3;

                if ($avgInterval >= 20 && $avgInterval <= 40) {
                    return 'monthly';
                }
                if ($avgInterval >= 5 && $avgInterval <= 10) {
                    return 'weekly';
                }
            }
        }

        return null;
    }

    protected function calculateConfidence(int $count, array $intervals, float $avgInterval): string
    {
        if ($count >= 4 && $avgInterval > 0) {
            $stdDev = $this->standardDeviation($intervals);
            $cv = $avgInterval > 0 ? $stdDev / $avgInterval : 1;

            if ($cv < 0.2) {
                return 'high';
            }
            if ($cv < 0.4) {
                return 'medium';
            }
        }

        if ($count >= 3) {
            return 'medium';
        }

        return 'low';
    }

    protected function standardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= count($values);

        return sqrt($variance);
    }

    protected function getMostFrequent(array $items): ?int
    {
        if (empty($items)) {
            return null;
        }

        $counts = array_count_values($items);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    protected function createReminders(int $tenantId, array $patterns): array
    {
        $created = [];
        $existingReminders = Reminder::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('metadata')
            ->get();

        foreach ($patterns as $pattern) {
            $alreadyExists = $existingReminders->contains(function ($r) use ($pattern) {
                $metadata = $r->metadata ?? [];
                if (($metadata['source'] ?? '') === 'auto_detected') {
                    $existingNormalized = $this->normalizeDescription($r->title);

                    return $this->isFuzzyMatch($existingNormalized, $pattern['normalized'])
                        || $existingNormalized === $pattern['normalized'];
                }

                return false;
            });

            if ($alreadyExists) {
                continue;
            }

            $nextDate = $this->calculateNextDate($pattern);
            if (! $nextDate) {
                continue;
            }

            $reminder = Reminder::create([
                'tenant_id' => $tenantId,
                'title' => $this->generateTitle($pattern['canonical']),
                'description' => 'Tagihan rutin terdeteksi otomatis. Rata-rata: Rp '.number_format($pattern['avg_amount'], 0, ',', '.'),
                'type' => $pattern['type'],
                'amount' => $pattern['avg_amount'],
                'category_type' => null,
                'reminder_day' => $pattern['day'],
                'reminder_time' => '08:00',
                'is_active' => true,
                'metadata' => json_encode([
                    'source' => 'auto_detected',
                    'confidence' => $pattern['confidence'],
                    'occurrences' => $pattern['occurrences'],
                    'avg_interval_days' => $pattern['avg_interval'],
                    'detected_at' => now()->toIso8601String(),
                ]),
            ]);

            $reminder->calculateNextSendAt();

            $created[] = [
                'reminder_id' => $reminder->id,
                'title' => $reminder->title,
                'type' => $pattern['type'],
                'amount' => $pattern['avg_amount'],
                'confidence' => $pattern['confidence'],
                'occurrences' => $pattern['occurrences'],
                'next_send_at' => $reminder->next_send_at?->format('d M Y'),
            ];
        }

        return $created;
    }

    protected function calculateNextDate(array $pattern): ?Carbon
    {
        $now = Carbon::now('Asia/Jakarta');
        $day = min(max($pattern['day'] ?? 1, 1), 28);

        switch ($pattern['type']) {
            case 'weekly':
                $next = $now->copy()->addDays(6)->startOfDay();
                break;

            case 'monthly':
            default:
                $next = $now->copy()->setDay($day)->startOfDay();
                if ($next->lte($now)) {
                    $next->addMonth();
                    $day = min($day, $next->daysInMonth);
                    $next->setDay($day);
                }
                break;
        }

        return $next;
    }

    protected function generateTitle(string $canonical): string
    {
        $title = trim($canonical);

        if (mb_strlen($title) > 50) {
            $title = mb_substr($title, 0, 47).'...';
        }

        return ucfirst($title);
    }
}
