<?php

namespace Database\Seeders;

use App\Modules\RisenAI\Models\SeoKnowledge;
use Illuminate\Database\Seeder;

class SeoKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $knowledge = [
            [
                'category' => 'about',
                'topic' => 'identity',
                'content' => 'FinWa adalah aplikasi pencatatan keuangan otomatis yang terintegrasi dengan WhatsApp. Dikelola oleh PT Idea Digital Creative.',
                'keywords' => 'finwa, profil, perusahaan, idea digital creative',
            ],
            [
                'category' => 'feature',
                'topic' => 'whatsapp_bot',
                'content' => 'Pencatatan transaksi dilakukan cukup dengan mengirim pesan WhatsApp ke Bot FinWa. Contoh format: "Makan siang 25rb" atau "Gaji 5jt".',
                'keywords' => 'whatsapp, bot, catat, transaksi, otomatis, pesan',
            ],
            [
                'category' => 'feature',
                'topic' => 'dashboard',
                'content' => 'FinWa menyediakan dashboard web real-time untuk melihat grafik pengeluaran, pemasukan, dan ringkasan bulanan secara visual.',
                'keywords' => 'dashboard, grafik, laporan, visual, web',
            ],
            [
                'category' => 'target',
                'topic' => 'umkm',
                'content' => 'FinWa sangat cocok untuk pelaku UMKM (Usaha Mikro, Kecil, dan Menengah) untuk memisahkan uang pribadi dan bisnis dengan mudah.',
                'keywords' => 'umkm, bisnis, usaha, warung, toko, jualan',
            ],
            [
                'category' => 'pricing',
                'topic' => 'paket_gratis',
                'content' => 'FinWa menyediakan Paket Gratis yang aktif selamanya bagi pengguna baru untuk mencatat transaksi harian secara praktis melalui WhatsApp.',
                'keywords' => 'gratis, free, selamanya, paket, biaya',
            ],
            [
                'category' => 'feature',
                'topic' => 'budgeting',
                'content' => 'Fitur Budgeting memungkinkan pengguna mengatur batasan pengeluaran per kategori agar tidak boros.',
                'keywords' => 'budgeting, anggaran, hemat, limit, pengeluaran',
            ],
        ];

        foreach ($knowledge as $item) {
            SeoKnowledge::updateOrCreate(
                ['topic' => $item['topic']],
                $item
            );
        }
    }
}
