<script setup lang="ts">
import { TrendingUp, TrendingDown } from 'lucide-vue-next';

interface Props {
    income: number;
    expense: number;
    period: string;
    incomeChange?: number;
    expenseChange?: number;
}

const props = withDefaults(defineProps<Props>(), {
    incomeChange: 0,
    expenseChange: 0
});

const formatCurrency = (amount: number) => {
    if (amount >= 1000000) {
        const val = (amount / 1000000).toFixed(1);
        return 'Rp' + (val.endsWith('.0') ? val.slice(0, -2) : val) + 'jt';
    }
    if (amount >= 1000) {
        const val = (amount / 1000).toFixed(0);
        return 'Rp' + val + 'rb';
    }
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
};

const formatFull = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
};
</script>

<template>
    <div class="relative overflow-hidden bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 border border-gray-200/50 dark:border-gray-700/30 lg:hidden">
        <!-- Semi-circle decorations -->
        <div class="absolute -bottom-10 -left-10 w-32 h-32 rounded-full bg-emerald-500/10 dark:bg-emerald-400/5 pointer-events-none"></div>
        <div class="absolute -bottom-7 -left-7 w-20 h-20 rounded-full bg-emerald-500/15 dark:bg-emerald-400/8 pointer-events-none"></div>

        <!-- Header -->
        <div class="flex items-center justify-between mb-4 relative z-10">
            <span class="text-[10px] text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/30 backdrop-blur-sm px-2 py-0.5 rounded-full">{{ period }}</span>
        </div>

        <!-- Income & Expense Row -->
        <div class="flex items-center justify-between gap-4 relative z-10">
            <!-- Income -->
            <div class="flex-1">
                <div class="flex items-center gap-1 mb-1">
                    <TrendingUp class="w-3 h-3 text-emerald-500" />
                    <p class="text-[10px] text-muted-foreground">Pemasukan</p>
                </div>
                <p class="text-xl font-semibold text-emerald-600 dark:text-emerald-400">{{ formatFull(income) }}</p>
            </div>

            <!-- Divider -->
            <div class="w-px h-10 bg-border/30"></div>

            <!-- Expense -->
            <div class="flex-1 text-right">
                <div class="flex items-center justify-end gap-1 mb-1">
                    <p class="text-[10px] text-muted-foreground">Pengeluaran</p>
                    <TrendingDown class="w-3 h-3 text-rose-500" />
                </div>
                <p class="text-xl font-semibold text-rose-600 dark:text-rose-400">{{ formatFull(expense) }}</p>
            </div>
        </div>
    </div>
</template>
