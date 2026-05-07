<?php

namespace App\Services\Budget;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Message;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BudgetAlertService - Handles budget-related commands and alerts
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class BudgetAlertService
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
     * Check if transaction triggers budget alert
     * Returns alert message if budget threshold is exceeded
     *
     * MOVED FROM: ProcessIncomingMessage::checkBudgetAlert()
     * LINES: 5638-5705
     * MODIFICATION: None (structural move only)
     */
    public function checkBudgetAlert(Transaction $transaction): ?string
    {
        try {
            // Find active budget for this category
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

            $categoryName = $transaction->category->name ?? 'Kategori';
            $budgetAmount = number_format($budget->amount, 0, ',', '.');
            $spentAmount = number_format($currentSpending, 0, ',', '.');
            $remainingAmount = number_format($remaining, 0, ',', '.');

            // Check if over budget
            if ($budget->isOverBudget()) {
                $overAmount = $currentSpending - $budget->amount;
                $overFormatted = number_format($overAmount, 0, ',', '.');

                $alert = "\n\n🚨 *BUDGET TERLAMPAUI!* 🚨\n".
                    "📁 Kategori: {$categoryName}\n".
                    "💰 Budget: Rp {$budgetAmount}\n".
                    "💸 Terpakai: Rp {$spentAmount}\n".
                    "⚠️ Lebih: Rp {$overFormatted}\n\n".
                    '_Pertimbangkan untuk mengurangi pengeluaran di kategori ini._';

                // Send separate alert notification
                $this->sendBudgetWarning($alert, $transaction, $budget, true);

                return $alert;
            }

            // Check if should trigger warning (approaching threshold)
            if ($budget->shouldTriggerAlert()) {
                $percentText = round($usagePercentage);

                $alert = "\n\n⚠️ *Peringatan Budget*\n".
                    "📁 {$categoryName}: {$percentText}% terpakai\n".
                    "💰 Budget: Rp {$budgetAmount}\n".
                    "💸 Terpakai: Rp {$spentAmount}\n".
                    "💵 Sisa: Rp {$remainingAmount}\n\n".
                    '_Hati-hati, budget hampir habis!_';

                return $alert;
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
     * Send budget warning as a follow-up message
     *
     * MOVED FROM: ProcessIncomingMessage::sendBudgetWarning()
     * LINES: 5707-5720
     * MODIFICATION: None (structural move only)
     */
    public function sendBudgetWarning(string $message, Transaction $transaction, Budget $budget, bool $isOverBudget = false): void
    {
        // For now, the warning is appended to transaction confirmation
        // In future, could send as separate delayed message
        Log::info('Budget warning triggered', [
            'transaction_id' => $transaction->id,
            'category_id' => $budget->category_id,
            'usage_percentage' => $budget->getUsagePercentage(),
            'is_over_budget' => $isOverBudget,
        ]);
    }

    /**
     * Handle check budget request (cek budget)
     *
     * MOVED FROM: ProcessIncomingMessage::handleCheckBudget()
     * LINES: 7850-7949
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckBudget(): void
    {
        try {
            // Get all active budgets for this tenant
            $budgets = Budget::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->where('period', 'monthly')
                ->with('category')
                ->get();

            if ($budgets->isEmpty()) {
                $this->sendReply(
                    "📊 *Status Budget*\n\n".
                    "Anda belum mengatur budget.\n\n".
                    "💡 *Mulai atur budget:*\n".
                    "• _\"set budget makan 500rb\"_\n".
                    "• _\"set budget transport 300rb\"_\n".
                    "• _\"set budget belanja 1jt\"_\n\n".
                    'Budget membantu Anda mengontrol pengeluaran per kategori.'
                );

                return;
            }

            $reply = "📊 *Status Budget Bulan Ini*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            $totalBudget = 0;
            $totalSpending = 0;
            $hasAlert = false;

            foreach ($budgets as $budget) {
                $categoryName = $budget->category->name ?? 'Lainnya';
                $categoryIcon = $budget->category->icon ?? '📁';
                $budgetAmount = (float) $budget->amount;
                $spending = $budget->getCurrentSpending();
                $remaining = $budget->getRemainingBudget();
                $percentage = $budget->getUsagePercentage();

                $totalBudget += $budgetAmount;
                $totalSpending += $spending;

                // Category header
                $reply .= "{$categoryIcon} *{$categoryName}*\n";
                $reply .= 'Budget: Rp '.number_format($budgetAmount, 0, ',', '.')."\n";
                $reply .= 'Terpakai: Rp '.number_format($spending, 0, ',', '.').' ('.number_format($percentage, 1).'%)';

                // Alert indicator
                if ($percentage >= 100) {
                    $reply .= ' 🚨';
                    $hasAlert = true;
                } elseif ($percentage >= 80) {
                    $reply .= ' ⚠️';
                    $hasAlert = true;
                }
                $reply .= "\n";

                // Progress bar
                $barLength = 10;
                $filled = (int) min(round(($percentage / 100) * $barLength), $barLength);
                $empty = $barLength - $filled;
                $reply .= str_repeat('▓', $filled).str_repeat('░', $empty)."\n";

                $reply .= 'Sisa: Rp '.number_format($remaining, 0, ',', '.')."\n\n";
            }

            // Summary
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= '📈 *Total Budget:* Rp '.number_format($totalBudget, 0, ',', '.')."\n";
            $reply .= '💸 *Total Terpakai:* Rp '.number_format($totalSpending, 0, ',', '.')."\n";
            $totalRemaining = max(0, $totalBudget - $totalSpending);
            $reply .= '💰 *Total Sisa:* Rp '.number_format($totalRemaining, 0, ',', '.')."\n\n";

            // Tips or alerts
            if ($hasAlert) {
                $reply .= "⚠️ *Perhatian:* Ada budget yang mendekati/melebihi batas!\n";
                $reply .= "Pertimbangkan untuk mengurangi pengeluaran.\n\n";
            } else {
                $reply .= "✅ Semua budget terkendali dengan baik!\n\n";
            }

            $reply .= '💡 Ubah budget: _"set budget [kategori] [nominal]"_';

            $this->sendReply($reply);

        } catch (\Exception $e) {
            Log::error('Error checking budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal memuat budget*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    /**
     * Handle set budget request (set budget untuk kategori)
     *
     * MOVED FROM: ProcessIncomingMessage::handleSetBudget()
     * LINES: 8065-8268
     * MODIFICATION: None (structural move only)
     */
    public function handleSetBudget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            // Manual extraction since we're in fast-path (no FinWa AI)
            $nominal = null;
            $kategori = null;

            // Extract amount from text
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta|m|million)?/i', $messageText, $matches)) {
                $amount = (float) str_replace(',', '.', $matches[1]);
                $unit = strtolower($matches[2] ?? '');

                // Convert to actual amount
                if (in_array($unit, ['rb', 'ribu', 'k'])) {
                    $nominal = $amount * 1000;
                } elseif (in_array($unit, ['jt', 'juta', 'm', 'million'])) {
                    $nominal = $amount * 1000000;
                } else {
                    $nominal = $amount;
                }
            }

            // Extract category from text
            // Remove budget keywords and amount to get category
            $textLower = strtolower($messageText);
            $textLower = preg_replace('/(set|atur|buat)?\s*budget\s*/i', '', $textLower);
            $textLower = preg_replace('/\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta|m|million)?/i', '', $textLower);
            $textLower = trim($textLower);

            // Map common categories to match actual category names in database
            // Check existing categories first by searching for similar names
            $existingCategory = Category::where('tenant_id', $this->message->tenant_id)
                ->where(function ($query) use ($textLower) {
                    $query->where('name', 'LIKE', '%'.$textLower.'%')
                        ->orWhere('slug', 'LIKE', '%'.$textLower.'%');
                })
                ->first();

            if ($existingCategory) {
                // Use existing category
                $kategori = $existingCategory->name;
                $categoryType = $existingCategory->type;
            } else {
                // Map to standard category types
                $categoryMap = [
                    // Makanan & Minuman
                    'makan' => 'Makanan & Minuman',
                    'makanan' => 'Makanan & Minuman',
                    'minuman' => 'Makanan & Minuman',
                    'food' => 'Makanan & Minuman',
                    'drink' => 'Makanan & Minuman',

                    // Transport
                    'transport' => 'Transport',
                    'transportasi' => 'Transport',
                    'bensin' => 'Transport',
                    'ojek' => 'Transport',
                    'grab' => 'Transport',
                    'gojek' => 'Transport',

                    // Belanja
                    'belanja' => 'Belanja',
                    'shopping' => 'Belanja',
                    'beli' => 'Belanja',

                    // Utilitas & Tagihan
                    'utilitas' => 'Utilitas',
                    'tagihan' => 'Utilitas',
                    'listrik' => 'Utilitas',
                    'air' => 'Utilitas',
                    'internet' => 'Utilitas',
                    'wifi' => 'Utilitas',
                    'pulsa' => 'Utilitas',

                    // Hiburan
                    'hiburan' => 'Hiburan',
                    'entertainment' => 'Hiburan',
                    'nonton' => 'Hiburan',
                    'game' => 'Hiburan',

                    // Kesehatan
                    'kesehatan' => 'Kesehatan',
                    'health' => 'Kesehatan',
                    'obat' => 'Kesehatan',
                    'dokter' => 'Kesehatan',

                    // Pendidikan
                    'pendidikan' => 'Pendidikan',
                    'education' => 'Pendidikan',
                    'sekolah' => 'Pendidikan',
                    'kursus' => 'Pendidikan',

                    // Keluarga
                    'keluarga' => 'Keluarga',
                    'family' => 'Keluarga',
                    'ortu' => 'Keluarga',
                    'orang tua' => 'Keluarga',
                ];

                $kategori = $categoryMap[$textLower] ?? ucfirst($textLower);
                $categoryType = 'pengeluaran_'.strtolower(str_replace([' ', '&'], ['_', ''], $kategori));
            }

            if (! $nominal) {
                $this->sendReply(
                    "📊 *Set Budget*\n\n".
                    "Untuk mengatur budget, ketik:\n\n".
                    "• _\"set budget makan 500rb\"_\n".
                    "• _\"budget transport 300rb\"_\n".
                    "• _\"anggaran belanja 1jt\"_\n\n".
                    '💡 Budget membantu Anda mengontrol pengeluaran per kategori.'
                );

                return;
            }

            // Find or create category
            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $this->message->tenant_id,
                    'type' => $categoryType,
                ],
                [
                    'name' => ucfirst($kategori ?? 'Lainnya'),
                    'slug' => Str::slug($kategori ?? 'lainnya'),
                    'icon' => '📁',
                    'is_system' => false,
                ]
            );

            // Delete existing active budgets for this category+period (prevent accumulation)
            Budget::where('tenant_id', $this->message->tenant_id)
                ->where('category_id', $category->id)
                ->where('period', 'monthly')
                ->where('is_active', true)
                ->delete();

            // Create new budget
            $budget = Budget::create([
                'tenant_id' => $this->message->tenant_id,
                'category_id' => $category->id,
                'amount' => $nominal,
                'period' => 'monthly',
                'start_date' => now()->startOfMonth(),
                'end_date' => now()->endOfMonth(),
                'is_active' => true,
                'alert_enabled' => true,
                'alert_threshold' => 80,
            ]);

            // Get current spending
            $currentSpending = $budget->getCurrentSpending();
            $remaining = $budget->getRemainingBudget();
            $percentage = $budget->getUsagePercentage();

            // Build reply
            $reply = "✅ *Budget Berhasil Diatur!*\n\n";
            $reply .= "📊 *{$category->name}*\n";
            $reply .= '💵 Budget: Rp '.number_format($nominal, 0, ',', '.')." /bulan\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            if ($currentSpending > 0) {
                $reply .= "📈 *Status Bulan Ini:*\n";
                $reply .= 'Terpakai: Rp '.number_format($currentSpending, 0, ',', '.').' ('.number_format($percentage, 1)."%)\n";
                $reply .= 'Sisa: Rp '.number_format($remaining, 0, ',', '.')."\n\n";

                // Progress bar
                $barLength = 10;
                $filled = max(0, min($barLength, (int) round(($percentage / 100) * $barLength)));
                $empty = $barLength - $filled;
                $reply .= str_repeat('▓', $filled).str_repeat('░', $empty)."\n\n";

                if ($percentage >= 80) {
                    $reply .= '⚠️ *Peringatan:* Budget sudah '.number_format($percentage, 1)."%!\n\n";
                }
            }

            $reply .= "🔔 Anda akan mendapat notifikasi saat mencapai 80% budget.\n\n";
            $reply .= '💡 Cek budget: _"cek budget"_';

            $this->sendReply($reply);

            Log::info('Budget created via WhatsApp', [
                'message_id' => $this->message->id,
                'budget_id' => $budget->id,
                'category' => $category->name,
                'amount' => $nominal,
            ]);

        } catch (\Exception $e) {
            Log::error('Error setting budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal mengatur budget*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }

    /**
     * Handle add to budget command
     * Increments existing budget amount instead of replacing
     *
     * MOVED FROM: ProcessIncomingMessage::handleAddBudget()
     * LINES: 8270-8437
     * MODIFICATION: None (structural move only)
     */
    public function handleAddBudget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            // Manual extraction since we're in fast-path (no FinWa AI)
            $nominal = null;
            $kategori = null;

            // Extract amount from text
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta|m|million)?/i', $messageText, $matches)) {
                $amount = (float) str_replace(',', '.', $matches[1]);
                $unit = strtolower($matches[2] ?? '');

                // Convert to actual amount
                if (in_array($unit, ['rb', 'ribu', 'k'])) {
                    $nominal = $amount * 1000;
                } elseif (in_array($unit, ['jt', 'juta', 'm', 'million'])) {
                    $nominal = $amount * 1000000;
                } else {
                    $nominal = $amount;
                }
            }

            // Extract category from text
            $textLower = strtolower($messageText);
            $textLower = preg_replace('/(tambah|nambah|tambahin|add)?\s*budget\s*/i', '', $textLower);
            $textLower = preg_replace('/\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta|m|million)?/i', '', $textLower);
            $textLower = trim($textLower);

            // Map common categories to match actual category names in database
            $existingCategory = Category::where('tenant_id', $this->message->tenant_id)
                ->where(function ($query) use ($textLower) {
                    $query->where('name', 'LIKE', '%'.$textLower.'%')
                        ->orWhere('slug', 'LIKE', '%'.$textLower.'%');
                })
                ->first();

            if ($existingCategory) {
                $kategori = $existingCategory->name;
                $categoryType = $existingCategory->type;
            } else {
                // Map to standard category types
                $categoryMap = [
                    'makan' => 'Makanan & Minuman',
                    'makanan' => 'Makanan & Minuman',
                    'minuman' => 'Makanan & Minuman',
                    'food' => 'Makanan & Minuman',
                    'drink' => 'Makanan & Minuman',
                    'transport' => 'Transport',
                    'transportasi' => 'Transport',
                    'bensin' => 'Transport',
                    'belanja' => 'Belanja',
                    'shopping' => 'Belanja',
                    'utilitas' => 'Utilitas',
                    'tagihan' => 'Utilitas',
                    'hiburan' => 'Hiburan',
                    'kesehatan' => 'Kesehatan',
                    'pendidikan' => 'Pendidikan',
                    'keluarga' => 'Keluarga',
                ];

                $kategori = $categoryMap[$textLower] ?? ucfirst($textLower);
                $categoryType = 'pengeluaran_'.strtolower(str_replace([' ', '&'], ['_', ''], $kategori));
            }

            if (! $nominal) {
                $this->sendReply(
                    "📊 *Tambah Budget*\n\n".
                    "Untuk menambah budget, ketik:\n\n".
                    "• _\"tambah budget makan 100rb\"_\n".
                    "• _\"nambah budget transport 50rb\"_\n\n".
                    '💡 Budget akan ditambahkan ke budget yang sudah ada.'
                );

                return;
            }

            // Find or create category
            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $this->message->tenant_id,
                    'type' => $categoryType,
                ],
                [
                    'name' => ucfirst($kategori ?? 'Lainnya'),
                    'slug' => Str::slug($kategori ?? 'lainnya'),
                    'icon' => '📁',
                    'is_system' => false,
                ]
            );

            // Find existing active budget for this category
            $existingBudget = Budget::where('tenant_id', $this->message->tenant_id)
                ->where('category_id', $category->id)
                ->where('period', 'monthly')
                ->where('is_active', true)
                ->first();

            if ($existingBudget) {
                // Add to existing budget
                $oldAmount = $existingBudget->amount;
                $newAmount = $oldAmount + $nominal;
                $existingBudget->update(['amount' => $newAmount]);

                $currentSpending = $existingBudget->getCurrentSpending();
                $remaining = max(0, $newAmount - $currentSpending);
                $percentage = $newAmount > 0 ? ($currentSpending / $newAmount) * 100 : 0;

                $reply = "✅ *Budget Ditambahkan!* 💰\n\n";
                $reply .= "📂 Kategori: *{$category->name}*\n";
                $reply .= '➕ Ditambah: Rp '.number_format($nominal, 0, ',', '.')."\n";
                $reply .= '📊 Budget Lama: Rp '.number_format($oldAmount, 0, ',', '.')."\n";
                $reply .= '📊 Budget Baru: Rp '.number_format($newAmount, 0, ',', '.')."\n";
                $reply .= "📅 Periode: Bulanan\n\n";

                if ($currentSpending > 0) {
                    $reply .= "📈 *Status Bulan Ini:*\n";
                    $reply .= 'Terpakai: Rp '.number_format($currentSpending, 0, ',', '.').' ('.number_format($percentage, 1)."%)\n";
                    $reply .= 'Sisa: Rp '.number_format($remaining, 0, ',', '.')."\n\n";

                    // Progress bar
                    $barLength = 10;
                    $filled = max(0, min($barLength, (int) round(($percentage / 100) * $barLength)));
                    $empty = $barLength - $filled;
                    $reply .= str_repeat('▓', $filled).str_repeat('░', $empty)."\n\n";

                    if ($percentage >= 80) {
                        $reply .= '⚠️ *Peringatan:* Budget sudah '.number_format($percentage, 1)."%!\n\n";
                    }
                }

                $reply .= '💡 Ketik _"cek budget"_ untuk melihat semua budget Anda.';

                $this->sendReply($reply);

                Log::info('Budget incremented via WhatsApp', [
                    'message_id' => $this->message->id,
                    'budget_id' => $existingBudget->id,
                    'category' => $category->name,
                    'old_amount' => $oldAmount,
                    'added_amount' => $nominal,
                    'new_amount' => $newAmount,
                ]);
            } else {
                // No existing budget, create new one
                $this->sendReply(
                    "⚠️ *Budget Belum Ada*\n\n".
                    "Belum ada budget untuk kategori *{$category->name}*.\n\n".
                    "Gunakan perintah:\n".
                    "_\"set budget {$textLower} ".number_format($nominal, 0, ',', '.')."\"_\n\n".
                    'untuk membuat budget baru.'
                );
            }

        } catch (\Exception $e) {
            Log::error('Error adding to budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menambah budget*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }

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
