<?php

namespace App\Support;

use App\Models\Message;
use App\Services\WhatsAppUserMappingService;

/**
 * Identitas TETAP pengirim WhatsApp untuk "ingatan percakapan".
 *
 * Gateway mengisi sender_id dengan nomor HP bila WhatsApp menyertakannya
 * (senderPn), selain itu dengan LID mentah. Untuk kontak yang SAMA, dua pesan
 * berurutan bisa datang dengan sender_id berbeda — dulu membuat pertanyaan
 * tertunda ("Balas 1/2/3", "Ya/Tidak", "Berapa biayanya?") tidak ditemukan
 * dan jawaban user dibalas "pesan tidak dikenali".
 *
 * canonical(): "wa:{id nomor terdaftar}" bila pengirim dikenali (LID & nomor HP
 * orang yang sama → nilai sama); selain itu digit mentah.
 */
final class SenderIdentity
{
    private const MEMO_SECONDS = 60;

    /** @var array<string, array{0: string|null, 1: int}> */
    private static array $memo = [];

    public static function canonical(int $tenantId, ?string $sender): ?string
    {
        if ($sender === null || trim($sender) === '') {
            return null;
        }

        $memoKey = $tenantId.'|'.$sender;
        $hit = self::$memo[$memoKey] ?? null;
        if ($hit !== null && $hit[1] > time()) {
            return $hit[0];
        }

        $digits = self::digits($sender);
        try {
            $numberId = app(WhatsAppUserMappingService::class)->resolveUserWhatsAppNumberId($sender, $tenantId);
        } catch (\Throwable) {
            $numberId = null;
        }
        $value = $numberId ? 'wa:'.$numberId : ($digits !== '' ? $digits : trim($sender));

        if (count(self::$memo) > 500) {
            self::$memo = [];
        }
        self::$memo[$memoKey] = [$value, time() + self::MEMO_SECONDS];

        return $value;
    }

    /**
     * Semua kunci yang mungkin untuk pengirim pesan ini: identitas kanonik,
     * digit sender_id, dan digit pengirim asli (LID) dari gateway.
     *
     * @return list<string>
     */
    public static function keys(Message $message, string $attributionSender): array
    {
        $keys = [self::canonical((int) $message->tenant_id, $attributionSender), self::digits($attributionSender)];

        $meta = is_array($message->metadata) ? $message->metadata : (json_decode((string) ($message->metadata ?? '{}'), true) ?: []);
        if (! ($meta['is_group'] ?? false)) {
            $raw = is_array($message->raw_data) ? $message->raw_data : (json_decode((string) ($message->raw_data ?? '{}'), true) ?: []);
            $original = $meta['original_sender_id'] ?? ($raw['originalFrom'] ?? null);
            if (is_string($original) && $original !== '') {
                $keys[] = self::digits($original);
            }
        }

        return array_values(array_unique(array_filter($keys, fn ($k) => is_string($k) && $k !== '')));
    }

    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D/', '', explode('@', (string) $value)[0]);
    }

    /** Untuk tes: lupakan memo. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
