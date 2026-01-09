<template>
    <section id="fitur" class="relative border-t border-gray-200/60 bg-gray-50/30">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="max-w-2xl">
                <p class="text-sm uppercase tracking-wider text-emerald-600 font-bold">Fitur Lengkap</p>
                <h2 class="mt-3 text-3xl md:text-4xl font-bold tracking-tight text-gray-900 leading-tight">
                    Cara Mudah Catat Keuangan via WhatsApp dengan Fitur AI Canggih
                </h2>
            </div>

            <!-- Feature Grid 4x2 -->
            <div class="mt-12 grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                <!-- Row 1 -->
                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-emerald-50 ring-1 ring-emerald-100 grid place-items-center text-emerald-600 group-hover:bg-emerald-100 group-hover:scale-110 transition-all duration-300">
                        <MessageSquare class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Catat via WhatsApp</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Catat keuangan langsung dari WA. Kirim pesan pengeluaran & pemasukan, langsung tercatat otomatis.</p>
                </div>

                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-purple-50 ring-1 ring-purple-100 grid place-items-center text-purple-600 group-hover:bg-purple-100 group-hover:scale-110 transition-all duration-300">
                        <Sparkles class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Berbasis AI</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Proses pencatatan lebih mudah tanpa perintah baku. AI memahami bahasa sehari-hari.</p>
                </div>

                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-blue-50 ring-1 ring-blue-100 grid place-items-center text-blue-600 group-hover:bg-blue-100 group-hover:scale-110 transition-all duration-300">
                        <Wallet class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Multi-Wallet</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Kelola beberapa dompet sekaligus: Tunai, Bank, GoPay, OVO, Dana, dan lainnya.</p>
                </div>

                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-amber-50 ring-1 ring-amber-100 grid place-items-center text-amber-600 group-hover:bg-amber-100 group-hover:scale-110 transition-all duration-300">
                        <PiggyBank class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Target Tabungan</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Set target nabung dan pantau progress. Capai tujuan finansialmu lebih terarah.</p>
                </div>

                <!-- Row 2 -->
                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-rose-50 ring-1 ring-rose-100 grid place-items-center text-rose-600 group-hover:bg-rose-100 group-hover:scale-110 transition-all duration-300">
                        <TrendingUp class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Budget Tracking</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Atur budget per kategori. Dapat notifikasi saat pengeluaran hampir mencapai batas.</p>
                </div>

                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-cyan-50 ring-1 ring-cyan-100 grid place-items-center text-cyan-600 group-hover:bg-cyan-100 group-hover:scale-110 transition-all duration-300">
                        <FileDown class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Download Laporan</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Unduh laporan keuangan lengkap dalam format PDF. Cocok untuk dokumentasi.</p>
                </div>

                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-orange-50 ring-1 ring-orange-100 grid place-items-center text-orange-600 group-hover:bg-orange-100 group-hover:scale-110 transition-all duration-300">
                        <Camera class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Scan Struk</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Kirim foto struk belanja, AI otomatis membaca dan mencatat transaksimu.</p>
                </div>

                <div class="group rounded-2xl p-4 sm:p-6 bg-white ring-1 ring-gray-200 shadow-sm hover:shadow-md hover:ring-emerald-500/20 hover:-translate-y-1 transition-all duration-300">
                    <div class="h-12 w-12 rounded-xl bg-indigo-50 ring-1 ring-indigo-100 grid place-items-center text-indigo-600 group-hover:bg-indigo-100 group-hover:scale-110 transition-all duration-300">
                        <Mic class="w-6 h-6" />
                    </div>
                    <h4 class="mt-5 text-lg font-bold tracking-tight text-gray-900">Voice Note</h4>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">Malas ketik? Kirim pesan suara, AI otomatis mengenali dan mencatat transaksimu.</p>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { 
    MessageSquare, 
    Sparkles, 
    Wallet, 
    PiggyBank, 
    TrendingUp, 
    FileDown, 
    Camera, 
    Mic 
} from 'lucide-vue-next';
</script>
