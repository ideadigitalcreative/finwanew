<?php

namespace App\Services\Budget;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BudgetCommandHandler - Handles all budget-related WhatsApp commands
 *
 * Consolidated from BudgetAlertService and BudgetCommandService
 * to eliminate code duplication.
 */
class BudgetCommandHandler
{
    protected Message $message;

    protected $sendReplyCallback;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Resolve category name from text input using DB lookup and fallback map.
     */
    protected function resolveCategory(string $textLower): array
    {
        // First, try to find an existing category in the database
        $existingCategory = Category::where('tenant_id', $this->message->tenant_id)
            ->where(function ($query) use ($textLower) {
                $query->where('name', 'LIKE', '%'.$textLower.'%')
                    ->orWhere('slug', 'LIKE', '%'.$textLower.'%');
            })
            ->first();

        if ($existingCategory) {
            return [
                'name' => $existingCategory->name,
                'type' => $existingCategory->type,
            ];
        }

        // Fallback to category mapping
        $categoryMap = [
            'makan' => 'Makanan & Minuman',
            'makanan' => 'Makanan & Minuman',
            'minuman' => 'Makanan & Minuman',
            'food' => 'Makanan & Minuman',
            'drink' => 'Makanan & Minuman',
            'jajan' => 'Makanan & Minuman',
            'snack' => 'Makanan & Minuman',
            'kopi' => 'Makanan & Minuman',
            'ngopi' => 'Makanan & Minuman',
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
            'baby' => 'Baby & Anak',
            'bayi' => 'Baby & Anak',
            'anak' => 'Baby & Anak',
            'hewan' => 'Hewan Peliharaan',
            'kucing' => 'Hewan Peliharaan',
            'anjing' => 'Hewan Peliharaan',
            'pet' => 'Hewan Peliharaan',
            'gadget' => 'Gadget & Elektronik',
            'elektronik' => 'Gadget & Elektronik',
            'hp' => 'Gadget & Elektronik',
            'laptop' => 'Gadget & Elektronik',
        ];

        $kategori = $categoryMap[$textLower] ?? ucfirst($textLower);

        // Map to valid category type
        $validTypes = [
            'Makanan & Minuman' => 'pengeluaran_makanan',
            'Transport' => 'pengeluaran_transport',
            'Belanja' => 'pengeluaran_belanja',
            'Utilitas' => 'pengeluaran_utilitas',
            'Hiburan' => 'pengeluaran_hiburan',
            'Kesehatan' => 'pengeluaran_kesehatan',
            'Pendidikan' => 'pengeluaran_pendidikan',
            'Keluarga' => 'pengeluaran_keluarga',
            'Tagihan' => 'pengeluaran_tagihan',
            'Pinjaman' => 'pengeluaran_pinjaman',
            'Cicilan' => 'pengeluaran_cicilan',
            'Investasi' => 'pengeluaran_investasi',
        ];

        $categoryType = $validTypes[$kategori] ?? 'pengeluaran_lainnya';

        return [
            'name' => $kategori,
            'type' => $categoryType,
        ];
    }

    /**
     * Parse amount and category text from a budget command message.
     */
    protected function parseBudgetInput(string $messageText, string $pattern): array
    {
        $nominal = null;

        // Extract amount from text
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

        // Extract category from text
        $textLower = strtolower($messageText);
        $textLower = preg_replace($pattern, '', $textLower);
        $textLower = preg_replace('/\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta|m|million)?/i', '', $textLower);
        $textLower = trim($textLower);

        return ['nominal' => $nominal, 'textLower' => $textLower];
    }

    /**
     * Build a progress bar string for WhatsApp messages.
     */
    protected function progressBar(float $percentage): string
    {
        $barLength = 10;
        $filled = (int) min(round(($percentage / 100) * $barLength), $barLength);
        $empty = $barLength - $filled;

        return str_repeat('▓', $filled).str_repeat('░', $empty);
    }

