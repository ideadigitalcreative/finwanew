<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import { ref, computed, onMounted, watch } from 'vue';
import { Bar } from 'vue-chartjs';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface ChartDataPoint {
    month: string;
    income: number;
    expense: number;
    net: number;
}

interface Props {
    data: ChartDataPoint[];
    transactions?: Array<{ transaction_date: string; type: string; amount: number; }>;
}

const props = withDefaults(defineProps<Props>(), {
    data: () => [],
    transactions: () => []
});

type FilterType = 'Harian' | 'Mingguan' | 'Bulanan';
const filter = ref<FilterType>('Bulanan');
const isFilterOpen = ref(false);
const filterOptions: FilterType[] = ['Harian', 'Mingguan', 'Bulanan'];

// Chart Colors Map (tema CuanCeria)
const chartColors = {
    dark: {
        primary: '#51fac1',              // Masuk - Mint
        expense: '#e36c8b',              // Keluar - Pink
        space:   '#ffd23f',              // Sisa - Kuning
        border:  'rgba(255, 255, 255, 0.1)',
        text:    '#a29f90'
    },
    light: {
        primary: '#51fac1',              // Masuk - Mint
        expense: '#e36c8b',              // Keluar - Pink
        space:   '#ffd23f',              // Sisa - Kuning
        border:  'rgba(234, 232, 226, 0.8)',
        text:    '#4d4634'
    }
};

const colors = ref(chartColors.light);

const isMobile = ref(false);
const updateMobileStatus = () => {
    isMobile.value = window.innerWidth < 768;
};

const updateTheme = () => {
    const isDark = document.documentElement.classList.contains('dark');
    colors.value = isDark ? chartColors.dark : chartColors.light;
};

onMounted(() => {
    updateTheme();
    updateMobileStatus();
    window.addEventListener('resize', updateMobileStatus);
    
    const observer = new MutationObserver(updateTheme);
    observer.observe(document.documentElement, { 
        attributes: true, 
        attributeFilter: ['class'] 
    });
});

// Helper to format local date
const formatLocalDate = (d: Date) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

// Chart Data
const chartData = computed(() => {
    let filteredData: ChartDataPoint[] = [];

    if (filter.value === 'Harian') {
        // Last 7 days
        const days = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
        const today = new Date();
        const last7Days: any[] = [];
        
        for (let i = 6; i >= 0; i--) {
            const date = new Date(today);
            date.setDate(today.getDate() - i);
            const dateStr = formatLocalDate(date);
            last7Days.push({ 
                dateStr, 
                month: days[date.getDay()], // Use day name as label
                income: 0, 
                expense: 0, 
                net: 0 
            });
        }
        
        props.transactions?.forEach(tx => {
            if (!tx.transaction_date) return;
            const txDate = tx.transaction_date.split('T')[0].split(' ')[0];
            const dayData = last7Days.find(d => d.dateStr === txDate);
            if (dayData) {
                if (tx.type === 'income') dayData.income += tx.amount;
                else dayData.expense += tx.amount;
                dayData.net = dayData.income - dayData.expense;
            }
        });
        filteredData = last7Days;

    } else if (filter.value === 'Mingguan') {
        // 4 weeks of current month
        const weeks = [
            { month: 'Minggu 1', income: 0, expense: 0, net: 0, start: 1, end: 7 },
            { month: 'Minggu 2', income: 0, expense: 0, net: 0, start: 8, end: 14 },
            { month: 'Minggu 3', income: 0, expense: 0, net: 0, start: 15, end: 21 },
            { month: 'Minggu 4', income: 0, expense: 0, net: 0, start: 22, end: 31 }
        ];

        props.transactions?.forEach(tx => {
            if (!tx.transaction_date) return;
            const txDate = new Date(tx.transaction_date);
            const day = txDate.getDate();
            const week = weeks.find(w => day >= w.start && day <= w.end);
            if (week) {
                if (tx.type === 'income') week.income += tx.amount;
                else week.expense += tx.amount;
                week.net = week.income - week.expense;
            }
        });
        filteredData = weeks;

    } else {
        // Bulanan
        filteredData = props.data;
    }
    
    const labels = filteredData.map(d => d.month.split(' ')[0]);
    
    return {
        labels,
        datasets: [
            {
                label: 'Sisa',
                data: filteredData.map(d => d.net),
                backgroundColor: colors.value.space,
                borderRadius: { topLeft: 0, topRight: 0, bottomLeft: 50, bottomRight: 50 }, // Increased rounding
                borderSkipped: false,
                barThickness: 'flex' as const,
                maxBarThickness: isMobile.value ? 25 : 50,
                stack: 'stack1',
            },
            {
                label: 'Keluar',
                data: filteredData.map(d => d.expense),
                backgroundColor: colors.value.expense,
                borderRadius: 0,
                borderSkipped: false,
                barThickness: 'flex' as const,
                maxBarThickness: isMobile.value ? 25 : 50,
                stack: 'stack1',
            },
            {
                label: 'Masuk',
                data: filteredData.map(d => d.income),
                backgroundColor: colors.value.primary,
                borderRadius: { topLeft: 50, topRight: 50, bottomLeft: 0, bottomRight: 0 }, // Increased rounding
                borderSkipped: false,
                barThickness: 'flex' as const,
                maxBarThickness: isMobile.value ? 25 : 50,
                stack: 'stack1',
            }
        ]
    };
});

