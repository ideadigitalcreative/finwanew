<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'whatsapp_number',
        'tenant_id',
        'role_id',
        'is_super_admin',
        'google_id',
        'current_tenant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Get the tenant that owns the user.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Check if user has permission
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->role) {
            return false;
        }

        $permissions = $this->role->permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    /**
     * Check if user is owner or admin
     */
    public function isAdmin(): bool
    {
        if (!$this->role) {
            return false;
        }

        return in_array($this->role->slug, ['owner', 'admin']);
    }

    /**
     * Get all tenants this user belongs to (many-to-many)
     */
    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'user_tenants')
            ->withPivot('role_id', 'is_active', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Get active tenants only
     */
    public function activeTenants()
    {
        return $this->tenants()->wherePivot('is_active', true);
    }

    /**
     * Get current tenant (from session or default)
     */
    public function currentTenant()
    {
        // Try to get from session first
        $tenantId = session('current_tenant_id', $this->tenant_id);
        
        return $this->activeTenants()->where('tenants.id', $tenantId)->first() 
            ?? $this->activeTenants()->first()
            ?? $this->tenant;
    }

    /**
     * Check if user belongs to tenant
     */
    public function belongsToTenant(int $tenantId): bool
    {
        return $this->activeTenants()->where('tenants.id', $tenantId)->exists();
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }
}
