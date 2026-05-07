<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLinkToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a unique token for a user
     */
    public static function generateForUser(int $userId): self
    {
        // Cleanup expired tokens
        self::where('expires_at', '<', now())->delete();

        // Delete previous tokens for this user
        self::where('user_id', $userId)->delete();

        return self::create([
            'user_id' => $userId,
            'token' => self::generateUniqueToken(),
            'expires_at' => now()->addMinutes(15), // Token valid for 15 minutes
        ]);
    }

    protected static function generateUniqueToken(): string
    {
        do {
            $token = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
            // Format as ABC-123
            $token = substr($token, 0, 3).'-'.substr($token, 3, 3);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
