<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log ekstraksi LLM hybrid (Hybrid.md Fase 4) — append-only.
 *
 * Satu baris per panggilan extractor: apa yang dikirim user, apa yang
 * dijawab LLM, apa kata validator, dan keputusan akhir. Dipakai untuk
 * audit & menyusun dataset perbaikan prompt / golden set.
 */
class LlmExtractionLog extends Model
{
    public const DECISION_INTERCEPTED = 'intercepted';
    public const DECISION_NOT_INTERCEPTED = 'not_intercepted';
    public const DECISION_REJECTED = 'rejected';
    public const DECISION_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'tenant_id',
        'message_id',
        'raw_message',
        'llm_result',
        'validator_result',
        'decision',
        'override_reasons',
        'model',
        'latency_ms',
    ];

    protected $casts = [
        'llm_result' => 'array',
        'validator_result' => 'array',
        'override_reasons' => 'array',
        'latency_ms' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
