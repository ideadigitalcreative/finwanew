<?php

/**
 * Fase 2 — unit test ExtractionValidator (Hybrid.md §7-2c, 2f).
 *
 * Murni deterministik: TANPA DB (Unit/Services tanpa RefreshDatabase),
 * TANPA network. Menguji aturan validator terhadap teks asli:
 *  a. nominal LLM vs extractAmount(text)
 *  b. deklarasi eksplisit menang atas tipe LLM
 *  c. whitelist kategori dari config
 *  d. batch > 1 item ditolak
 *  e. confidence < threshold tidak menyela
 */

use App\Services\Transaction\ExtractionValidator;
use App\Services\Transaction\HybridExtractionResult;

/** Hasil hybrid sintetis (named args, tanpa DB). */
function hyb2Result(
    string $status = HybridExtractionResult::STATUS_OK,
    ?string $intent = 'transaction',
    ?string $type = 'expense',
    ?int $amount = 100000,
    array $items = [],
    ?string $category = null,
    ?float $confidence = 0.9,
): HybridExtractionResult {
    if ($items === [] && $amount !== null) {
        $items = [['amount' => $amount, 'label' => null]];
    }

    return new HybridExtractionResult(
        status: $status,
        intent: $intent,
        type: $type,
        amount: $amount,
        items: $items,
        category: $category,
        confidence: $confidence,
    );
}

function hyb2Validator(): ExtractionValidator
{
    return new ExtractionValidator(0.75);
}

// ── shouldIntercept (syarat murah 2d) ─────────────────────────────

test('shouldIntercept: hasil ok, transaksi, nominal valid, tunggal, confidence 0.9 → true', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result()))->toBeTrue();
});

test('shouldIntercept: status unavailable (LLM error) → false (jatuh-when-in-doubt)', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(status: HybridExtractionResult::STATUS_UNAVAILABLE)))->toBeFalse();
});

test('shouldIntercept: status disabled → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(status: HybridExtractionResult::STATUS_OFF)))->toBeFalse();
});

test('shouldIntercept: intent query → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(intent: 'query')))->toBeFalse();
});

test('shouldIntercept: intent other → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(intent: 'other')))->toBeFalse();
});

test('shouldIntercept: type null → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(type: null)))->toBeFalse();
});

test('shouldIntercept: amount null → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(amount: null, items: [])))->toBeFalse();
});

test('shouldIntercept: amount 0 → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(amount: 0)))->toBeFalse();
});

test('shouldIntercept: confidence 0.6 di bawah threshold 0.75 → false', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(confidence: 0.6)))->toBeFalse();
});

test('shouldIntercept: confidence tepat 0.75 (batas) → true', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(confidence: 0.75)))->toBeTrue();
});

test('shouldIntercept: batch 2 item → false (ditolak di Fase 2)', function () {
    $r = hyb2Result(items: [
        ['amount' => 24000, 'label' => null],
        ['amount' => 6000, 'label' => null],
    ]);

    expect(hyb2Validator()->shouldIntercept($r))->toBeFalse();
});

// ── validate: 2c-a nominal vs rules ───────────────────────────────

test('validate: nominal LLM == rules → lolos, amount dipakai (100000)', function () {
    $v = hyb2Validator()->validate('beli kursus 100rb', hyb2Result(amount: 100000));

    expect($v['passed'])->toBeTrue()
        ->and($v['amount'])->toBe(100000)
        ->and($v['is_income'])->toBeFalse()
        ->and($v['reasons'])->not->toContain(ExtractionValidator::REASON_AMOUNT_MISMATCH);
});

test('validate: nominal LLM beda dari rules → TETAP lolos tapi amount = rules (LLM tidak pernah menang)', function () {
    $v = hyb2Validator()->validate('beli kursus 100rb', hyb2Result(amount: 999999));

    expect($v['passed'])->toBeTrue()
        ->and($v['amount'])->toBe(100000)
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_AMOUNT_MISMATCH);
});

test('validate: rules tidak menemukan angka di teks → ditolak (LLM kemungkinan mengarang)', function () {
    $v = hyb2Validator()->validate('beli kursus ya besok', hyb2Result(amount: 100000));

    expect($v['passed'])->toBeFalse()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_NO_AMOUNT);
});

test('validate: amount dalam format ribuan titik (75.000) terverifikasi benar', function () {
    $v = hyb2Validator()->validate('beli tiket 75.000', hyb2Result(amount: 75000));

    expect($v['passed'])->toBeTrue()
        ->and($v['amount'])->toBe(75000);
});

// ── validate: 2c-b deklarasi eksplisit menang ─────────────────────

test('validate: deklarasi "Pemasukan" menang atas tipe LLM expense → is_income true', function () {
    $v = hyb2Validator()->validate('Pemasukan gaji 500000', hyb2Result(type: 'expense', amount: 500000));

    expect($v['passed'])->toBeTrue()
        ->and($v['is_income'])->toBeTrue()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_TYPE_OVERRIDDEN);
});

