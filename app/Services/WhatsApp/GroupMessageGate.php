<?php

namespace App\Services\WhatsApp;

use App\Models\Channel;
use App\Models\UserWhatsAppNumber;
use App\Models\WhatsappGroup;
use App\Services\SubscriptionLimitService;
use App\Services\WhatsAppService;
use App\Services\WhatsAppUserMappingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gerbang pesan WhatsApp GRUP.
 *
 * Aturan (keputusan produk):
 *  1. Bot hanya menanggapi pesan ber-PEMICU: diawali "#" atau me-mention bot.
 *     Obrolan biasa diabaikan TANPA balasan (anti-spam / anti-blokir WA).
 *  2. Grup harus DIAKTIFKAN dulu oleh pemilik akun: "#aktifkan finwa".
 *     1 grup = 1 akun keuangan.
 *  3. Fitur grup hanya untuk Paket PRO (config services.finwa.group_plans).
 *  4. Hanya anggota yang NOMORNYA TERDAFTAR di akun tersebut yang bisa mencatat.
 *
 * Pesan yang lolos diteruskan ke pipeline biasa dengan identitas ANGGOTA
 * pengirim (sender_id = nomor anggota), sehingga atribusi transaksi, konfirmasi
 * & konteks berjalan per anggota. Balasan dikirim ke grup (metadata.group_jid).
 */
class GroupMessageGate
{
    /** Batas pesan diproses per grup per menit (anti-banjir) */
    public const RATE_PER_MINUTE = 20;

    public function __construct(
        private WhatsAppUserMappingService $mapping = new WhatsAppUserMappingService,
    ) {}

    public static function isGroupPayload(array $wa): bool
    {
        return ! empty($wa['isGroup'])
            || str_ends_with((string) ($wa['originalFrom'] ?? ''), '@g.us')
            || ! empty($wa['group']['jid']);
    }

    /**
     * @param  callable(array): array  $forward  meneruskan payload pesan ke pipeline (buat Message + job)
     * @return array hasil untuk respons webhook
     */
    public function handle(array $wa, string $sessionId, Channel $channel, ?string $messageId, callable $forward): array
    {
        $group = (array) ($wa['group'] ?? []);
        $groupJid = $this->groupJid($wa, $group);
        $body = trim((string) ($wa['body'] ?? ''));

        // v1: hanya pesan teks (struk/foto di grup belum didukung)
        if (! in_array($wa['type'] ?? 'chat', ['chat', 'text'], true) || $body === '' || $groupJid === null) {
            return ['success' => true, 'skipped' => true, 'reason' => 'group_non_text'];
        }

        $text = $this->stripTrigger($body, (bool) ($group['mentionsBot'] ?? false));
        if ($text === null) {
            // Tidak ada pemicu → obrolan biasa, abaikan tanpa balasan
            return ['success' => true, 'skipped' => true, 'reason' => 'group_no_trigger'];
        }

        $authorPhone = $this->cleanPhone($group['authorPhone'] ?? null);
        $authorLid = $group['authorLid'] ?? null;
        $authorName = $group['authorName'] ?? null;

        if (! $this->withinRateLimit($groupJid)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'group_rate_limited'];
        }

        $lower = mb_strtolower($text);

        // ── Perintah grup ───────────────────────────────────────────────
        if (preg_match('/^(?:aktifkan|aktif|hubungkan|daftarkan)(?:\s+(?:finwa|grup|group|bot))?$/u', $lower)) {
            return $this->activate($sessionId, $groupJid, $authorPhone, $authorLid, $wa);
        }
        if (preg_match('/^(?:nonaktifkan|matikan|putuskan|stop)(?:\s+(?:finwa|grup|group|bot))?$/u', $lower)) {
            return $this->deactivate($sessionId, $groupJid, $authorPhone, $authorLid);
        }
        if ($text === '' || preg_match('/^(?:help|bantuan|panduan|menu|cara)$/u', $lower)) {
            $this->reply($sessionId, $groupJid, self::helpText());

            return ['success' => true, 'handled' => 'group_help'];
        }

        // ── Grup harus aktif ────────────────────────────────────────────
        $binding = WhatsappGroup::where('group_jid', $groupJid)->where('is_active', true)->first();
        if (! $binding) {
            $this->replyOnce($sessionId, $groupJid, "inactive:{$groupJid}",
                "ℹ️ *FinWa belum aktif di grup ini.*\n\n".
                "Pemilik akun FinWa *Paket Pro* bisa mengaktifkan dengan mengetik:\n".
                '*#aktifkan finwa*'
            );

            return ['success' => true, 'skipped' => true, 'reason' => 'group_not_activated'];
        }

