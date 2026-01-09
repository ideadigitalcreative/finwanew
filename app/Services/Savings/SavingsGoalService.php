<?php

namespace App\Services\Savings;

use App\Models\Message;
use App\Models\SavingsGoal;
use Illuminate\Support\Facades\Log;

/**
 * SavingsGoalService - Handles savings goal commands
 * 
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 * 
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class SavingsGoalService
{
    protected Message $message;
    protected $sendReplyCallback;

    /**
     * Constructor
     * 
     * @param Message $message The message being processed
     * @param callable $sendReplyCallback Callback to send reply via WhatsApp
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
     * Handle set savings target request (set target 10jt, target nabung 5jt)
     * 
     * MOVED FROM: ProcessIncomingMessage::handleSetSavingsTarget()
     * LINES: 5818-5897
     * MODIFICATION: None (structural move only)
     */
    public function handleSetSavingsTarget(string $messageText): void
    {
        try {
            // Extract amount from message
            $nominal = null;
            $name = 'Target Tabungan';
            
            // Pattern 1: set target [nominal] [optional: untuk nama]
            // e.g., "set target 50jt untuk nikah", "target nabung 10jt untuk umroh"
            if (preg_match('/(?:set\s+target|target\s+(?:tabungan|nabung|saving)|mau\s+nabung|buat\s+target)\s*(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?(?:\s+(?:untuk|buat)\s+(.+))?/i', $messageText, $matches)) {
                $numericValue = str_replace(['.', ','], '', $matches[1]);
                $nominal = (float) $numericValue;
                
                $multiplier = strtolower($matches[2] ?? '');
                if (in_array($multiplier, ['rb', 'ribu', 'k'])) {
                    $nominal *= 1000;
                } elseif (in_array($multiplier, ['jt', 'juta'])) {
                    $nominal *= 1000000;
                }
                
                if (!empty($matches[3])) {
                    $name = ucfirst(trim($matches[3]));
                }
            }
            // Pattern 2: tabung [nominal] untuk [nama] - NEW FORMAT
            // e.g., "tabung 50jt untuk menikah", "nabung 1jt buat umroh"
            elseif (preg_match('/(?:tabung|nabung)\s+(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?\s+(?:untuk|buat)\s+(.+)/i', $messageText, $matches)) {
                $numericValue = str_replace(['.', ','], '', $matches[1]);
                $nominal = (float) $numericValue;
                
                $multiplier = strtolower($matches[2] ?? '');
                if (in_array($multiplier, ['rb', 'ribu', 'k'])) {
                    $nominal *= 1000;
                } elseif (in_array($multiplier, ['jt', 'juta'])) {
                    $nominal *= 1000000;
                }
                
                if (!empty($matches[3])) {
                    $name = ucfirst(trim($matches[3]));
                }
            }
            
            if (!$nominal || $nominal <= 0) {
                $this->sendReply(
                    "🎯 *Set Target Tabungan*\n\n" .
                    "Untuk mengatur target, ketik:\n\n" .
                    "• _\"set target 10jt\"_\n" .
                    "• _\"target nabung 5jt untuk liburan\"_\n" .
                    "• _\"mau nabung 2jt untuk iPhone\"_\n\n" .
                    "💡 Target membantu Anda fokus pada tujuan keuangan."
                );
                return;
            }
            
            // Create savings goal
            $goal = SavingsGoal::create([
                'tenant_id' => $this->message->tenant_id,
                'name' => $name,
                'target_amount' => $nominal,
                'current_amount' => 0,
                'status' => 'active',
                'icon' => '🎯',
            ]);
            
            $formattedAmount = number_format($nominal, 0, ',', '.');
            
            $this->sendReply(
                "✅ *Target Tabungan Dibuat!*\n\n" .
                "🎯 {$name}\n" .
                "💵 Target: Rp {$formattedAmount}\n" .
                "📊 Progress: 0%\n\n" .
                $goal->getProgressBar() . "\n\n" .
                "💡 *Cara menabung:*\n" .
                "_\"tabung 500rb\"_ atau _\"nabung 1jt\"_\n\n" .
                "Cek progress: _\"cek target\"_"
            );
            
            Log::info('Savings target created', [
                'message_id' => $this->message->id,
                'goal_id' => $goal->id,
                'amount' => $nominal
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error creating savings target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal membuat target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle check savings target request (cek target, lihat target)
     * 
     * MOVED FROM: ProcessIncomingMessage::handleCheckSavingsTarget()
     * LINES: 5899-5971
     * MODIFICATION: None (structural move only)
     */
    public function handleCheckSavingsTarget(): void
    {
        try {
            $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->get();
            
            if ($goals->isEmpty()) {
                $this->sendReply(
                    "🎯 *Target Tabungan*\n\n" .
                    "Belum ada target aktif.\n\n" .
                    "Buat target baru:\n" .
                    "_\"set target 10jt untuk liburan\"_\n" .
                    "_\"target nabung 5jt\"_"
                );
                return;
            }
            
            $message = "🎯 *Target Tabungan Anda*\n";
            $message .= "━━━━━━━━━━━━━━━\n\n";
            
            foreach ($goals as $index => $goal) {
                $num = $index + 1;
                $targetFormatted = number_format($goal->target_amount, 0, ',', '.');
                $currentFormatted = number_format($goal->current_amount, 0, ',', '.');
                $remainingFormatted = number_format($goal->getRemainingAmount(), 0, ',', '.');
                $percentage = round($goal->getProgressPercentage());
                
                $message .= "{$num}. {$goal->icon} *{$goal->name}*\n";
                $message .= "   💵 Target: Rp {$targetFormatted}\n";
                $message .= "   💰 Terkumpul: Rp {$currentFormatted}\n";
                $message .= "   📊 " . $goal->getProgressBar(15) . "\n";
                
                if ($goal->getRemainingAmount() > 0) {
                    $message .= "   📌 Kurang: Rp {$remainingFormatted}\n";
                    
                    // Suggested monthly savings if deadline exists
                    $suggested = $goal->getSuggestedMonthlySavings();
                    if ($suggested) {
                        $suggestedFormatted = number_format($suggested, 0, ',', '.');
                        $message .= "   💡 Nabung Rp {$suggestedFormatted}/bulan\n";
                    }
                } else {
                    $message .= "   🎉 *TARGET TERCAPAI!*\n";
                }
                $message .= "\n";
            }
            
            $message .= "_Tambah tabungan: \"tabung 500rb\"_";
            
            $this->sendReply($message);
            
            Log::info('Savings targets viewed', [
                'message_id' => $this->message->id,
                'goals_count' => $goals->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error viewing savings targets', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle add savings request (tabung 500rb, nabung 1jt)
     * 
     * MOVED FROM: ProcessIncomingMessage::handleAddSavings()
     * LINES: 5973-6065
     * MODIFICATION: None (structural move only)
     */
    public function handleAddSavings(string $messageText): void
    {
        try {
            // Extract amount
            $nominal = null;
            
            if (preg_match('/(?:tabung|nabung)\s+(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)?/i', $messageText, $matches)) {
                $numericValue = str_replace(['.', ','], '', $matches[1]);
                $nominal = (float) $numericValue;
                
                $multiplier = strtolower($matches[2] ?? '');
                if (in_array($multiplier, ['rb', 'ribu', 'k'])) {
                    $nominal *= 1000;
                } elseif (in_array($multiplier, ['jt', 'juta'])) {
                    $nominal *= 1000000;
                }
            }
            
            if (!$nominal || $nominal <= 0) {
                $this->sendReply(
                    "💰 *Menabung*\n\n" .
                    "Format: _tabung 500rb_ atau _nabung 1jt_\n\n" .
                    "Contoh:\n" .
                    "• _tabung 100rb_\n" .
                    "• _nabung 500rb_\n" .
                    "• _tabung 2jt_"
                );
                return;
            }
            
            // Get active savings goal
            $goal = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->first();
            
            if (!$goal) {
                $this->sendReply(
                    "❌ *Belum ada target tabungan*\n\n" .
                    "Buat target dulu:\n" .
                    "_\"set target 10jt untuk liburan\"_\n\n" .
                    "Setelah itu baru bisa menabung!"
                );
                return;
            }
            
            // Add savings
            $previousAmount = $goal->current_amount;
            $goal->addSavings($nominal);
            
            $formattedNominal = number_format($nominal, 0, ',', '.');
            $formattedCurrent = number_format($goal->current_amount, 0, ',', '.');
            $formattedTarget = number_format($goal->target_amount, 0, ',', '.');
            $formattedRemaining = number_format($goal->getRemainingAmount(), 0, ',', '.');
            
            $message = "✅ *Tabungan Ditambahkan!*\n\n";
            $message .= "🎯 {$goal->name}\n";
            $message .= "💵 +Rp {$formattedNominal}\n\n";
            $message .= "📊 Progress:\n";
            $message .= $goal->getProgressBar() . "\n\n";
            $message .= "💰 Terkumpul: Rp {$formattedCurrent}\n";
            $message .= "🎯 Target: Rp {$formattedTarget}\n";
            
            if ($goal->isCompleted()) {
                $message .= "\n🎉🎉 *SELAMAT! TARGET TERCAPAI!* 🎉🎉";
            } else {
                $message .= "📌 Kurang: Rp {$formattedRemaining}";
            }
            
            $this->sendReply($message);
            
            Log::info('Savings added', [
                'message_id' => $this->message->id,
                'goal_id' => $goal->id,
                'amount' => $nominal,
                'new_total' => $goal->current_amount
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error adding savings', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menambah tabungan*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle set target request (set target tabungan)
     * 
     * MOVED FROM: ProcessIncomingMessage::handleSetTarget()
     * LINES: 8439-8487
     * MODIFICATION: None (structural move only)
     */
    public function handleSetTarget(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            $nominal = $finwaEntities['nominal'] ?? null;
            
            if (!$nominal) {
                $this->sendReply(
                    "🎯 *Set Target Tabungan*\n\n" .
                    "Untuk mengatur target, ketik:\n\n" .
                    "• _\"set target 10jt\"_\n" .
                    "• _\"mau nabung 5jt bulan ini\"_\n" .
                    "• _\"target tabung 2jt untuk liburan\"_\n\n" .
                    "💡 Target membantu Anda fokus pada tujuan keuangan."
                );
                return;
            }
            
            // Note: Full target feature would need a SavingsTarget model
            // For now, just acknowledge the request
            
            $this->sendReply(
                "✅ *Target Tabungan Diatur!*\n\n" .
                "🎯 Target Anda:\n" .
                "💵 Rp " . number_format($nominal, 0, ',', '.') . "\n\n" .
                "Terus pantau progress Anda dengan:\n" .
                "_\"cek target\"_\n\n" .
                "💪 Semangat mencapai target!"
            );
            
            Log::info('Savings target set via chat', [
                'message_id' => $this->message->id,
                'amount' => $nominal
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error setting target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal mengatur target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
    
    /**
     * Handle delete savings target request (hapus target menikah, hapus tabungan nikah)
     * 
     * ADDED: New function to delete specific savings targets
     */
    public function handleDeleteSavingsTarget(string $messageText): void
    {
        try {
            // Extract target name from message
            // Patterns: "hapus target menikah", "hapus tabungan nikah", "hapus target tabungan menikah"
            $targetName = null;
            
            // Pattern handles: "hapus target tabungan X", "hapus target X", "hapus tabungan X"
            if (preg_match('/(?:hapus|delete|batalkan|remove|hilangkan)\s+(?:target\s+tabungan|target|tabungan|saving)\s+(.+)/i', $messageText, $matches)) {
                $targetName = trim($matches[1]);
            }
            
            if (!$targetName) {
                // Show list of targets that can be deleted
                $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                    ->where('status', 'active')
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                if ($goals->isEmpty()) {
                    $this->sendReply(
                        "🎯 *Hapus Target Tabungan*\n\n" .
                        "Belum ada target tabungan aktif.\n\n" .
                        "Buat target baru:\n" .
                        "_\"set target 10jt untuk liburan\"_"
                    );
                    return;
                }
                
                $message = "🗑️ *Hapus Target Tabungan*\n\n";
                $message .= "Target yang tersedia:\n";
                foreach ($goals as $index => $goal) {
                    $num = $index + 1;
                    $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                }
                $message .= "\nUntuk menghapus, ketik:\n";
                $message .= "_\"hapus target [nama target]\"_\n\n";
                $message .= "Contoh: _hapus target menikah_";
                
                $this->sendReply($message);
                return;
            }
            
            // Search for the target by name (case-insensitive, fuzzy match)
            $goals = SavingsGoal::where('tenant_id', $this->message->tenant_id)
                ->where('status', 'active')
                ->get();
            
            $matchedGoal = null;
            $similarGoals = [];
            
            foreach ($goals as $goal) {
                $goalNameLower = strtolower($goal->name);
                $targetNameLower = strtolower($targetName);
                
                // Exact match
                if ($goalNameLower === $targetNameLower) {
                    $matchedGoal = $goal;
                    break;
                }
                
                // Partial match (target name contains search term or vice versa)
                if (str_contains($goalNameLower, $targetNameLower) || str_contains($targetNameLower, $goalNameLower)) {
                    $similarGoals[] = $goal;
                }
            }
            
            // If no exact match but one similar goal found, use it
            if (!$matchedGoal && count($similarGoals) === 1) {
                $matchedGoal = $similarGoals[0];
            }
            
            if (!$matchedGoal) {
                if (count($similarGoals) > 1) {
                    // Multiple similar matches found
                    $message = "⚠️ *Ditemukan beberapa target serupa:*\n\n";
                    foreach ($similarGoals as $index => $goal) {
                        $num = $index + 1;
                        $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                    }
                    $message .= "\nSebutkan nama yang lebih spesifik:\n";
                    $message .= "_\"hapus target [nama lengkap]\"_";
                    $this->sendReply($message);
                    return;
                }
                
                // No match found
                $message = "❌ *Target tidak ditemukan*\n\n";
                $message .= "Target \"{$targetName}\" tidak ada.\n\n";
                
                if ($goals->isNotEmpty()) {
                    $message .= "Target yang tersedia:\n";
                    foreach ($goals as $index => $goal) {
                        $num = $index + 1;
                        $message .= "{$num}. {$goal->icon} {$goal->name}\n";
                    }
                }
                
                $this->sendReply($message);
                return;
            }
            
            // Delete the target (soft delete by setting status to 'cancelled')
            $deletedName = $matchedGoal->name;
            $deletedIcon = $matchedGoal->icon;
            $currentAmount = $matchedGoal->current_amount;
            $targetAmount = $matchedGoal->target_amount;
            
            $matchedGoal->status = 'cancelled';
            $matchedGoal->save();
            
            $formattedCurrent = number_format($currentAmount, 0, ',', '.');
            $formattedTarget = number_format($targetAmount, 0, ',', '.');
            
            $this->sendReply(
                "✅ *Target Berhasil Dihapus!*\n\n" .
                "{$deletedIcon} *{$deletedName}*\n" .
                "💰 Terkumpul: Rp {$formattedCurrent}\n" .
                "🎯 Target: Rp {$formattedTarget}\n\n" .
                "Target tabungan ini sudah dihapus.\n\n" .
                "_Lihat target lain: \"cek target\"_\n" .
                "_Buat target baru: \"set target 10jt untuk liburan\"_"
            );
            
            Log::info('Savings target deleted', [
                'message_id' => $this->message->id,
                'goal_id' => $matchedGoal->id,
                'goal_name' => $deletedName
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting savings target', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menghapus target*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi."
            );
        }
    }
}
