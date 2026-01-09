<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'tenant_id',
        'category_id',
        'message_id',
        'balance_id', // Link to balance account
        'type',
        'amount',
        'transaction_date',
        'source',
        'description',
        'reference_number',
        'confidence_score',
        'status',
        'reviewed_by',
        'reviewed_at',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'confidence_score' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'metadata' => 'array'
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(Balance::class);
    }
}
