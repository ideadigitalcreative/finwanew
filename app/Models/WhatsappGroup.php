<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grup WhatsApp yang diaktifkan untuk satu akun keuangan (tenant).
 * Anggota grup yang nomornya terdaftar di akun ini bisa mencatat transaksi
 * dengan pemicu "#" atau mention bot.
 */
class WhatsappGroup extends Model
{
    protected $table = 'whatsapp_groups';

    protected $fillable = [
        'group_jid',
        'tenant_id',
        'activated_by_user_id',
        'name',
        'is_active',
        'last_message_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }
}
