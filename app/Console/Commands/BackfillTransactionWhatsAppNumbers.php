<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Console\Command;

class BackfillTransactionWhatsAppNumbers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:backfill-whatsapp-numbers
                            {--tenant= : Hanya proses tenant tertentu}
                            {--dry-run : Tampilkan hasil tanpa menyimpan perubahan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Isi kolom user_whatsapp_number_id pada transaksi lama berdasarkan pesan pengirimnya';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppUserMappingService $mappingService): int
    {
        $query = Transaction::query()
            ->whereNull('user_whatsapp_number_id')
            ->whereNotNull('message_id')
            ->with('message:id,sender_id,tenant_id,metadata');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Tidak ada transaksi yang perlu di-backfill.');

            return self::SUCCESS;
        }

        $this->info("Memproses {$total} transaksi...");

        $dryRun = (bool) $this->option('dry-run');
        $matched = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(500, function ($transactions) use ($mappingService, $dryRun, &$matched, &$skipped) {
            foreach ($transactions as $transaction) {
                $senderId = $this->resolveSenderId($transaction);

                if (! $senderId) {
                    $skipped++;

                    continue;
                }

                $numberId = $mappingService->resolveUserWhatsAppNumberId(
                    $senderId,
                    $transaction->message->tenant_id ?? $transaction->tenant_id
                );

                if (! $numberId) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    $transaction->forceFill(['user_whatsapp_number_id' => $numberId])->save();
                }

                $matched++;
            }
        });

        $suffix = $dryRun ? ' (dry-run, tidak ada perubahan disimpan)' : '';
        $this->info("Selesai. Terhubung ke nomor: {$matched}, dilewati: {$skipped}.{$suffix}");

        return self::SUCCESS;
    }

    /**
     * Ambil sender yang relevan untuk atribusi: participant (author) jika pesan grup,
     * selain itu sender_id pesan.
     */
    protected function resolveSenderId(Transaction $transaction): ?string
    {
        $message = $transaction->message;

        if (! $message) {
            return null;
        }

        $metadata = $message->metadata;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        $metadata = is_array($metadata) ? $metadata : [];

        if (($metadata['is_group'] ?? false) && ! empty($metadata['author'])) {
            return $metadata['author'];
        }

        return $message->sender_id;
    }
}
