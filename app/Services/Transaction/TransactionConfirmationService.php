<?php

namespace App\Services\Transaction;

use App\Models\Balance;
use App\Models\Message;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TransactionConfirmationService - Handles transaction confirmation messages
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class TransactionConfirmationService
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
     * Send transaction confirmation reply
     *
     * MOVED FROM: ProcessIncomingMessage::sendTransactionConfirmation()
     * LINES: 5425-5636
     * MODIFICATION: Added parameters for sentiment and suggestion to replace class properties
     */
    public function sendConfirmation(array $transactions, bool $needsReview = false, ?array $currentSentiment = null, ?string $currentSuggestion = null): void
    {
        if (empty($transactions)) {
            return;
        }

        $count = count($transactions);

        // Random confirmation messages for variety
        $confirmMessages = [
            'Berhasil Dicatat! 🎉',
            'Tercatat! ✨',
            'Sudah Dicatat! 📝',
            'Oke, Dicatat! 👍',
            'Siap, Sudah Masuk! ✅',
        ];
        $statusText = $needsReview ? 'Menunggu Review ⏳' : $confirmMessages[array_rand($confirmMessages)];

        // Default Standard Responses
        $confirmMessages = [
            'Berhasil Dicatat! 🎉', 'Tercatat! ✨', 'Sudah Dicatat! 📝',
            'Oke, Dicatat! 👍', 'Siap, Sudah Masuk! ✅',
        ];
        $statusText = $needsReview ? 'Menunggu Review ⏳' : $confirmMessages[array_rand($confirmMessages)];

        // Dynamic Sentiment Response (Respon Emosional) 🎭
        // Restore from Cache if property is lost (Robustness Fix)
        if (! $currentSentiment) {
            $currentSentiment = Cache::get('finwa_sentiment_'.$this->message->id);
        }
        if (! $currentSuggestion) {
            $currentSuggestion = Cache::get('finwa_suggestion_'.$this->message->id);
        }

        // Define responses per mood (Global for this method)
        $sentimentResponses = [
            'excited' => [ // Income/Bonus
                'Mantap! Tercatat! 🤩', 'Asyik! Masuk! 🤑', 'Alhamdulillah! ✨',
                'Keren! Rezeki lancar! 🚀', 'Wuidih! Cair! 💸', 'Yeay! Dompet tebal! 💰',
            ],
            'happy' => [ // Good expense/saving
                'Mantap! Tercatat! 🤩', 'Oke, Investasi bagus! 📈', 'Sip! Lanjut! ✨',
                'Tercatat dengan senang hati! 😊',
            ],
            'sad' => [ // Regret/Loss/Big Expense
                'Siap, Tercatat! 💪', 'Sabar ya! Tercatat. 🥲', 'Oke, semangat cari ganti! 🔥',
                'Gapapa, uang bisa dicari lagi! ❤️', 'Tercatat. Jangan sedih ya! 🥺',
                'Huhuhu... Tercatat 😭',
            ],
            'frustrated' => [ // Expensive/Waste
                'Waduh... Tercatat! 😅', 'Sabar... Tercatat kok. 🧘',
                'Tarik napas... Tercatat. 🍃', 'Siap, lupakan dan move on! 🛑',
            ],
        ];

        // Access the first transaction safely for sentiment check
        $firstTransaction = $transactions[0] ?? null;

        if (! $needsReview && $currentSentiment && isset($currentSentiment['mood'])) {
            $mood = $currentSentiment['mood'];
            $score = $currentSentiment['score'] ?? 0;
            $txType = $firstTransaction->type ?? 'expense';

            Log::info('SENTIMENT CHECK', [
                'mood' => $mood,
                'score' => $score,
                'target_mood_exists' => isset($sentimentResponses[$mood]) || in_array($mood, ['positive', 'negative']),
            ]);

            // Mapping
            $targetMood = $mood;
            if ($mood === 'positive') {
                $targetMood = 'happy';
            }
            if ($mood === 'negative') {
                $targetMood = 'sad';
            }

            // Override if mood exists and confidence is high
            if (isset($sentimentResponses[$targetMood]) && abs($score) > 0.1) {
                $options = $sentimentResponses[$targetMood];
                $statusText = $options[array_rand($options)];
            }
        }

        // Format header
        $reply = "✅ *{$statusText}*\n\n";

        $totalIncome = 0;
        $totalExpense = 0;
        $lastTransaction = null;

        foreach ($transactions as $index => $transaction) {
            $transaction->load(['category', 'balance']);
            $lastTransaction = $transaction;

            $typeEmoji = $transaction->type === 'income' ? '💰' : '💸';
            $typeLabel = $transaction->type === 'income' ? 'Pemasukan' : 'Pengeluaran';
            $amount = number_format($transaction->amount, 0, ',', '.');
            
            // Force load fresh category data from database to ensure latest name/icon
            $transaction->load('category');
            $category = $transaction->category->name ?? 'Lainnya';
            $categoryIcon = $transaction->category->icon ?? '📝';
            $desc = $transaction->description ?? '-';

            if ($transaction->type === 'income') {
                $totalIncome += $transaction->amount;
            } else {
                $totalExpense += $transaction->amount;
            }

            // Format transaksi dengan kategori icon
            $reply .= "{$typeEmoji} *{$typeLabel}*\n";
            $reply .= "💵 Rp {$amount}\n";
            $reply .= "{$categoryIcon} {$category}";
            if ($desc && $desc !== '-' && strlen($desc) < 50) {
                $reply .= " • _{$desc}_";
            }

            // Show date if backdated (not today)
            if ($transaction->transaction_date->format('Y-m-d') !== now()->format('Y-m-d')) {
                $reply .= "\n📅 ".$transaction->transaction_date->translatedFormat('d F Y');
            }

            $reply .= "\n";

            // Show balance after transaction (for single transaction)
            if ($count === 1 && $transaction->balance) {
                $balanceName = $transaction->balance->account_name ?? 'Dompet Utama';
                $currentBalance = number_format($transaction->balance->balance ?? 0, 0, ',', '.');
                $reply .= "👛 Sisa saldo {$balanceName}: Rp {$currentBalance}\n";
            } elseif ($count === 1) {
                // Show total balance if no specific balance linked
                $totalBalance = Balance::where('tenant_id', $this->message->tenant_id)
                    ->sum('balance');
                if ($totalBalance > 0) {
                    $currentBalance = number_format($totalBalance, 0, ',', '.');
                    $reply .= "👛 Total saldo: Rp {$currentBalance}\n";
                }
            }

            if ($index < $count - 1) {
                $reply .= "\n";
            }
        }

        // Summary untuk multiple transactions
        if ($count > 1) {
            $reply .= "\n━━━━━━━━━━━━━━━\n";
            if ($totalIncome > 0) {
                $reply .= '💰 Total Masuk: Rp '.number_format($totalIncome, 0, ',', '.')."\n";
            }
            if ($totalExpense > 0) {
                $reply .= '💸 Total Keluar: Rp '.number_format($totalExpense, 0, ',', '.')."\n";
            }
        }

        // Monthly spending summary for expenses
        if ($totalExpense > 0 && $lastTransaction) {
            try {
                $thisMonth = now()->startOfMonth();
                $monthlyExpense = Transaction::where('tenant_id', $this->message->tenant_id)
                    ->where('type', 'expense')
                    ->where('transaction_date', '>=', $thisMonth)
                    ->sum('amount');

                $monthName = now()->locale('id')->translatedFormat('F');
                $monthlyFormatted = number_format($monthlyExpense, 0, ',', '.');
                $reply .= "\n📊 _Total pengeluaran {$monthName}: Rp {$monthlyFormatted}_";
            } catch (\Exception $e) {
                // Ignore if can't get monthly summary
            }
        }

        // Motivational message for income
        // Motivational message for income (fallback if no smart suggestion)
        if ($totalIncome > 0 && $totalExpense === 0 && ! $currentSuggestion) {
            $motivations = [
                "\n\n💪 Mantap! Terus tingkatkan penghasilan!",
                "\n\n🌟 Keren! Rezeki lancar terus ya!",
                "\n\n🎯 Bagus! Semoga makin berkah!",
            ];
            $reply .= $motivations[array_rand($motivations)];
        }

        // Add AI Suggestion if available
        if ($currentSuggestion) {
            $reply .= "\n\n".$currentSuggestion;
        }

        // Add budget alert if any transaction triggered it
        foreach ($transactions as $tx) {
            if ($tx->type === 'expense' && isset($tx->budget_alert)) {
                $reply .= $tx->budget_alert;
                break; // Only show one alert
            }
        }

        // Add new achievement notifications
        foreach ($transactions as $tx) {
            if (isset($tx->new_achievements) && ! empty($tx->new_achievements)) {
                foreach ($tx->new_achievements as $achievement) {
                    $reply .= "\n\n🎉 *Achievement Unlocked!*\n";
                    $reply .= "{$achievement->icon} *{$achievement->name}*\n";
                    $reply .= "_{$achievement->description}_\n";
                    $reply .= "⭐ +{$achievement->points} poin";
                }
                break; // Only show from first transaction
            }
        }

        $this->sendReply($reply);
    }

    /**
     * Send detailed receipt confirmation with product list
     * Shows: date, products, prices, and total (no store name)
     *
     * MOVED FROM: ProcessIncomingMessage::sendReceiptConfirmation()
     * LINES: 4399-4490
     */
    public function sendReceiptConfirmation(Transaction $transaction, array $items, ?string $merchant, int $remainingCount = 0): void
    {
        $amount = number_format($transaction->amount, 0, ',', '.');

        $reply = "🧾 *Struk Tercatat!* ✅\n";
        if ($merchant) {
            $reply .= "🏪 *{$merchant}*\n";
        }
        $reply .= "\n";

        // Transaction date (use actual transaction date, not today)
        $transactionDate = $transaction->transaction_date ?? now();
        $reply .= '📅 '.$transactionDate->translatedFormat('d F Y')."\n";

        // Category info
        $transaction->load('category');
        if ($transaction->category) {
            $catIcon = $transaction->category->icon ?? '📝';
            $catName = $transaction->category->name ?? 'Lainnya';
            $reply .= "{$catIcon} Kategori: {$catName}\n";
        }
        $reply .= "\n";

        // Filter and clean product list
        if (! empty($items)) {
            $validItems = [];
            foreach ($items as $item) {
                // Support both formats: FinWa-AI uses 'nama'/'harga', OcrJobController uses 'name'/'price'
                $nama = $item['nama'] ?? $item['name'] ?? '';
                $harga = $item['harga'] ?? $item['price'] ?? $item['line_total'] ?? 0;

                // Skip invalid items
                if (empty($nama) || $harga <= 0) {
                    continue;
                }
                if ($harga > 50000000) {
                    continue;
                } // Skip unrealistic prices per item (>50jt)
                if (strlen($nama) < 2) {
                    continue;
                } // Too short

                // Less strict filtering
                if (preg_match('/^[0-9\s\.\,\-\:\/]+$/', $nama)) {
                    continue;
                } // Skip if ONLY numbers/symbols
                if (stripos($nama, 'telp') !== false && strlen($nama) > 20) {
                    continue;
                } // Phone numbers
                if (stripos($nama, 'jl.') !== false && stripos($nama, 'jalan') !== false) {
                    continue;
                } // Likely address

                // Skip metode pembayaran / label struk (bukan produk): QRIS Yokee, OVO, AMOUNT, dll
                $receiptParser = app(\App\Services\OCR\ReceiptParserService::class);
                if ($receiptParser->isPaymentMethodOrNonProductLine($nama)) {
                    continue;
                }

                // Clean the product name but keep more chars
                // Remove trailing dates/times or long number sequences often found at end of line
                $nama = preg_replace('/\d{2}\/\d{2}\/\d{4}.*$/', '', $nama);
                $nama = trim($nama);

                if (strlen($nama) >= 2) {
                    $validItems[] = ['nama' => $nama, 'harga' => $harga];
                }
            }

            // Display max 7 items for clearer detail
            if (! empty($validItems)) {
                $reply .= "📋 *Rincian Belanja:*\n";
                $maxDisplay = 7;
                $displayItems = array_slice($validItems, 0, $maxDisplay);
                foreach ($displayItems as $index => $item) {
                    $no = $index + 1;
                    $nama = ucwords(strtolower($item['nama']));
                    $harga = number_format($item['harga'], 0, ',', '.');
                    // Format: 1. Nama Barang .... Rp 15.000
                    $reply .= "  {$no}. {$nama} — _Rp {$harga}_\n";
                }

                $totalRemaining = $remainingCount + (count($validItems) > $maxDisplay ? count($validItems) - $maxDisplay : 0);
                if ($totalRemaining > 0) {
                    $reply .= "  _+ {$totalRemaining} item lainnya..._\n";
                }
                $reply .= "\n";
            }
        }

        // Total
        $reply .= "━━━━━━━━━━━━━━━\n";
        $reply .= "💵 *TOTAL: Rp {$amount}*\n\n";

        // Balance - Show specific balance or total
        if ($transaction->balance) {
            $currentBalance = number_format($transaction->balance->balance ?? 0, 0, ',', '.');
            $balanceName = $transaction->balance->account_name ?? 'Saldo';
            $reply .= "👛 Sisa saldo {$balanceName}: Rp {$currentBalance}\n";
        } else {
            // Show total balance if no specific balance linked
            $totalBalance = Balance::where('tenant_id', $this->message->tenant_id)
                ->sum('balance');
            if ($totalBalance > 0) {
                $currentBalance = number_format($totalBalance, 0, ',', '.');
                $reply .= "👛 Total saldo: Rp {$currentBalance}\n";
            }
        }

        // Monthly summary
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthlyExpense = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->sum('amount');
        $monthlyExpenseFormatted = number_format($monthlyExpense, 0, ',', '.');
        $reply .= '📊 Belanja '.now()->translatedFormat('F').": Rp {$monthlyExpenseFormatted}";

        $this->sendReply($reply);
    }
}
