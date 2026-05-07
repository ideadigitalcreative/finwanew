<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Navbar from '@/components/Landing/Navbar.vue';
import FooterSection from '@/components/Landing/FooterSection.vue';
import { ref, computed } from 'vue';

// State for active section
const activeSection = ref('intro');

// State for expandable sections
const expandedSections = ref<Record<string, boolean>>({});

const toggleSection = (sectionId: string) => {
    expandedSections.value[sectionId] = !expandedSections.value[sectionId];
};

const isExpanded = (sectionId: string) => expandedSections.value[sectionId];

// Scroll to section
const scrollToSection = (sectionId: string) => {
    activeSection.value = sectionId;
    const element = document.getElementById(sectionId);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

// Navigation menu items
const menuItems = [
    { id: 'intro', label: 'Pengenalan', icon: '🎯' },
    { id: 'mulai', label: 'Cara Memulai', icon: '🚀' },
    { id: 'pemasukan', label: 'Catat Pemasukan', icon: '💰' },
    { id: 'pengeluaran', label: 'Catat Pengeluaran', icon: '💸' },
    { id: 'batch', label: 'Catat Banyak Sekaligus', icon: '📝' },
    { id: 'dompet', label: 'Kelola Dompet', icon: '💳' },
    { id: 'laporan', label: 'Laporan Keuangan', icon: '📊' },
    { id: 'struk', label: 'Foto Struk', icon: '📸' },
    { id: 'voice', label: 'Voice Note', icon: '🎤' },
    { id: 'edit', label: 'Edit & Hapus', icon: '✏️' },
    { id: 'budget', label: 'Budget & Target', icon: '🎯' },
    { id: 'keyword-umkm', label: 'Keyword Bisnis', icon: '🏪' },
    { id: 'tips', label: 'Tips UMKM', icon: '💡' },
    { id: 'faq', label: 'FAQ', icon: '❓' },
];
</script>

<template>
    <Head>
        <title>Panduan UMKM — FinWa</title>
        <meta name="description" content="Panduan lengkap FinWa untuk UMKM. Pelajari cara mencatat operasional harian, keuangan bisnis, pemasukan, pengeluaran via WhatsApp dengan mudah." />
        <meta name="robots" content="index, follow" />
        <link rel="canonical" href="https://finwa.web.id/panduan-umkm" />
        <meta name="keywords" content="panduan finwa, umkm, catat keuangan, whatsapp, bisnis, usaha kecil, menengah" />
    </Head>

    <div class="antialiased bg-white text-neutral-900 font-sans min-h-screen flex flex-col">
        <Navbar />
        
        <main class="flex-grow">
            <!-- Hero Section -->
            <section class="bg-gradient-to-br from-emerald-600 via-emerald-500 to-teal-500 text-white py-16 px-4 sm:px-6 lg:px-8">
                <div class="max-w-4xl mx-auto text-center">
                    <div class="inline-flex items-center bg-white/20 backdrop-blur-sm rounded-full px-4 py-2 mb-6">
                        <span class="text-2xl mr-2">📱</span>
                        <span class="text-sm font-medium">Panduan Lengkap</span>
                    </div>
                    <h1 class="text-4xl sm:text-5xl font-bold mb-4">
                        Panduan FinWa untuk UMKM
                    </h1>
                    <p class="text-xl text-emerald-100 max-w-2xl mx-auto">
                        Mencatat Operasional Harian dengan WhatsApp — Mudah, Cepat, dan Praktis!
                    </p>
                </div>
            </section>

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                <div class="lg:grid lg:grid-cols-4 lg:gap-12">
                    <!-- Sidebar Navigation -->
                    <aside class="lg:col-span-1 mb-8 lg:mb-0">
                        <div class="sticky top-8 bg-neutral-50 rounded-2xl p-4 shadow-sm">
                            <h3 class="font-semibold text-neutral-800 mb-4 px-2">Daftar Isi</h3>
                            <nav class="space-y-1">
                                <button
                                    v-for="item in menuItems"
                                    :key="item.id"
                                    @click="scrollToSection(item.id)"
                                    :class="[
                                        'w-full text-left px-3 py-2 rounded-xl text-sm transition-all duration-200 flex items-center gap-2',
                                        activeSection === item.id
                                            ? 'bg-emerald-500 text-white font-medium'
                                            : 'text-neutral-600 hover:bg-neutral-100'
                                    ]"
                                >
                                    <span>{{ item.icon }}</span>
                                    <span>{{ item.label }}</span>
                                </button>
                            </nav>
                        </div>
                    </aside>

                    <!-- Main Content -->
                    <div class="lg:col-span-3 space-y-16">
                        <!-- Introduction -->
                        <section id="intro" class="scroll-mt-8">
                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-3xl p-8 border border-emerald-100">
                                <h2 class="text-3xl font-bold text-neutral-900 mb-4 flex items-center gap-3">
                                    <span class="text-4xl">🎯</span>
                                    Apa itu FinWa?
                                </h2>
                                <p class="text-lg text-neutral-700 leading-relaxed">
                                    <strong class="text-emerald-600">FinWa</strong> adalah asisten keuangan pintar yang membantu Anda mencatat dan menganalisis keuangan bisnis melalui WhatsApp. 
                                    Cukup kirim pesan seperti ngobrol biasa, tanpa perlu aplikasi tambahan!
                                </p>
                                <div class="grid sm:grid-cols-3 gap-4 mt-8">
                                    <div class="bg-white rounded-xl p-4 shadow-sm">
                                        <div class="text-3xl mb-2">💬</div>
                                        <h4 class="font-semibold text-neutral-800">Chat Biasa</h4>
                                        <p class="text-sm text-neutral-600">Kirim pesan seperti ngobrol dengan teman</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 shadow-sm">
                                        <div class="text-3xl mb-2">⚡</div>
                                        <h4 class="font-semibold text-neutral-800">Instan</h4>
                                        <p class="text-sm text-neutral-600">Tercatat otomatis dalam hitungan detik</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 shadow-sm">
                                        <div class="text-3xl mb-2">📊</div>
                                        <h4 class="font-semibold text-neutral-800">Lengkap</h4>
                                        <p class="text-sm text-neutral-600">Laporan otomatis kapan saja</p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Getting Started -->
                        <section id="mulai" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">🚀</span>
                                Cara Memulai
                            </h2>
                            <div class="space-y-4">
                                <div class="bg-white border border-neutral-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center font-bold shrink-0">1</div>
                                        <div>
                                            <h3 class="font-semibold text-neutral-800 text-lg">Daftar Akun</h3>
                                            <p class="text-neutral-600 mt-1">Kunjungi website FinWa dan daftar dengan email atau Google Account Anda.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="bg-white border border-neutral-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center font-bold shrink-0">2</div>
                                        <div>
                                            <h3 class="font-semibold text-neutral-800 text-lg">Hubungkan WhatsApp</h3>
                                            <p class="text-neutral-600 mt-1">Masuk ke dashboard dan daftarkan nomor WhatsApp yang ingin Anda gunakan untuk mencatat keuangan.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="bg-white border border-neutral-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center font-bold shrink-0">3</div>
                                        <div>
                                            <h3 class="font-semibold text-neutral-800 text-lg">Siap Mencatat!</h3>
                                            <p class="text-neutral-600 mt-1">Langsung kirim pesan ke nomor FinWa untuk mulai mencatat keuangan Anda.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Catat Pemasukan -->
                        <section id="pemasukan" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">💰</span>
                                Mencatat Pemasukan (Uang Masuk)
                            </h2>
                            
                            <div class="bg-emerald-50 rounded-2xl p-6 mb-6">
                                <h3 class="font-semibold text-emerald-800 mb-4">Format Dasar</h3>
                                <p class="text-neutral-700 mb-4">Cukup ketik pesan seperti ngobrol biasa:</p>
                                <div class="grid sm:grid-cols-2 gap-3">
                                    <div class="bg-white rounded-xl p-4 border border-emerald-200">
                                        <code class="text-emerald-600 font-mono text-sm">penjualan hari ini 500rb</code>
                                        <p class="text-xs text-neutral-500 mt-1">Hasil penjualan</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-emerald-200">
                                        <code class="text-emerald-600 font-mono text-sm">laku 1.5jt</code>
                                        <p class="text-xs text-neutral-500 mt-1">Barang terjual</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-emerald-200">
                                        <code class="text-emerald-600 font-mono text-sm">order masuk 2jt</code>
                                        <p class="text-xs text-neutral-500 mt-1">Orderan baru</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-emerald-200">
                                        <code class="text-emerald-600 font-mono text-sm">dp masuk 500rb</code>
                                        <p class="text-xs text-neutral-500 mt-1">Down payment customer</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-emerald-200">
                                        <code class="text-emerald-600 font-mono text-sm">cuan 300rb</code>
                                        <p class="text-xs text-neutral-500 mt-1">Keuntungan hari ini</p>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-emerald-200">
                                        <code class="text-emerald-600 font-mono text-sm">omset hari ini 3jt</code>
                                        <p class="text-xs text-neutral-500 mt-1">Total omset</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Format Angka -->
                            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-6">
                                <h4 class="font-semibold text-amber-800 mb-3">💡 Format Angka yang Didukung</h4>
                                <div class="flex flex-wrap gap-3">
                                    <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200">
                                        <code class="text-amber-700">25rb</code> = Rp 25.000
                                    </span>
                                    <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200">
                                        <code class="text-amber-700">50k</code> = Rp 50.000
                                    </span>
                                    <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200">
                                        <code class="text-amber-700">1.5jt</code> = Rp 1.500.000
                                    </span>
                                </div>
                            </div>

                            <!-- Contoh UMKM -->
                            <div class="grid sm:grid-cols-3 gap-4">
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5">
                                    <h4 class="font-semibold text-neutral-800 mb-3">🏪 Toko/Warung</h4>
                                    <div class="space-y-2 font-mono text-sm text-neutral-600">
                                        <div class="bg-neutral-50 p-2 rounded">laku 1.2jt hari ini</div>
                                        <div class="bg-neutral-50 p-2 rounded">order masuk gofood 150rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">closing deal 500rb</div>
                                    </div>
                                </div>
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5">
                                    <h4 class="font-semibold text-neutral-800 mb-3">💼 Jasa/Freelance</h4>
                                    <div class="space-y-2 font-mono text-sm text-neutral-600">
                                        <div class="bg-neutral-50 p-2 rounded">dp masuk project 3jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">pelanggan bayar 500rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">piutang lunas 1jt</div>
                                    </div>
                                </div>
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5">
                                    <h4 class="font-semibold text-neutral-800 mb-3">🍜 Kuliner</h4>
                                    <div class="space-y-2 font-mono text-sm text-neutral-600">
                                        <div class="bg-neutral-50 p-2 rounded">cuan hari ini 850rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">omset katering 2.5jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">laris manis 1.8jt</div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Catat Pengeluaran -->
                        <section id="pengeluaran" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">💸</span>
                                Mencatat Pengeluaran (Uang Keluar)
                            </h2>

                            <div class="bg-rose-50 rounded-2xl p-6 mb-6">
                                <h3 class="font-semibold text-rose-800 mb-4">Format Dasar</h3>
                                <div class="grid sm:grid-cols-2 gap-3">
                                    <div class="bg-white rounded-xl p-4 border border-rose-200 flex justify-between items-center">
                                        <code class="text-rose-600 font-mono text-sm">kulakan 500rb</code>
                                        <span class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded-full">Modal</span>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-rose-200 flex justify-between items-center">
                                        <code class="text-rose-600 font-mono text-sm">bayar supplier 1.5jt</code>
                                        <span class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded-full">Modal</span>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-rose-200 flex justify-between items-center">
                                        <code class="text-rose-600 font-mono text-sm">beli stok 2jt</code>
                                        <span class="text-xs bg-amber-100 text-amber-700 px-2 py-1 rounded-full">Modal</span>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-rose-200 flex justify-between items-center">
                                        <code class="text-rose-600 font-mono text-sm">packaging 50rb</code>
                                        <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Operasional</span>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-rose-200 flex justify-between items-center">
                                        <code class="text-rose-600 font-mono text-sm">ongkir JNE 25rb</code>
                                        <span class="text-xs bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Operasional</span>
                                    </div>
                                    <div class="bg-white rounded-xl p-4 border border-rose-200 flex justify-between items-center">
                                        <code class="text-rose-600 font-mono text-sm">sewa toko 3jt</code>
                                        <span class="text-xs bg-rose-100 text-rose-700 px-2 py-1 rounded-full">Hunian</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid sm:grid-cols-3 gap-4">
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5">
                                    <h4 class="font-semibold text-neutral-800 mb-3">📦 Modal & Stok</h4>
                                    <div class="space-y-2 font-mono text-sm text-neutral-600">
                                        <div class="bg-neutral-50 p-2 rounded">kulakan barang 750rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">restok dagangan 2jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">bayar supplier 1.5jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">modal bahan baku 500rb</div>
                                    </div>
                                </div>
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5">
                                    <h4 class="font-semibold text-neutral-800 mb-3">🏪 Operasional Bisnis</h4>
                                    <div class="space-y-2 font-mono text-sm text-neutral-600">
                                        <div class="bg-neutral-50 p-2 rounded">packaging 100rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">ongkir JNE 50rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">ekspedisi sicepat 35rb</div>
                                        <div class="bg-neutral-50 p-2 rounded">biaya operasional 200rb</div>
                                    </div>
                                </div>
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5">
                                    <h4 class="font-semibold text-neutral-800 mb-3">👥 Gaji & Sewa</h4>
                                    <div class="space-y-2 font-mono text-sm text-neutral-600">
                                        <div class="bg-neutral-50 p-2 rounded">gaji pegawai 5jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">bayar karyawan 2jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">sewa toko 3jt</div>
                                        <div class="bg-neutral-50 p-2 rounded">sewa gudang 1.5jt</div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Batch Transaction -->
                        <section id="batch" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">📝</span>
                                Mencatat Banyak Transaksi Sekaligus
                            </h2>

                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="bg-gradient-to-br from-violet-50 to-purple-50 rounded-2xl p-6 border border-violet-100">
                                    <h3 class="font-semibold text-violet-800 mb-4">📤 Format Pengeluaran Harian</h3>
                                    <div class="bg-white rounded-xl p-4 font-mono text-sm text-neutral-700 border border-violet-200">
                                        <div class="text-violet-600 font-medium">Biaya tanggal 13 Desember 2025</div>
                                        <div>1. Bahan baku 500rb</div>
                                        <div>2. Bensin motor 50rb</div>
                                        <div>3. Parkir 10rb</div>
                                        <div>4. Makan siang 25rb</div>
                                        <div>5. Ongkir JNE 15rb</div>
                                    </div>
                                </div>

                                <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl p-6 border border-emerald-100">
                                    <h3 class="font-semibold text-emerald-800 mb-4">📥 Format Pemasukan Banyak Customer</h3>
                                    <div class="bg-white rounded-xl p-4 font-mono text-sm text-neutral-700 border border-emerald-200">
                                        <div class="text-emerald-600 font-medium">Uang masuk atas nama</div>
                                        <div>Pak Ahmad 500rb</div>
                                        <div>Bu Siti 350rb</div>
                                        <div>Toko Makmur 1.2jt</div>
                                        <div>Customer Online 275rb</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-5">
                                <h4 class="font-semibold text-blue-800 mb-3">💡 Tips Batch</h4>
                                <ul class="space-y-2 text-neutral-700">
                                    <li class="flex items-center gap-2">
                                        <span class="text-green-500">✅</span>
                                        Sertakan tanggal di baris pertama
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <span class="text-green-500">✅</span>
                                        Gunakan nomor urut untuk setiap item
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <span class="text-green-500">✅</span>
                                        Sertakan nominal di setiap item
                                    </li>
                                </ul>
                            </div>
                        </section>

                        <!-- Kelola Dompet -->
                        <section id="dompet" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">💳</span>
                                Mengelola Dompet/Rekening
                            </h2>

                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="bg-white border border-neutral-200 rounded-2xl p-6">
                                    <h3 class="font-semibold text-neutral-800 mb-4">➕ Menambah Dompet Baru</h3>
                                    <div class="space-y-2 font-mono text-sm">
                                        <div class="bg-neutral-50 p-3 rounded-lg text-emerald-700">tambah dompet BCA</div>
                                        <div class="bg-neutral-50 p-3 rounded-lg text-emerald-700">tambah dompet Gopay saldo 500rb</div>
                                        <div class="bg-neutral-50 p-3 rounded-lg text-emerald-700">tambah rekening Dana</div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-6">
                                    <h3 class="font-semibold text-neutral-800 mb-4">👀 Melihat Semua Dompet</h3>
                                    <div class="space-y-2 font-mono text-sm">
                                        <div class="bg-neutral-50 p-3 rounded-lg text-blue-700">lihat dompet</div>
                                        <div class="bg-neutral-50 p-3 rounded-lg text-blue-700">daftar rekening</div>
                                        <div class="bg-neutral-50 p-3 rounded-lg text-blue-700">cek saldo</div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-6">
                                    <h3 class="font-semibold text-neutral-800 mb-4">⚙️ Mengatur Saldo Dompet</h3>
                                    <div class="space-y-2 font-mono text-sm">
                                        <div class="bg-neutral-50 p-3 rounded-lg text-amber-700">set saldo BCA menjadi 5jt</div>
                                        <div class="bg-neutral-50 p-3 rounded-lg text-amber-700">ubah saldo Gopay jadi 500rb</div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-6">
                                    <h3 class="font-semibold text-neutral-800 mb-4">💰 Pemasukan ke Dompet Tertentu</h3>
                                    <div class="space-y-2 font-mono text-sm">
                                        <div class="bg-neutral-50 p-3 rounded-lg text-teal-700">dapet transfer 1jt ke BCA</div>
                                        <div class="bg-neutral-50 p-3 rounded-lg text-teal-700">terima bayaran 500rb ke Gopay</div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Laporan Keuangan -->
                        <section id="laporan" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">📊</span>
                                Melihat Laporan Keuangan
                            </h2>

                            <div class="bg-gradient-to-br from-indigo-50 to-blue-50 rounded-2xl p-6 border border-indigo-100">
                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <h3 class="font-semibold text-indigo-800 mb-4">📈 Ringkasan Keuangan</h3>
                                        <div class="space-y-2">
                                            <div class="bg-white rounded-xl p-3 border border-indigo-100 flex justify-between items-center">
                                                <code class="text-indigo-600 font-mono text-sm">ringkasan bulan ini</code>
                                                <span class="text-xs text-neutral-500">Laporan bulanan</span>
                                            </div>
                                            <div class="bg-white rounded-xl p-3 border border-indigo-100 flex justify-between items-center">
                                                <code class="text-indigo-600 font-mono text-sm">cashflow bulan ini</code>
                                                <span class="text-xs text-neutral-500">Arus kas</span>
                                            </div>
                                            <div class="bg-white rounded-xl p-3 border border-indigo-100 flex justify-between items-center">
                                                <code class="text-indigo-600 font-mono text-sm">pengeluaran minggu ini</code>
                                                <span class="text-xs text-neutral-500">Detail mingguan</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-indigo-800 mb-4">📋 Lihat Transaksi</h3>
                                        <div class="space-y-2">
                                            <div class="bg-white rounded-xl p-3 border border-indigo-100">
                                                <code class="text-indigo-600 font-mono text-sm">lihat transaksi</code>
                                            </div>
                                            <div class="bg-white rounded-xl p-3 border border-indigo-100">
                                                <code class="text-indigo-600 font-mono text-sm">cek statistik</code>
                                            </div>
                                            <div class="bg-white rounded-xl p-3 border border-indigo-100">
                                                <code class="text-indigo-600 font-mono text-sm">export laporan</code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Foto Struk -->
                        <section id="struk" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">📸</span>
                                Mencatat dari Struk/Nota
                            </h2>

                            <div class="bg-gradient-to-br from-orange-50 to-amber-50 rounded-2xl p-6 border border-orange-100">
                                <div class="grid md:grid-cols-2 gap-8 items-center">
                                    <div>
                                        <h3 class="font-semibold text-orange-800 mb-4">Cara Pakai</h3>
                                        <ol class="space-y-3">
                                            <li class="flex items-start gap-3">
                                                <span class="w-6 h-6 bg-orange-500 text-white rounded-full flex items-center justify-center font-bold text-sm shrink-0">1</span>
                                                <span class="text-neutral-700">Foto struk belanja/nota dengan jelas</span>
                                            </li>
                                            <li class="flex items-start gap-3">
                                                <span class="w-6 h-6 bg-orange-500 text-white rounded-full flex items-center justify-center font-bold text-sm shrink-0">2</span>
                                                <span class="text-neutral-700">Kirim foto ke chat FinWa</span>
                                            </li>
                                            <li class="flex items-start gap-3">
                                                <span class="w-6 h-6 bg-orange-500 text-white rounded-full flex items-center justify-center font-bold text-sm shrink-0">3</span>
                                                <span class="text-neutral-700">FinWa akan otomatis membaca dan mencatat!</span>
                                            </li>
                                        </ol>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-orange-800 mb-4">Tips Foto Struk</h3>
                                        <ul class="space-y-2 text-neutral-700">
                                            <li class="flex items-center gap-2">
                                                <span class="text-green-500">✅</span>
                                                Pastikan struk tidak kusut
                                            </li>
                                            <li class="flex items-center gap-2">
                                                <span class="text-green-500">✅</span>
                                                Cahaya cukup terang
                                            </li>
                                            <li class="flex items-center gap-2">
                                                <span class="text-green-500">✅</span>
                                                Total belanja terlihat jelas
                                            </li>
                                            <li class="flex items-center gap-2">
                                                <span class="text-green-500">✅</span>
                                                Foto lurus (tidak miring)
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Voice Note -->
                        <section id="voice" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">🎤</span>
                                Mencatat dengan Voice Note
                            </h2>

                            <div class="bg-gradient-to-br from-indigo-50 to-violet-50 rounded-2xl p-6 border border-indigo-100">
                                <div class="grid md:grid-cols-2 gap-8 items-center">
                                    <div>
                                        <h3 class="font-semibold text-indigo-800 mb-4">Cara Pakai</h3>
                                        <ol class="space-y-3">
                                            <li class="flex items-start gap-3">
                                                <span class="w-6 h-6 bg-indigo-500 text-white rounded-full flex items-center justify-center font-bold text-sm shrink-0">1</span>
                                                <span class="text-neutral-700">Tekan dan tahan tombol 🎤 di WhatsApp</span>
                                            </li>
                                            <li class="flex items-start gap-3">
                                                <span class="w-6 h-6 bg-indigo-500 text-white rounded-full flex items-center justify-center font-bold text-sm shrink-0">2</span>
                                                <span class="text-neutral-700">Ucapkan transaksi dengan jelas, misalnya: <em>"beli makan siang dua puluh lima ribu"</em></span>
                                            </li>
                                            <li class="flex items-start gap-3">
                                                <span class="w-6 h-6 bg-indigo-500 text-white rounded-full flex items-center justify-center font-bold text-sm shrink-0">3</span>
                                                <span class="text-neutral-700">Lepas tombol untuk kirim, FinWa akan mengenali dan mencatat otomatis!</span>
                                            </li>
                                        </ol>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-indigo-800 mb-4">Contoh Ucapan</h3>
                                        <div class="space-y-2 font-mono text-sm">
                                            <div class="bg-white p-3 rounded-lg border border-indigo-200 text-indigo-700">"beli makan siang dua puluh lima ribu"</div>
                                            <div class="bg-white p-3 rounded-lg border border-indigo-200 text-indigo-700">"bayar parkir lima ribu"</div>
                                            <div class="bg-white p-3 rounded-lg border border-indigo-200 text-indigo-700">"terima transfer dari pak budi tiga ratus ribu"</div>
                                            <div class="bg-white p-3 rounded-lg border border-indigo-200 text-indigo-700">"catat beli bensin lima puluh ribu"</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
                                    <h4 class="font-semibold text-amber-800 mb-2">💡 Tips Voice Note</h4>
                                    <ul class="space-y-2 text-neutral-700 text-sm">
                                        <li class="flex items-center gap-2">
                                            <span class="text-green-500">✅</span>
                                            Bicara dengan jelas, tidak terlalu cepat
                                        </li>
                                        <li class="flex items-center gap-2">
                                            <span class="text-green-500">✅</span>
                                            Sebutkan nominal dengan jelas (contoh: "dua puluh lima ribu")
                                        </li>
                                        <li class="flex items-center gap-2">
                                            <span class="text-green-500">✅</span>
                                            Hindari background noise (suara bising di sekitar)
                                        </li>
                                        <li class="flex items-center gap-2">
                                            <span class="text-green-500">✅</span>
                                            Cocok untuk mencatat saat sibuk atau malas mengetik!
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </section>

                        <!-- Edit & Hapus -->
                        <section id="edit" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">✏️</span>
                                Mengedit & Menghapus Transaksi
                            </h2>

                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="bg-white border border-neutral-200 rounded-2xl p-6">
                                    <h3 class="font-semibold text-neutral-800 mb-4">✏️ Edit Transaksi Terakhir</h3>
                                    <div class="space-y-3">
                                        <div class="bg-neutral-50 p-3 rounded-lg">
                                            <code class="text-blue-600 font-mono text-sm">edit jadi 100rb</code>
                                            <p class="text-xs text-neutral-500 mt-1">Ubah nominal</p>
                                        </div>
                                        <div class="bg-neutral-50 p-3 rounded-lg">
                                            <code class="text-blue-600 font-mono text-sm">ganti kategori makan</code>
                                            <p class="text-xs text-neutral-500 mt-1">Ubah kategori</p>
                                        </div>
                                        <div class="bg-neutral-50 p-3 rounded-lg">
                                            <code class="text-blue-600 font-mono text-sm">edit tanggal 10 Des 2025</code>
                                            <p class="text-xs text-neutral-500 mt-1">Ubah tanggal</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-6">
                                    <h3 class="font-semibold text-neutral-800 mb-4">🗑️ Hapus Transaksi</h3>
                                    <div class="space-y-3">
                                        <div class="bg-neutral-50 p-3 rounded-lg">
                                            <code class="text-rose-600 font-mono text-sm">hapus transaksi</code>
                                            <p class="text-xs text-neutral-500 mt-1">Hapus transaksi terakhir</p>
                                        </div>
                                        <div class="bg-neutral-50 p-3 rounded-lg">
                                            <code class="text-rose-600 font-mono text-sm">hapus semua transaksi</code>
                                            <p class="text-xs text-neutral-500 mt-1">Hapus semua data</p>
                                        </div>
                                        <div class="bg-rose-50 p-3 rounded-lg border border-rose-200">
                                            <code class="text-rose-700 font-mono text-sm">reset semua data</code>
                                            <p class="text-xs text-rose-600 mt-1">⚠️ Hati-hati! Menghapus semua</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>



                        <!-- Budget & Target -->
                        <section id="budget" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">🎯</span>
                                Mengatur Budget & Target
                            </h2>

                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="bg-gradient-to-br from-cyan-50 to-teal-50 rounded-2xl p-6 border border-cyan-100">
                                    <h3 class="font-semibold text-cyan-800 mb-4">💰 Budget per Kategori</h3>
                                    <div class="space-y-2 font-mono text-sm">
                                        <div class="bg-white p-3 rounded-lg border border-cyan-200 text-cyan-700">set budget makan 1jt</div>
                                        <div class="bg-white p-3 rounded-lg border border-cyan-200 text-cyan-700">set budget transport 500rb</div>
                                        <div class="bg-white p-3 rounded-lg border border-cyan-200 text-cyan-700">cek budget</div>
                                    </div>
                                </div>

                                <div class="bg-gradient-to-br from-fuchsia-50 to-purple-50 rounded-2xl p-6 border border-fuchsia-100">
                                    <h3 class="font-semibold text-fuchsia-800 mb-4">🎯 Target Tabungan</h3>
                                    <div class="space-y-2 font-mono text-sm">
                                        <div class="bg-white p-3 rounded-lg border border-fuchsia-200 text-fuchsia-700">set target 10jt</div>
                                        <div class="bg-white p-3 rounded-lg border border-fuchsia-200 text-fuchsia-700">cek target</div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Keyword Bisnis UMKM -->
                        <section id="keyword-umkm" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">🏪</span>
                                Daftar Keyword Bisnis
                            </h2>

                            <p class="text-neutral-600 mb-6">
                                FinWa mendukung berbagai keyword khusus untuk UMKM dan bisnis. Berikut daftar lengkap keyword yang bisa Anda gunakan:
                            </p>

                            <div class="grid md:grid-cols-2 gap-6">
                                <!-- Pemasukan Bisnis -->
                                <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl p-6 border border-emerald-100">
                                    <h3 class="font-semibold text-emerald-800 mb-4 flex items-center gap-2">
                                        <span class="text-xl">💰</span>
                                        Keyword Pemasukan Bisnis
                                    </h3>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">penjualan</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">omset</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">laku</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">laris</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">order masuk</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">orderan</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">dp masuk</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">down payment</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">piutang lunas</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">closing</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">deal</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">cuan</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">untung</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">profit</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">cash masuk</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-emerald-200 text-emerald-700">pelanggan bayar</span>
                                    </div>
                                </div>

                                <!-- Pengeluaran Modal -->
                                <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl p-6 border border-amber-100">
                                    <h3 class="font-semibold text-amber-800 mb-4 flex items-center gap-2">
                                        <span class="text-xl">📦</span>
                                        Keyword Modal & Stok
                                    </h3>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">modal</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">kulakan</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">stok</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">restock</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">bahan baku</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">supplier</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">vendor</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">distributor</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">grosir</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-amber-200 text-amber-700">agen</span>
                                    </div>
                                </div>

                                <!-- Pengeluaran Operasional -->
                                <div class="bg-gradient-to-br from-orange-50 to-rose-50 rounded-2xl p-6 border border-orange-100">
                                    <h3 class="font-semibold text-orange-800 mb-4 flex items-center gap-2">
                                        <span class="text-xl">🏪</span>
                                        Keyword Operasional Bisnis
                                    </h3>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">packaging</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">packing</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">kemasan</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">kardus</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">ekspedisi</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">JNE</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">J&T</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">SiCepat</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">AnterAja</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">operasional</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-orange-200 text-orange-700">ongkir kirim</span>
                                    </div>
                                </div>

                                <!-- Gaji & Sewa -->
                                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-6 border border-blue-100">
                                    <h3 class="font-semibold text-blue-800 mb-4 flex items-center gap-2">
                                        <span class="text-xl">👥</span>
                                        Keyword Gaji & Sewa
                                    </h3>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-blue-200 text-blue-700">gaji pegawai</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-blue-200 text-blue-700">upah pegawai</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-blue-200 text-blue-700">bayar karyawan</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-blue-200 text-blue-700">sewa toko</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-blue-200 text-blue-700">sewa gudang</span>
                                        <span class="bg-white px-3 py-1.5 rounded-lg text-sm border border-blue-200 text-blue-700">sewa ruko</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-5">
                                <h4 class="font-semibold text-blue-800 mb-3">💡 Tips Penggunaan Keyword</h4>
                                <ul class="space-y-2 text-neutral-700">
                                    <li class="flex items-center gap-2">
                                        <span class="text-green-500">✅</span>
                                        Keyword pemasukan otomatis dicatat sebagai <strong class="text-emerald-600">uang masuk</strong>
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <span class="text-green-500">✅</span>
                                        Keyword modal & operasional otomatis dicatat sebagai <strong class="text-rose-600">uang keluar</strong>
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <span class="text-green-500">✅</span>
                                        Cukup tulis keyword + nominal, misalnya: <code class="bg-white px-2 py-1 rounded text-emerald-600">laku 500rb</code>
                                    </li>
                                </ul>
                            </div>
                        </section>

                        <!-- Tips UMKM -->
                        <section id="tips" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">💡</span>
                                Tips & Trik untuk UMKM
                            </h2>

                            <div class="grid md:grid-cols-2 gap-4">
                                <div class="bg-white border border-neutral-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">⚡</span>
                                        <div>
                                            <h4 class="font-semibold text-neutral-800">1. Catat Langsung</h4>
                                            <p class="text-sm text-neutral-600 mt-1">Jangan tunda! Setiap ada transaksi, langsung kirim pesan ke FinWa.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">✍️</span>
                                        <div>
                                            <h4 class="font-semibold text-neutral-800">2. Format Singkat</h4>
                                            <p class="text-sm text-neutral-600 mt-1"><code class="text-emerald-600">bahan 500rb</code> lebih cepat dari deskripsi panjang.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">💼</span>
                                        <div>
                                            <h4 class="font-semibold text-neutral-800">3. Pisahkan Dompet</h4>
                                            <p class="text-sm text-neutral-600 mt-1">Buat dompet terpisah untuk kas toko dan pribadi.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">📊</span>
                                        <div>
                                            <h4 class="font-semibold text-neutral-800">4. Rutin Cek Ringkasan</h4>
                                            <p class="text-sm text-neutral-600 mt-1">Cek harian, mingguan, dan bulanan untuk pantau bisnis.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-neutral-200 rounded-2xl p-5 hover:shadow-md transition-shadow">
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">📸</span>
                                        <div>
                                            <h4 class="font-semibold text-neutral-800">5. Foto Struk Besar</h4>
                                            <p class="text-sm text-neutral-600 mt-1">Untuk belanja besar seperti kulakan, foto struk langsung!</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- FAQ -->
                        <section id="faq" class="scroll-mt-8">
                            <h2 class="text-3xl font-bold text-neutral-900 mb-6 flex items-center gap-3">
                                <span class="text-4xl">❓</span>
                                Pertanyaan Umum (FAQ)
                            </h2>

                            <div class="space-y-4">
                                <details class="bg-neutral-50 rounded-2xl p-6 group">
                                    <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                        Salah catat nominal, bagaimana?
                                        <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                                    </summary>
                                    <div class="mt-4 text-neutral-600">
                                        <p>Ketik: <code class="bg-white px-2 py-1 rounded text-emerald-600">edit jadi [nominal benar]</code></p>
                                        <p class="text-sm mt-2">Contoh: <code class="bg-white px-2 py-1 rounded text-emerald-600">edit jadi 500rb</code></p>
                                    </div>
                                </details>

                                <details class="bg-neutral-50 rounded-2xl p-6 group">
                                    <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                        Lupa catat kemarin, bagaimana?
                                        <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                                    </summary>
                                    <div class="mt-4 text-neutral-600">
                                        <p>Tambahkan tanggal di pesan:</p>
                                        <p class="text-sm mt-2"><code class="bg-white px-2 py-1 rounded text-emerald-600">beli bahan kemarin 500rb</code></p>
                                    </div>
                                </details>

                                <details class="bg-neutral-50 rounded-2xl p-6 group">
                                    <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                        Bisa untuk grup WA?
                                        <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                                    </summary>
                                    <div class="mt-4 text-neutral-600">
                                        <p>Saat ini FinWa bekerja via chat pribadi untuk keamanan data Anda.</p>
                                    </div>
                                </details>

                                <details class="bg-neutral-50 rounded-2xl p-6 group">
                                    <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                        Data saya aman?
                                        <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                                    </summary>
                                    <div class="mt-4 text-neutral-600">
                                        <p>Ya, data Anda terenkripsi dan hanya bisa diakses oleh Anda.</p>
                                    </div>
                                </details>

                                <details class="bg-neutral-50 rounded-2xl p-6 group">
                                    <summary class="font-medium text-neutral-800 cursor-pointer list-none flex justify-between items-center">
                                        Bisa untuk banyak bisnis?
                                        <span class="text-emerald-500 group-open:rotate-180 transition-transform">▼</span>
                                    </summary>
                                    <div class="mt-4 text-neutral-600">
                                        <p>Gunakan dompet terpisah untuk masing-masing bisnis:</p>
                                        <p class="text-sm mt-2">
                                            <code class="bg-white px-2 py-1 rounded text-emerald-600">tambah dompet Toko A</code><br>
                                            <code class="bg-white px-2 py-1 rounded text-emerald-600">tambah dompet Toko B</code>
                                        </p>
                                    </div>
                                </details>
                            </div>
                        </section>

                        <!-- Quick Reference -->
                        <section class="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-3xl p-8 text-white">
                            <h2 class="text-2xl font-bold mb-6 flex items-center gap-3">
                                <span class="text-3xl">📋</span>
                                Daftar Perintah Cepat
                            </h2>
                            <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-4">
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Bantuan</h4>
                                    <code class="text-emerald-100 text-sm">help</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Lihat Saldo</h4>
                                    <code class="text-emerald-100 text-sm">cek saldo</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Lihat Dompet</h4>
                                    <code class="text-emerald-100 text-sm">lihat dompet</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Lihat Transaksi</h4>
                                    <code class="text-emerald-100 text-sm">lihat transaksi</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Ringkasan Bulanan</h4>
                                    <code class="text-emerald-100 text-sm">ringkasan bulan ini</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Statistik</h4>
                                    <code class="text-emerald-100 text-sm">cek statistik</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Hapus Terakhir</h4>
                                    <code class="text-emerald-100 text-sm">hapus transaksi</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Edit Terakhir</h4>
                                    <code class="text-emerald-100 text-sm">edit jadi [nominal]</code>
                                </div>
                                <div class="bg-white/10 backdrop-blur-sm rounded-xl p-4">
                                    <h4 class="font-semibold mb-2">Export</h4>
                                    <code class="text-emerald-100 text-sm">export laporan</code>
                                </div>
                            </div>
                        </section>

                        <!-- CTA -->
                        <section class="text-center py-8">
                            <h3 class="text-2xl font-bold text-neutral-900 mb-4">Siap Mencatat Keuangan UMKM Anda?</h3>
                            <p class="text-neutral-600 mb-6">Mulai gratis sekarang dan kelola keuangan bisnis dengan mudah!</p>
                            <div class="flex flex-wrap justify-center gap-4">
                                <a href="/register" class="inline-flex items-center gap-2 bg-emerald-500 text-white px-8 py-3 rounded-xl font-semibold hover:bg-emerald-600 transition-colors shadow-lg shadow-emerald-500/30">
                                    Daftar Gratis
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                    </svg>
                                </a>
                                <a href="/bantuan" class="inline-flex items-center gap-2 bg-white text-neutral-700 px-8 py-3 rounded-xl font-semibold border border-neutral-200 hover:bg-neutral-50 transition-colors">
                                    Pusat Bantuan
                                </a>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </main>

        <FooterSection />
    </div>
</template>

<style scoped>
/* Smooth scroll behavior */
html {
    scroll-behavior: smooth;
}

/* Code styling */
code {
    font-family: 'Fira Code', 'Monaco', 'Consolas', monospace;
}

/* Details/Summary styling */
details summary::-webkit-details-marker {
    display: none;
}
</style>
