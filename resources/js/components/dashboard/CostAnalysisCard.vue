<script setup lang="ts">
import { PieChart } from 'lucide-vue-next';

interface Category {
    label: string;
    amount: number;
    percent: number;
}

interface Props {
    categories: Category[];
    totalExpense?: number;
    period?: string;
}

const props = withDefaults(defineProps<Props>(), {
    categories: () => [],
    totalExpense: 0,
    period: '',
});

const colors = [
    '#10b981', '#3b82f6', '#f59e0b', '#ef4444',
    '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
];

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};
</script>

<template>
    <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 h-full flex flex-col">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-violet-100 dark:bg-violet-900/30">
                    <PieChart class="w-4 h-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-sm font-semibold text-foreground">Cost Analysis</h3>
            </div>
            <span class="text-xs text-muted-foreground bg-muted/30 px-2 py-1 rounded-full">{{ period || 'Bulan ini' }}</span>
        </div>

        <div class="mb-4">
            <p class="text-2xl font-bold text-foreground">{{ formatCurrency(totalExpense) }}</p>
        </div>

        <div v-if="categories.length > 0" class="flex-1 space-y-2.5">
            <div
                v-for="(cat, index) in categories.slice(0, 7)"
                :key="cat.label"
                class="flex items-center gap-3"
            >
                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="{ backgroundColor: colors[index % colors.length] }"></div>
                <span class="text-sm text-muted-foreground flex-1 truncate">{{ cat.label }}</span>
                <span class="text-sm font-medium text-foreground tabular-nums">{{ cat.percent }}%</span>
            </div>
        </div>

        <p v-else class="text-sm text-muted-foreground text-center py-4">Belum ada data pengeluaran</p>
    </div>
</template>
