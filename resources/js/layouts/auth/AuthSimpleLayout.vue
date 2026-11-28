<script setup lang="ts">
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="antialiased bg-[#fbf9f3] dark:bg-[#1b1c19] text-[#1b1c19] dark:text-[#f5f3ee] selection:bg-[#ffd23f]/40 selection:text-[#574500] min-h-svh flex flex-col items-center justify-center p-4 sm:p-6 md:p-10 font-['Plus_Jakarta_Sans',sans-serif] relative overflow-hidden"
    >
        <!-- Background Accents Halus Bergaya Finwa -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
            <div class="absolute -top-[15%] -left-[10%] h-[45%] w-[45%] rounded-full blur-[110px] bg-[#ffd23f]/15"></div>
            <div class="absolute top-[30%] -right-[15%] h-[40%] w-[40%] rounded-full blur-[100px] bg-[#51fac1]/15"></div>
            <div class="absolute -bottom-[15%] left-[25%] h-[45%] w-[45%] rounded-full blur-[120px] bg-[#ffd23f]/10"></div>
        </div>
        
        <div class="w-full max-w-md relative z-10">
            <div class="flex flex-col gap-6">
                <!-- Header Logo -->
                <div class="flex flex-col items-center gap-2">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center group transition-transform duration-200 hover:scale-105"
                    >
                        <img src="/logo.png" alt="Logo FinWa Aplikasi Keuangan" class="h-12 sm:h-14 w-auto mb-1" />
                    </Link>
                    <div class="space-y-1 text-center">
                        <h1 class="text-xl sm:text-2xl font-bold text-[#1b1c19] dark:text-white tracking-tight">{{ title }}</h1>
                        <p v-if="description" class="text-center text-xs sm:text-sm text-[#4d4634]/70 dark:text-white/60">
                            {{ description }}
                        </p>
                    </div>
                </div>

                <!-- Kartu Konten Utama (Gaya Segar Seperti Hero Card Dashboard) -->
                <div class="relative overflow-hidden rounded-3xl border border-[#eae8e2] dark:border-white/10 bg-white/95 dark:bg-[#23231f]/95 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:shadow-[0_8px_30px_rgb(0,0,0,0.2)] p-6 sm:p-8 backdrop-blur-md">
                    <!-- Decorative Backdrop Glow yang Segar -->
                    <div class="absolute -right-12 -top-12 w-44 h-44 rounded-full bg-[#ffd23f]/25 dark:bg-[#ffd23f]/15 blur-2xl pointer-events-none"></div>
                    <div class="absolute -left-10 -bottom-10 w-40 h-40 rounded-full bg-[#51fac1]/30 dark:bg-[#51fac1]/15 blur-2xl pointer-events-none"></div>

                    <div class="relative z-10">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
