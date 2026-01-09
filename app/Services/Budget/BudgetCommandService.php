<?php

namespace App\Services\Budget;

use App\Models\Message;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * BudgetCommandService - Handles Budget-related commands
 */
class BudgetCommandService
{
    protected Message $message;
    protected $sendReplyCallback;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    protected function sendReply(string $text): void
    {
        call_user_func($this->sendReplyCallback, $text);
    }

    /**
     * Handle check budget request (cek budget)
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
                    "📊 *Status Budget*\n\n" .
                    "Anda belum mengatur budget.\n\n" .
                    "💡 *Mulai atur budget:*\n" .
                    "• _\"set budget makan 500rb\"_\n" .
                    "• _\"set budget transport 300rb\"_\n" .
                    "• _\"set budget belanja 1jt\"_\n\n" .
                    "Budget membantu Anda mengontrol pengeluaran per kategori."
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
                $reply .= "Budget: Rp " . number_format($budgetAmount, 0, ',', '.') . "\n";
                $reply .= "Terpakai: Rp " . number_format($spending, 0, ',', '.') . " (" . number_format($percentage, 1) . "%)";
                
                // Alert indicator
                if ($percentage >= 100) {
                    $reply .= " 🚨";
                    $hasAlert = true;
                } elseif ($percentage >= 80) {
                    $reply .= " ⚠️";
                    $hasAlert = true;
                }
                $reply .= "\n";
                
                // Progress bar
                $barLength = 10;
                $filled = (int) min(round(($percentage / 100) * $barLength), $barLength);
                $empty = $barLength - $filled;
                $reply .= str_repeat('▓', $filled) . str_repeat('░', $empty) . "\n";
                
                $reply .= "Sisa: Rp " . number_format($remaining, 0, ',', '.') . "\n\n";
            }
            
            // Summary
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "📈 *Total Budget:* Rp " . number_format($totalBudget, 0, ',', '.') . "\n";
            $reply .= "💸 *Total Terpakai:* Rp " . number_format($totalSpending, 0, ',', '.') . "\n";
            $totalRemaining = max(0, $totalBudget - $totalSpending);
            $reply .= "💰 *Total Sisa:* Rp " . number_format($totalRemaining, 0, ',', '.') . "\n\n";
            
            // Tips or alerts
            if ($hasAlert) {
                $reply .= "⚠️ *Perhatian:* Ada budget yang mendekati/melebihi batas!\n";
                $reply .= "Pertimbangkan untuk mengurangi pengeluaran.\n\n";
            } else {
                $reply .= "✅ Semua budget terkendali dengan baik!\n\n";
            }
            
            $reply .= "💡 Ubah budget: _\"set budget [kategori] [nominal]\"_";
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error checking budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat budget*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }

    /**
     * Handle set budget request (set budget untuk kategori)
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
            $textLower = strtolower($messageText);
            $textLower = preg_replace('/(set|atur|buat)?\s*budget\s*/i', '', $textLower);
            $textLower = preg_replace('/\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta|m|million)?/i', '', $textLower);
            $textLower = trim($textLower);
            
            // Map common categories to match actual category names in database
            $existingCategory = Category::where('tenant_id', $this->message->tenant_id)
                ->where(function($query) use ($textLower) {
                    $query->where('name', 'LIKE', '%' . $textLower . '%')
                          ->orWhere('slug', 'LIKE', '%' . $textLower . '%');
                })
                ->first();
            
