<script setup lang="ts">
import { computed } from 'vue';

interface Props {
    income: number;
    period: string;
    // null = tidak ada data bulan lalu sebagai pembanding
    changePercent: number | null;
    gained: number;
}

const props = defineProps<Props>();

const formatFull = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatCompact = (amount: number) => {
    return 'Rp ' + new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(amount);
};

const changeLabel = computed(() => {
    if (props.changePercent === null || props.changePercent === undefined) return 'Belum ada data bulan lalu';
    const v = props.changePercent;
    const sign = v > 0 ? '+' : v < 0 ? '−' : '';
    return `${sign}${Math.abs(v).toLocaleString('id-ID')}% vs bulan lalu`;
});
</script>

<template>
    <div class="bg-[#51fac1] text-[#00513a] dark:bg-emerald-950/50 dark:text-emerald-200 border border-white/60 dark:border-emerald-800/40 rounded-2xl p-4 relative overflow-hidden flex flex-col justify-between font-['Plus_Jakarta_Sans',sans-serif] flex-1 min-w-0 @container transition-all duration-300">
        <!-- Decorative Glow -->
        <div class="absolute -right-6 -bottom-6 w-24 h-24 rounded-full bg-white/30 dark:bg-emerald-400/10 blur-xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-extrabold uppercase tracking-wider">Pemasukan Bulan Ini</span>
                <div class="w-7 h-7 shrink-0 rounded-full bg-white dark:bg-white/10 flex items-center justify-center shadow-sm" aria-hidden="true">
                    <span class="material-symbols-outlined text-[16px]">arrow_downward</span>
                </div>
            </div>
            <div class="text-xl md:text-2xl font-extrabold tracking-tight leading-tight tabular-nums" :title="formatFull(income)">
                <!-- Format ringkas bila kartu sempit supaya nominal tidak terpotong -->
                <span class="@[15rem]:hidden whitespace-nowrap">{{ formatCompact(income) }}</span>
                <span class="hidden @[15rem]:inline whitespace-nowrap">{{ formatFull(income) }}</span>
            </div>
            <div class="flex items-start gap-1 text-xs font-semibold leading-snug">
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ changePercent === null ? 'info' : changePercent >= 0 ? 'trending_up' : 'trending_down' }}</span>
                <span>{{ changeLabel }}</span>
            </div>
        </div>
    </div>
</template>
