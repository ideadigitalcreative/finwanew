<?php

use App\Services\Transaction\TransactionExtractorService;

/*
|--------------------------------------------------------------------------
| Slang Normalization Tests
|--------------------------------------------------------------------------
|
| These tests verify that the KeywordNormalizer correctly normalizes
| non-standard Indonesian slang words before transaction extraction.
|
*/

it('normalizes "dpt" to "dapat" and detects as income', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Dpt dari bos 700000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
});

it('normalizes "dpt" to "dapat" in middle of message', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Udah dpt uang ngajar 350000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
});

it('normalizes "mkn" to "makan" and detects as expense', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Mkn siang 25000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
});

it('normalizes "tf" to "transfer" for expense context', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Tf ke pak budi 500000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
});

it('normalizes "dk" to "dapat" for income context', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Dk gaji 3000000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
});

it('handles numeric shorthand "k" and "rb" for thousands', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('dapat 700k dari bos');

    expect($result)->not->toBeNull();
    expect($result['amount'])->toBe(700000);
    expect($result['type'])->toBe('income');
});

it('normalizes "maem" to "makan"', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('maem malam 50000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('expense');
});

it('handles mixed slang in single message', function () {
    $service = new TransactionExtractorService();
    $result = $service->extractTransactionLocally('Dpt dari customer via transfer 1500000');

    expect($result)->not->toBeNull();
    expect($result['type'])->toBe('income');
});