    /**
     * Handle check budget request (cek budget)
     */
    public function handleCheckBudget(): void
    {
        try {
            $budgets = Budget::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->where('period', 'monthly')
                ->with('category')
                ->get();

            Budget::loadBulkSpending($budgets);

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

                $reply .= "{$categoryIcon} *{$categoryName}*\n";
                $reply .= 'Budget: Rp '.number_format($budgetAmount, 0, ',', '.')."\n";
                $reply .= 'Terpakai: Rp '.number_format($spending, 0, ',', '.').' ('.number_format($percentage, 1).'%)';

                if ($percentage >= 100) {
                    $reply .= ' 🚨';
                    $hasAlert = true;
                } elseif ($percentage >= 80) {
                    $reply .= ' ⚠️';
                    $hasAlert = true;
                }
                $reply .= "\n";

                $reply .= $this->progressBar($percentage)."\n";
                $reply .= 'Sisa: Rp '.number_format($remaining, 0, ',', '.')."\n\n";
            }

            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= '📈 *Total Budget:* Rp '.number_format($totalBudget, 0, ',', '.')."\n";
            $reply .= '💸 *Total Terpakai:* Rp '.number_format($totalSpending, 0, ',', '.')."\n";
            $totalRemaining = max(0, $totalBudget - $totalSpending);
            $reply .= '💰 *Total Sisa:* Rp '.number_format($totalRemaining, 0, ',', '.')."\n\n";

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
     */
    public function handleSetBudget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            $parsed = $this->parseBudgetInput($messageText, '/(set|atur|buat)?\s*budget\s*/i');
            $nominal = $parsed['nominal'];
            $textLower = $parsed['textLower'];

            $resolved = $this->resolveCategory($textLower);
            $kategori = $resolved['name'];
            $categoryType = $resolved['type'];

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

            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $this->message->tenant_id,
                    'type' => $categoryType,
                ],
                [
                    'name' => $kategori,
                    'slug' => Str::slug($kategori),
                    'icon' => '📁',
                    'is_system' => false,
                ]
            );

            // Delete existing active budgets for this category+period
            Budget::where('tenant_id', $this->message->tenant_id)
                ->where('category_id', $category->id)
                ->where('period', 'monthly')
                ->where('is_active', true)
                ->delete();

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

            $currentSpending = $budget->getCurrentSpending();
            $remaining = $budget->getRemainingBudget();
            $percentage = $budget->getUsagePercentage();

            $reply = "✅ *Budget Berhasil Diatur!*\n\n";
            $reply .= "📊 *{$category->name}*\n";
            $reply .= '💵 Budget: Rp '.number_format($nominal, 0, ',', '.')." /bulan\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";

            if ($currentSpending > 0) {
                $reply .= "📈 *Status Bulan Ini:*\n";
                $reply .= 'Terpakai: Rp '.number_format($currentSpending, 0, ',', '.').' ('.number_format($percentage, 1)."%)\n";
                $reply .= 'Sisa: Rp '.number_format($remaining, 0, ',', '.')."\n\n";

                $reply .= $this->progressBar($percentage)."\n\n";

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
     */
    public function handleAddBudget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            $parsed = $this->parseBudgetInput($messageText, '/(tambah|nambah|tambahin|add)?\s*budget\s*/i');
            $nominal = $parsed['nominal'];
            $textLower = $parsed['textLower'];

            $resolved = $this->resolveCategory($textLower);
            $kategori = $resolved['name'];
            $categoryType = $resolved['type'];

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

            $category = Category::firstOrCreate(
                [
                    'tenant_id' => $this->message->tenant_id,
                    'type' => $categoryType,
                ],
                [
                    'name' => $kategori,
                    'slug' => Str::slug($kategori),
                    'icon' => '📁',
                    'is_system' => false,
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
                $reply .= '➕ Ditambah: Rp '.number_format($nominal, 0, ',', '.')."\n";
                $reply .= '📊 Budget Lama: Rp '.number_format($oldAmount, 0, ',', '.')."\n";
                $reply .= '📊 Budget Baru: Rp '.number_format($newAmount, 0, ',', '.')."\n";
                $reply .= "📅 Periode: Bulanan\n\n";

                if ($currentSpending > 0) {
                    $reply .= "📈 *Status Bulan Ini:*\n";
                    $reply .= 'Terpakai: Rp '.number_format($currentSpending, 0, ',', '.').' ('.number_format($percentage, 1)."%)\n";
                    $reply .= 'Sisa: Rp '.number_format($remaining, 0, ',', '.')."\n\n";

                    $reply .= $this->progressBar($percentage)."\n\n";

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

    /**
     * Handle delete budget request (hapus budget kategori)
     */
    public function handleDeleteBudget(string $messageText): void
    {
        try {
            $textLower = strtolower($messageText);
            $textLower = preg_replace('/(hapus|delete|hilangkan|buang)\s*budget\s*/i', '', $textLower);
            $textLower = trim($textLower);

            if (empty($textLower)) {
                $budgets = Budget::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->with('category')
                    ->get();

                if ($budgets->isEmpty()) {
                    $this->sendReply(
                        "📊 *Hapus Budget*\n\n".
                        'Tidak ada budget aktif untuk dihapus.'
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

            $category = Category::where('tenant_id', $this->message->tenant_id)
                ->where(function ($query) use ($textLower) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.$textLower.'%'])
                        ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.$textLower.'%']);
                })
                ->first();

            if (! $category) {
                $this->sendReply(
                    "⚠️ *Kategori Tidak Ditemukan*\n\n".
                    "Kategori '{$textLower}' tidak ditemukan.\n\n".
                    'Ketik _hapus budget_ untuk melihat daftar budget aktif.'
                );

                return;
            }

            $budget = Budget::where('tenant_id', $this->message->tenant_id)
                ->where('category_id', $category->id)
                ->where('is_active', true)
                ->first();

            if (! $budget) {
                $this->sendReply(
                    "⚠️ *Budget Tidak Ditemukan*\n\n".
                    "Tidak ada budget aktif untuk kategori *{$category->name}*.\n\n".
                    'Ketik _hapus budget_ untuk melihat daftar budget aktif.'
                );

                return;
            }

            $categoryName = $category->name;
            $budgetAmount = $budget->amount;

            $budget->delete();

            $this->sendReply(
                "✅ *Budget Berhasil Dihapus!*\n\n".
                "🗑️ Kategori: *{$categoryName}*\n".
                '💵 Budget: Rp '.number_format($budgetAmount, 0, ',', '.')."\n\n".
                "Budget untuk kategori ini tidak lagi aktif.\n\n".
                '💡 Ketik _cek budget_ untuk melihat budget Anda.'
            );

            Log::info('Budget deleted via WhatsApp', [
                'message_id' => $this->message->id,
                'category' => $categoryName,
                'amount' => $budgetAmount,
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting budget', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal menghapus budget*\n\n".
                'Terjadi kesalahan. Silakan coba lagi.'
            );
        }
    }
}
