<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Navbar from '@/components/Landing/Navbar.vue';
import FooterSection from '@/components/Landing/FooterSection.vue';
</script>

<template>
    <Head>
        <title>Kebijakan Privasi — FinWa</title>
        <meta name="description" content="Kebijakan Privasi FinWa - Pelajari bagaimana kami mengumpulkan, menggunakan, dan melindungi data pribadi Anda." />
        <meta name="robots" content="index, follow" />
        <link rel="canonical" href="https://finwa.web.id/privasi" />
    </Head>

    <div class="antialiased bg-white text-neutral-900 font-sans min-h-screen flex flex-col">
        <Navbar />
        
        <main class="flex-grow py-16 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl sm:text-4xl font-bold text-neutral-900 mb-4">Kebijakan Privasi</h1>
                <p class="text-neutral-500 mb-8">Terakhir diperbarui: 8 Desember 2025</p>

                <div class="prose prose-neutral max-w-none">
                    
                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">1. Pendahuluan</h2>
                        <p class="text-neutral-600 mb-4">
                            Kami berkomitmen untuk melindungi privasi Anda. 
                            Kebijakan Privasi ini menjelaskan bagaimana kami mengumpulkan, menggunakan, mengungkapkan, 
                            dan melindungi informasi pribadi Anda ketika Anda menggunakan layanan FinWa.
                        </p>
                        <p class="text-neutral-600">
                            Dengan menggunakan layanan kami, Anda menyetujui pengumpulan dan penggunaan informasi 
                            sesuai dengan kebijakan ini.
                        </p>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">2. Informasi yang Kami Kumpulkan</h2>
                        <p class="text-neutral-600 mb-4">Kami mengumpulkan beberapa jenis informasi:</p>
                        
                        <h3 class="text-lg font-medium text-neutral-700 mb-2">2.1 Informasi Akun</h3>
                        <ul class="list-disc list-inside text-neutral-600 mb-4 space-y-1">
                            <li>Nama lengkap</li>
                            <li>Alamat email</li>
                            <li>Nomor WhatsApp</li>
                            <li>Foto profil (jika menggunakan login Google)</li>
                        </ul>

                        <h3 class="text-lg font-medium text-neutral-700 mb-2">2.2 Data Transaksi Keuangan</h3>
                        <ul class="list-disc list-inside text-neutral-600 mb-4 space-y-1">
                            <li>Catatan pemasukan dan pengeluaran yang Anda kirimkan</li>
                            <li>Kategori transaksi</li>
                            <li>Tanggal dan waktu transaksi</li>
                            <li>Deskripsi transaksi</li>
                        </ul>

                        <h3 class="text-lg font-medium text-neutral-700 mb-2">2.3 Informasi dari Google (jika menggunakan Login Google)</h3>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>Nama profil Google</li>
                            <li>Alamat email Google</li>
                            <li>Foto profil Google</li>
                            <li>ID pengguna Google (untuk autentikasi)</li>
                        </ul>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">3. Cara Kami Menggunakan Informasi</h2>
                        <p class="text-neutral-600 mb-4">Kami menggunakan informasi yang dikumpulkan untuk:</p>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li>Menyediakan dan memelihara layanan FinWa</li>
                            <li>Memproses dan mencatat transaksi keuangan Anda</li>
                            <li>Menampilkan laporan dan analisis keuangan di dashboard</li>
                            <li>Mengirim notifikasi terkait akun dan transaksi</li>
                            <li>Memberikan dukungan pelanggan</li>
                            <li>Meningkatkan dan mengembangkan layanan kami</li>
                            <li>Mencegah penipuan dan penyalahgunaan</li>
                        </ul>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">4. Berbagi dan Pengungkapan Data</h2>
                        <p class="text-neutral-600 mb-4">
                            Kami <strong>tidak menjual</strong> data pribadi Anda kepada pihak ketiga. 
                            Kami hanya akan membagikan informasi Anda dalam situasi berikut:
                        </p>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li><strong>Dengan persetujuan Anda:</strong> Ketika Anda secara eksplisit mengizinkan kami untuk berbagi informasi</li>
                            <li><strong>Penyedia layanan:</strong> Dengan vendor tepercaya yang membantu mengoperasikan layanan kami (hosting, analitik)</li>
                            <li><strong>Kewajiban hukum:</strong> Jika diwajibkan oleh hukum atau proses hukum yang berlaku</li>
                            <li><strong>Keamanan:</strong> Untuk melindungi hak, keamanan, dan properti kami atau pengguna lain</li>
                        </ul>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">5. Keamanan Data</h2>
                        <p class="text-neutral-600">
                            Kami menerapkan langkah-langkah keamanan teknis dan organisasional yang sesuai untuk melindungi 
                            data pribadi Anda, termasuk:
                        </p>
                        <ul class="list-disc list-inside text-neutral-600 mt-2 space-y-1">
                            <li>Enkripsi data saat transit (HTTPS/TLS)</li>
                            <li>Enkripsi password dengan algoritma hash yang aman</li>
                            <li>Pembatasan akses ke data berdasarkan kebutuhan</li>
                            <li>Pemantauan keamanan sistem secara berkala</li>
                        </ul>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">6. Hak-Hak Anda</h2>
                        <p class="text-neutral-600 mb-4">Anda memiliki hak untuk:</p>
                        <ul class="list-disc list-inside text-neutral-600 space-y-1">
                            <li><strong>Akses:</strong> Meminta salinan data pribadi Anda</li>
                            <li><strong>Koreksi:</strong> Memperbarui atau memperbaiki data yang tidak akurat</li>
                            <li><strong>Penghapusan:</strong> Meminta penghapusan akun dan data Anda</li>
                            <li><strong>Ekspor:</strong> Mengunduh data transaksi Anda dalam format yang dapat dibaca</li>
                            <li><strong>Penarikan Persetujuan:</strong> Mencabut persetujuan untuk penggunaan tertentu</li>
                        </ul>
                        <p class="text-neutral-600 mt-4">
                            Untuk menggunakan hak-hak ini, silakan hubungi kami di 
                            <a href="mailto:privacy@finwa.web.id" class="text-emerald-600 hover:underline">privacy@finwa.web.id</a>
                        </p>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">7. Penyimpanan Data</h2>
                        <p class="text-neutral-600">
                            Kami menyimpan data pribadi Anda selama akun Anda aktif atau selama diperlukan untuk menyediakan 
                            layanan. Jika Anda menghapus akun, kami akan menghapus data Anda dalam waktu 30 hari, 
                            kecuali jika kami diwajibkan menyimpannya untuk keperluan hukum.
                        </p>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">8. Penggunaan Cookie</h2>
                        <p class="text-neutral-600">
                            Kami menggunakan cookie dan teknologi pelacakan serupa untuk:
                        </p>
                        <ul class="list-disc list-inside text-neutral-600 mt-2 space-y-1">
                            <li>Menjaga sesi login Anda</li>
                            <li>Mengingat preferensi Anda</li>
                            <li>Menganalisis penggunaan layanan untuk peningkatan</li>
                        </ul>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">9. Perubahan Kebijakan</h2>
                        <p class="text-neutral-600">
                            Kami dapat memperbarui Kebijakan Privasi ini dari waktu ke waktu. Kami akan memberitahu Anda 
                            tentang perubahan signifikan melalui email atau pemberitahuan di layanan kami. 
                            Tanggal "terakhir diperbarui" di bagian atas menunjukkan kapan kebijakan ini terakhir direvisi.
                        </p>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-xl font-semibold text-neutral-800 mb-4">10. Hubungi Kami</h2>
                        <p class="text-neutral-600">
                            Jika Anda memiliki pertanyaan tentang Kebijakan Privasi ini, silakan hubungi kami:
                        </p>
                        <div class="bg-neutral-50 rounded-xl p-6 mt-4">
                            <p class="text-neutral-700">
                                <strong>FinWa</strong><br>
                                Email: <a href="mailto:privacy@finwa.web.id" class="text-emerald-600 hover:underline">privacy@finwa.web.id</a><br>
                                Website: <a href="https://finwa.web.id" class="text-emerald-600 hover:underline">https://finwa.web.id</a>
                            </p>
                        </div>
                    </section>

                </div>
            </div>
        </main>

        <FooterSection />
    </div>
</template>