            if ($existingCategory) {
                // Use existing category
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
                    'ojek' => 'Transport',
                    'grab' => 'Transport',
                    'gojek' => 'Transport',
                    'belanja' => 'Belanja',
                    'shopping' => 'Belanja',
                    'beli' => 'Belanja',
                    'utilitas' => 'Utilitas',
                    'tagihan' => 'Utilitas',
                    'listrik' => 'Utilitas',
                    'air' => 'Utilitas',
                    'internet' => 'Utilitas',
                    'wifi' => 'Utilitas',
                    'pulsa' => 'Utilitas',
                    'hiburan' => 'Hiburan',
                    'entertainment' => 'Hiburan',
                    'nonton' => 'Hiburan',
                    'game' => 'Hiburan',
                    'kesehatan' => 'Kesehatan',
                    'health' => 'Kesehatan',
                    'obat' => 'Kesehatan',
                    'dokter' => 'Kesehatan',
                    'pendidikan' => 'Pendidikan',
                    'education' => 'Pendidikan',
                    'sekolah' => 'Pendidikan',
                    'kursus' => 'Pendidikan',
                    'keluarga' => 'Keluarga',
                    'family' => 'Keluarga',
                    'ortu' => 'Keluarga',
                    'orang tua' => 'Keluarga',
                ];
                