test('validate: deklarasi "Pengeluaran" menang atas tipe LLM income → is_income false', function () {
    $v = hyb2Validator()->validate('Pengeluaran beli sepeda 1500000', hyb2Result(type: 'income', amount: 1500000));

    expect($v['passed'])->toBeTrue()
        ->and($v['is_income'])->toBeFalse()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_TYPE_OVERRIDDEN);
});

test('validate: tanpa deklarasi → tipe LLM dipakai', function () {
    $v = hyb2Validator()->validate('gaji bulanan 3000000', hyb2Result(type: 'income', amount: 3000000));

    expect($v['passed'])->toBeTrue()
        ->and($v['is_income'])->toBeTrue()
        ->and($v['reasons'])->not->toContain(ExtractionValidator::REASON_TYPE_OVERRIDDEN);
});

// ── validate: 2c-c whitelist kategori ─────────────────────────────

test('validate: kategori whitelist config ("kursus") → lolos, kategori & type dipertahankan', function () {
    $v = hyb2Validator()->validate('beli kursus 100rb', hyb2Result(amount: 100000, category: 'kursus'));

    expect($v['passed'])->toBeTrue()
        ->and($v['kategori'])->toBe('kursus')
        ->and($v['category_type'])->toBe('pengeluaran_pendidikan')
        ->and($v['reasons'])->not->toContain(ExtractionValidator::REASON_CATEGORY_NORMALIZED);
});

test('validate: kategori di luar whitelist → fallback rules (kategori null, bukan penolakan)', function () {
    $v = hyb2Validator()->validate('beli kursus 100rb', hyb2Result(amount: 100000, category: 'kategori_ngawur_zzz'));

    expect($v['passed'])->toBeTrue()
        ->and($v['kategori'])->toBeNull()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_CATEGORY_NORMALIZED);
});

test('validate: kategori null dari LLM → lolos tanpa catatan (fallback alami)', function () {
    $v = hyb2Validator()->validate('beli kursus 100rb', hyb2Result(amount: 100000, category: null));

    expect($v['passed'])->toBeTrue()
        ->and($v['kategori'])->toBeNull()
        ->and($v['reasons'])->toBeEmpty();
});

// ── validate: 2c-d batch ──────────────────────────────────────────

test('validate: 2 item batch → ditolak dengan reason batch_items', function () {
    $r = hyb2Result(amount: 30000, items: [
        ['amount' => 24000, 'label' => null],
        ['amount' => 6000, 'label' => null],
    ]);
    $v = hyb2Validator()->validate('Beli rokok 24000 sama jajan 6000', $r);

    expect($v['passed'])->toBeFalse()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_BATCH);
});

// ── validate: 2c-e threshold ──────────────────────────────────────

test('shouldIntercept konsisten dengan threshold: confidence 0.74 → lepas saringan', function () {
    expect(hyb2Validator()->shouldIntercept(hyb2Result(confidence: 0.74)))->toBeFalse();
});

test('threshold validator adalah 0.75 sesuai §10', function () {
    expect(hyb2Validator()->getConfidenceThreshold())->toBe(0.75);
});

// ── validate: 2c-b' frasa pasti bertentangan dengan tipe LLM ──────

test('validate: LLM bilang income untuk "Sumbangan pernikahan 12rb" → ditolak (rules bilang expense)', function () {
    $v = hyb2Validator()->validate('Sumbangan pernikahan 12rb', hyb2Result(type: 'income', amount: 12000));

    expect($v['passed'])->toBeFalse()
        ->and($v['is_income'])->toBeFalse()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_TYPE_CONTRADICTED);
});

test('validate: LLM bilang income untuk "Memberi sumbangan pernikahan 12rb" → ditolak', function () {
    $v = hyb2Validator()->validate('Memberi sumbangan pernikahan 12rb', hyb2Result(type: 'income', amount: 12000));

    expect($v['passed'])->toBeFalse()
        ->and($v['reasons'])->toContain(ExtractionValidator::REASON_TYPE_CONTRADICTED);
});

test('validate: frasa pasti SEJALAN dengan tipe LLM → tetap lolos', function () {
    $v = hyb2Validator()->validate('sedekah jumat 20rb', hyb2Result(type: 'expense', amount: 20000));

    expect($v['passed'])->toBeTrue()
        ->and($v['reasons'])->not->toContain(ExtractionValidator::REASON_TYPE_CONTRADICTED);
});

test('validate: "dapat sumbangan 500rb" sebagai income tetap lolos (menerima)', function () {
    $v = hyb2Validator()->validate('dapat sumbangan 500rb', hyb2Result(type: 'income', amount: 500000));

    expect($v['passed'])->toBeTrue()
        ->and($v['is_income'])->toBeTrue();
});
