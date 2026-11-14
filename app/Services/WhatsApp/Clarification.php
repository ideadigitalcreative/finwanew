<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;

/**
 * Pertanyaan balik BERNOMOR yang bisa dijawab singkat ("1", "2", "masuk", "keluar").
 *
 * Dipakai saat bot ragu (arah utang, dompet sumber, nominal tanpa satuan, jenis
 * transaksi). Satu pertanyaan tertunda per pengirim, berlaku 10 menit. Jawaban
 * yang bukan pilihan → pertanyaan dibatalkan & pesan diproses seperti biasa.
 *
 * Bentuk opsi: ['1' => ['label' => '...', 'action' => [...data bebas...]], ...]
 */
final class Clarification
{
    private const TTL_MINUTES = 10;

    private const NUMBER_WORDS = [
        'satu' => '1', 'pertama' => '1', 'dua' => '2', 'kedua' => '2',
        'tiga' => '3', 'ketiga' => '3', 'empat' => '4', 'keempat' => '4',
    ];

    public static function key(int $tenantId, string $identity): string
    {
        return 'clarify:'.$tenantId.':'.$identity;
    }

    /**
     * Kunci identitas pengirim: string (sender_id mentah) atau daftar kunci dari
     * App\Support\SenderIdentity::keys() — LID & nomor HP orang yang sama.
     *
     * @return list<string>
     */
    private static function identities(string|array $sender): array
    {
        $list = is_array($sender) ? $sender : [\App\Support\SenderIdentity::digits($sender) ?: $sender];

        return array_values(array_unique(array_filter(array_map('strval', $list), fn ($k) => $k !== '')));
    }

    /**
     * Simpan pertanyaan & kembalikan teks siap kirim.
     *
     * @param  array<string, array{label: string, action: array}>  $options
     * @param  array<string, string>  $aliases  kata → nomor opsi (mis. 'masuk' => '2')
     * @param  array  $extra  data tambahan (mis. 'amount_base' → jawaban berupa nominal diterima)
     */
    public static function ask(int $tenantId, string|array $sender, string $kind, string $title, array $options, array $aliases = [], string $footer = '', array $extra = []): string
    {
        // Disimpan di SEMUA kunci identitas: jawaban yang datang dengan sender_id
        // berbeda (LID ↔ nomor HP) tetap menemukan pertanyaannya.
        $payload = ['kind' => $kind, 'options' => $options, 'aliases' => $aliases, 'extra' => $extra];
        foreach (self::identities($sender) as $identity) {
            Cache::put(self::key($tenantId, $identity), $payload, now()->addMinutes(self::TTL_MINUTES));
        }

        $emoji = ['1' => '1️⃣', '2' => '2️⃣', '3' => '3️⃣', '4' => '4️⃣', '5' => '5️⃣', '6' => '6️⃣'];
        $lines = [$title, ''];
        foreach ($options as $no => $opt) {
            $lines[] = ($emoji[(string) $no] ?? "{$no}.").' '.$opt['label'];
        }
        $lines[] = '';
        $lines[] = $footer !== '' ? $footer : '_Balas dengan nomor pilihan._';

        return implode("\n", $lines);
    }

    public static function pending(int $tenantId, string|array $sender): ?array
    {
        foreach (self::identities($sender) as $identity) {
            $p = Cache::get(self::key($tenantId, $identity));
            if (is_array($p)) {
                return $p;
            }
        }

        return null;
    }

    public static function clear(int $tenantId, string|array $sender): void
    {
        foreach (self::identities($sender) as $identity) {
            Cache::forget(self::key($tenantId, $identity));
        }
    }

    /**
     * Cocokkan jawaban dengan opsi. Null bila bukan jawaban atas pertanyaan ini.
     *
     * @return array{no: string, label: string, action: array}|null
     */
    public static function resolve(string $reply, array $pending): ?array
    {
        $t = mb_strtolower(trim($reply));
        $t = trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t));
        $t = (string) preg_replace('/\s+/u', ' ', $t);
        if ($t === '') {
            return null;
        }

        $options = $pending['options'] ?? [];
        $aliases = $pending['aliases'] ?? [];

        // Jawaban HARUS berupa SATU pilihan (boleh dengan kata pengisi) — pesan baru seperti
        // "uang masuk 1jt" tidak boleh dianggap jawaban "masuk" lalu pesannya hilang.
        $fillers = ['aja', 'saja', 'deh', 'dong', 'kak', 'min', 'nya', 'ya', 'yah', 'sih', 'tolong', 'itu', 'yang', 'yg',
            'no', 'nomor', 'nmr', 'pilih', 'pilihan', 'opsi', 'jawab', 'jawabannya', 'catat', 'dicatat', 'sebagai', 'jadi'];
        $pick = function (string $value) use ($options, $aliases): ?array {
            $no = self::NUMBER_WORDS[$value] ?? $value;
            if (isset($options[$no])) {
                return ['no' => (string) $no] + $options[$no];
            }
            if (isset($aliases[$value], $options[$aliases[$value]])) {
                return ['no' => (string) $aliases[$value]] + $options[$aliases[$value]];
            }

            return null;
        };

        // Utuh dulu: "ya", "saya pinjam", "2"
        if ($hit = $pick($t)) {
            return $hit;
        }

        $words = explode(' ', $t);
        $meaningful = array_values(array_filter($words, fn ($w) => ! in_array($w, $fillers, true)));

        // Seluruhnya kata pengisi ("ya catat", "iya dong") → kata pertama yang berupa pilihan
        if ($meaningful === []) {
            foreach ($words as $w) {
                if ($hit = $pick($w)) {
                    return $hit;
                }
            }

            return null;
        }

        // "2 aja", "nomor 1", "pemasukan dong", "saya pinjam ya"; selain itu bukan jawaban
        return count($meaningful) <= 2 ? $pick(implode(' ', $meaningful)) : null;
    }
}
