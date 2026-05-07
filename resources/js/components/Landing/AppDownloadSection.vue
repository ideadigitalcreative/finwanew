<template>
    <section class="relative overflow-hidden py-12 sm:py-16">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="relative overflow-hidden rounded-[2.5rem] bg-white border border-neutral-100 px-8 py-6 sm:px-16 sm:py-8 lg:flex lg:items-center lg:gap-x-20 shadow-[0_32px_64px_-12px_rgba(0,0,0,0.08)]">
                <!-- Decorative background elements -->
                <div class="absolute -right-20 -top-20 h-96 w-96 rounded-full bg-[var(--fw-100)]/40 blur-3xl"></div>
                <div class="absolute -bottom-20 -left-20 h-96 w-96 rounded-full bg-[var(--fw-50)]/50 blur-3xl"></div>
                
                <div class="relative z-10 lg:flex-auto">
                    <h2 class="text-3xl font-bold tracking-tight text-neutral-900 sm:text-4xl lg:text-4xl leading-tight">
                        Catat Keuangan Otomatis Hanya Lewat WhatsApp!
                    </h2>
                    <p class="mt-4 text-base leading-7 text-neutral-600 max-w-xl">
                        Gak perlu ribet buka aplikasi lain. Cukup chat pengeluaran dan pemasukan, FinWa yang beresin sisanya. Download aplikasinya sekarang!
                    </p>
                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <a 
                            href="https://play.google.com/store/apps/details?id=com.idea.finwa" 
                            target="_blank" 
                            rel="noopener noreferrer"
                            class="transition-transform hover:scale-105 active:scale-95"
                        >
                            <img src="/Google_Play.webp" alt="Download FinWa di Google Play" class="h-[44px] md:h-[52px] w-auto drop-shadow-lg" />
                        </a>
                        <a 
                            href="/finwa/launch" 
                            class="transition-transform hover:scale-105 active:scale-95"
                        >
                            <img src="/app-store.png" alt="Download FinWa di App Store" class="h-[44px] md:h-[52px] w-auto drop-shadow-lg" />
                        </a>
                    </div>
                </div>
                
                <!-- Background Image / Illustration -->
                <div class="relative mt-8 lg:mt-0 lg:flex-none">
                    <div class="flex items-center justify-center">
                         <div class="relative w-full max-w-[420px] transform hover:scale-105 transition-transform duration-700">
                             <!-- Background Image Decoration -->
                             <img 
                                src="/bd.png" 
                                alt="" 
                                class="absolute -inset-20 w-[140%] h-[140%] max-w-none object-contain opacity-50 pointer-events-none -translate-x-1/10"
                             />
                             
                             <!-- Background Glow -->
                             <div class="absolute -inset-10 bg-[var(--fw-200)]/30 blur-3xl rounded-full animate-pulse"></div>
                             
                             <img 
                                src="/Untitled design (1).png" 
                                alt="FinWa App" 
                                class="relative z-10 w-full h-auto drop-shadow-[0_20px_50px_rgba(0,0,0,0.15)]"
                             />
                         </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
// Logic can be added here if needed
</script>

<style scoped>
/* Ensure we use the landing brand colors */
:root {
    --fw-h: 137.46;
    --fw-600: oklch(0.6 0.17 var(--fw-h));
    --fw-800: oklch(0.4 0.11 var(--fw-h));
    --fw-400: oklch(0.76 0.14 var(--fw-h));
}
</style>
