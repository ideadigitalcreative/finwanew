<?php

namespace App\Console\Commands;

use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Console\Command;

/**
 * Cari akun berdasarkan nomor WhatsApp, atau daftarkan nomor ke aplikasi
 * agar aplikasi bisa mengirim pesan ke nomor tersebut.
 *
 * Contoh: php artisan whatsapp:register 6287873237861
 *         php artisan whatsapp:register 087873237861 "Nozel" nozel@example.com
 */
class RegisterWhatsAppNumber extends Command
{
    protected $signature = 'whatsapp:register
                            {number : Nomor WhatsApp (contoh: 6287873237861 atau 087873237861)}
                            {name? : Nama (wajib jika nomor belum terdaftar)}
                            {email? : Email (wajib jika nomor belum terdaftar)}';

    protected $description = 'Cari akun nomor WhatsApp atau daftarkan nomor agar aplikasi bisa kirim pesan';

    public function handle(): int
    {
        $raw = preg_replace('/[^0-9]/', '', $this->argument('number'));
        if (strlen($raw) < 10) {
            $this->error("Nomor terlalu pendek: {$raw}");

            return 1;
        }

        $mappingService = new WhatsAppUserMappingService;
        $phone = $mappingService->cleanPhoneNumber($raw);
        if (strlen($phone) >= 10 && strlen($phone) <= 13 && ! str_starts_with($phone, '62')) {
            if (str_starts_with($phone, '0')) {
                $phone = '62'.substr($phone, 1);
            } else {
                $phone = '62'.$phone;
            }
        }

        $this->line("Nomor yang dicek: {$phone}");
        $this->newLine();

        // 1. Cari di users.whatsapp_number
        $user = User::where('whatsapp_number', $phone)->first();
        if (! $user && preg_match('/^62(\d+)$/', $phone, $m)) {
            $user = User::whereIn('whatsapp_number', [$m[1], '0'.$m[1]])->first();
        }

        // 2. Cari di user_whatsapp_numbers
        $mapping = UserWhatsAppNumber::where('whatsapp_number', $phone)->where('is_active', true)->first();
        if (! $mapping && preg_match('/^62(\d+)$/', $phone, $m)) {
            $mapping = UserWhatsAppNumber::where('is_active', true)
                ->whereIn('whatsapp_number', [$m[1], '0'.$m[1]])
                ->first();
        }

        if ($user || $mapping) {
            $u = $user ?? $mapping->user;
            $tenantId = $u->tenant_id;
            $sub = Subscription::where('tenant_id', $tenantId)
                ->whereIn('status', ['active', 'trial'])
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->first();

            $this->info('Akun ditemukan (sudah terdaftar).');
            $this->table(
                ['Field', 'Nilai'],
                [
                    ['User ID', $u->id],
                    ['Nama', $u->name],
                    ['Tenant ID', $tenantId],
                    ['Nomor', $phone],
                    ['Langganan', $sub ? $sub->status.' (ends: '.($sub->ends_at ? $sub->ends_at->format('Y-m-d') : '-').')' : 'Tidak aktif'],
                ]
            );
            $this->line('Aplikasi bisa mengirim pesan ke nomor ini selama langganan aktif.');

            return 0;
        }

        $this->warn('Nomor belum terdaftar. Untuk mendaftarkan agar aplikasi bisa kirim pesan, isi nama dan email.');
        $name = $this->argument('name') ?: $this->ask('Nama');
        $email = $this->argument('email') ?: $this->ask('Email');

        if (empty($name) || empty($email)) {
            $this->error('Nama dan email wajib untuk pendaftaran.');

            return 1;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return 1;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('Email sudah terdaftar. Gunakan email lain.');

            return 1;
        }

        if (! $this->confirm("Daftarkan {$phone} sebagai {$name} ({$email})?")) {
            return 0;
        }

        try {
            $result = RegHelper::createAccount([
                'phone' => $phone,
                'name' => $name,
                'email' => $email,
            ]);
            $user = $result['user'];
            $tenant = $result['tenant'];

            $this->info('Pendaftaran berhasil.');
            $this->table(
                ['Field', 'Nilai'],
                [
                    ['User ID', $user->id],
                    ['Tenant ID', $tenant->id],
                    ['Nomor', $phone],
                    ['Langganan', 'Paket Gratis'],
                ]
            );
            $this->line('Nomor sekarang terdaftar. Aplikasi bisa mengirim pesan ke nomor ini.');

            return 0;
        } catch (\Throwable $e) {
            $this->error('Gagal mendaftar: '.$e->getMessage());

            return 1;
        }
    }
}
