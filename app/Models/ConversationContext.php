<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationContext extends Model
{
    protected $fillable = [
        'tenant_id',
        'message',
        'intent',
        'entities',
        'response_type',
    ];

    protected $casts = [
        'entities' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
