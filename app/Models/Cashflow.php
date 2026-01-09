<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cashflow extends Model
{
    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'total_income',
        'total_expense',
        'net_cashflow',
        'summary',
        'breakdown'
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_income' => 'decimal:2',
        'total_expense' => 'decimal:2',
        'net_cashflow' => 'decimal:2',
        'summary' => 'array',
        'breakdown' => 'array'
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
