<template>
    <section id="mulai" class="relative border-t border-neutral-200/60">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
            <div class="rounded-2xl ring-1 ring-neutral-200 bg-gradient-to-b from-emerald-50/50 to-white p-6 sm:p-8 md:p-10 text-center">
                <div class="flex flex-col items-center">
                    <h4 class="text-2xl md:text-3xl font-semibold tracking-tight text-neutral-900">Mulai berlangganan dan rasakan kemudahannya.</h4>
                    <p class="mt-2 text-neutral-600 max-w-2xl">Tidak perlu format rumit. Cukup chat, semua otomatis tercatat rapi.</p>
                    <div class="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
                        <a href="#harga" class="inline-flex items-center gap-2 rounded-md px-4 py-2.5 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                            Lihat Paket Harga
                        </a>
                        <a href="#fitur" class="inline-flex items-center gap-2 rounded-md px-4 py-2.5 text-sm font-medium text-neutral-700 ring-1 ring-neutral-200 hover:bg-neutral-50 hover:ring-neutral-300 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
                            Pelajari Fitur
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="testimonials" class="max-w-[1200px] mx-auto flex flex-col py-[40px] px-[20px] md:py-[50px] md:px-[30px] lg:py-[70px] lg:px-[50px] gap-[20px] md:gap-[30px] bg-[#F5F8FA] rounded-[32px] mb-16">
        <div class="flex flex-col gap-[20px] md:gap-[30px] items-center text-center">
            <div class="gradient-badge w-fit p-[8px_16px] rounded-full border border-[oklch(0.9_0.1_54.67)] flex items-center gap-[6px] bg-white">
                <Medal class="w-5 h-5 text-[oklch(0.72_0.18_54.67)]" />
                <p class="font-medium text-xs md:text-sm text-[oklch(0.72_0.18_54.67)]">Lebih Hemat & Teratur</p>
            </div>
            <div class="flex flex-col px-4">
                <h2 class="font-bold text-[24px] md:text-3xl lg:text-4xl leading-tight">Review Aplikasi FinWa dari Pengguna</h2>
                <p class="text-[#6D7786] text-sm md:text-base lg:text-lg -tracking-[2%] mt-2">Berikut ini kata mereka yang sudah menggunakan Finwa.</p>
            </div>
        </div>
        
        <div class="testi w-full overflow-hidden flex flex-col gap-6 relative">
            <div class="fade-overlay absolute left-0 z-10 h-full w-[50px] bg-gradient-to-r from-[#F5F8FA] to-transparent"></div>
            <div class="fade-overlay absolute right-0 z-10 h-full w-[50px] bg-gradient-to-l from-[#F5F8FA] to-transparent"></div>
            
            <!-- Row 1: Slide Left -->
            <div class="group/slider flex flex-nowrap w-max items-center hover:pause-animate">
                <div class="testi-container animate-slideToL flex gap-6 pl-6 items-center flex-nowrap">
                    <!-- Loop items multiple times for infinite scroll illusion -->
                    <div v-for="(testi, i) in testimonials1" :key="`row1-${i}`" class="test-card w-[280px] flex flex-col h-full bg-white rounded-xl gap-2 p-4 shadow-sm border border-neutral-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 flex shrink-0 rounded-full overflow-hidden bg-emerald-100 items-center justify-center text-emerald-600">
                                <User class="w-6 h-6" />
                            </div>
                            <p class="font-semibold text-neutral-900">{{ testi.name }}</p>
                        </div>
                        <p class="text-sm text-[#475466]">{{ testi.message }}</p>
                        <div class="flex gap-[2px]">
                            <Star v-for="j in 5" :key="j" class="w-4 h-4 fill-yellow-400 text-yellow-400" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Slide Right -->
            <div class="group/slider flex flex-nowrap w-max items-center hover:pause-animate">
                <div class="logo-container animate-slideToR flex gap-6 pl-6 items-center flex-nowrap">
                    <div v-for="(testi, i) in testimonials2" :key="`row2-${i}`" class="test-card w-[280px] flex flex-col h-full bg-white rounded-xl gap-2 p-4 shadow-sm border border-neutral-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 flex shrink-0 rounded-full overflow-hidden bg-blue-100 items-center justify-center text-blue-600">
                                <User class="w-6 h-6" />
                            </div>
                            <p class="font-semibold text-neutral-900">{{ testi.name }}</p>
                        </div>
                        <p class="text-sm text-[#475466]">{{ testi.message }}</p>
                        <div class="flex gap-[2px]">
                            <Star v-for="j in 5" :key="j" class="w-4 h-4 fill-yellow-400 text-yellow-400" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { Medal, Star, User } from 'lucide-vue-next';

const testimonials1 = [
    { name: 'Budi Santoso', message: 'FinWa sangat membantu saya mencatat pengeluaran harian tanpa ribet. Cukup chat aja!' },
    { name: 'Siti Aminah', message: 'Fitur otomatisnya keren banget. Sekarang saya jadi lebih sadar soal keuangan.' },
    { name: 'Rizky Pratama', message: 'Dulu malas catat keuangan, sekarang jadi rajin karena semudah chatting.' },
    { name: 'Dewi Lestari', message: 'Sangat praktis dan hemat waktu. Laporan keuangannya juga lengkap.' },
    { name: 'Andi Wijaya', message: 'Solusi terbaik buat yang gak mau ribet buka aplikasi pencatat keuangan.' },
    { name: 'Budi Santoso', message: 'FinWa sangat membantu saya mencatat pengeluaran harian tanpa ribet. Cukup chat aja!' },
    { name: 'Siti Aminah', message: 'Fitur otomatisnya keren banget. Sekarang saya jadi lebih sadar soal keuangan.' },
    { name: 'Rizky Pratama', message: 'Dulu malas catat keuangan, sekarang jadi rajin karena semudah chatting.' },
];

const testimonials2 = [
    { name: 'Maya Putri', message: 'Tampilan dashboardnya bersih dan mudah dipahami. Suka banget!' },
    { name: 'Eko Saputra', message: 'Akhirnya nemu cara catat keuangan yang konsisten. Makasih FinWa!' },
    { name: 'Rina Kartika', message: 'Fitur scan struknya membantu banget, gak perlu ketik manual lagi.' },
    { name: 'Dimas Anggara', message: 'Harganya terjangkau dengan fitur yang sangat bermanfaat.' },
    { name: 'Nina Zatulini', message: 'Sangat recommended buat mahasiswa yang mau atur uang jajan.' },
    { name: 'Maya Putri', message: 'Tampilan dashboardnya bersih dan mudah dipahami. Suka banget!' },
    { name: 'Eko Saputra', message: 'Akhirnya nemu cara catat keuangan yang konsisten. Makasih FinWa!' },
    { name: 'Rina Kartika', message: 'Fitur scan struknya membantu banget, gak perlu ketik manual lagi.' },
];
</script>

<style scoped>
@keyframes slideToL {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
}
@keyframes slideToR {
    0% { transform: translateX(-50%); }
    100% { transform: translateX(0); }
}

.animate-slideToL {
    animation: slideToL 40s linear infinite;
}

.animate-slideToR {
    animation: slideToR 40s linear infinite;
}

.group\/slider:hover .animate-slideToL,
.group\/slider:hover .animate-slideToR {
    animation-play-state: paused;
}
</style>