        // ── Premium ─────────────────────────────────────────────────────
        if (! $this->isPremium((int) $binding->tenant_id)) {
            $this->replyOnce($sessionId, $groupJid, "notpremium:{$groupJid}",
                "⚠️ *Fitur grup khusus Paket Pro.*\n\n".
                'Langganan akun pemilik grup bukan Paket Pro. Perpanjang di: '.config('app.url').'/subscriptions'
            );

            return ['success' => true, 'skipped' => true, 'reason' => 'group_not_premium'];
        }

        // ── Anggota terdaftar di akun ini ───────────────────────────────
        $member = $this->findMember((int) $binding->tenant_id, $authorPhone, $authorLid);
        if (! $member) {
            $who = $authorName ?: 'Nomor Anda';
            $this->replyOnce($sessionId, $groupJid, 'notmember:'.$groupJid.':'.($authorPhone ?? $authorLid),
                "🔒 *{$who} belum terdaftar di akun FinWa grup ini.*\n\n".
                'Minta pemilik akun menambahkan nomor Anda di menu *WhatsApp* pada dashboard FinWa.'
            );

            return ['success' => true, 'skipped' => true, 'reason' => 'group_member_not_registered'];
        }

        $binding->forceFill(['last_message_at' => now()])->save();

        $memberNumber = (string) $member->whatsapp_number;

