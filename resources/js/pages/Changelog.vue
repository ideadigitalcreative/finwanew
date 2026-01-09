<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Navbar from '@/components/Landing/Navbar.vue';
import FooterSection from '@/components/Landing/FooterSection.vue';
</script>

<template>
    <Head>
        <title>Changelog — FinWa</title>
        <meta name="description" content="Changelog FinWa - Lihat riwayat pembaruan fitur dan perbaikan terbaru aplikasi FinWa." />
        <meta name="robots" content="index, follow" />
        <link rel="canonical" href="https://finwa.web.id/changelog" />
    </Head>

    <div class="antialiased bg-white text-neutral-900 font-sans min-h-screen flex flex-col">
        <Navbar />
        
        <main class="flex-grow py-16 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl sm:text-4xl font-bold text-neutral-900 mb-4">Changelog</h1>
                <p class="text-neutral-500 mb-8">Riwayat pembaruan dan perbaikan FinWa</p>

                <div class="space-y-8">
                    
                    <!-- v2.6.0 -->
                    <article class="border-l-4 border-emerald-500 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-emerald-100 text-emerald-700 text-sm font-semibold px-3 py-1 rounded-full">v2.6.0</span>
                            <span class="text-neutral-500 text-sm">25 Desember 2025</span>
                            <span class="bg-blue-100 text-blue-700 text-xs font-medium px-2 py-0.5 rounded">Terbaru</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">Export PDF & Context Memory</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>📄 <strong>Export PDF</strong> - Download laporan keuangan langsung via WhatsApp</li>
                            <li>🧠 <strong>Context Memory</strong> - Bot mengingat konteks percakapan untuk follow-up questions</li>
                            <li>🎯 <strong>Target Tabungan</strong> - Set target, tambah tabungan, dan cek progress</li>
                            <li>📊 <strong>Subscription Tracker</strong> - Deteksi otomatis pengeluaran rutin/langganan</li>
                            <li>💡 <strong>Personalized Tips</strong> - Tips keuangan personal di weekly digest</li>
                        </ul>
                    </article>

                    <!-- v2.5.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v2.5.0</span>
                            <span class="text-neutral-500 text-sm">20 Desember 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">Achievement System & Insights</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>🏆 <strong>Achievement System</strong> - Badge dan poin untuk pencapaian keuangan</li>
                            <li>📈 <strong>Spending Insights</strong> - Analisis pola pengeluaran otomatis</li>
                            <li>🔥 <strong>Streak Tracking</strong> - Track konsistensi pencatatan harian</li>
                            <li>🔔 <strong>Smart Notifications</strong> - Notifikasi pencapaian dan tips</li>
                        </ul>
                    </article>

                    <!-- v2.4.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v2.4.0</span>
                            <span class="text-neutral-500 text-sm">15 Desember 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">Multi-Wallet & Budget</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>💼 <strong>Multi-Wallet</strong> - Kelola berbagai dompet dan rekening</li>
                            <li>🎯 <strong>Budget per Kategori</strong> - Set dan monitor budget untuk setiap kategori</li>
                            <li>📊 <strong>Budget Alerts</strong> - Peringatan saat budget hampir habis</li>
                            <li>💳 <strong>Transfer Antar Wallet</strong> - Pindah dana antar dompet</li>
                        </ul>
                    </article>

                    <!-- v2.3.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v2.3.0</span>
                            <span class="text-neutral-500 text-sm">10 Desember 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">AI Enhancement & OCR</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>🤖 <strong>FinWa AI</strong> - AI lokal untuk klasifikasi intent lebih akurat</li>
                            <li>📸 <strong>OCR Struk</strong> - Scan foto struk untuk input transaksi otomatis</li>
                            <li>🎤 <strong>Voice Message</strong> - Input transaksi via voice note</li>
                            <li>📝 <strong>Batch Transaction</strong> - Catat banyak transaksi sekaligus</li>
                        </ul>
                    </article>

                    <!-- v2.2.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v2.2.0</span>
                            <span class="text-neutral-500 text-sm">1 Desember 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">Dashboard & Reports</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>📊 <strong>Dashboard Interaktif</strong> - Visualisasi keuangan yang lebih baik</li>
                            <li>📅 <strong>Weekly Digest</strong> - Ringkasan mingguan otomatis via WhatsApp</li>
                            <li>📈 <strong>Chart & Grafik</strong> - Grafik pengeluaran dan pemasukan</li>
                            <li>🗂️ <strong>Kategori Custom</strong> - Buat kategori transaksi sendiri</li>
                        </ul>
                    </article>

                    <!-- v2.1.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v2.1.0</span>
                            <span class="text-neutral-500 text-sm">20 November 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">Edit & Delete Transactions</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>✏️ <strong>Edit Transaksi</strong> - Ubah nominal, kategori, atau deskripsi</li>
                            <li>🗑️ <strong>Hapus Transaksi</strong> - Hapus transaksi yang salah</li>
                            <li>↩️ <strong>Koreksi Cepat</strong> - "edit jadi 50rb" untuk koreksi langsung</li>
                            <li>📝 <strong>Riwayat Transaksi</strong> - Lihat detail transaksi terakhir</li>
                        </ul>
                    </article>

                    <!-- v2.0.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v2.0.0</span>
                            <span class="text-neutral-500 text-sm">1 November 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">FinWa 2.0 - Redesign</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>🎨 <strong>UI/UX Baru</strong> - Dashboard dan landing page yang lebih modern</li>
                            <li>🔐 <strong>Login Google</strong> - Autentikasi mudah dengan akun Google</li>
                            <li>📱 <strong>Responsive Design</strong> - Optimal di semua ukuran layar</li>
                            <li>⚡ <strong>Performa Tinggi</strong> - Loading lebih cepat dengan Vue 3</li>
                        </ul>
                    </article>

                    <!-- v1.0.0 -->
                    <article class="border-l-4 border-neutral-300 pl-6 py-2">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="bg-neutral-100 text-neutral-700 text-sm font-semibold px-3 py-1 rounded-full">v1.0.0</span>
                            <span class="text-neutral-500 text-sm">Oktober 2025</span>
                        </div>
                        <h3 class="text-lg font-semibold text-neutral-800 mb-2">Peluncuran Awal</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>💬 <strong>WhatsApp Bot</strong> - Catat transaksi via chat WhatsApp</li>
                            <li>📊 <strong>Ringkasan Keuangan</strong> - Cek saldo dan pengeluaran</li>
                            <li>🏷️ <strong>Kategori Otomatis</strong> - Deteksi kategori dari deskripsi</li>
                        </ul>
                    </article>

                </div>

                <!-- Subscribe for Updates -->
                <div class="mt-12 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-8 text-center">
                    <h2 class="text-xl font-semibold text-neutral-800 mb-2">Dapatkan Update Terbaru</h2>
                    <p class="text-neutral-600 mb-4">Ikuti kami untuk info fitur baru dan tips keuangan</p>
                    <div class="flex justify-center gap-4">
                        <a href="https://wa.me/6285242766676" target="_blank" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-3 rounded-lg font-medium transition">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            Hubungi Kami
                        </a>
                    </div>
                </div>
            </div>
        </main>

        <FooterSection />
    </div>
</template>
