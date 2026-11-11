<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token FCM perangkat Android (aplikasi WebView) milik seorang user.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_name',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
