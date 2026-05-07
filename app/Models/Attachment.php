<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'tenant_id',
        'message_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'type',
        'signed_url',
        'signed_url_expires_at',
        'is_encrypted',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
        'signed_url_expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
