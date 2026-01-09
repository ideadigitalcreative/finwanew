<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SttJob extends Model
{
    protected $fillable = [
        'tenant_id',
        'message_id',
        'attachment_id',
        'file_path',
        'file_type',
        'file_size',
        'duration_seconds',
        'status',
        'transcribed_text',
        'metadata',
        'error_message',
        'processing_time_ms',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
