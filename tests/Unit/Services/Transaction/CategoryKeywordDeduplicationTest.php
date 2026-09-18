<?php

namespace Tests\Unit\Services\Transaction;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test to verify no duplicate keywords exist between pengeluaran_bahan_makanan and pengeluaran_makanan.
 * This prevents PHP's last-key-wins behavior from causing misclassification.
 */
class CategoryKeywordDeduplicationTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = config('finwa_category_rules');
    }

    #[Test]
    public function it_maps_cooking_ingredients_to_bahan_makanan(): void
    {
        $keywords = $this->config['expense_keywords'];

        $expected_bahan_makanan = [
            'indomie', 'pop mie', 'royco', 'masako', 'saori', 'miwon',
            'santan', 'garam', 'bawang', 'cabai', 'cabe', 'tomat',
            'jahe', 'kunyit', 'lada', 'merica', 'terigu', 'kaldu',
            'kecap manis', 'kecap asin', 'saus sambal', 'saus tomat',
            'sambal botol', 'mentega', 'margarine', 'minyak zaitun',
            'minyak goreng', 'gula', 'tepung', 'sambal', 'saos',
            'saus', 'kecap', 'telur', 'tempe', 'tahu', 'beras',
            'daging', 'udang',
        ];

        foreach ($expected_bahan_makanan as $keyword) {
            $this->assertArrayHasKey($keyword, $keywords, "Keyword '$keyword' not found in expense_keywords");
            $this->assertEquals(
                'pengeluaran_bahan_makanan',
                $keywords[$keyword],
                "Keyword '$keyword' should map to 'pengeluaran_bahan_makanan' but maps to '{$keywords[$keyword]}'"
            );
        }
    }

    #[Test]
    public function it_maps_ready_to_eat_food_to_makanan(): void
    {
        $keywords = $this->config['expense_keywords'];

        $expected_makanan = [
            'makan', 'makan siang', 'makan malam', 'nasi goreng', 'bakso',
            'kopi', 'gofood', 'grabfood', 'sate', 'mie ayam', 'ramen',
            'sushi', 'seblak', 'martabak', 'rendang', 'bubur', 'es krim',
            'cappuccino', 'latte', 'americano', 'aqua', 'air mineral',
            'nasi padang', 'ayam geprek', 'bakwan', 'cilok', 'cireng',
        ];

        foreach ($expected_makanan as $keyword) {
            $this->assertArrayHasKey($keyword, $keywords, "Keyword '$keyword' not found in expense_keywords");
            $this->assertEquals(
                'pengeluaran_makanan',
                $keywords[$keyword],
                "Keyword '$keyword' should map to 'pengeluaran_makanan' but maps to '{$keywords[$keyword]}'"
            );
        }
    }

    #[Test]
    public function it_has_no_duplicate_keys_with_conflicting_categories(): void
    {
        // PHP arrays can't have duplicate keys - the last one wins.
        // This test verifies the INTENT by checking that the same keyword
        // doesn't appear mapped to BOTH makanan and bahan_makanan in the
        // raw file content (even though PHP would silently resolve it).
        $filePath = config_path('finwa_category_rules.php');
        $content = file_get_contents($filePath);

        // Extract all keyword => category pairs from the expense_keywords section
        preg_match_all("/'([^']+)'\s*=>\s*'(pengeluaran_(?:makanan|bahan_makanan))'/", $content, $matches, PREG_SET_ORDER);

        $keywordCategories = [];
        $conflicts = [];

        foreach ($matches as $match) {
            $keyword = $match[1];
            $category = $match[2];

            if (isset($keywordCategories[$keyword]) && $keywordCategories[$keyword] !== $category) {
                $conflicts[] = "$keyword: {$keywordCategories[$keyword]} vs $category";
            }
            $keywordCategories[$keyword] = $category;
        }

        $this->assertEmpty(
            $conflicts,
            "Found keywords with conflicting categories (makanan vs bahan_makanan):\n" . implode("\n", $conflicts)
        );
    }

    #[Test]
    public function category_inference_correctly_classifies_bahan_makanan(): void
    {
        $service = new \App\Services\Transaction\CategoryInferenceService();

        $bahanMessages = [
            'beli royco 5rb',
            'beli indomie goreng 3500',
            'bawang merah 10rb',
            'beli gula pasir 15rb',
            'minyak goreng 2 liter 30rb',
            'beli tepung terigu 12rb',
        ];

        foreach ($bahanMessages as $message) {
            $result = $service->infer($message);
            $this->assertEquals(
                'pengeluaran_bahan_makanan',
                $result['category_type'],
                "Message '$message' should be classified as pengeluaran_bahan_makanan but got '{$result['category_type']}'"
            );
        }
    }

    #[Test]
    public function category_inference_correctly_classifies_makanan(): void
    {
        $service = new \App\Services\Transaction\CategoryInferenceService();

        $makanMessages = [
            'makan siang di warteg 15rb',
            'beli bakso 20rb',
            'es kopi susu 25rb',
            'nasi goreng 18rb',
            'gofood ayam geprek 30rb',
        ];

        foreach ($makanMessages as $message) {
            $result = $service->infer($message);
            $this->assertEquals(
                'pengeluaran_makanan',
                $result['category_type'],
                "Message '$message' should be classified as pengeluaran_makanan but got '{$result['category_type']}'"
            );
        }
    }

    #[Test]
    public function category_inference_classifies_finwa_payment_as_langganan(): void
    {
        $service = new \App\Services\Transaction\CategoryInferenceService();

        $langgananMessages = [
            'bayar finwa 50.000',
            'bayar finwa 50rb',
            'langganan finwa 50rb',
            'bayar langganan finwa 99rb',
            'bayar 20 ribu premium bot finWa',
            'bayar premium bot finwa 20rb',
        ];

        foreach ($langgananMessages as $message) {
            $result = $service->infer($message);
            $this->assertEquals(
                'pengeluaran_langganan',
                $result['category_type'],
                "Message '$message' should be classified as pengeluaran_langganan but got '{$result['category_type']}'"
            );
        }
    }
}
