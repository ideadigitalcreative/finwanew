<?php

namespace Tests\Unit\Services\Transaction;

use App\Services\Transaction\TransactionExtractorService;
use Tests\TestCase;

/**
 * Test ekstraksi nama akun multi-word seperti "BSI POPY", "BCA Dwiki"
 */
class MultiWordAccountNameTest extends TestCase
{
    private TransactionExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new TransactionExtractorService();
    }

    public function test_extracts_multi_word_account_at_end_of_message()
    {
        $testCases = [
            ['text' => 'Beli rujak 40 ribu BSI POPY', 'expected' => 'BSI POPY'],
            ['text' => 'Makan malam 50rb BSI popy', 'expected' => 'BSI popy'],
            ['text' => 'Belanja 100rb BCA Dwiki', 'expected' => 'BCA Dwiki'],
            ['text' => 'Transfer 200 rebu Dana Vika', 'expected' => 'Dana Vika'],
        ];

        foreach ($testCases as $case) {
            $result = $this->extractor->extractAccountNameFromMessage($case['text']);

            $this->assertEquals(
                strtolower($case['expected']),
                strtolower($result),
                "Gagal ekstrak nama akun dari: {$case['text']}"
            );
        }
    }

    public function test_extracts_single_word_account_still_works()
    {
        $testCases = [
            ['text' => 'Bayar listrik 150rb BCA', 'expected' => 'BCA'],
            ['text' => 'Makan 50rb gopay', 'expected' => 'gopay'],
            ['text' => 'Transfer via mandiri 100rb', 'expected' => 'mandiri'],
        ];

        foreach ($testCases as $case) {
            $result = $this->extractor->extractAccountNameFromMessage($case['text']);

            $this->assertEquals(
                strtolower($case['expected']),
                strtolower($result),
                "Gagal ekstrak nama akun dari: {$case['text']}"
            );
        }
    }

    public function test_full_transaction_extraction_with_multi_word_account()
    {
        $message = 'Beli rujak 40 ribu BSI POPY';

        $result = $this->extractor->extractTransactionLocally($message);

        $this->assertNotNull($result);
        $this->assertEquals(40000, $result['amount']);
        $this->assertEquals('expense', $result['type']);
        $this->assertEquals('bsi popy', strtolower($result['account_name']));
    }
}
