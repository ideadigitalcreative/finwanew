<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'type', // 'once', 'daily', 'weekly', 'monthly'
        'amount',
        'category_type',
        'metadata',
        'reminder_date', // For once type
        'reminder_day', // For monthly (1-31) or weekly (0-6)
        'reminder_time', // HH:MM format
        'is_active',
        'last_sent_at',
        'next_send_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reminder_date' => 'date',
        'reminder_day' => 'integer',
        'is_active' => 'boolean',
        'last_sent_at' => 'datetime',
        'next_send_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Calculate next send datetime based on reminder type
     */
    public function calculateNextSendAt(): void
    {
        $now = now();

        switch ($this->type) {
            case 'once':
                $this->next_send_at = $this->reminder_date->setTimeFromTimeString($this->reminder_time ?? '08:00');
                break;

            case 'daily':
                $nextSend = $now->copy()->setTimeFromTimeString($this->reminder_time ?? '08:00');
                if ($nextSend->lte($now)) {
                    $nextSend->addDay();
                }
                $this->next_send_at = $nextSend;
                break;

            case 'weekly':
                $nextSend = $now->copy()
                    ->next($this->reminder_day) // 0 = Sunday, 1 = Monday, etc.
                    ->setTimeFromTimeString($this->reminder_time ?? '08:00');
                if ($nextSend->lte($now)) {
                    $nextSend->addWeek();
                }
                $this->next_send_at = $nextSend;
                break;

            case 'monthly':
                $day = min($this->reminder_day, $now->daysInMonth);
                $nextSend = $now->copy()
                    ->setDay($day)
                    ->setTimeFromTimeString($this->reminder_time ?? '08:00');
                if ($nextSend->lte($now)) {
                    $nextSend->addMonth();
                    $day = min($this->reminder_day, $nextSend->daysInMonth);
                    $nextSend->setDay($day);
                }
                $this->next_send_at = $nextSend;
                break;
        }

        $this->save();
    }
}
