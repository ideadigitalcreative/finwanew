<template>
    <section id="fitur" class="relative border-t border-gray-200/60 bg-gray-50/30">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="max-w-2xl">
                <div
                    class="gradient-badge flex w-fit items-center gap-[6px] rounded-full border border-[#f0c9a8] bg-[#fff3e6]/95 p-[8px_16px] backdrop-blur-sm"
                >
                    <LayoutGrid class="h-5 w-5 shrink-0 text-[#fa8605]" />
                    <p class="text-xs font-medium text-[#8a4608] md:text-sm">Fitur Lengkap</p>
                </div>
                <h2 class="mt-3 text-3xl md:text-4xl font-bold tracking-tight text-gray-900 leading-tight">
                    Cara Mudah Catat Keuangan via WhatsApp dengan Fitur AI Canggih
                </h2>
            </div>

            <!-- Feature Grid: ikon besar kanan-bawah + gradasi hijau sangat tipis ke putih -->
            <div class="mt-12 grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                <div
                    v-for="(f, idx) in features"
                    :key="idx"
                    class="group relative h-full min-h-[11.5rem] overflow-hidden rounded-2xl border border-gray-200/90 bg-white p-4 sm:p-6 transition-colors duration-200 hover:border-[color:var(--fw-400)]"
                >
                    <div
                        class="pointer-events-none absolute inset-0 bg-gradient-to-tl from-[var(--fw-cardwash-from)] via-[var(--fw-cardwash-mid)] to-white"
                        aria-hidden="true"
                    />
                    <div
                        class="pointer-events-none absolute -bottom-7 -right-7 text-[var(--fw-icon-faint)] transition-opacity duration-300 group-hover:text-[var(--fw-icon-faint-hover)] sm:-bottom-8 sm:-right-8"
                        aria-hidden="true"
                    >
                        <component :is="f.icon" class="h-28 w-28 sm:h-32 sm:w-32" :stroke-width="1.5" />
                    </div>
                    <div class="relative z-10 max-w-[92%] pb-2">
                        <h3 class="text-lg font-bold tracking-tight text-[var(--fw-700)]">{{ f.title }}</h3>
                        <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ f.description }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { markRaw } from 'vue';
import type { Component } from 'vue';
import {
    AudioLines,
    Cpu,
    FileText,
    LayoutGrid,
    Layers2,
    PieChart,
    Receipt,
    Smartphone,
    Target,
} from 'lucide-vue-next';

type FeatureItem = {
    title: string;
    description: string;
    icon: Component;
};

const features: FeatureItem[] = [
    {
        title: 'Catat via WhatsApp',
        description:
            'Catat keuangan langsung dari WA. Kirim pesan pengeluaran & pemasukan, langsung tercatat otomatis.',
        icon: markRaw(Smartphone),
    },
    {
        title: 'Berbasis AI',
        description: 'Proses pencatatan lebih mudah tanpa perintah baku. AI memahami bahasa sehari-hari.',
        icon: markRaw(Cpu),
    },
    {
        title: 'Scan Struk',
        description: 'Kirim foto struk belanja, AI otomatis membaca dan mencatat transaksimu.',
        icon: markRaw(Receipt),
    },
    {
        title: 'Voice Note',
        description: 'Malas ketik? Kirim pesan suara, AI otomatis mengenali dan mencatat transaksimu.',
        icon: markRaw(AudioLines),
    },
    {
        title: 'Multi-Wallet',
        description: 'Kelola beberapa dompet sekaligus: Tunai, Bank, GoPay, OVO, Dana, dan lainnya.',
        icon: markRaw(Layers2),
    },
    {
        title: 'Target Tabungan',
        description: 'Set target nabung dan pantau progress. Capai tujuan finansialmu lebih terarah.',
        icon: markRaw(Target),
    },
    {
        title: 'Budget Tracking',
        description: 'Atur budget per kategori. Dapat notifikasi saat pengeluaran hampir mencapai batas.',
        icon: markRaw(PieChart),
    },
    {
        title: 'Download Laporan',
        description: 'Unduh laporan keuangan lengkap dalam format PDF. Cocok untuk dokumentasi.',
        icon: markRaw(FileText),
    },
];
</script>
