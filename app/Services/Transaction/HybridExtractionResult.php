<?php

namespace App\Services\Transaction;

/**
 * Hasil ekstraksi hybrid (Hybrid.md Fase 1).
 *
 * Sifat: DTO datar — hanya membawa hasil mentah dari extractor.
 * Validasi/keputusan akhir ada di ExtractionValidator (Fase 2).
 * LLM TIDAK PERNAH jadi sumber kebenaran angka: field amount disimpan apa
 * adanya dari LLM untuk diverifikasi validator terhadap teks asli.
 */
class HybridExtractionResult
{
    public const STATUS_OK = 'ok';                // LLM menjawab & JSON valid
    public const STATUS_OFF = 'disabled';         // flag off / konfigurasi kosong
    public const STATUS_UNAVAILABLE = 'unavailable'; // LLM error/timeout/JSON rusak

    /**
     * @param  array<int, array{amount: int, label: ?string}>  $items
     * @param  array<string, mixed>  $meta  cadangan field tambahan dari LLM
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $intent = null,       // transaction|query|other
        public readonly ?string $type = null,         // income|expense
        public readonly ?int $amount = null,          // nominal total (IDR)
        public readonly array $items = [],            // item terpisah utk batch
        public readonly ?string $wallet = null,       // dompet jika disebut
        public readonly ?string $category = null,     // saran kategori
        public readonly ?float $confidence = null,    // 0.0-1.0 dari LLM
        public readonly ?string $note = null,         // alasan status != ok
        public readonly ?string $raw = null,          // teks respons mentah
        public readonly array $meta = [],
    ) {}

    public static function disabled(): self
    {
        return new self(self::STATUS_OFF);
    }

    public static function unavailable(?string $note = null, ?string $raw = null): self
    {
        return new self(self::STATUS_UNAVAILABLE, note: $note, raw: $raw);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_OK;
    }
}
