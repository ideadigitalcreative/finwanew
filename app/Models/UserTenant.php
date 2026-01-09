<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTenant extends Model
{
    protected $table = 'user_tenants';
    
    protected $fillable = [
        'user_id',
        'tenant_id',
        'role_id',
        'is_active',
        'joined_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'joined_at' => 'datetime'
    ];

    public $timestamps = true;
    
    public $incrementing = true;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
