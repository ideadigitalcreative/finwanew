<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Services\FAQ\FAQService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pesan bernominal tanpa bentuk pertanyaan harus lolos dari FAQ supaya
 * tercatat sebagai transaksi ("... free facemist 63k" dulu dibalas daftar harga).
 */
class FAQTransactionGuardTest extends TestCase
{
    public static function transactionMessages(): array
    {
        return [
            ['CO skintint FFY - free facemist 63k'],
            ['beli kopi gratis ongkir 20rb'],
            ['bayar tarif tol 15.000'],
            ['langganan netflix berbayar Rp 54.000'],
            ['parkir free 5000'],
            ['top up unlimited 1,5jt'],
        ];
    }

    public static function faqMessages(): array
    {
        return [
            ['finwa gratis?'],
            ['apakah finwa free'],
            ['berapa harga langganan'],
            ['paket premium 20rb itu gratis?'],
            ['berapa biaya paket 12 bulan, 204.000 ya'],
            ['Pengeluaran hanya di batasi 50 kali/bulan ya'],
            ['ada limit transaksi'],
        ];
    }

    #[DataProvider('transactionMessages')]
    public function test_pesan_transaksi_tidak_dijawab_faq(string $text): void
    {
        $this->assertTrue(FAQService::looksLikeTransactionEntry($text));

        $replies = [];
        $service = new FAQService(new Message, function (string $reply) use (&$replies) {
            $replies[] = $reply;
        });

        $this->assertFalse($service->checkAndHandleFAQ($text));
        $this->assertSame([], $replies);
    }

    #[DataProvider('faqMessages')]
    public function test_pertanyaan_tetap_ditangani_faq(string $text): void
    {
        $this->assertFalse(FAQService::looksLikeTransactionEntry($text));
    }

    public function test_pertanyaan_harga_masih_dibalas_daftar_harga(): void
    {
        $replies = [];
        $service = new FAQService(new Message, function (string $reply) use (&$replies) {
            $replies[] = $reply;
        });

        $this->assertTrue($service->checkAndHandleFAQ('finwa gratis?'));
        $this->assertStringContainsString('Harga & Paket FinWa', $replies[0]);
    }
}
