<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Bar } from 'vue-chartjs';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface ComparisonItem {
    category: string;
    budget: number;
    actual: number;
}

interface Props {
    data: ComparisonItem[];
}

const props = defineProps<Props>();

const hasData = computed(() => props.data.length > 0);

const formatCurrency = (v: number) => {
    if (v >= 1_000_000) return `Rp ${(v / 1_000_000).toFixed(1)}jt`;
    if (v >= 1_000) return `Rp ${Math.round(v / 1_000)}rb`;
    return `Rp ${v.toLocaleString('id-ID')}`;
};

const isDark = ref(false);

const chartData = computed(() => ({
    labels: props.data.map(d => d.category),
    datasets: [
        {
            label: 'Anggaran',
            data: props.data.map(d => d.budget),
            backgroundColor: 'rgba(255, 210, 63, 0.75)',
            borderColor: 'rgb(224, 159, 0)',
            borderWidth: 1,
            borderRadius: 6,
            barPercentage: 0.7,
        },
        {
            label: 'Realisasi',
            data: props.data.map(d => d.actual),
            backgroundColor: (ctx: any) => {
                const actual = props.data[ctx.dataIndex]?.actual ?? 0;
                const budget = props.data[ctx.dataIndex]?.budget ?? 1;
                if (actual > budget) return 'rgba(227, 108, 139, 0.85)';
                if (actual > budget * 0.8) return 'rgba(255, 210, 63, 0.9)';
                return 'rgba(81, 250, 193, 0.85)';
            },
            borderColor: (ctx: any) => {
                const actual = props.data[ctx.dataIndex]?.actual ?? 0;
                const budget = props.data[ctx.dataIndex]?.budget ?? 1;
                if (actual > budget) return 'rgb(227, 108, 139)';
                if (actual > budget * 0.8) return 'rgb(224, 159, 0)';
                return 'rgb(25, 179, 130)';
            },
            borderWidth: 1,
            borderRadius: 6,
            barPercentage: 0.7,
        },
    ],
}));

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'top' as const,
            align: 'end' as const,
            labels: {
                color: isDark.value ? '#e5e7eb' : '#374151',
                usePointStyle: true,
                pointStyle: 'rectRounded',
                padding: 16,
                font: { size: 11 },
            },
        },
        title: { display: false },
        tooltip: {
            backgroundColor: isDark.value ? '#1f2937' : '#ffffff',
            titleColor: isDark.value ? '#f3f4f6' : '#111827',
            bodyColor: isDark.value ? '#d1d5db' : '#4b5563',
            borderColor: isDark.value ? '#374151' : '#e5e7eb',
            borderWidth: 1,
            cornerRadius: 8,
            padding: 12,
            titleFont: { weight: 'bold' as const },
            callbacks: {
                label: (ctx: any) => ` ${ctx.dataset.label}: ${formatCurrency(ctx.raw)}`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: isDark.value ? '#9ca3af' : '#6b7280',
                font: { size: 11 },
                maxRotation: 45,
            },
        },
        y: {
            beginAtZero: true,
            grid: {
                color: isDark.value ? 'rgba(75, 85, 99, 0.3)' : 'rgba(209, 213, 219, 0.5)',
            },
            ticks: {
                color: isDark.value ? '#9ca3af' : '#6b7280',
                font: { size: 11 },
                callback: (val: any) => formatCurrency(val),
            },
        },
    },
}));

// Summary
const totalBudget = computed(() => props.data.reduce((s, d) => s + d.budget, 0));
const totalActual = computed(() => props.data.reduce((s, d) => s + d.actual, 0));
const totalDiff = computed(() => totalBudget.value - totalActual.value);
const isOverAll = computed(() => totalActual.value > totalBudget.value);

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');
    const observer = new MutationObserver(() => {
        isDark.value = document.documentElement.classList.contains('dark');
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});
</script>

<template>
    <div class="bg-white dark:bg-[#23231f] rounded-2xl flex flex-col border border-[#eae8e2] dark:border-white/10 overflow-hidden font-['Plus_Jakarta_Sans',sans-serif]">

        <!-- Header -->
        <div class="p-4 md:p-5 border-b border-[#eae8e2] dark:border-white/10">
            <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-xl bg-[#ffe089] text-[#574500] flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-[20px]">monitoring</span>
                </span>
                <div class="min-w-0">
                    <h3 class="font-bold text-base md:text-lg text-[#1b1c19] dark:text-white leading-tight">Anggaran vs Realisasi</h3>
                    <p class="text-xs md:text-sm text-[#4d4634] dark:text-gray-400">Perbandingan per kategori</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="p-4 md:p-5 flex flex-col gap-4">

            <!-- Empty state -->
            <template v-if="!hasData">
                <div class="flex flex-col items-center justify-center py-8 text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-[#f5f3ee] dark:bg-white/10 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-[#4d4634] dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-[#4d4634] dark:text-gray-300">Belum ada data perbandingan</p>
                        <p class="text-xs text-[#4d4634]/70 dark:text-gray-500 mt-0.5">Buat budget per kategori untuk melihat grafik ini</p>
                    </div>
                </div>
            </template>

            <!-- Chart -->
            <template v-else>
                <div class="relative" style="height: 260px;">
                    <Bar :data="chartData" :options="chartOptions" />
                </div>

                <!-- Summary -->
                <div class="grid grid-cols-3 gap-3 pt-2 border-t border-[#eae8e2] dark:border-white/10">
                    <div class="text-center">
                        <p class="text-[10px] text-[#4d4634] dark:text-gray-500 uppercase tracking-wide">Total Anggaran</p>
                        <p class="text-sm font-bold text-[#725a00] dark:text-[#ffe089]">{{ formatCurrency(totalBudget) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] text-[#4d4634] dark:text-gray-500 uppercase tracking-wide">Total Realisasi</p>
                        <p class="text-sm font-bold" :class="isOverAll ? 'text-[#93000a] dark:text-[#e36c8b]' : 'text-[#007152] dark:text-[#51fac1]'">{{ formatCurrency(totalActual) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] text-[#4d4634] dark:text-gray-500 uppercase tracking-wide">Selisih</p>
                        <p class="text-sm font-bold" :class="totalDiff >= 0 ? 'text-[#007152] dark:text-[#51fac1]' : 'text-[#93000a] dark:text-[#e36c8b]'">
                            {{ totalDiff >= 0 ? '+' : '' }}{{ formatCurrency(totalDiff) }}
                        </p>
                    </div>
                </div>
            </template>

        </div>
    </div>
</template>
