<template>
    <section id="cara-pakai" class="relative border-t border-gray-200/60 bg-gradient-to-b from-gray-50/40 to-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="max-w-2xl">
                <div
                    class="gradient-badge flex w-fit items-center gap-[6px] rounded-full border border-[#f0c9a8] bg-[#fff3e6]/95 p-[8px_16px] backdrop-blur-sm"
                >
                    <ListOrdered class="h-5 w-5 shrink-0 text-[#fa8605]" />
                    <p class="text-xs font-medium text-[#8a4608] md:text-sm">4 Langkah Mudah</p>
                </div>
                <h2 class="mt-3 text-3xl md:text-4xl font-bold tracking-tight text-gray-900 leading-tight">
                    Cara Mencatat Pengeluaran di WhatsApp
                </h2>
                <p class="mt-3 text-gray-600">Hanya 4 langkah untuk mulai kelola keuanganmu dengan rapi.</p>
            </div>

            <div class="mt-14 flex flex-col lg:flex-row lg:items-start lg:justify-between lg:gap-0">
                <template v-for="(step, index) in steps" :key="step.n">
                    <article
                        class="group relative flex-1 min-w-0 overflow-hidden rounded-2xl border border-gray-200/90 bg-white px-5 pt-5 pb-5 transition-colors duration-200 hover:border-[color:var(--fw-400)] sm:px-6 sm:pt-6 sm:pb-6"
                    >
                        <!-- Latar gradasi tipis (sama nuansa kartu fitur) -->
                        <div
                            class="pointer-events-none absolute inset-0 z-0 bg-gradient-to-tl from-[var(--fw-cardwash-from)] via-[var(--fw-cardwash-mid)] to-white"
                            aria-hidden="true"
                        />
                        <!-- Angka besar: geser ke kanan-bawah agar terpotong sudut kartu -->
                        <div
                            class="pointer-events-none absolute -bottom-8 -right-5 z-0 select-none font-bold tabular-nums leading-[0.82] text-[var(--fw-icon-faint)] transition-colors duration-300 group-hover:text-[var(--fw-icon-faint-hover)] sm:-bottom-12 sm:-right-8 text-[7.5rem] sm:text-[9rem] lg:text-[8rem] xl:text-[9.25rem]"
                            aria-hidden="true"
                        >
                            {{ step.n }}
                        </div>
                        <!-- Angka di belakang (z-0); teks lebar normal supaya tidak memanjang ke bawah -->
                        <div class="relative z-10 w-full min-w-0 px-0 pb-1 pt-0">
                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--fw-50)] text-[var(--fw-700)] ring-1 ring-[color:var(--fw-ring-soft)] transition-colors group-hover:bg-[var(--fw-100)]"
                                >
                                    <component :is="step.icon" class="h-5 w-5" :stroke-width="1.75" />
                                </div>
                                <div class="min-w-0 flex-1 pr-1 sm:pr-2">
                                    <h3 class="text-lg font-bold tracking-tight text-[var(--fw-700)]">{{ step.title }}</h3>
                                    <p class="mt-2 text-sm leading-snug text-gray-600 break-words sm:leading-relaxed">
                                        {{ step.description }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </article>

                    <!-- Penghubung antar langkah -->
                    <div
                        v-if="index < steps.length - 1"
                        class="flex shrink-0 items-center justify-center py-3 lg:w-12 lg:self-center lg:py-0 xl:w-16"
                        aria-hidden="true"
                    >
                        <!-- Mobile: garis vertikal + panah -->
                        <div class="flex flex-col items-center gap-0.5 lg:hidden">
                            <div class="h-6 w-px rounded-full bg-gradient-to-b from-[var(--fw-400)] to-[var(--fw-600)]" />
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--fw-650)] text-white shadow-sm ring-1 ring-[color:var(--fw-ring-35)]">
                                <ChevronDown class="h-5 w-5" :stroke-width="2.25" />
                            </span>
                            <div class="h-6 w-px rounded-full bg-gradient-to-b from-[var(--fw-600)] to-[var(--fw-300)]" />
                        </div>
                        <!-- Desktop: garis horizontal + chevron -->
                        <div class="relative hidden h-0.5 w-full min-w-[1.5rem] max-w-[4rem] rounded-full bg-gradient-to-r from-[var(--fw-300)] via-[var(--fw-500)] to-[var(--fw-200)] lg:block xl:max-w-[5rem]">
                            <span
                                class="absolute inset-y-0 -right-1 my-auto flex h-7 w-7 items-center justify-center rounded-full bg-[var(--fw-650)] text-white shadow-sm ring-1 ring-[color:var(--fw-ring-35)]"
                            >
                                <ChevronRight class="h-3.5 w-3.5" :stroke-width="2.5" />
                            </span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { markRaw } from 'vue';
import type { Component } from 'vue';
import {
    ChevronDown,
    ChevronRight,
    Cpu,
    LayoutDashboard,
    ListOrdered,
    MessagesSquare,
    UserPlus,
} from 'lucide-vue-next';

type Step = {
    n: number;
    title: string;
    description: string;
    icon: Component;
};

const steps: Step[] = [
    {
        n: 1,
        title: 'Daftar',
        description:
            'Buat akun gratis dan hubungkan nomor WhatsApp-mu.',
        icon: markRaw(UserPlus),
    },
    {
        n: 2,
        title: 'Chat / Voice',
        description: 'Kirim pesan suara untuk catat pengeluaran dan pemasukan.',
        icon: markRaw(MessagesSquare),
    },
    {
        n: 3,
        title: 'Otomatis',
        description: 'AI FinWa langsung mengenali dan mencatat transaksimu secara otomatis.',
        icon: markRaw(Cpu),
    },
    {
        n: 4,
        title: 'Pantau',
        description:
            'Buka dashboard kapan saja untuk lihat grafik, ringkasan, dan laporan keuanganmu yang rapi.',
        icon: markRaw(LayoutDashboard),
    },
];
</script>
