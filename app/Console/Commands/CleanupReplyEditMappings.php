<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupReplyEditMappings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reply-edit:cleanup {--days= : Override retensi (hari)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hapus pemetaan wa_message_id & audit edit reply yang melewati retensi';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) ($this->option('days')
            ?: config('finwa_reply_edit.mapping_retention_days', 90));

        $cutoff = now()->subDays($days);

        $mapping = DB::table('transaction_message_mappings')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $audits = DB::table('transaction_edit_audits')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pemetaan dihapus: {$mapping} baris (lebih tua dari {$days} hari)");
        $this->info("Audit edit dihapus: {$audits} baris");

        return self::SUCCESS;
    }
}
