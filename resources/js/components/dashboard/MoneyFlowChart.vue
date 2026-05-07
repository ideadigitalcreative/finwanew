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
}

const props = defineProps<Props>();

type FilterType = 'Harian' | 'Mingguan' | 'Bulanan';
const filter = ref<FilterType>('Bulanan');
const isFilterOpen = ref(false);
const filterOptions: FilterType[] = ['Harian', 'Mingguan', 'Bulanan'];

// Chart Colors Map (Updated to match image reference)
const chartColors = {
    dark: {
        primary: 'hsl(142, 70%, 50%)',   // Income - Emerald
        expense: 'hsl(30, 90%, 55%)',    // Expense - Orange
        space:   '#3b82f6',              // Sisa - Blue
        border:  'hsla(217, 20%, 20%, 0.3)',
        text:    '#9ca3af'
    },
    light: {
        primary: 'rgb(33, 196, 93)',     // Income - Emerald
        expense: 'rgb(251, 146, 60)',    // Expense - Orange
        space:   '#3b82f6',              // Sisa - Blue
        border:  'rgba(226, 232, 240, 0.8)',
        text:    '#6b7280'
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

// Chart Data
const chartData = computed(() => {
    const filteredData = filter.value === 'Harian' 
        ? props.data.slice(-1) 
        : filter.value === 'Mingguan' 
            ? props.data.slice(-3) 
            : props.data;
    
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
                backgroundColor: colors.value.primary,
                titleColor: '#ffffff',
                bodyColor: '#ffffff', 
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
                        family: 'Instrument Sans'
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
    <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-3 md:p-5 border border-gray-200/50 dark:border-gray-700/30 transition-all duration-500">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 md:mb-6">
            <h3 class="text-sm md:text-base font-semibold text-foreground">Arus Uang</h3>
            
            <div class="flex items-center justify-between sm:justify-end gap-3 md:gap-4 w-full sm:w-auto">
                <!-- Legend -->
                <div class="hidden sm:flex items-center gap-6">
                    <div class="flex items-center gap-1.5">
                        <div class="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full" :style="{ backgroundColor: colors.primary }" />
                        <span class="text-xs text-muted-foreground">Masuk</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full" :style="{ backgroundColor: colors.expense }" />
                        <span class="text-xs text-muted-foreground">Keluar</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-2 h-2 md:w-2.5 md:h-2.5 rounded-full" :style="{ backgroundColor: colors.space }" />
                        <span class="text-xs text-muted-foreground">Sisa</span>
                    </div>
                </div>
                
                <!-- Filter -->
                <div class="relative">
                    <button 
                        @click="isFilterOpen = !isFilterOpen"
                        class="flex items-center gap-1.5 text-xs md:text-sm text-foreground font-medium hover:text-primary transition-colors px-3 py-1.5 md:px-4 md:py-2 rounded-full bg-primary/10 backdrop-blur-sm border border-primary/20 hover:bg-primary/20"
                    >
                        {{ filter }}
                        <ChevronDown class="w-3.5 h-3.5 md:w-4 md:h-4" />
                    </button>
                    
                    <!-- Dropdown Content -->
                     <div v-if="isFilterOpen" class="absolute right-0 top-full mt-2 w-32 backdrop-blur-2xl bg-card/90 border border-border/30 rounded-xl z-50 overflow-hidden flex flex-col p-1">
                        <button 
                            v-for="opt in filterOptions" 
                            :key="opt"
                            @click="filter = opt; isFilterOpen = false"
                            class="text-left px-3 py-2 text-xs md:text-sm rounded-lg hover:bg-muted/50 transition-colors"
                            :class="filter === opt ? 'text-primary font-semibold bg-primary/5' : 'text-muted-foreground'"
                        >
                            {{ opt }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chart Container -->
        <div class="h-[170px] md:h-[280px] w-full relative">
             <Bar :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>
