<?php

namespace Tests\Unit;

use App\Services\Transaction\CategoryInferenceService;
use Tests\TestCase;

class CategoryInferenceServiceTest extends TestCase
{
    public function test_kasih_undangan_hajatan_masuk_kategori_acara(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('kasih undangan hajatan 67rb');
        
        $this->assertEquals('pengeluaran_acara', $result['category_type']);
        $this->assertEquals('Acara & Hajatan', $result['category_name']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_kasih_amplop_kondangan_masuk_kategori_sosial(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('kasih amplop kondangan 100rb');
        
        $this->assertEquals('pengeluaran_sosial', $result['category_type']);
        $this->assertEquals('Sosial & Kondangan', $result['category_name']);
    }

    public function test_bayar_listrik_masuk_kategori_utilitas(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('bayar listrik 500rb');
        
        $this->assertEquals('pengeluaran_utilitas', $result['category_type']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_makan_siang_masuk_kategori_makanan(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('makan siang 25rb');
        
        $this->assertEquals('pengeluaran_makanan', $result['category_type']);
        $this->assertEquals('Makanan & Minuman', $result['category_name']);
    }

    public function test_gajian_bulan_ini_masuk_pendapatan(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('gajian bulan ini 5jt');
        
        $this->assertEquals('pendapatan_gaji', $result['category_type']);
        $this->assertEquals('Gaji', $result['category_name']);
        $this->assertEquals('income', $result['type']);
    }

    public function test_bonus_masuk_pendapatan_bonus(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('bonus target 1jt');
        
        $this->assertEquals('pendapatan_bonus', $result['category_type']);
        $this->assertEquals('Bonus', $result['category_name']);
        $this->assertEquals('income', $result['type']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_cashback_masuk_pendapatan_refund(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('cashback shopee 10rb');
        
        $this->assertEquals('pendapatan_refund', $result['category_type']);
        $this->assertEquals('Refund & Cashback', $result['category_name']);
        $this->assertEquals('income', $result['type']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_bayar_hutang_masuk_kategori_bayar_hutang(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('bayar hutang ke pak RT 2jt');
        
        $this->assertEquals('pengeluaran_bayar_hutang', $result['category_type']);
        $this->assertGreaterThanOrEqual(0.5, $result['confidence']);
    }

    public function test_bayar_cicilan_masuk_kategori_cicilan(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('bayar cicilan motor 1jt');
        
        $this->assertEquals('pengeluaran_cicilan', $result['category_type']);
        $this->assertEquals('Cicilan', $result['category_name']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_bayar_bpjs_masuk_kategori_asuransi(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('bayar bpjs 150rb');
        
        $this->assertEquals('pengeluaran_asuransi', $result['category_type']);
        $this->assertEquals('Asuransi', $result['category_name']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_beli_obat_masuk_kategori_kesehatan(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('beli obat di apotek 50rb');
        
        $this->assertEquals('pengeluaran_kesehatan', $result['category_type']);
        $this->assertEquals('Kesehatan', $result['category_name']);
    }

    public function test_beli_bensin_motor_masuk_kategori_transport(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('beli bensin motor 20rb');
        
        $this->assertEquals('pengeluaran_transport', $result['category_type']);
        $this->assertEquals('Transportasi', $result['category_name']);
    }

    public function test_donasi_anak_yatim_masuk_donasi(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('donasi anak yatim 500rb');
        
        $this->assertEquals('pengeluaran_donasi', $result['category_type']);
        $this->assertEquals('Donasi', $result['category_name']);
    }

    public function test_servis_motor_masuk_kategori_otomotif(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('servis motor di bengkel 200rb');
        
        $this->assertEquals('pengeluaran_otomotif', $result['category_type']);
        $this->assertEquals('Otomotif', $result['category_name']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_kulakan_stok_masuk_kategori_modal(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('kulakan stok bahan baku 500rb');
        
        $this->assertEquals('pengeluaran_modal', $result['category_type']);
        $this->assertEquals('Modal & Stok', $result['category_name']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }

    public function test_beli_baju_masuk_kategori_pakaian(): void
    {
        $service = new CategoryInferenceService();
        $result = $service->infer('beli baju uniqlo 300rb');
        
        $this->assertEquals('pengeluaran_pakaian', $result['category_type']);
        $this->assertEquals('Pakaian & Fashion', $result['category_name']);
        $this->assertGreaterThanOrEqual(0.4, $result['confidence']);
    }
}
