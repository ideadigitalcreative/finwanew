<script setup lang="ts">
import { computed } from 'vue';

interface ExpenseItem {
    label: string;
    amount: number;
    percent: number;
    colorClass: string;
    bgTrackClass: string;
}

interface Props {
    categories?: Array<{ label: string; amount: number; percent: number }>;
    totalExpense?: number;
}

const props = withDefaults(defineProps<Props>(), {
    categories: () => [],
    totalExpense: 0,
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
};

// Color palette playful & segar khas CuanCeria: kuning cerah, pink coral, toska, ungu, jingga, hijau mint
const colorPalette = [
    { dot: 'bg-[#ffd23f]', bar: 'bg-[#ffd23f]' },
    { dot: 'bg-[#ff6b8a]', bar: 'bg-[#ff6b8a]' },
    { dot: 'bg-[#00c9a7]', bar: 'bg-[#00c9a7]' },
    { dot: 'bg-[#a78bfa]', bar: 'bg-[#a78bfa]' },
    { dot: 'bg-[#fb923c]', bar: 'bg-[#fb923c]' },
    { dot: 'bg-[#34d399]', bar: 'bg-[#34d399]' },
];

const displayItems = computed<ExpenseItem[]>(() => {
    return props.categories.map((c, i) => {
        const palette = colorPalette[i % colorPalette.length];
        return {
            label: c.label,
            amount: c.amount,
            percent: c.percent,
            colorClass: palette.dot,
            bgTrackClass: palette.bar,
        };
    });
});

const hasData = computed(() => displayItems.value.length > 0);
</script>

<template>
    <!-- 7. Kategori Pengeluaran & Progress Breakdown (1:1 Blueprint) -->
    <section class="p-4 rounded-2xl bg-gradient-to-br from-[#fff9e8] via-white to-[#e8fff7] dark:from-[#26231b] dark:via-card dark:to-[#15261f] border border-[#ffd23f]/40 dark:border-[#ffd23f]/20 flex flex-col gap-3.5 font-['Plus_Jakarta_Sans',sans-serif] shadow-sm">
        <div class="flex items-center justify-between">
            <span class="text-sm md:text-base font-bold text-[#1b1c19] dark:text-foreground">Porsi Pengeluaran</span>
            <a href="/budgets" class="text-xs text-[#745c00] dark:text-primary font-bold flex items-center gap-1 hover:underline">
                <span>Atur Batas</span>
                <span class="material-symbols-outlined text-[16px]">settings</span>
            </a>
        </div>

        <!-- Empty State -->
        <div v-if="!hasData" class="flex items-center justify-center h-28 text-center">
            <div class="flex flex-col items-center gap-1.5">
                <span class="material-symbols-outlined text-3xl text-[#eae8e2] dark:text-muted-foreground">pie_chart</span>
                <span class="text-xs text-[#4d4634] dark:text-muted-foreground font-medium">Belum ada pengeluaran bulan ini</span>
            </div>
        </div>

        <!-- Multicolored Segmented Progress Track -->
        <div v-else class="w-full h-3.5 bg-gradient-to-r from-[#fdf4d8] via-[#e7f6f0] to-[#fde8e8] dark:from-[#2a251c] dark:via-[#17251f] dark:to-[#2a1f22] rounded-full overflow-hidden flex shadow-inner">
            <div 
                v-for="(item, idx) in displayItems" 
                :key="idx"
                :class="item.bgTrackClass"
                :style="{ width: `${item.percent}%` }"
                :title="`${item.label} ${item.percent}%`"
                class="h-full transition-all duration-500"
            ></div>
        </div>

        <!-- Category Items List -->
        <div class="flex flex-col gap-2.5 pt-1">
            <div 
                v-for="(item, idx) in displayItems" 
                :key="idx"
                class="flex items-center justify-between"
            >
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-3 h-3 rounded-full flex-shrink-0" :class="item.colorClass"></div>
                    <span class="text-xs text-[#1b1c19] dark:text-foreground font-semibold truncate">{{ item.label }}</span>
                </div>
                <div class="flex items-baseline gap-2 flex-shrink-0">
                    <span class="text-xs font-extrabold text-[#1b1c19] dark:text-foreground">{{ formatCurrency(item.amount) }}</span>
                    <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground font-semibold w-7 text-right">{{ item.percent }}%</span>
                </div>
            </div>
        </div>
    </section>
</template>
