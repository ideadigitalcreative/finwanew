<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Balance extends Model
{
    protected $fillable = [
        'tenant_id',
        'account_name',
        'account_number',
        'account_type',
        'currency',
        'balance',
        'balance_date',
        'is_active',
        'is_default',
        'metadata'
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'balance_date' => 'date',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array'
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
