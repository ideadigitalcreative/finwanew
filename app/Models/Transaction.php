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
        'user_whatsapp_number_id', // Nomor WA pengirim (Suami/Istri/Anak/dll)
        'type',
        'amount',
        'transaction_date',
        'source',
        'description',
        'merchant',
        'reference_number',
        'confidence_score',
        'status',
        'reviewed_by',
        'reviewed_at',
        'metadata',
        'linked_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'confidence_score' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
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

    public function linkedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'linked_transaction_id');
    }

    public function whatsappNumber(): BelongsTo
    {
        return $this->belongsTo(UserWhatsAppNumber::class, 'user_whatsapp_number_id');
    }
}
