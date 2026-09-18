<?php

namespace Tests\Unit\Services\Transaction;

use App\Services\Transaction\TransactionExtractorService;
use Tests\TestCase;

/**
 * Test ekstraksi nominal dengan typo umum "rebu"
 */
class TypoAmountExtractionTest extends TestCase
{
    private TransactionExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new TransactionExtractorService();
    }

    public function test_extracts_transaction_with_typo_rebu()
    {
        $message = 'Gaji bulan ini 65 rebu di gopay';

        $result = $this->extractor->extractTransactionLocally($message);

        $this->assertNotNull($result, 'Ekstraksi tidak boleh gagal untuk nominal dengan typo "rebu"');
        $this->assertEquals(65000, $result['amount']);
        $this->assertEquals('income', $result['type']);
        $this->assertEquals('gopay', strtolower($result['account_name']));
    }

    public function test_extracts_various_typo_rebu_formats()
    {
        $testCases = [
            ['text' => 'Makan 50 rebu', 'expected' => 50000],
            ['text' => 'Belanja 100rebu', 'expected' => 100000],
            ['text' => 'Transfer 1,5 rebu', 'expected' => 1500],
            ['text' => 'Bonus 250 rebu dari kantor', 'expected' => 250000],
        ];

        foreach ($testCases as $case) {
            $result = $this->extractor->extractTransactionLocally($case['text']);

            $this->assertNotNull(
                $result,
                "Gagal ekstrak: {$case['text']}"
            );
            $this->assertEquals(
                $case['expected'],
                $result['amount'],
                "Nominal salah untuk: {$case['text']}"
            );
        }
    }

    public function test_extracts_rp_prefixed_typo_rebu()
    {
        $message = 'Bayar listrik Rp 150 rebu';

        $result = $this->extractor->extractTransactionLocally($message);

        $this->assertNotNull($result);
        $this->assertEquals(150000, $result['amount']);
    }

    public function test_extracts_multiplication_with_typo_rebu()
    {
        $message = 'Snack 10 rebu x 5';

        $result = $this->extractor->extractTransactionLocally($message);

        $this->assertNotNull($result);
        $this->assertEquals(50000, $result['amount'], 'Harus menghitung 10rb x 5 = 50rb');
    }
}