                $kategori = $categoryMap[$textLower] ?? ucfirst($textLower);
                $categoryType = 'pengeluaran_' . strtolower(str_replace([' ', '&'], ['_', ''], $kategori));
            }
            
            if (!$nominal) {
                $this->sendReply(
                    "📊 *Set Budget*\n\n" .
                    "Untuk mengatur budget, ketik:\n\n" .
                    "• _\"set budget makan 500rb\"_\n" .
                    "• _\"budget transport 300rb\"_\n" .
                    "• _\"anggaran belanja 1jt\"_\n\n" .
                    "💡 Budget membantu Anda mengontrol pengeluaran per kategori."
                );
                return;
            }
            
            // Find or create category
            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $this->message->tenant_id,
                    'type' => $categoryType
                ],
                [
                    'name' => ucfirst($kategori ?? 'Lainnya'),
                    'slug' => \Illuminate\Support\Str::slug($kategori ?? 'lainnya'),
                    'icon' => '📁',
                    'is_system' => false
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
                'alert_threshold' => 80
            ]);
            
            // Get current spending
            $currentSpending = $budget->getCurrentSpending();
            $remaining = $budget->getRemainingBudget();
            $percentage = $budget->getUsagePercentage();
            
            // Build reply
            $reply = "✅ *Budget Berhasil Diatur!*\n\n";
            $reply .= "📊 *{$category->name}*\n";
            $reply .= "💵 Budget: Rp " . number_format($nominal, 0, ',', '.') . " /bulan\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            if ($currentSpending > 0) {
                $reply .= "📈 *Status Bulan Ini:*\n";
                $reply .= "Terpakai: Rp " . number_format($currentSpending, 0, ',', '.') . " (" . number_format($percentage, 1) . "%)\n";
                $reply .= "Sisa: Rp " . number_format($remaining, 0, ',', '.') . "\n\n";
                
                // Progress bar
                $barLength = 10;
                $filled = max(0, min($barLength, (int) round(($percentage / 100) * $barLength)));
                $empty = $barLength - $filled;
                $reply .= str_repeat('▓', $filled) . str_repeat('░', $empty) . "\n\n";
                
                if ($percentage >= 80) {
                    $reply .= "⚠️ *Peringatan:* Budget sudah " . number_format($percentage, 1) . "%!\n\n";
                }
            }
            
            $reply .= "🔔 Anda akan mendapat notifikasi saat mencapai 80% budget.\n\n";
            $reply .= "💡 Cek budget: _\"cek budget\"_";
            
            $this->sendReply($reply);
            
            Log::info('Budget created via WhatsApp', [
                'message_id' => $this->message->id,
                'budget_id' => $budget->id,
                'category' => $category->name,
                'amount' => $nominal
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error setting budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mengatur budget*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }

    /**
     * Handle add to budget command
     */
    public function handleAddBudget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            // Manual extraction since we're in fast-path (no FinWa AI)
            $nominal = null;
            $kategori = null;
            
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta|m|million)?/i', $messageText, $matches)) {
                $amount = (float) str_replace(',', '.', $matches[1]);
                $unit = strtolower($matches[2] ?? '');
                
                if (in_array($unit, ['rb', 'ribu', 'k'])) {
                    $nominal = $amount * 1000;
                } elseif (in_array($unit, ['jt', 'juta', 'm', 'million'])) {
                    $nominal = $amount * 1000000;
                } else {
                    $nominal = $amount;
                }
            }
            
            $textLower = strtolower($messageText);
            $textLower = preg_replace('/(tambah|nambah|tambahin|add)?\s*budget\s*/i', '', $textLower);
            $textLower = preg_replace('/\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta|m|million)?/i', '', $textLower);
            $textLower = trim($textLower);
            
            $existingCategory = Category::where('tenant_id', $this->message->tenant_id)
                ->where(function($query) use ($textLower) {
                    $query->where('name', 'LIKE', '%' . $textLower . '%')
                          ->orWhere('slug', 'LIKE', '%' . $textLower . '%');
                })
                ->first();
            
            if ($existingCategory) {
                $kategori = $existingCategory->name;
                $categoryType = $existingCategory->type;
            } else {
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
                $categoryType = 'pengeluaran_' . strtolower(str_replace([' ', '&'], ['_', ''], $kategori));
            }
            
            if (!$nominal) {
                $this->sendReply(
                    "📊 *Tambah Budget*\n\n" .
                    "Untuk menambah budget, ketik:\n\n" .
                    "• _\"tambah budget makan 100rb\"_\n" .
                    "• _\"nambah budget transport 50rb\"_\n\n" .
                    "💡 Budget akan ditambahkan ke budget yang sudah ada."
                );
                return;
            }
            
            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $this->message->tenant_id,
                    'type' => $categoryType
                ],
                [
                    'name' => ucfirst($kategori ?? 'Lainnya'),
                    'slug' => \Illuminate\Support\Str::slug($kategori ?? 'lainnya'),
                    'icon' => '📁',
                    'is_system' => false
                ]
            );
            
            $existingBudget = Budget::where('tenant_id', $this->message->tenant_id)
                ->where('category_id', $category->id)
                ->where('period', 'monthly')
                ->where('is_active', true)
                ->first();
            
            if ($existingBudget) {
                $oldAmount = $existingBudget->amount;
                $newAmount = $oldAmount + $nominal;
                $existingBudget->update(['amount' => $newAmount]);
                
                $currentSpending = $existingBudget->getCurrentSpending();
                $remaining = max(0, $newAmount - $currentSpending);
                $percentage = $newAmount > 0 ? ($currentSpending / $newAmount) * 100 : 0;
                
                $reply = "✅ *Budget Ditambahkan!* 💰\n\n";
                $reply .= "📂 Kategori: *{$category->name}*\n";
                $reply .= "➕ Ditambah: Rp " . number_format($nominal, 0, ',', '.') . "\n";
                $reply .= "📊 Budget Lama: Rp " . number_format($oldAmount, 0, ',', '.') . "\n";
                $reply .= "📊 Budget Baru: Rp " . number_format($newAmount, 0, ',', '.') . "\n";
                $reply .= "📅 Periode: Bulanan\n\n";
                
                if ($currentSpending > 0) {
                    $reply .= "📈 *Status Bulan Ini:*\n";
                    $reply .= "Terpakai: Rp " . number_format($currentSpending, 0, ',', '.') . " (" . number_format($percentage, 1) . "%)\n";
                    $reply .= "Sisa: Rp " . number_format($remaining, 0, ',', '.') . "\n\n";
                    
                    $barLength = 10;
                    $filled = max(0, min($barLength, (int) round(($percentage / 100) * $barLength)));
                    $empty = $barLength - $filled;
                    $reply .= str_repeat('▓', $filled) . str_repeat('░', $empty) . "\n\n";
                    
                    if ($percentage >= 80) {
                        $reply .= "⚠️ *Peringatan:* Budget sudah " . number_format($percentage, 1) . "%!\n\n";
                    }
                }
                
                $reply .= "💡 Ketik _\"cek budget\"_ untuk melihat semua budget Anda.";
                
                $this->sendReply($reply);
                
                Log::info('Budget incremented via WhatsApp', [
                    'message_id' => $this->message->id,
                    'budget_id' => $existingBudget->id,
                    'category' => $category->name,
                    'old_amount' => $oldAmount,
                    'added_amount' => $nominal,
                    'new_amount' => $newAmount
                ]);
            } else {
                $this->sendReply(
                    "⚠️ *Budget Belum Ada*\n\n" .
                    "Belum ada budget untuk kategori *{$category->name}*.\n\n" .
                    "Gunakan perintah:\n" .
                    "_\"set budget {$textLower} " . number_format($nominal, 0, ',', '.') . "\"_\n\n" .
                    "untuk membuat budget baru."
                );
            }
            
        } catch (\Exception $e) {
            Log::error('Error adding budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menambah budget*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }

    /**
     * Handle delete budget request (hapus budget kategori)
     */
    public function handleDeleteBudget(string $messageText): void
    {
        try {
            // Extract category from text
            $textLower = strtolower($messageText);
            $textLower = preg_replace('/(hapus|delete|hilangkan|buang)\s*budget\s*/i', '', $textLower);
            $textLower = trim($textLower);
            
            if (empty($textLower)) {
                // Show list of budgets that can be deleted
                $budgets = Budget::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->with('category')
                    ->get();
                
                if ($budgets->isEmpty()) {
                    $this->sendReply(
                        "📊 *Hapus Budget*\n\n" .
                        "Tidak ada budget aktif untuk dihapus."
                    );
                    return;
                }
                
                $reply = "🗑️ *Hapus Budget*\n\n";
                $reply .= "Pilih budget yang ingin dihapus:\n\n";
                
                foreach ($budgets as $budget) {
                    $categoryName = $budget->category->name ?? 'Lainnya';
                    $reply .= "• _hapus budget {$categoryName}_\n";
                }
                
                $this->sendReply($reply);
                return;
            }
            
            // Find the category
            $category = Category::where('tenant_id', $this->message->tenant_id)
                ->where(function($query) use ($textLower) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%' . $textLower . '%'])
                          ->orWhereRaw('LOWER(slug) LIKE ?', ['%' . $textLower . '%']);
                })
                ->first();
            
            if (!$category) {
                $this->sendReply(
                    "⚠️ *Kategori Tidak Ditemukan*\n\n" .
                    "Kategori '{$textLower}' tidak ditemukan.\n\n" .
                    "Ketik _hapus budget_ untuk melihat daftar budget aktif."
                );
                return;
            }
            
            // Find the active budget for this category
            $budget = Budget::where('tenant_id', $this->message->tenant_id)
                ->where('category_id', $category->id)
                ->where('is_active', true)
                ->first();
            
            if (!$budget) {
                $this->sendReply(
                    "⚠️ *Budget Tidak Ditemukan*\n\n" .
                    "Tidak ada budget aktif untuk kategori *{$category->name}*.\n\n" .
                    "Ketik _hapus budget_ untuk melihat daftar budget aktif."
                );
                return;
            }
            
            // Store info for reply
            $categoryName = $category->name;
            $budgetAmount = $budget->amount;
            
            // Delete the budget
            $budget->delete();
            
            $this->sendReply(
                "✅ *Budget Berhasil Dihapus!*\n\n" .
                "🗑️ Kategori: *{$categoryName}*\n" .
                "💵 Budget: Rp " . number_format($budgetAmount, 0, ',', '.') . "\n\n" .
                "Budget untuk kategori ini tidak lagi aktif.\n\n" .
                "💡 Ketik _cek budget_ untuk melihat budget Anda."
            );
            
            Log::info('Budget deleted via WhatsApp', [
                'message_id' => $this->message->id,
                'category' => $categoryName,
                'amount' => $budgetAmount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menghapus budget*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
}
