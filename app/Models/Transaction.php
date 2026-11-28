<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    /** metadata.kind untuk baris penyesuaian saldo (bukan pemasukan/pengeluaran). */
    public const KIND_OPENING_BALANCE = 'opening_balance';

    public const KIND_BALANCE_ADJUSTMENT = 'balance_adjustment';

    public const KIND_RECONCILE_BASELINE = 'reconcile_baseline';

    /**
     * metadata.kind untuk hutang/piutang yang HANYA DICATAT (hutang lama, paylater,
     * kartu kredit): tanpa dompet, tidak mengubah saldo, bukan pemasukan/pengeluaran.
     */
    public const KIND_DEBT_RECORD_ONLY = 'debt_record_only';

    /**
     * metadata.kind untuk pembayaran tagihan paylater/kartu kredit yang belanjanya
     * sudah tercatat sebagai pengeluaran: dompet berkurang, tapi bukan pengeluaran lagi.
     */
    public const KIND_CREDIT_REPAYMENT = 'credit_repayment';

    /** Jenis yang MENAMBAH saldo dompet; selain itu (expense, debit_internal) mengurangi. */
    public const CREDIT_TYPES = ['income', 'kredit_internal'];

    protected $fillable = [
        'tenant_id',
        'category_id',
        'message_id',
        'balance_id', // Link to balance account
        'user_whatsapp_number_id', // Nomor WA pengirim (Suami/Istri/Anak/dll)
        'type',
        'amount',
        'transaction_date',
        'source',
        'description',
        'merchant',
        'reference_number',
        'confidence_score',
        'status',
        'reviewed_by',
        'reviewed_at',
        'metadata',
        'linked_transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'confidence_score' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(Balance::class);
    }

    public function linkedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'linked_transaction_id');
    }

    public function whatsappNumber(): BelongsTo
    {
        return $this->belongsTo(UserWhatsAppNumber::class, 'user_whatsapp_number_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(TransactionAudit::class);
    }

    public function kind(): ?string
    {
        $meta = is_array($this->metadata) ? $this->metadata : (json_decode((string) $this->metadata, true) ?: []);

        return $meta['kind'] ?? null;
    }

    /** Hutang/piutang yang hanya dicatat — tidak menggerakkan saldo dompet. */
    public function isDebtRecordOnly(): bool
    {
        return $this->kind() === self::KIND_DEBT_RECORD_ONLY;
    }

    /** Belanja pakai paylater/kartu kredit: pengeluaran + hutang naik, dompet tidak berubah. */
    public function isCreditPurchase(): bool
    {
        $meta = is_array($this->metadata) ? $this->metadata : [];

        return ($meta['credit_purchase'] ?? false) === true;
    }

    /** Bayar tagihan paylater/kartu kredit yang tidak dihitung pengeluaran lagi. */
    public function isCreditRepayment(): bool
    {
        return $this->kind() === self::KIND_CREDIT_REPAYMENT;
    }

    /** Saldo awal / penyesuaian saldo — bukan pemasukan maupun pengeluaran. */
    public function isBalanceAdjustment(): bool
    {
        return in_array($this->kind(), [self::KIND_OPENING_BALANCE, self::KIND_BALANCE_ADJUSTMENT, self::KIND_RECONCILE_BASELINE], true);
    }
}
