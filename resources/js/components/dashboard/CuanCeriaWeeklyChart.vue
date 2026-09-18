<script setup lang="ts">
import { computed } from 'vue';

interface DayFlow {
    day: string; // 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'
    income: number;
    expense: number;
    isHot?: boolean;
}

interface Props {
    periodLabel?: string;
    flowData?: DayFlow[];
    peakExpenseNote?: string;
}

const props = withDefaults(defineProps<Props>(), {
    periodLabel: 'Minggu ini',
    flowData: () => [],
    peakExpenseNote: '',
});

// Normalisasi tinggi bar maksimal 100%
const chartItems = computed(() => {
    if (!props.flowData || props.flowData.length === 0) return [];
    
    // Temukan pengeluaran tertinggi untuk menandai hot day jika belum ada
    let maxExp = 0;
    props.flowData.forEach(d => {
        if (d.expense > maxExp) maxExp = d.expense;
    });

    return props.flowData.map(d => ({
        ...d,
        isHot: d.isHot || (d.expense === maxExp && maxExp > 0),
        incomeHeight: `${Math.max(10, Math.min(100, d.income))}%`,
        expenseHeight: `${Math.max(10, Math.min(100, d.expense))}%`,
    }));
});

// Label hari tetap urut Sen–Min
const ordered = computed(() => {
    const order = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
    const items = chartItems.value;
    return order
        .map(day => items.find(i => i.day === day))
        .filter(Boolean) as DayFlow[];
});

const hasData = computed(() => ordered.value.length > 0);
</script>

<template>
    <!-- Arus Kas Mingguan (Dual-Bar Chart) -->
    <section class="p-4 rounded-2xl bg-white dark:bg-card border border-[#eae8e2] dark:border-border/60 flex flex-col gap-3 font-['Plus_Jakarta_Sans',sans-serif]">
        <div class="flex items-center justify-between">
            <div class="flex flex-col">
                <span class="text-sm md:text-base font-bold text-[#1b1c19] dark:text-foreground">Aktivitas Mingguan</span>
                <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground font-semibold">{{ periodLabel }}</span>
            </div>
            <div class="flex items-center gap-2.5">
                <div class="flex items-center gap-1">
                    <span class="w-2.5 h-2.5 rounded-full bg-[#51fac1]"></span>
                    <span class="text-[10px] font-bold text-[#4d4634] dark:text-muted-foreground">Masuk</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="w-2.5 h-2.5 rounded-full bg-[#ffd23f]"></span>
                    <span class="text-[10px] font-bold text-[#4d4634] dark:text-muted-foreground">Keluar</span>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div v-if="!hasData" class="flex items-center justify-center h-36 text-center">
            <div class="flex flex-col items-center gap-1.5">
                <span class="material-symbols-outlined text-3xl text-[#eae8e2] dark:text-muted-foreground">bar_chart</span>
                <span class="text-xs text-[#4d4634] dark:text-muted-foreground font-medium">Belum ada transaksi minggu ini</span>
            </div>
        </div>

        <!-- Visual Dual-Bar Chart Representation -->
        <div v-else class="pt-2 pb-1 flex items-end justify-between h-36 gap-2">
            <div 
                v-for="item in ordered" 
                :key="item.day"
                class="flex flex-col items-center gap-1.5 flex-1 h-full justify-end relative"
            >
                <span 
                    v-if="item.isHot" 
                    class="absolute -top-3 px-1.5 py-0.2 rounded-full bg-[#ad2c4f] text-white text-[9px] font-extrabold shadow-xs"
                >
                    Hot
                </span>

                <div class="w-full flex items-end justify-center gap-1 h-28">
                    <!-- Income Bar (hijau mint) -->
                    <div 
                        class="w-2.5 bg-[#51fac1] rounded-t-full transition-all duration-500" 
                        :style="{ height: item.incomeHeight }"
                    ></div>
                    <!-- Expense Bar (kuning tema) -->
                    <div 
                        class="rounded-t-full transition-all duration-500 shadow-xs"
                        :class="item.isHot ? 'w-3 bg-[#ad2c4f]' : 'w-2.5 bg-[#ffd23f]'" 
                        :style="{ height: item.expenseHeight }"
                    ></div>
                </div>

                <span 
                    class="text-[10px] font-bold"
                    :class="item.isHot ? 'text-[#ad2c4f]' : 'text-[#4d4634] dark:text-muted-foreground'"
                >
                    {{ item.day }}
                </span>
            </div>
        </div>

        <!-- Alert Note Puncak Pengeluaran -->
        <div 
            v-if="peakExpenseNote" 
            class="p-2.5 rounded-xl bg-[#ffd9dd]/30 dark:bg-rose-950/20 border border-[#ffd9dd]/50 dark:border-rose-900/30 flex items-center justify-between"
        >
            <div class="flex items-center gap-2 min-w-0">
                <span class="material-symbols-outlined text-[18px] text-[#ad2c4f]">info</span>
                <span class="text-xs text-[#1b1c19] dark:text-foreground truncate">
                    {{ peakExpenseNote }}
                </span>
            </div>
            <span class="material-symbols-outlined text-[16px] text-[#ad2c4f] flex-shrink-0">chevron_right</span>
        </div>
    </section>
</template>