<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWhatsAppNumber extends Model
{
    protected $table = 'user_whatsapp_numbers';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'whatsapp_number',
        'name',
        'is_primary',
        'is_active',
        'is_lid',
        'default_balance_id',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'is_lid' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Peta id nomor → id nomor "asli" dalam satu tenant. Baris LID adalah alias
     * WhatsApp untuk nomor yang sama (disembunyikan di halaman WhatsApp), jadi
     * transaksinya dihitung milik nomor aslinya. Induk dicari lewat
     * user_lid_mappings.phone_number → nama "LID - 62…" → nomor utama user.
     * LID tanpa induk tetap memetakan ke dirinya sendiri.
     *
     * @return array<int, int>
     */
    public static function canonicalIdMap(int $tenantId): array
    {
        $rows = static::where('tenant_id', $tenantId)
            ->get(['id', 'user_id', 'whatsapp_number', 'name', 'is_primary', 'is_lid']);

        $isReal = fn (self $n) => ! $n->is_lid || $n->is_primary;
        $real = $rows->filter($isReal);
        $digits = fn (?string $s) => preg_replace('/\D/', '', (string) $s);

        $lidPhones = UserLidMapping::where('tenant_id', $tenantId)
            ->whereIn('lid', $rows->reject($isReal)->pluck('whatsapp_number'))
            ->pluck('phone_number', 'lid');

        $map = [];
        foreach ($rows as $n) {
            if ($isReal($n)) {
                $map[$n->id] = $n->id;

                continue;
            }

            $phone = $digits($lidPhones[$n->whatsapp_number] ?? (preg_match('/\d{8,}/', (string) $n->name, $m) ? $m[0] : null));
            $parent = ($phone !== '' ? $real->first(fn ($r) => $digits($r->whatsapp_number) === $phone) : null)
                ?? $real->first(fn ($r) => $r->user_id == $n->user_id && $r->is_primary)
                ?? $real->first(fn ($r) => $r->user_id == $n->user_id);

            $map[$n->id] = $parent?->id ?? $n->id;
        }

        return $map;
    }
}
