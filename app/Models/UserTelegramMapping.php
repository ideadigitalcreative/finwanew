<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTelegramMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'telegram_chat_id',
        'telegram_username',
        'first_name',
        'last_name',
        'is_active',
        'notifications_enabled',
        'linked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'notifications_enabled' => 'boolean',
        'linked_at' => 'datetime',
    ];

    /**
     * Get the user that owns this Telegram mapping.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tenant that owns this Telegram mapping.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Find mapping by Telegram chat ID.
     */
    public static function findByTelegramChatId(int|string $chatId): ?self
    {
        return self::where('telegram_chat_id', $chatId)->first();
    }

    /**
     * Find mapping by user and tenant.
     */
    public static function findByUserAndTenant(int $userId, int $tenantId): ?self
    {
        return self::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Create or update Telegram mapping for a user.
     */
    public static function linkTelegramToUser(
        int $userId,
        int $tenantId,
        int|string $telegramChatId,
        ?string $username = null,
        ?string $firstName = null,
        ?string $lastName = null
    ): self {
        return self::updateOrCreate(
            ['telegram_chat_id' => $telegramChatId],
            [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'telegram_username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => true,
                'notifications_enabled' => true,
                'linked_at' => now(),
            ]
        );
    }

    /**
     * Unlink Telegram from user.
     */
    public static function unlinkTelegram(int|string $telegramChatId): bool
    {
        $mapping = self::findByTelegramChatId($telegramChatId);
        if (!$mapping) return false;

        $mapping->is_active = false;
        $mapping->save();

        return true;
    }

    /**
     * Get active mappings for a tenant.
     */
    public static function getActiveMappingsForTenant(int $tenantId): array
    {
        return self::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('notifications_enabled', true)
            ->get();
    }
}
