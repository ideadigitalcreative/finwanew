<template>
    <section class="relative overflow-hidden">
        <!-- Grid Background -->
        <div class="absolute inset-0 z-0 pointer-events-none">
            <div class="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:40px_40px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_70%,transparent_100%)]"></div>
        </div>

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-12 lg:gap-16 pt-16 pb-20 gap-x-10 gap-y-12 items-center">
                <div class="flex flex-col justify-center lg:col-span-7">
                    <div class="w-max animate-fade-in-up">
                        <div class="bg-gradient-to-r from-emerald-500/20 to-cyan-500/20 p-[1px] rounded-full">
                            <div class="inline-flex items-center gap-2 text-xs font-medium text-emerald-700 bg-emerald-50/80 backdrop-blur-sm ring-1 ring-emerald-600/20 px-3 py-1.5 rounded-full">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5"><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"></path><path d="M7 11h10"></path><path d="M7 15h6"></path><path d="M7 7h8"></path></svg>
                                Catat Keuangan via WhatsApp
                            </div>
                        </div>
                    </div>
                    
                    <!-- Users Badge -->
                    <div class="mt-3 w-fit flex flex-row items-center gap-1 md:gap-2 p-0.5 pr-3 md:p-1 md:pr-4 rounded-full bg-gray-100 border border-gray-200">
                        <div class="w-[50px] md:w-[70px] h-[24px] md:h-[32px] flex items-center justify-center shrink-0">
                            <div class="flex -space-x-1.5">
                                <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-gradient-to-br from-emerald-400 to-cyan-400 ring-2 ring-white flex items-center justify-center text-sm md:text-base">👨</div>
                                <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-gradient-to-br from-blue-400 to-purple-400 ring-2 ring-white flex items-center justify-center text-sm md:text-base">👩</div>
                                <div class="w-6 h-6 md:w-8 md:h-8 rounded-full bg-gradient-to-br from-orange-400 to-pink-400 ring-2 ring-white flex items-center justify-center text-sm md:text-base">🧑</div>
                            </div>
                        </div>
                        <p class="font-semibold text-[11px] md:text-xs text-gray-700 self-center my-auto" style="line-height: 1;">+600 pengguna</p>
                    </div>
                    
                    <h1 class="mt-6 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.15]">
                        <span class="bg-gradient-to-br from-emerald-600 via-emerald-500 to-cyan-600 bg-clip-text text-transparent">Catat Keuangan via WhatsApp dengan Mudah & Otomatis</span>
                    </h1>
                    <p class="text-lg sm:text-xl text-gray-700 mt-4 font-medium">Cara baru catat keuangan dari WA. Tinggal chat, langsung tercatat!</p>
                    <p class="sm:text-lg text-base text-gray-600 mt-6 leading-relaxed max-w-lg">
                        Aplikasi pencatatan keuangan lewat WhatsApp paling praktis.<br>
                        Kirim pesan pengeluaran & pemasukan, FinWa otomatis mencatat ke dashboard pintar.
                    </p>
                    <div class="mt-8 flex flex-row gap-2 md:gap-3">
                        <a href="https://wa.me/6285762000079?text=Halo%20kak%2C%20saya%20mau%20daftar%20FinWa.%20Ketik%20*Daftar*%20untuk%20mulai%20ya!" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center gap-1.5 md:gap-2 rounded-xl px-3 py-2 md:px-5 md:py-2.5 text-xs md:text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/30 hover:-translate-y-0.5 transition-all duration-200" style="background-color: oklch(0.65 0.19 137.46);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 md:w-4 md:h-4"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"></path></svg>
                            Coba Gratis Sekarang
                        </a>
                        <a href="#fitur" class="inline-flex items-center justify-center gap-1.5 md:gap-2 rounded-xl px-3 py-2 md:px-5 md:py-2.5 text-xs md:text-sm font-semibold text-gray-700 bg-white ring-1 ring-gray-200 hover:bg-gray-50 hover:ring-gray-300 transition-all duration-200 shadow-sm hover:shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 md:w-4 md:h-4"><path d="M9 9.003a1 1 0 0 1 1.517-.859l4.997 2.997a1 1 0 0 1 0 1.718l-4.997 2.997A1 1 0 0 1 9 14.996z"></path><circle cx="12" cy="12" r="10"></circle></svg>
                            Pelajari
                        </a>
                    </div>
                </div>

                <!-- Hero Visual -->
                <div class="relative lg:col-span-5 flex items-center justify-center lg:justify-end px-4 md:px-0">
                    <div class="absolute -inset-10 -z-10 opacity-40 blur-3xl" style="background: radial-gradient(circle at center, rgba(16,185,129,0.15), transparent 70%);"></div>
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
