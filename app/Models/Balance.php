<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Balance extends Model
{
    protected $fillable = [
        'tenant_id',
        'account_name',
        'account_number',
        'account_type',
        'currency',
        'balance',
        'balance_date',
        'is_active',
        'is_default',
        'metadata',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'balance_date' => 'date',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Tambah/kurangi saldo secara ATOMIK di database
     * (UPDATE balances SET balance = balance + ?), lalu sinkronkan atribut model.
     *
     * Pola lama `$balance->balance += X; $balance->save();` adalah
     * read-modify-write: dua pesan yang diproses bersamaan bisa saling
     * menimpa sehingga salah satu perubahan saldo hilang.
     *
     * Atribut `balance` ditandai bersih setelahnya, jadi `save()` berikutnya
     * (mis. untuk balance_date) tidak menimpa nilai saldo dari database.
     */
    public function adjustBalance(float|int|string $delta): void
    {
        $delta = (float) $delta;
        if ($delta == 0.0 || ! $this->exists) {
            return;
        }

        static::query()->whereKey($this->getKey())->increment('balance', $delta);

        $this->balance = static::query()->whereKey($this->getKey())->value('balance');
        $this->syncOriginalAttribute('balance');
    }
}
