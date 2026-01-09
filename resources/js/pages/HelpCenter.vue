<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Navbar from '@/components/Landing/Navbar.vue';
import FooterSection from '@/components/Landing/FooterSection.vue';
</script>

<template>
    <Head>
        <title>Pusat Bantuan — FinWa</title>
        <meta name="description" content="Pusat Bantuan FinWa - Temukan jawaban atas pertanyaan Anda tentang cara menggunakan FinWa untuk mencatat keuangan via WhatsApp." />
        <meta name="robots" content="index, follow" />
        <link rel="canonical" href="https://finwa.web.id/bantuan" />
    </Head>

    <div class="antialiased bg-white text-neutral-900 font-sans min-h-screen flex flex-col">
        <Navbar />
        
        <main class="flex-grow py-16 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl sm:text-4xl font-bold text-neutral-900 mb-8">Pusat Bantuan</h1>
                
                <p class="text-lg text-neutral-600 mb-12">
                    Selamat datang di Pusat Bantuan FinWa. Di sini Anda dapat menemukan panduan dan jawaban atas pertanyaan umum tentang layanan kami.
                </p>

                <!-- Getting Started -->
                <section class="mb-12">
                    <h2 class="text-2xl font-semibold text-neutral-800 mb-4">
                        Memulai dengan FinWa
                    </h2>
                    <div class="bg-neutral-50 rounded-xl p-6 space-y-4">
                        <div>
                            <h3 class="font-medium text-neutral-800 mb-2">1. Daftar Akun</h3>
                            <p class="text-neutral-600">Buat akun FinWa melalui website kami atau gunakan login dengan Google untuk akses cepat.</p>
                        </div>
                        <div>
                            <h3 class="font-medium text-neutral-800 mb-2">2. Hubungkan WhatsApp</h3>
                            <p class="text-neutral-600">Masuk ke dashboard dan daftarkan nomor WhatsApp yang ingin Anda gunakan untuk mencatat keuangan.</p>
                        </div>
                        <div>
                            <h3 class="font-medium text-neutral-800 mb-2">3. Mulai Mencatat</h3>
                            <p class="text-neutral-600">Kirim pesan ke bot FinWa untuk mencatat pemasukan atau pengeluaran. Contoh: "keluar 50rb makan siang"</p>
                        </div>
                    </div>
                </section>

                <!-- FAQ -->
                <section class="mb-12">
                    <h2 class="text-2xl font-semibold text-neutral-800 mb-4">
                        Pertanyaan Umum
                    </h2>
                    <div class="space-y-4">
                        <details class="bg-neutral-50 rounded-xl p-6 group">
                            <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                Bagaimana cara mencatat transaksi?
                                <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                            </summary>
                            <p class="text-neutral-600 mt-4">
                                Cukup kirim pesan ke bot FinWa dengan format sederhana. Contoh:<br>
                                • "masuk 5jt gaji bulanan"<br>
                                • "keluar 150rb belanja"<br>
                                • "pengeluaran 25000 parkir"
                            </p>
                        </details>

                        <details class="bg-neutral-50 rounded-xl p-6 group">
                            <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                Apakah data saya aman?
                                <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                            </summary>
                            <p class="text-neutral-600 mt-4">
                                Ya, kami menggunakan enkripsi untuk melindungi data Anda. Kami tidak menjual atau membagikan data pribadi Anda kepada pihak ketiga. 
                                Baca <a href="/privasi" class="text-emerald-600 hover:underline">Kebijakan Privasi</a> kami untuk informasi lebih lanjut.
                            </p>
                        </details>

                        <details class="bg-neutral-50 rounded-xl p-6 group">
                            <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                Bagaimana cara menghapus akun?
                                <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                            </summary>
                            <p class="text-neutral-600 mt-4">
                                Anda dapat menghapus akun melalui halaman Pengaturan Profil di dashboard. Semua data Anda akan dihapus secara permanen setelah konfirmasi.
                            </p>
                        </details>

                        <details class="bg-neutral-50 rounded-xl p-6 group">
                            <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                Bisakah saya mengekspor data keuangan saya?
                                <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                            </summary>
                            <p class="text-neutral-600 mt-4">
                                Ya, Anda dapat mengekspor data transaksi ke format Excel atau PDF melalui menu Transaksi di dashboard.
                            </p>
                        </details>
                    </div>
                </section>

                <!-- Contact -->
                <section class="mb-12">
                    <h2 class="text-2xl font-semibold text-neutral-800 mb-4">
                        Hubungi Kami
                    </h2>
                    <div class="bg-emerald-50 rounded-xl p-6">
                        <p class="text-neutral-700 mb-4">
                            Tidak menemukan jawaban yang Anda cari? Hubungi tim support kami:
                        </p>
                        <ul class="space-y-2 text-neutral-600">
                            <li>Email: <a href="mailto:support@finwa.web.id" class="text-emerald-600 hover:underline">support@finwa.web.id</a></li>
                            <li>WhatsApp: <a href="https://wa.me/6285242766676" class="text-emerald-600 hover:underline">+62 852-4276-6676</a></li>
                        </ul>
                        <p class="text-neutral-500 text-sm mt-4">
                            Waktu respons: 1-2 hari kerja
                        </p>
                    </div>
                </section>

            </div>
        </main>

        <FooterSection />
    </div>
</template>
