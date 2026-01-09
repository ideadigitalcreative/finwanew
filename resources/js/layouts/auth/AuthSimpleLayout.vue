<script setup lang="ts">
import { home } from '@/routes';
import { Link } from '@inertiajs/vue3';
import BackgroundAccents from '@/components/Landing/BackgroundAccents.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="antialiased bg-gray-50 text-neutral-900 selection:bg-emerald-500/30 selection:text-emerald-900 min-h-svh flex flex-col items-center justify-center p-6 md:p-10"
        style="font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, Noto Sans, Apple Color Emoji, Segoe UI Emoji;"
    >
        <BackgroundAccents />
        
        <div class="w-full max-w-sm relative z-10">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium group"
                    >
                        <img src="/logo.png" alt="Logo FinWa Aplikasi Keuangan" class="h-12 w-auto mb-1" />
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-semibold text-neutral-900">{{ title }}</h1>
                        <p class="text-center text-sm text-neutral-600">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
