<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Achievement extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'type',
        'criteria',
        'points',
        'is_active',
    ];

    protected $casts = [
        'criteria' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get tenants who have earned this achievement
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'user_achievements')
            ->withPivot('earned_at', 'metadata')
            ->withTimestamps();
    }

    /**
     * Scope to get active achievements
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get achievements by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
