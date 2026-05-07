<?php

namespace App\Console\Commands;

use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Console\Command;

/**
 * Diagnosa nomor WhatsApp: cek kenapa aplikasi tidak merespon untuk nomor tertentu.
 * Tidak mengubah kode pemrosesan pesan, hanya pemeriksaan read-only.
 *
 * Contoh: php artisan whatsapp:diagnose 0895367229544
 */
class DiagnoseWhatsAppNumber extends Command
{
    protected $signature = 'whatsapp:diagnose
                            {number : Nomor WhatsApp (contoh: 0895367229544 atau 62895367229544)}';

    protected $description = 'Diagnosa nomor WhatsApp: cek registrasi, mapping, LID, dan alur pesan';

    public function handle(): int
    {
        $raw = $this->argument('number');
        $raw = preg_replace('/[^0-9]/', '', $raw);

        if (strlen($raw) < 10) {
            $this->error("Nomor terlalu pendek: {$raw}");

            return 1;
        }

        // Normalisasi seperti ProcessIncomingMessage
        $senderNumber = $raw;
        if (strlen($senderNumber) >= 10 && strlen($senderNumber) <= 13) {
            if (str_starts_with($senderNumber, '0')) {
                $senderNumber = '62'.substr($senderNumber, 1);
            } elseif (str_starts_with($senderNumber, '8') && ! str_starts_with($senderNumber, '62')) {
                $senderNumber = '62'.$senderNumber;
            }
        }

        $mappingService = new WhatsAppUserMappingService;
        $cleanPhone = $mappingService->cleanPhoneNumber($raw);

        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  DIAGNOSA NOMOR WHATSAPP');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $this->line("  Input (digit only) : {$raw}");
        $this->line("  Normalized (62xxx): {$senderNumber}");
        $this->line("  cleanPhoneNumber() : {$cleanPhone}");
        $this->newLine();

        // 1. Format & LID
        $isLID = ! preg_match('/^628[0-9]{8,13}$/', $senderNumber);
        $this->info('1. FORMAT & LID');
        $this->line('   Format valid (628xxxxxxxxxx): '.($isLID ? 'TIDAK → dianggap LID' : 'YA'));
        if ($isLID) {
            $this->warn('   → Jika gateway mengirim nomor ini sebagai LID, balasan bisa ke alamat lain.');
        }
        $this->newLine();

        // 2. User (users.whatsapp_number)
        $this->info('2. USER (users.whatsapp_number)');
        $userByMain = User::where('whatsapp_number', $senderNumber)->first();
        $userByAlt = null;
        if (! $userByMain && preg_match('/^62(\d+)$/', $senderNumber, $m)) {
            $without62 = $m[1];
            $userByAlt = User::whereIn('whatsapp_number', [$without62, '0'.$without62])->first();
        }
        $user = $userByMain ?? $userByAlt;
        if ($user) {
            $this->line("   Ditemukan: user_id={$user->id}, tenant_id={$user->tenant_id}, name={$user->name}");
            $this->line("   Format di DB: {$user->whatsapp_number}");
        } else {
            $this->warn('   Tidak ditemukan (nomor belum terdaftar sebagai user).');
        }
        $this->newLine();

        // 3. UserWhatsAppNumber
        $this->info('3. USER_WHATSAPP_NUMBERS');
        $mappingByMain = UserWhatsAppNumber::where('whatsapp_number', $senderNumber)->where('is_active', true)->first();
        $mappingByAlt = null;
        if (! $mappingByMain && preg_match('/^62(\d+)$/', $senderNumber, $m)) {
            $without62 = $m[1];
            $mappingByAlt = UserWhatsAppNumber::where('is_active', true)
                ->whereIn('whatsapp_number', [$without62, '0'.$without62])
                ->first();
        }
        $mapping = $mappingByMain ?? $mappingByAlt;
        if ($mapping) {
            $this->line("   Ditemukan: user_id={$mapping->user_id}, tenant_id={$mapping->tenant_id}, is_primary={$mapping->is_primary}");
            $this->line("   Format di DB: {$mapping->whatsapp_number}");
        } else {
            $this->warn('   Tidak ditemukan.');
        }
        $this->newLine();

        // 4. Mapping service (getTenantIdFromWhatsAppNumber)
        $this->info('4. MAPPING SERVICE (getTenantIdFromWhatsAppNumber)');
        $tenantId = $mappingService->getTenantIdFromWhatsAppNumber($raw);
        if ($tenantId !== null) {
            $this->line("   tenant_id: {$tenantId}");
        } else {
            $this->warn('   tenant_id: null (nomor tidak ter-mapping ke tenant manapun).');
        }
        $this->newLine();

        // 5. Registration flow (cache)
        $this->info('5. REGISTRATION FLOW (cache)');
        $inFlow = RegHelper::isInRegistrationFlow($senderNumber);
        $step = RegHelper::getCurrentStep($senderNumber);
        $regData = RegHelper::getRegistrationData($senderNumber);
        $this->line('   Dalam alur registrasi: '.($inFlow ? 'YA' : 'Tidak'));
        if ($inFlow) {
            $this->line('   Step saat ini: '.($step ?? '-'));
            $this->line('   Data: '.json_encode($regData, JSON_UNESCAPED_UNICODE));
        }
        $this->newLine();

        // 6. Subscription (tenant)
        $correctTenantIdForSub = ($user ? $user->tenant_id : null) ?? ($mapping ? $mapping->tenant_id : null);
        $subscriptionValid = false;
        $subscriptionDetail = '';
        if ($correctTenantIdForSub) {
            $this->info('6. SUBSCRIPTION (tenant_id = '.$correctTenantIdForSub.')');
            $tenant = Tenant::find($correctTenantIdForSub);
            if (! $tenant) {
                $this->warn('   Tenant tidak ditemukan.');
            } else {
                $hasActiveSubscription = Subscription::where('tenant_id', $correctTenantIdForSub)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                    })
                    ->first();
                $isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();
                $subscriptionValid = $hasActiveSubscription || $isInTrial;
                if ($hasActiveSubscription) {
                    $subscriptionDetail = 'Langganan aktif (ends_at: '.($hasActiveSubscription->ends_at ? $hasActiveSubscription->ends_at->format('Y-m-d') : 'null').')';
                } elseif ($isInTrial) {
                    $subscriptionDetail = 'Trial aktif sampai '.$tenant->trial_ends_at->format('Y-m-d');
                } else {
                    $subscriptionDetail = 'Tidak ada langganan aktif / trial kedaluwarsa';
                }
                $this->line('   Status: '.($subscriptionValid ? 'AKTIF' : 'TIDAK AKTIF'));
                $this->line("   Detail: {$subscriptionDetail}");
                if (! $subscriptionValid) {
                    $this->warn('   → Webhook akan menolak pesan (Subscription expired). User tidak akan dapat respons.');
                }
            }
        } else {
            $this->info('6. SUBSCRIPTION');
            $this->line('   Dilewati (nomor belum terdaftar ke tenant).');
        }
        $this->newLine();

        // 7. LID mapping (penting untuk akun Business / multi-device)
        $this->info('7. LID MAPPING (akun Business / multi-device)');
        $lidMappings = collect();
        if ($user) {
            $lidMappings = UserLidMapping::where('user_id', $user->id)->get();
            $lidByPhone = UserLidMapping::where('phone_number', $senderNumber)->orWhere('phone_number', $cleanPhone)->get();
            foreach ($lidByPhone as $l) {
                if ($lidMappings->where('id', $l->id)->isEmpty()) {
                    $lidMappings = $lidMappings->push($l);
                }
            }
        }
        if ($lidMappings->isEmpty()) {
            $this->line('   Tidak ada mapping LID untuk user ini.');
            $this->warn('   → Jika user mengirim dari akun "Business" atau device lain, gateway bisa mengirim LID bukan nomor. Tanpa mapping LID→nomor, pesan akan ditolak dan tidak ada respons.');
            $this->line('   Solusi: Pastikan gateway mengirim sender_id sebagai nomor 62xxx, atau buat LID mapping setelah gateway mengirim LID (lihat log webhook: originalFrom / from).');
        } else {
            foreach ($lidMappings as $lid) {
                $this->line("   LID: {$lid->lid} → user_id={$lid->user_id}, tenant_id={$lid->tenant_id}, phone={$lid->phone_number}");
            }
        }
        $this->newLine();

        // 8. Kesimpulan
        $this->info('8. KESIMPULAN');
        $correctTenantId = ($user ? $user->tenant_id : null) ?? ($mapping ? $mapping->tenant_id : null);

        if ($correctTenantId) {
            $this->line('   Nomor terdaftar dan akan diarahkan ke tenant_id = '.$correctTenantId.'.');
            if (! $subscriptionValid) {
                $this->warn('   Langganan TIDAK AKTIF → webhook menolak pesan. Perpanjang langganan agar aplikasi merespon.');
            } elseif ($lidMappings->isEmpty()) {
                $this->warn('   Tidak ada LID mapping. Jika user pakai akun Business/device lain, gateway mungkin kirim LID → pesan ditolak. Cek log webhook (from / originalFrom) saat user kirim pesan.');
            } else {
                $this->line('   Pesan seharusnya diproses dan dibalas.');
            }
        } else {
            $this->warn('   Nomor TIDAK terdaftar (tidak ada di users / user_whatsapp_numbers).');
            $this->line('   Perilaku yang terjadi:');
            $this->line('   - Jika pesan dari GRUP → aplikasi sengaja tidak membalas (unknown participant).');
            $this->line('   - Jika pesan PRIVATE → aplikasi mengirim alur registrasi / welcome.');
            $this->line('   Solusi: Daftarkan nomor via website atau selesaikan registrasi via WA (private chat).');
        }

        if ($isLID) {
            $this->newLine();
            $this->warn('   Format nomor dianggap LID. Pastikan gateway mengirim nomor dalam format 62xxx agar tidak di-LID.');
        }

        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        return 0;
    }
}
