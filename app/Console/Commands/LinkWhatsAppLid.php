<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Console\Command;

/**
 * Link LID (Linked ID) WhatsApp ke user yang sudah terdaftar dengan nomor telepon.
 * Dipakai ketika user mengirim dari akun Business / multi-device dan gateway mengirim LID, bukan nomor.
 *
 * Cara dapat LID: cek log webhook saat user mengirim pesan (from / originalFrom / sender_id).
 *
 * Jangan pakai tanda < > di terminal. Ganti LID_NYATA dengan angka LID dari log.
 * Contoh: php artisan whatsapp:link-lid 120363123456789 6287873237861
 */
class LinkWhatsAppLid extends Command
{
    /**
     * Pastikan ada baris user_whatsapp_numbers untuk LID (routing & fallback di job).
     */
    private function ensureLidWhatsAppNumberRow(User $user, string $lidDigits): void
    {
        UserWhatsAppNumber::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'whatsapp_number' => $lidDigits,
            ],
            [
                'name' => 'LID (manual link)',
                'is_primary' => false,
                'is_active' => true,
                'is_lid' => true,
            ]
        );
    }

    protected $signature = 'whatsapp:link-lid
                            {lid : LID dari log webhook, angka saja, contoh: 120363123456789}
                            {phone : Nomor WhatsApp terdaftar, contoh: 6287873237861 (boleh +62 813-2883-1653)}
                            {--force : Timpa mapping LID yang sudah ada ke user lain tanpa konfirmasi}';

    protected $description = 'Link LID WhatsApp ke user yang sudah terdaftar (untuk akun Business / multi-device)';

    public function handle(): int
    {
        $lidRaw = $this->argument('lid');
        $phoneRaw = $this->argument('phone');

        $lid = str_replace('@lid', '', preg_replace('/[^0-9]/', '', $lidRaw));
        if (strlen($lid) < 10) {
            $this->error("LID terlalu pendek: {$lid}");

            return 1;
        }

        $mappingService = new WhatsAppUserMappingService;
        $phone = $mappingService->cleanPhoneNumber($phoneRaw);
        if (! preg_match('/^628[0-9]{8,13}$/', $phone)) {
            $this->warn("Nomor tidak dalam format 628xxxxxxxxxx, tetap dipakai: {$phone}");
        }

        $user = User::where('whatsapp_number', $phone)->first();
        if (! $user) {
            $uw = UserWhatsAppNumber::where('whatsapp_number', $phone)->where('is_active', true)->first();
            if ($uw) {
                $user = $uw->user;
            }
        }
        if (! $user) {
            $this->error("User tidak ditemukan untuk nomor: {$phone}. Daftarkan nomor dulu atau periksa format.");

            return 1;
        }

        $existing = UserLidMapping::findByLid($lid);
        if ($existing) {
            if ($existing->user_id === $user->id && $existing->phone_number === $phone) {
                $this->ensureLidWhatsAppNumberRow($user, $lid);
                $this->info("LID sudah ter-link ke user ini (user_id={$user->id}, tenant_id={$user->tenant_id}).");

                return 0;
            }
            if (! $this->option('force') && ! $this->confirm("LID sudah dipetakan ke user_id={$existing->user_id}. Timpa dengan user_id={$user->id}?")) {
                return 0;
            }
        }

        UserLidMapping::linkLidToUser($lid, $user->id, $user->tenant_id, $phone);
        $this->ensureLidWhatsAppNumberRow($user, $lid);
        $this->info("LID {$lid} berhasil di-link ke user_id={$user->id}, tenant_id={$user->tenant_id}, phone={$phone}.");
        $this->line('Pesan dari LID ini akan diarahkan ke tenant yang sama. Silakan uji kirim pesan dari akun tersebut.');

        return 0;
    }
}
