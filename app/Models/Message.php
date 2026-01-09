<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected $fillable = [
        'tenant_id',
        'channel_id',
        'channel',
        'channel_account',
        'sender_id',
        'message_id',
        'type',
        'content',
        'timestamp',
        'raw_data',
        'metadata',
        'status'
    ];

    protected $casts = [
        'raw_data' => 'array',
        'metadata' => 'array',
        'timestamp' => 'integer'
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function ocrJob(): HasOne
    {
        return $this->hasOne(OcrJob::class);
    }

    public function sttJob(): HasOne
    {
        return $this->hasOne(SttJob::class);
    }
}
