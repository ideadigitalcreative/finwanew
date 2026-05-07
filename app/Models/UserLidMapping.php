<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLidMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'lid',
        'phone_number',
        'verified',
    ];

    protected $casts = [
        'verified' => 'boolean',
    ];

    /**
     * Get the user that owns this LID mapping.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tenant that owns this LID mapping.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Find user by LID.
     */
    public static function findByLid(string $lid): ?self
    {
        // Remove @lid suffix if present
        $cleanLid = str_replace('@lid', '', $lid);

        return self::where('lid', $cleanLid)->first();
    }

    /**
     * Create or update LID mapping for a user.
     */
    public static function linkLidToUser(string $lid, int $userId, int $tenantId, ?string $phoneNumber = null): self
    {
        $cleanLid = str_replace('@lid', '', $lid);

        return self::updateOrCreate(
            ['lid' => $cleanLid],
            [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'phone_number' => $phoneNumber,
                'verified' => $phoneNumber !== null,
            ]
        );
    }
}