// Chart Options
const chartOptions = computed(() => {
    return {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
             padding: { top: 20 }
        },
        plugins: {
            legend: {
                display: false 
            },
            tooltip: {
                enabled: true,
                backgroundColor: '#23231f',
                titleColor: '#ffe089',
                bodyColor: '#ffffff', 
                borderColor: 'rgba(255,255,255,0.08)',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 12,
                displayColors: true,
                callbacks: {
                     label: function(context: any) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            x: {
                stacked: true,
                grid: {
                    display: false,
                    drawBorder: false,
                },
                ticks: {
                    color: colors.value.text,
                    font: {
                        size: 10,
                        family: "'Plus Jakarta Sans', sans-serif"
                    },
                    padding: 10
                },
                border: {
                    display: false
                }
            },
            y: {
                stacked: true,
                display: false,
                grid: {
                    color: colors.value.border,
                    drawBorder: false,
                    borderDash: [5, 5]
                },
                beginAtZero: true
            }
        },
        interaction: {
            mode: 'index' as const,
            intersect: false,
        }
    };
});

// Custom formatting for custom tooltip if we were to implement it fully custom
// But using default styled tooltip is safer for stability given existing libraries
</script>

<template>
    <div class="bg-gradient-to-br from-[#fffdfa] via-white to-[#f4fbf7] dark:from-[#1f201c] dark:via-[#23231f] dark:to-[#1a2520] rounded-2xl p-3 md:p-5 border border-[#eae8e2] dark:border-white/10 shadow-sm transition-all duration-500 h-full flex flex-col font-['Plus_Jakarta_Sans',sans-serif]">
        <!-- Header -->
        <div class="flex items-center justify-between gap-3 mb-4 md:mb-6">
            <h3 class="text-sm md:text-base font-extrabold text-[#1b1c19] dark:text-foreground flex items-center gap-1.5">
                <span class="w-6 h-6 rounded-lg bg-[#ffd23f] text-[#574500] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[14px]">bar_chart</span>
                </span>
                Arus Uang
            </h3>
            
            <div class="flex items-center justify-end gap-3 md:gap-4">
                <!-- Legend -->
                <div class="hidden sm:flex items-center gap-6">
                    <div class="flex items-center gap-1.5">
                        <div class="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full" :style="{ backgroundColor: colors.primary }" />
                        <span class="text-xs text-[#4d4634] dark:text-white/60">Masuk</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full" :style="{ backgroundColor: colors.expense }" />
                        <span class="text-xs text-[#4d4634] dark:text-white/60">Keluar</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full" :style="{ backgroundColor: colors.space }" />
                        <span class="text-xs text-[#4d4634] dark:text-white/60">Sisa</span>
                    </div>
                </div>
                
                <!-- Filter -->
                <div class="relative">
                    <button 
                        @click="isFilterOpen = !isFilterOpen"
                        class="flex items-center gap-1.5 text-xs md:text-sm text-[#574500] font-bold transition-colors px-3 py-1.5 md:px-4 md:py-2 rounded-full bg-[#ffd23f]/30 border border-[#ffd23f]/50 hover:bg-[#ffd23f]/50"
                    >
                        {{ filter }}
                        <ChevronDown class="w-3.5 h-3.5 md:w-4 md:h-4" />
                    </button>
                    
                    <!-- Dropdown Content -->
                     <div v-if="isFilterOpen" class="absolute right-0 top-full mt-2 w-32 backdrop-blur-2xl bg-white/90 dark:bg-[#23231f]/90 border border-[#eae8e2] dark:border-white/10 rounded-xl shadow-lg z-50 overflow-hidden flex flex-col p-1">
                        <button 
                            v-for="opt in filterOptions" 
                            :key="opt"
                            @click="filter = opt; isFilterOpen = false"
                            class="text-left px-3 py-2 text-xs md:text-sm rounded-lg hover:bg-[#f5f3ee] dark:hover:bg-white/10 transition-colors"
                            :class="filter === opt ? 'text-[#574500] dark:text-[#ffe089] font-semibold bg-[#ffd23f]/30' : 'text-[#4d4634] dark:text-white/60'"
                        >
                            {{ opt }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chart Container -->
        <div class="flex-1 min-h-[170px] md:min-h-[280px] w-full relative">
             <Bar :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>
