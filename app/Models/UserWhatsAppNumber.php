<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWhatsAppNumber extends Model
{
    protected $table = 'user_whatsapp_numbers';
    
    protected $fillable = [
        'user_id',
        'tenant_id',
        'whatsapp_number',
        'name',
        'is_primary',
        'is_active',
        'is_lid',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'is_lid' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
