<script setup lang="ts">
import { computed } from 'vue';
import { Heart, TrendingUp } from 'lucide-vue-next';

interface Props {
    totalIncome: number;
    totalExpense: number;
    incomeChange?: number;
    expenseChange?: number;
    period?: string;
}

const props = withDefaults(defineProps<Props>(), {
    incomeChange: 0,
    expenseChange: 0,
    period: '',
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const netSavings = computed(() => props.totalIncome - props.totalExpense);

const savingRate = computed(() => {
    if (props.totalIncome <= 0) return 0;
    return Math.round((netSavings.value / props.totalIncome) * 100);
});

const clampedRate = computed(() => Math.max(0, Math.min(100, savingRate.value)));

const arcLength = 204;
const dashOffset = computed(() => arcLength - (arcLength * clampedRate.value) / 100);

const healthColor = computed(() => {
    const rate = savingRate.value;
    if (rate >= 25) return { text: 'text-emerald-600 dark:text-emerald-400', stroke: '#10b981' };
    if (rate >= 10) return { text: 'text-amber-600 dark:text-amber-400', stroke: '#f59e0b' };
    if (rate >= 0)  return { text: 'text-orange-600 dark:text-orange-400', stroke: '#f97316' };
    return { text: 'text-red-600 dark:text-red-400', stroke: '#ef4444' };
});

const healthLabel = computed(() => {
    const rate = savingRate.value;
    if (rate >= 25) return 'Sangat Sehat';
    if (rate >= 10) return 'Cukup Sehat';
    if (rate >= 0)  return 'Perlu Perhatian';
    return 'Defisit';
});

const healthBadgeClass = computed(() => {
    const rate = savingRate.value;
    if (rate >= 25) return 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400';
    if (rate >= 10) return 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400';
    if (rate >= 0)  return 'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-400';
    return 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-400';
});
</script>

<template>
    <div class="p-4 md:p-5 rounded-2xl bg-card border border-border/60 h-full flex flex-col justify-between gap-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5">
                <span class="material-symbols-outlined text-xl text-emerald-500">spa</span>
                <div>
                    <h3 class="text-sm md:text-base font-bold text-foreground">Kesehatan Finansial</h3>
                    <p class="text-[10px] text-muted-foreground">{{ period || 'Bulan ini' }}</p>
                </div>
            </div>
            <span :class="['px-2.5 py-0.5 rounded-full text-xs font-bold', healthBadgeClass]">
                {{ healthLabel }}
            </span>
        </div>

        <!-- Gauge SVG Playful -->
        <div class="flex flex-col items-center justify-center my-auto">
            <div class="relative flex items-center justify-center">
                <svg width="180" height="100" viewBox="0 0 160 90">
                    <path
                        d="M15,80 A65,65 0 0,1 145,80"
                        fill="none"
                        stroke="currentColor"
                        class="text-muted"
                        stroke-width="14"
                        stroke-linecap="round"
                    />
                    <path
                        d="M15,80 A65,65 0 0,1 145,80"
                        fill="none"
                        :stroke="healthColor.stroke"
                        stroke-width="14"
                        stroke-linecap="round"
                        stroke-dasharray="204"
                        :stroke-dashoffset="dashOffset"
                        class="transition-all duration-1000 ease-out"
                    />
                </svg>
                <div class="absolute bottom-2 flex flex-col items-center">
                    <span class="text-2xl font-black" :class="healthColor.text">
                        {{ clampedRate }}%
                    </span>
                    <span class="text-[10px] text-muted-foreground uppercase font-bold tracking-wider">Tabungan</span>
                </div>
            </div>
        </div>

        <div class="p-2.5 rounded-xl bg-muted/40 flex items-center justify-between">
            <span class="text-xs text-muted-foreground">Tabungan Bersih:</span>
            <span class="text-xs font-extrabold text-foreground">{{ formatCurrency(netSavings) }}</span>
        </div>
    </div>
</template>
