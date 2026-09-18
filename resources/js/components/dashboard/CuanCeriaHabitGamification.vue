<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    streakDays?: number;
    activeDays?: string[]; // e.g. ['Sn', 'Rb'] — only days with transactions
}

const props = withDefaults(defineProps<Props>(), {
    streakDays: 0,
    activeDays: () => [],
});

const daysList = [
    { key: 'Sn', label: 'Sn' },
    { key: 'Sl', label: 'Sl' },
    { key: 'Rb', label: 'Rb' },
    { key: 'Km', label: 'Km' },
    { key: 'Jm', label: 'Jm' },
    { key: 'Sb', label: 'Sb' },
    { key: 'Mg', label: 'Mg', isSpecial: true },
];

const isActiveDay = (key: string) => props.activeDays.includes(key);
const activeCount = computed(() => props.activeDays.length);
const peaceIndex = computed(() => Math.min(100, Math.round((activeCount.value / 7) * 100)));
</script>

<template>
    <!-- 5. Gamifikasi & Habit Highlights (real data) -->
    <section class="flex flex-col gap-2 font-['Plus_Jakarta_Sans',sans-serif]">
        <div class="p-4 rounded-2xl bg-[#f5f3ee] dark:bg-card border border-[#eae8e2] dark:border-border/60 flex flex-col gap-3">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-xl">🔥</span>
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-[#1b1c19] dark:text-foreground">
                            {{ streakDays }} Hari Tertib Nyatet!
                        </span>
                        <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground font-semibold">
                            {{ activeCount > 0 ? `${activeCount} dari 7 hari minggu ini sudah dicatat` : 'Mulai catat hari ini untuk mulai streak!' }}
                        </span>
                    </div>
                </div>
                <span 
                    v-if="streakDays > 0"
                    class="px-2.5 py-1 rounded-full bg-[#ffe089] text-[#241a00] text-[10px] font-extrabold shadow-xs"
                >
                    +{{ Math.min(streakDays * 5, 100) }} XP
                </span>
            </div>

            <!-- Streak Days Indicator (Sn - Mg) — only active days highlighted -->
            <div class="grid grid-cols-7 gap-1.5 text-center">
                <div 
                    v-for="d in daysList" 
                    :key="d.key" 
                    class="flex flex-col items-center gap-1"
                >
                    <span 
                        class="text-[10px] font-bold"
                        :class="d.isSpecial && isActiveDay(d.key) ? 'text-[#745c00] dark:text-primary' : 'text-[#4d4634] dark:text-muted-foreground'"
                    >
                        {{ d.label }}
                    </span>
                    <!-- Active day: green tosca with check -->
                    <div 
                        v-if="isActiveDay(d.key) && !d.isSpecial"
                        class="w-7 h-7 rounded-full bg-[#51fac1] text-[#007152] flex items-center justify-center shadow-xs"
                    >
                        <span class="material-symbols-outlined text-[14px]">check</span>
                    </div>
                    <!-- Active Sunday: gold star bounce -->
                    <div 
                        v-else-if="isActiveDay(d.key) && d.isSpecial"
                        class="w-7 h-7 rounded-full bg-[#ffd23f] text-[#725a00] flex items-center justify-center shadow-sm animate-bounce"
                    >
                        <span class="material-symbols-outlined text-[14px]">star</span>
                    </div>
                    <!-- Inactive day: muted empty circle -->
                    <div 
                        v-else
                        class="w-7 h-7 rounded-full bg-[#eae8e2] dark:bg-muted/60 text-[#b0ad9e] dark:text-muted-foreground/50 flex items-center justify-center"
                    >
                        <span class="material-symbols-outlined text-[14px]">close</span>
                    </div>
                </div>
            </div>

            <!-- Financial Peace Index Badge -->
            <div class="mt-1 pt-2.5 border-t-0 flex items-center justify-between bg-white dark:bg-muted/40 px-3 py-2 rounded-xl">
                <div class="flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px] text-[#006c4f]">sentiment_very_satisfied</span>
                    <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground font-bold">Indeks Ketenangan Finansial:</span>
                </div>
                <span class="text-xs font-extrabold text-[#006c4f] dark:text-emerald-400">
                    {{ peaceIndex }} / 100
                </span>
            </div>
        </div>
    </section>
</template>
