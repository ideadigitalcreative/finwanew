<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Navbar from '@/components/Landing/Navbar.vue';
import FooterSection from '@/components/Landing/FooterSection.vue';
</script>

<template>
    <Head>
        <title>Tentang Kami — FinWa</title>
        <meta name="description" content="Tentang Kami - FinWa merupakan layanan di bawah PT Idea Digital Creative." />
        <meta name="robots" content="index, follow" />
        <link rel="canonical" href="https://finwa.web.id/tentang-kami" />
    </Head>

    <div class="antialiased bg-white text-neutral-900 font-sans min-h-screen flex flex-col">
        <Navbar />
        
        <main class="flex-grow py-16 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl sm:text-4xl font-bold text-neutral-900 mb-8">Tentang Kami</h1>

                <div class="prose prose-neutral max-w-none">
                    
                    <section class="mb-12">
                        <div class="bg-emerald-50 rounded-2xl p-8 border border-emerald-100">
                            <p class="text-neutral-800 text-lg leading-relaxed text-justify m-0">
                                <strong class="text-emerald-700">FinWa</strong> merupakan layanan yang berada di bawah naungan <strong>PT Idea Digital Creative</strong>, yang berfokus pada pengembangan solusi digital untuk membantu pengelolaan keuangan secara praktis, aman, dan efisien. Kami berkomitmen menghadirkan inovasi yang memudahkan pengguna dalam mengatur keuangan sehari-hari dengan teknologi yang terpercaya.
                            </p>
                        </div>
                    </section>

                    <section class="mb-8">
                        <h2 class="text-2xl font-semibold text-neutral-800 mb-6 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                            Status Legalitas
                        </h2>
                        
                        <div class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden divide-y divide-neutral-100">
                            <div class="p-4 sm:p-6 hover:bg-neutral-50 transition-colors flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-6">
                                <div class="font-medium text-neutral-500 sm:text-neutral-600 w-full sm:w-1/3 flex-shrink-0">Nama Perusahaan</div>
                                <div class="text-neutral-900 font-semibold break-words">PT Idea Digital Creative</div>
                            </div>
                            <div class="p-4 sm:p-6 hover:bg-neutral-50 transition-colors flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-6">
                                <div class="font-medium text-neutral-500 sm:text-neutral-600 w-full sm:w-1/3 flex-shrink-0">Bidang Usaha</div>
                                <div class="text-neutral-900 break-words">Pengembangan Perangkat Lunak dan Solusi Digital</div>
                            </div>
                            <div class="p-4 sm:p-6 hover:bg-neutral-50 transition-colors flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-6">
                                <div class="font-medium text-neutral-500 sm:text-neutral-600 w-full sm:w-1/3 flex-shrink-0">Status Badan Hukum</div>
                                <div class="text-neutral-900 break-words">Perseroan Terbatas (PT)</div>
                            </div>
                            <div class="p-4 sm:p-6 hover:bg-neutral-50 transition-colors flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-6">
                                <div class="font-medium text-neutral-500 sm:text-neutral-600 w-full sm:w-1/3 flex-shrink-0">Nomor SK Kemenkumham</div>
                                <div class="text-neutral-900 break-words">AHU-009822.AH.01.31.Tahun 2025</div>
                            </div>
                            <div class="p-4 sm:p-6 hover:bg-neutral-50 transition-colors flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-6">
                                <div class="font-medium text-neutral-500 sm:text-neutral-600 w-full sm:w-1/3 flex-shrink-0">Nomor Induk Berusaha (NIB)</div>
                                <div class="text-neutral-900 break-words">2612220024749</div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </main>

        <FooterSection />
    </div>
</template>
