<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

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
        'telegram_username',
        'telegram_chat_id',
        'tenant_id',
        'role_id',
        'is_super_admin',
        'google_id',
        'current_tenant_id',
        'telegram_link_token',
        'telegram_link_token_created_at',
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
            'telegram_link_token_created_at' => 'datetime',
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
        if (! $this->role) {
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
        if (! $this->role) {
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

    /**
     * Get Telegram mapping for this user
     */
    public function telegramMapping()
    {
        return $this->hasOne(UserTelegramMapping::class, 'user_id');
    }

    /**
     * Check if user has linked Telegram account
     */
    public function hasLinkedTelegram(): bool
    {
        return $this->telegram_chat_id !== null && $this->telegram_chat_id > 0;
    }

    /**
     * Check if user has a valid (not expired) Telegram link token
     */
    public function hasValidTelegramLinkToken(): bool
    {
        return $this->telegram_link_token !== null &&
               $this->telegram_link_token_created_at !== null &&
               $this->telegram_link_token_created_at->gt(now()->subHour());
    }

    /**
     * Generate a new Telegram linking token
     */
    public function generateTelegramLinkToken(): string
    {
        $token = \Str::random(32);
        $this->telegram_link_token = Hash::make($token);
        $this->telegram_link_token_created_at = now();
        $this->save();

        return $token;
    }

    /**
     * Invalidate the current Telegram link token
     */
    public function invalidateTelegramLinkToken(): void
    {
        $this->telegram_link_token = null;
        $this->telegram_link_token_created_at = null;
        $this->save();
    }

    /**
     * Scope untuk mencari user dengan valid telegram link token
     * NOTE: Tidak bisa langsung query karena token di-hash, harus loop dan check manual
     */
    public function scopeWithValidTelegramLinkToken($query, string $plainToken)
    {
        // Scope ini tidak digunakan karena tidak efisien
        // Gunakan findUserByToken() di controller sebagai gantinya
        return $query->whereNotNull('telegram_link_token')
            ->where('telegram_link_token_created_at', '>=', now()->subHour());
    }
}
