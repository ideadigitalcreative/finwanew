<script setup lang="ts">
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';

// Register ChartJS components
ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps<{
    categories: Array<{
        category_name: string;
        total_expense: number;
    }>;
}>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatCurrencyShort = (amount: number) => {
    if (amount >= 1000000) {
        return `${(amount / 1000000).toFixed(1)}jt`;
    } else if (amount >= 1000) {
        return `${(amount / 1000).toFixed(0)}rb`;
    }
    return formatCurrency(amount);
};

const formatPercentage = (value: number, total: number) => {
    if (total === 0) return '0%';
    return ((value / total) * 100).toFixed(1) + '%';
};

// Modern soft palette for categories
const colors = [
    '#3b82f6', // Blue
    '#ef4444', // Red
    '#10b981', // Emerald
    '#f59e0b', // Amber
    '#8b5cf6', // Violet
    '#ec4899', // Pink
    '#06b6d4', // Cyan
    '#f97316', // Orange
    '#6366f1', // Indigo
    '#84cc16', // Lime
];

const chartData = computed(() => {
    // Filter categories with expense > 0
    const expenseCategories = props.categories
        .filter(c => c.total_expense > 0)
        .sort((a, b) => b.total_expense - a.total_expense); // Sort by biggest expense

    const total = expenseCategories.reduce((sum, c) => sum + c.total_expense, 0);

    const items = expenseCategories.map((c, i) => ({
        label: c.category_name,
        value: c.total_expense,
        color: colors[i % colors.length],
        percentage: formatPercentage(c.total_expense, total)
    }));

    return {
        labels: expenseCategories.map(c => c.category_name),
        datasets: [
            {
                backgroundColor: expenseCategories.map((_, i) => colors[i % colors.length]),
                borderColor: 'transparent',
                data: expenseCategories.map(c => c.total_expense),
                hoverOffset: 4,
                borderRadius: 8,
                spacing: 2,
            }
        ],
        // Store metadata for custom legend
        customMeta: {
            total,
            items
        }
    };
});

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '75%', // Donut style
    plugins: {
        legend: {
            display: false // We use custom legend
        },
        tooltip: {
            callbacks: {
                label: function(context: any) {
                    const value = context.raw;
                    // Calculate percentage manually for tooltip
                    const dataset = context.dataset;
                    const total = dataset.data.reduce((acc: number, curr: number) => acc + curr, 0);
                    const percentage = ((value / total) * 100).toFixed(1) + '%';
                    return ` ${context.label}: ${formatCurrency(value)} (${percentage})`;
                }
            }
        }
    }
};
</script>

<template>
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(0,0,0,0.04)] dark:bg-gray-800 flex flex-col h-full hover:shadow-lg transition-shadow duration-300">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">Pengeluaran per Kategori</h3>
            <div class="rounded-full bg-gray-50 p-2 dark:bg-gray-700/50">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-500 dark:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                    <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
                </svg>
            </div>
        </div>
        
        <div class="relative flex-1 min-h-[220px] flex items-center justify-center py-4">
            <Doughnut v-if="chartData.datasets[0].data.length > 0" :data="chartData" :options="chartOptions" />
            <div v-else class="flex flex-col items-center justify-center text-center text-muted-foreground py-10">
                <div class="mb-3 rounded-full bg-gray-50 p-4 dark:bg-gray-700/30">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                    </svg>
                </div>
                <p class="text-sm font-medium">Belum ada data pengeluaran</p>
                <p class="text-xs text-gray-400 mt-1">Mulai catat transaksi Anda</p>
            </div>
            
            <!-- Center Text (Total) if data exists -->
            <div v-if="chartData.datasets[0].data.length > 0" class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <span class="text-xs text-gray-400 font-medium bg-white/80 dark:bg-gray-800/80 px-2 py-0.5 rounded-full backdrop-blur-sm mb-1">Total</span>
                <span class="text-xl md:text-2xl font-black text-gray-900 dark:text-white tracking-tight">
                    {{ formatCurrencyShort(chartData.customMeta.total) }}
                </span>
            </div>
        </div>

        <!-- Custom Legend with Progress Bars -->
        <div v-if="chartData.datasets[0].data.length > 0" class="mt-6 space-y-3">
            <div 
                v-for="(item, index) in chartData.customMeta.items.slice(0, 4)" 
                :key="item.label"
                class="group"
            >
                <div class="flex items-center justify-between text-sm mb-1.5">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full shadow-sm ring-2 ring-white dark:ring-gray-800" :style="{ backgroundColor: item.color }"></span>
                        <span class="font-medium text-gray-700 dark:text-gray-300 truncate max-w-[120px]" :title="item.label">{{ item.label }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ item.percentage }}</span>
                        <span class="text-gray-400">({{ formatCurrencyShort(item.value) }})</span>
                    </div>
                </div>
            </div>
            
            <!-- Show 'More' if category > 4 -->
            <div v-if="chartData.customMeta.items.length > 4" class="text-center pt-2">
                <span class="text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors cursor-pointer">
                    +{{ chartData.customMeta.items.length - 4 }} kategori lainnya
                </span>
            </div>
        </div>
    </div>
</template>
