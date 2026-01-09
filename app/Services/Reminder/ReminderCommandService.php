<?php

namespace App\Services\Reminder;

use App\Models\Message;
use App\Models\Reminder;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * ReminderCommandService - Handles Reminder-related commands
 * 
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 * 
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class ReminderCommandService
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
     * Handle set reminder request
     * Supports: "ingatkan bayar listrik tanggal 20 setiap bulan 500rb"
     *           "reminder internet 250rb tanggal 5"
     *           "ingatkan gaji setiap tanggal 25"
     * 
     * MOVED FROM: ProcessIncomingMessage::handleSetReminder()
     * LINES: 2350-2504
     * MODIFICATION: None (structural move only)
     */
    public function handleSetReminder(string $messageText, ?array $finwaEntities = null): void
    {
        try {
            $text = strtolower($messageText);
            
            // Extract reminder details
            $title = null;
            $amount = null;
            $day = null;
            $type = 'monthly'; // Default to monthly
            
            // Get amount from FinWa entities or parse from text
            if ($finwaEntities && isset($finwaEntities['nominal']) && $finwaEntities['nominal'] > 0) {
                $amount = $finwaEntities['nominal'];
            } else {
                // Parse amount
                if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)/i', $text, $amountMatch)) {
                    $num = floatval(str_replace(',', '.', $amountMatch[1]));
                    $suffix = strtolower($amountMatch[2]);
                    $multipliers = ['rb' => 1000, 'ribu' => 1000, 'k' => 1000, 'jt' => 1000000, 'juta' => 1000000];
                    $amount = (int)($num * ($multipliers[$suffix] ?? 1));
                }
            }
            
            // Parse day (tanggal X, tgl X)
            if (preg_match('/(?:tanggal|tgl)\s+(\d{1,2})/i', $text, $dayMatch)) {
                $day = (int)$dayMatch[1];
                if ($day < 1 || $day > 31) {
                    $day = null;
                }
            }
            
            // Determine reminder type
            if (preg_match('/(?:setiap\s+hari|harian|daily)/i', $text)) {
                $type = 'daily';
            } elseif (preg_match('/(?:setiap\s+minggu|mingguan|weekly)/i', $text)) {
                $type = 'weekly';
            }
            
            // Extract title/description
            $commonTags = [
                'listrik' => ['title' => 'Bayar Listrik', 'category' => 'pengeluaran_utilitas'],
                'pln' => ['title' => 'Bayar Listrik PLN', 'category' => 'pengeluaran_utilitas'],
                'internet' => ['title' => 'Bayar Internet', 'category' => 'pengeluaran_utilitas'],
                'wifi' => ['title' => 'Bayar WiFi', 'category' => 'pengeluaran_utilitas'],
                'indihome' => ['title' => 'Bayar IndiHome', 'category' => 'pengeluaran_utilitas'],
                'air' => ['title' => 'Bayar Air PDAM', 'category' => 'pengeluaran_utilitas'],
                'pdam' => ['title' => 'Bayar PDAM', 'category' => 'pengeluaran_utilitas'],
                'pulsa' => ['title' => 'Beli Pulsa', 'category' => 'pengeluaran_pulsa_token'],
                'kuota' => ['title' => 'Beli Kuota', 'category' => 'pengeluaran_pulsa_token'],
                'gaji' => ['title' => 'Gaji Masuk', 'category' => 'pendapatan_gaji'],
                'kos' => ['title' => 'Bayar Kos', 'category' => 'pengeluaran_hunian'],
                'kontrakan' => ['title' => 'Bayar Kontrakan', 'category' => 'pengeluaran_hunian'],
                'sewa' => ['title' => 'Bayar Sewa', 'category' => 'pengeluaran_hunian'],
                'cicilan' => ['title' => 'Bayar Cicilan', 'category' => 'pengeluaran_pinjaman'],
                'kredit' => ['title' => 'Bayar Kredit', 'category' => 'pengeluaran_pinjaman'],
                'asuransi' => ['title' => 'Bayar Asuransi', 'category' => 'pengeluaran_asuransi'],
                'netflix' => ['title' => 'Bayar Netflix', 'category' => 'pengeluaran_hiburan'],
                'spotify' => ['title' => 'Bayar Spotify', 'category' => 'pengeluaran_hiburan'],
            ];
            
            $categoryType = 'pengeluaran_tagihan';
            foreach ($commonTags as $keyword => $info) {
                if (str_contains($text, $keyword)) {
                    $title = $info['title'];
                    $categoryType = $info['category'];
                    break;
                }
            }
            
            // If no specific title found, try to extract from text
            if (!$title) {
                // Remove common keywords and extract remaining as title
                $cleaned = preg_replace('/(?:ingatkan|reminder|pengingat|setiap|bulan|hari|minggu|tanggal|tgl|\d+(?:rb|ribu|k|jt|juta)?)/i', '', $text);
                $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));
                if (strlen($cleaned) > 3) {
                    $title = ucfirst($cleaned);
                } else {
                    $title = 'Pengingat Tagihan';
                }
            }
            
            // Validate - need at least title and day for monthly
            if ($type === 'monthly' && !$day) {
                $this->sendReply(
                    "📅 *Buat Pengingat*\n\n" .
                    "Untuk membuat pengingat, sertakan tanggal:\n\n" .
                    "*Contoh:*\n" .
                    "• _ingatkan bayar listrik tanggal 20 500rb_\n" .
                    "• _reminder internet 250rb tgl 5_\n" .
                    "• _ingatkan gaji masuk tanggal 25_\n\n" .
                    "💡 Anda akan diingatkan setiap bulan di tanggal tersebut!"
                );
                return;
            }
            
            // Create reminder
            $reminder = Reminder::create([
                'tenant_id' => $this->message->tenant_id,
                'title' => $title,
                'description' => $amount ? "Jumlah estimasi: Rp " . number_format($amount, 0, ',', '.') : null,
                'type' => $type,
                'amount' => $amount,
                'category_type' => $categoryType,
                'reminder_day' => $day,
                'reminder_time' => '08:00',
                'is_active' => true,
            ]);
            
            // Calculate next send time
            $reminder->calculateNextSendAt();
            
            Log::info('Reminder created via chat', [
                'message_id' => $this->message->id,
                'reminder_id' => $reminder->id,
                'title' => $title
            ]);
            
            // Build confirmation message
            $typeLabels = [
                'daily' => 'Setiap hari',
                'weekly' => 'Setiap minggu',
                'monthly' => "Setiap tanggal {$day}",
            ];
            
            $reply = "✅ *Pengingat Berhasil Dibuat!*\n\n";
            $reply .= "📌 *{$title}*\n";
            if ($amount) {
                $reply .= "💰 Rp " . number_format($amount, 0, ',', '.') . "\n";
            }
            $reply .= "🔄 " . $typeLabels[$type] . "\n";
            $reply .= "⏰ Jam 08:00\n";
            $reply .= "📅 Pengingat berikutnya: " . $reminder->next_send_at->format('d M Y') . "\n\n";
            $reply .= "💡 Anda akan menerima notifikasi WhatsApp sesuai jadwal!";
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error creating reminder', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal membuat pengingat*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle delete reminder request
     * Supports: "hapus pengingat", "hapus pengingat 1", "hapus pengingat terakhir", "hapus semua pengingat"
     * 
     * MOVED FROM: ProcessIncomingMessage::handleDeleteReminder()
     * LINES: 2506-2635
     * MODIFICATION: None (structural move only)
     */
    public function handleDeleteReminder(string $messageText): void
    {
        try {
            $text = strtolower($messageText);
            
            // Get all active reminders for this tenant
            $reminders = Reminder::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->orderBy('created_at', 'desc')
                ->get();
            
            if ($reminders->isEmpty()) {
                $this->sendReply(
                    "📭 *Tidak ada pengingat aktif*\n\n" .
                    "Anda belum memiliki pengingat yang aktif.\n\n" .
                    "💡 Buat pengingat baru dengan:\n" .
                    "_ingatkan bayar listrik tanggal 20 500rb_"
                );
                return;
            }
            
            // Check if user wants to delete all
            if (str_contains($text, 'semua') || str_contains($text, 'all')) {
                $count = $reminders->count();
                Reminder::where('tenant_id', $this->message->tenant_id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                
                Log::info('All reminders deleted via chat', [
                    'message_id' => $this->message->id,
                    'count' => $count
                ]);
                
                $this->sendReply(
                    "🗑️ *Semua Pengingat Dihapus!*\n\n" .
                    "✅ {$count} pengingat berhasil dinonaktifkan."
                );
                return;
            }
            
            // Check if user wants to delete last (terakhir)
            if (str_contains($text, 'terakhir') || str_contains($text, 'last')) {
                $lastReminder = $reminders->first();
                $lastReminder->is_active = false;
                $lastReminder->save();
                
                Log::info('Last reminder deleted via chat', [
                    'message_id' => $this->message->id,
                    'reminder_id' => $lastReminder->id,
                    'title' => $lastReminder->title
                ]);
                
                $this->sendReply(
                    "🗑️ *Pengingat Dihapus!*\n\n" .
                    "📌 *{$lastReminder->title}*\n" .
                    "✅ Pengingat terakhir berhasil dinonaktifkan."
                );
                return;
            }
            
            // Check if user specified a number (hapus pengingat 1, hapus pengingat 2)
            if (preg_match('/(\d+)/', $text, $matches)) {
                $number = (int)$matches[1];
                
                // Check if it's a valid index (1-based)
                if ($number >= 1 && $number <= $reminders->count()) {
                    $targetReminder = $reminders[$number - 1];
                    $targetReminder->is_active = false;
                    $targetReminder->save();
                    
                    Log::info('Reminder deleted by number via chat', [
                        'message_id' => $this->message->id,
                        'reminder_id' => $targetReminder->id,
                        'number' => $number,
                        'title' => $targetReminder->title
                    ]);
                    
                    $this->sendReply(
                        "🗑️ *Pengingat #{$number} Dihapus!*\n\n" .
                        "📌 *{$targetReminder->title}*\n" .
                        "✅ Pengingat berhasil dinonaktifkan."
                    );
                    return;
                }
            }
            
            // If no specific target, show list and ask user to specify
            $reply = "📋 *Daftar Pengingat Aktif*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            foreach ($reminders as $index => $rem) {
                $num = $index + 1;
                $typeLabel = match($rem->type) {
                    'daily' => 'Harian',
                    'weekly' => 'Mingguan',
                    'monthly' => "Tgl {$rem->reminder_day}",
                    default => $rem->type
                };
                $reply .= "*{$num}.* {$rem->title}\n";
                $reply .= "   🔄 {$typeLabel} | ⏰ {$rem->reminder_time}\n";
                if ($rem->amount) {
                    $reply .= "   💰 Rp " . number_format($rem->amount, 0, ',', '.') . "\n";
                }
                $reply .= "\n";
            }
            
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "💡 *Cara hapus:*\n";
            $reply .= "• _hapus pengingat 1_ - hapus nomor tertentu\n";
            $reply .= "• _hapus pengingat terakhir_ - hapus terakhir\n";
            $reply .= "• _hapus semua pengingat_ - hapus semua";
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error deleting reminder', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menghapus pengingat*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }
    
    /**
     * Handle list reminders request
     * Shows all active reminders for this tenant
     * 
     * MOVED FROM: ProcessIncomingMessage::handleListReminders()
     * LINES: 2637-2709
     * MODIFICATION: None (structural move only)
     */
    public function handleListReminders(): void
    {
        try {
            $reminders = Reminder::where('tenant_id', $this->message->tenant_id)
                ->where('is_active', true)
                ->orderBy('next_send_at', 'asc')
                ->get();
            
            if ($reminders->isEmpty()) {
                $this->sendReply(
                    "📭 *Tidak ada pengingat aktif*\n\n" .
                    "Anda belum memiliki pengingat yang aktif.\n\n" .
                    "💡 Buat pengingat baru dengan:\n" .
                    "_ingatkan bayar listrik tanggal 20 500rb_"
                );
                return;
            }
            
            $reply = "📋 *Daftar Pengingat Aktif*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            foreach ($reminders as $index => $rem) {
                $num = $index + 1;
                $typeLabel = match($rem->type) {
                    'daily' => 'Setiap hari',
                    'weekly' => 'Setiap minggu',
                    'monthly' => "Setiap tanggal {$rem->reminder_day}",
                    'once' => 'Sekali',
                    default => $rem->type
                };
                
                $reply .= "*{$num}. {$rem->title}*\n";
                $reply .= "   🔄 {$typeLabel}\n";
                $reply .= "   ⏰ Jam {$rem->reminder_time}\n";
                if ($rem->amount) {
                    $reply .= "   💰 Rp " . number_format($rem->amount, 0, ',', '.') . "\n";
                }
                if ($rem->next_send_at) {
                    $reply .= "   📅 Berikutnya: " . $rem->next_send_at->format('d M Y') . "\n";
                }
                $reply .= "\n";
            }
            
            $reply .= "━━━━━━━━━━━━━━━\n";
            $reply .= "Total: {$reminders->count()} pengingat aktif\n\n";
            $reply .= "💡 *Perintah:*\n";
            $reply .= "• _hapus pengingat 1_ - hapus nomor tertentu\n";
            $reply .= "• _hapus semua pengingat_ - hapus semua\n";
            $reply .= "• _ingatkan [nama] tgl [X]_ - buat baru";
            
            $this->sendReply($reply);
            
            Log::info('Reminders listed via chat', [
                'message_id' => $this->message->id,
                'count' => $reminders->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error listing reminders', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal menampilkan pengingat*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }

    /**
     * Handle enable daily reminder
     * 
     * MOVED FROM: ProcessIncomingMessage::handleEnableDailyReminder()
     * LINES: 6353-6394
     * MODIFICATION: None (structural move only)
     */
    public function handleEnableDailyReminder(): void
    {
        try {
            $tenant = Tenant::find($this->message->tenant_id);
            
            if (!$tenant) {
                $this->sendReply("⚠️ Terjadi kesalahan. Silakan coba lagi.");
                return;
            }
            
            // Update tenant settings
            $settings = $tenant->settings ?? [];
            $settings['daily_reminder_enabled'] = true;
            $settings['reminder_hour'] = 20; // Default 8 PM WIB
            
            $tenant->update(['settings' => $settings]);
            
            Log::info('Daily reminder enabled', [
                'tenant_id' => $tenant->id
            ]);
            
            $this->sendReply(
                "✅ *Reminder Harian Aktif!*\n\n" .
                "🔔 Mulai sekarang, FinWa akan mengingatkan kamu setiap hari jam 20:00 WIB jika belum ada transaksi dicatat.\n\n" .
                "💡 Tips:\n" .
                "• Catat transaksi apapun agar tidak lupa\n" .
                "• Ketik 'matikan reminder' untuk nonaktifkan\n\n" .
                "_Reminder akan terkirim hanya jika kamu belum mencatat transaksi hari itu_"
            );
            
        } catch (\Exception $e) {
            Log::error('Error enabling daily reminder', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply("⚠️ Gagal mengaktifkan reminder. Silakan coba lagi.");
        }
    }

    /**
     * Handle disable daily reminder
     * 
     * MOVED FROM: ProcessIncomingMessage::handleDisableDailyReminder()
     * LINES: 6396-6433
     * MODIFICATION: None (structural move only)
     */
    public function handleDisableDailyReminder(): void
    {
        try {
            $tenant = Tenant::find($this->message->tenant_id);
            
            if (!$tenant) {
                $this->sendReply("⚠️ Terjadi kesalahan. Silakan coba lagi.");
                return;
            }
            
            // Update tenant settings
            $settings = $tenant->settings ?? [];
            $settings['daily_reminder_enabled'] = false;
            
            $tenant->update(['settings' => $settings]);
            
            Log::info('Daily reminder disabled', [
                'tenant_id' => $tenant->id
            ]);
            
            $this->sendReply(
                "🔕 *Reminder Harian Dinonaktifkan*\n\n" .
                "FinWa tidak akan mengirim reminder harian lagi.\n\n" .
                "💡 Ketik 'aktifkan reminder' jika ingin mengaktifkan kembali."
            );
            
        } catch (\Exception $e) {
            Log::error('Error disabling daily reminder', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply("⚠️ Gagal menonaktifkan reminder. Silakan coba lagi.");
        }
    }
}
