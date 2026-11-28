<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'transaction_id', 'action', 'via', 'user_id', 'message_id', 'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }
}
