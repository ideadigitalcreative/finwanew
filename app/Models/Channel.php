<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $fillable = [
        'tenant_id',
        'type',
        'channel_account',
        'name',
        'session_id',
        'session_status',
        'config',
        'is_active',
        'is_shared_channel',
        'last_activity_at',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'is_shared_channel' => 'boolean',
        'last_activity_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
