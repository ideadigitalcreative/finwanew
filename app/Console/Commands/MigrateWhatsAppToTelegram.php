<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\UserTelegramMapping;
use Illuminate\Support\Facades\DB;

class MigrateWhatsAppToTelegram extends Command
{
    protected $signature = 'whatsapp:migrate-to-telegram 
                            {--dry-run : Tampilkan hasil tanpa menyimpan} 
                            {--user-id= : ID user spesifik untuk migrate} 
                            {--limit=100 : Jumlah user maksimal yang dimigrate}';

    protected $description = 'Migrate user WhatsApp ke Telegram mapping';

    public function handle()
    {
        $this->info('=== Migrate WhatsApp ke Telegram ===' . PHP_EOL);

        $dryRun = $this->option('dry-run');
        $userId = $this->option('user-id');
        $limit = (int) $this->option('limit');

        // Query user yang punya WhatsApp number tapi belum ada mapping Telegram
        $query = User::query()
            ->whereNotNull('whatsapp_number')
            ->whereNull('telegram_chat_id')
            ->limit($limit);

        if ($userId) {
            $query->where('users.id', $userId);
            $this->info("Target user ID: $userId");
        }

        $users = $query->get();

        if ($users->count() === 0) {
            $this->warn('Tidak ada user yang perlu dimigrate.');
            return Command::SUCCESS;
        }

        $this->info("Ditemukan {$users->count()} user untuk dimigrate." . PHP_EOL);

        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;

        foreach ($users as $user) {
            try {
                $whatsapp = $user->whatsapp_number;
                
                // Cek apakah user sudah punya mapping Telegram
                $existingMapping = UserTelegramMapping::where('user_id', $user->id)
                    ->where('tenant_id', $user->tenant_id)
                    ->first();

                if ($existingMapping) {
                    $this->warn("Skip user {$user->id} ({$user->name}): sudah ada mapping Telegram (chat_id: {$existingMapping->telegram_chat_id})");
                    $skipCount++;
                    continue;
                }

                // Simulasi mapping (dalam dry-run mode)
                if ($dryRun) {
                    $this->line("  [DRY-RUN] User ID {$user->id} | {$user->name} | WhatsApp: {$whatsapp}");
                    $this->line("           -> Telegram chat_id: (belum ditentukan, user harus hubungi bot dulu)");
                    $successCount++;
                    continue;
                }

                // SKIP mapping creation - akan di-create otomatis saat user first contact
                // User cukup kirim pesan ke bot, nanti otomatis di-link oleh TelegramUserMapper
                // Tidak perlu pre-create mapping dengan placeholder karena telegram_chat_id unique
                
                $this->line("  ✓ User ID {$user->id} | {$user->name} | WhatsApp: {$whatsapp}");
                $this->line("           -> Mapping akan dibuat saat user first contact dengan bot");
                $successCount++;

            } catch (\Exception $e) {
                $this->error("  ✗ User ID {$user->id}: " . $e->getMessage());
                $errorCount++;
            }
        }

        echo PHP_EOL;
        $this->info("Summary:");
        $this->line("  Success: $successCount");
        $this->line("  Skipped: $skipCount");
        $this->line("  Error:   $errorCount");

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        return Command::SUCCESS;
    }
}