        return $forward([
            'tenant_id' => (int) $binding->tenant_id,
            'channel_id' => $channel->id,
            'channel' => 'whatsapp',
            'channel_account' => $channel->channel_account,
            // Identitas ANGGOTA → atribusi, konfirmasi & konteks per anggota
            'sender_id' => $memberNumber,
            'message_id' => $messageId,
            'type' => 'text',
            'content' => $text,
            'timestamp' => $wa['timestamp'] ?? time(),
            'original_sender_id' => $authorLid,
            'raw_data' => [
                'from' => $memberNumber,
                'originalFrom' => $wa['originalFrom'] ?? null,
                'isGroup' => true,
                'author' => $memberNumber,
                'group_jid' => $groupJid,
                'author_lid' => $authorLid,
                'author_name' => $authorName,
                'original_body' => $body,
                'mentions' => $group['mentionedJids'] ?? [],
                'reply_context' => is_array($wa['replyContext'] ?? null) ? $wa['replyContext'] : null,
            ],
        ]);
    }

    /**
     * Ambil teks setelah pemicu, atau null bila tidak ada pemicu.
     * "#beli kopi 25rb" → "beli kopi 25rb"; "@6281… beli kopi 25rb" (mention bot) → "beli kopi 25rb".
     * "#finwa …" / "#catat …" juga diterima.
     */
    public function stripTrigger(string $body, bool $mentionsBot): ?string
    {
        $t = trim($body);

        if (str_starts_with($t, '#')) {
            $t = (string) preg_replace('/^#+\s*(?:(?:finwa|catat)\b\s*[:,\-]?\s*)?/iu', '', $t);

            return trim($t);
        }

        if ($mentionsBot) {
            // Hapus token mention (@628…, @1234…) di mana pun
            return trim((string) preg_replace('/@\d{5,}\s*/u', '', $t));
        }

        return null;
    }

    private function activate(string $sessionId, string $groupJid, ?string $authorPhone, ?string $authorLid, array $wa): array
    {
        $member = $this->findMember(null, $authorPhone, $authorLid);
        if (! $member) {
            $this->reply($sessionId, $groupJid,
                "🔒 *Nomor Anda belum terdaftar di FinWa.*\n\n".
                'Daftar dulu lewat chat pribadi ke nomor bot ini, lalu ketik lagi *#aktifkan finwa* di grup.'
            );

            return ['success' => true, 'handled' => 'group_activate_not_registered'];
        }

        $tenantId = (int) $member->tenant_id;
        if (! $this->isPremium($tenantId)) {
            $this->reply($sessionId, $groupJid,
                "⭐ *Fitur grup khusus Paket Pro.*\n\n".
                'Upgrade ke Paket Pro di: '.config('app.url').'/subscriptions'
            );

            return ['success' => true, 'handled' => 'group_activate_not_premium'];
        }

        $existing = WhatsappGroup::where('group_jid', $groupJid)->first();
        if ($existing && $existing->is_active && (int) $existing->tenant_id !== $tenantId) {
            $this->reply($sessionId, $groupJid,
                "⚠️ *Grup ini sudah terhubung ke akun FinWa lain.*\n\n".
                '1 grup hanya bisa untuk 1 akun. Pemilik akun tersebut bisa melepasnya dengan *#nonaktifkan finwa*.'
            );

            return ['success' => true, 'handled' => 'group_activate_taken'];
        }

        WhatsappGroup::updateOrCreate(
            ['group_jid' => $groupJid],
            ['tenant_id' => $tenantId, 'activated_by_user_id' => $member->user_id, 'is_active' => true]
        );

        Log::info('Grup WhatsApp diaktifkan', ['group_jid' => $groupJid, 'tenant_id' => $tenantId, 'user_id' => $member->user_id]);

        $this->reply($sessionId, $groupJid,
            "✅ *FinWa aktif di grup ini!*\n\n".
            "Anggota yang nomornya terdaftar di akun Anda bisa mencatat dengan awalan *#*:\n".
            "• _#beli kopi 25rb_\n".
            "• _#gaji 5jt_\n\n".
            "Info saldo & ringkasan dikirim lewat *chat pribadi*, bukan di grup.\n".
            'Nonaktifkan: _#nonaktifkan finwa_'
        );

        return ['success' => true, 'handled' => 'group_activated'];
    }

    private function deactivate(string $sessionId, string $groupJid, ?string $authorPhone, ?string $authorLid): array
    {
        $binding = WhatsappGroup::where('group_jid', $groupJid)->where('is_active', true)->first();
        if (! $binding) {
            return ['success' => true, 'skipped' => true, 'reason' => 'group_not_activated'];
        }

        if (! $this->findMember((int) $binding->tenant_id, $authorPhone, $authorLid)) {
            $this->reply($sessionId, $groupJid, '🔒 Hanya anggota akun FinWa grup ini yang bisa menonaktifkan.');

            return ['success' => true, 'handled' => 'group_deactivate_forbidden'];
        }

        $binding->forceFill(['is_active' => false])->save();
        $this->reply($sessionId, $groupJid, "👋 *FinWa dinonaktifkan di grup ini.*\n\nAktifkan lagi kapan saja: _#aktifkan finwa_");

        return ['success' => true, 'handled' => 'group_deactivated'];
    }

    /**
     * Nomor WA anggota (UserWhatsAppNumber) yang cocok dengan pengirim.
     * $tenantId null = di akun mana pun (untuk aktivasi).
     */
    private function findMember(?int $tenantId, ?string $authorPhone, ?string $authorLid): ?UserWhatsAppNumber
    {
        foreach (array_filter([$authorPhone, $authorLid]) as $identity) {
            $id = $this->mapping->resolveUserWhatsAppNumberId((string) $identity, $tenantId);
            if ($id) {
                $number = UserWhatsAppNumber::where('is_active', true)->find($id);
                if ($number && ($tenantId === null || (int) $number->tenant_id === $tenantId)) {
                    return $number;
                }
            }
        }

        return null;
    }

    /** Fitur grup hanya untuk paket tertentu (default: Pro & Enterprise) */
    private function isPremium(int $tenantId): bool
    {
        $plan = strtolower(app(SubscriptionLimitService::class)->getTenantPlanById($tenantId));

        return in_array($plan, array_map('strtolower', (array) config('services.finwa.group_plans', ['pro', 'enterprise'])), true);
    }

    private function groupJid(array $wa, array $group): ?string
    {
        foreach ([$group['jid'] ?? null, $wa['originalFrom'] ?? null] as $candidate) {
            if (is_string($candidate) && str_ends_with($candidate, '@g.us')) {
                return $candidate;
            }
        }
        $from = (string) ($wa['from'] ?? '');

        return $from !== '' ? preg_replace('/@.*$/', '', $from).'@g.us' : null;
    }

    private function cleanPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $d = preg_replace('/\D/', '', $phone);
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        }

        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
    }

    private function withinRateLimit(string $groupJid): bool
    {
        $key = 'group_rate:'.md5($groupJid).':'.now()->format('YmdHi');
        Cache::add($key, 0, 120);

        return Cache::increment($key) <= self::RATE_PER_MINUTE;
    }

    private function reply(string $sessionId, string $groupJid, string $text): void
    {
        try {
            app(WhatsAppService::class)->sendMessage($sessionId, $groupJid, $text, 'text', null, true);
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim balasan grup', ['group_jid' => $groupJid, 'error' => $e->getMessage()]);
        }
    }

    /** Balasan informatif paling sering 1x/jam per kunci (hindari spam di grup). */
    private function replyOnce(string $sessionId, string $groupJid, string $key, string $text): void
    {
        if (Cache::add('group_notice:'.md5($key), 1, now()->addHour())) {
            $this->reply($sessionId, $groupJid, $text);
        }
    }

    public static function helpText(): string
    {
        return "👥 *FinWa di Grup*\n\n".
            "Catat dengan awalan *#* (atau mention bot):\n".
            "• _#beli kopi 25rb_\n".
            "• _#gaji 5jt_\n".
            "• _#bayar listrik 300rb_\n\n".
            "Cek saldo & ringkasan: _#cek saldo_ → dikirim lewat chat pribadi.\n\n".
            "Pemilik akun Paket Pro: _#aktifkan finwa_ / _#nonaktifkan finwa_";
    }
}
