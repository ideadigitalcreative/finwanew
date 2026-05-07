<template>
    <section class="relative overflow-hidden">
        <!-- Grid Background -->
        <div class="absolute inset-0 z-0 pointer-events-none">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:40px_40px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_70%,transparent_100%)]"></div>
        </div>

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 relative z-10">
            <div
                class="grid grid-cols-1 items-center gap-x-10 gap-y-8 pt-8 pb-16 sm:pt-12 sm:pb-16 md:gap-y-10 md:pt-14 md:pb-20 lg:grid-cols-12 lg:items-start lg:gap-16 lg:gap-y-10 lg:pt-6 lg:pb-20"
            >
                <div class="flex flex-col justify-center lg:col-span-7 lg:justify-start lg:pt-1">
                    <div class="mb-1.5 w-max animate-fade-in-up">
                        <div class="rounded-full bg-gradient-to-r from-[#ffe4cc] to-[#ffd4a8] p-[1px]">
                            <div class="inline-flex items-center gap-2 rounded-full border border-[#f5d4b8] bg-[#fff3e6]/95 px-3 py-1.5 text-xs font-medium text-[#8a4608] backdrop-blur-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-3.5 w-3.5 shrink-0 text-[#fa8605]"><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"></path><path d="M7 11h10"></path><path d="M7 15h6"></path><path d="M7 7h8"></path></svg>
                                Catat Keuangan via WhatsApp
                            </div>
                        </div>
                    </div>
                    
                    <!-- Users Badge -->
                    <div class="mt-2 md:mt-3 w-fit flex flex-row items-center gap-1.5 md:gap-2.5 p-1 pr-3.5 md:p-1.5 md:pr-5 rounded-full bg-gray-100 border border-gray-200">
                        <div class="w-[58px] md:w-[78px] h-[28px] md:h-[36px] flex items-center justify-center shrink-0">
                            <div class="flex -space-x-1.5 md:-space-x-2">
                                <img
                                    src="/2.jpg"
                                    alt=""
                                    width="36"
                                    height="36"
                                    class="relative z-[1] h-7 w-7 shrink-0 rounded-full object-cover ring-2 ring-white md:h-9 md:w-9"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <img
                                    src="/1.jpg"
                                    alt=""
                                    width="36"
                                    height="36"
                                    class="relative z-[2] h-7 w-7 shrink-0 rounded-full object-cover ring-2 ring-white md:h-9 md:w-9"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <img
                                    src="/3.jpg"
                                    alt=""
                                    width="36"
                                    height="36"
                                    class="relative z-[3] h-7 w-7 shrink-0 rounded-full object-cover ring-2 ring-white md:h-9 md:w-9"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                        </div>
                        <p class="my-auto self-center text-xs font-semibold text-[#355d2a] md:text-sm" style="line-height: 1;">+600 pengguna</p>
                    </div>
                    
                    <h1 class="mt-4 md:mt-6 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.15]">
                        <span class="bg-gradient-to-br from-[#59c323] via-[#4da818] to-[#3d9014] bg-clip-text text-transparent">Catat Keuangan via WhatsApp dengan Mudah & Otomatis</span>
                    </h1>
                    <p class="mt-3 text-lg font-medium text-[#355d2a] sm:text-xl md:mt-4">Cara baru catat keuangan dari WA. Tinggal chat, langsung tercatat!</p>
                    <p class="mt-4 max-w-lg text-base leading-relaxed text-[#4a6240] sm:mt-6 sm:text-lg md:mt-6">
                        Aplikasi pencatatan keuangan lewat WhatsApp paling praktis.<br>
                        Kirim pesan pengeluaran & pemasukan, FinWa otomatis mencatat ke dashboard pintar.
                    </p>
                    <div class="mt-5 md:mt-8 flex flex-row flex-wrap items-center gap-2 md:gap-2.5">
                        <a href="https://play.google.com/store/apps/details?id=com.idea.finwa" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg transition-all duration-200 hover:-translate-y-0.5 hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4da818] focus-visible:ring-offset-2" aria-label="Download di Google Play">
                            <img src="/Google_Play.webp" alt="Download di Google Play" class="h-10 md:h-12 w-auto" width="155" height="48" loading="eager" decoding="async" />
                        </a>
                        <a href="/finwa/launch" class="inline-flex items-center rounded-lg transition-all duration-200 hover:-translate-y-0.5 hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4da818] focus-visible:ring-offset-2" aria-label="Instal FinWa sebagai aplikasi (PWA)">
                            <img src="/app-store.png" alt="Instal FinWa sebagai aplikasi (PWA)" class="h-10 md:h-12 w-auto" width="120" height="40" loading="eager" decoding="async" />
                        </a>
                    </div>
                </div>

                <!-- Hero Visual -->
                <div class="relative flex items-center justify-center px-4 md:px-0 lg:col-span-5 lg:items-start lg:justify-end lg:pt-1">
                    <div class="absolute -inset-10 -z-10 opacity-40 blur-3xl" style="background: radial-gradient(circle at center, rgb(77 168 24 / 0.2), transparent 70%);"></div>
                    <!-- WhatsApp Chat Mockup -->
                    <div class="relative transform transition-transform hover:scale-[1.02] duration-500">
                        <WhatsAppChatMockup />
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import WhatsAppChatMockup from './WhatsAppChatMockup.vue'
</script>
