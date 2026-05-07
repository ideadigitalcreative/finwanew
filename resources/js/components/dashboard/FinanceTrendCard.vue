<script setup lang="ts">
import { computed, ref } from 'vue';

interface DayData {
    day: string;
    income: number;
    expense: number;
}

interface Props {
    chartData?: DayData[];
    period?: string;
}

const props = withDefaults(defineProps<Props>(), {
    chartData: () => [],
    period: 'Bulan Ini'
});

// Use provided data or empty
const data = computed(() => {
    if (props.chartData && props.chartData.length > 0) {
        return props.chartData;
    }
    return [];
});

const hasData = computed(() => data.value.length > 0);

// Tooltip state
const hoveredPoint = ref<{ x: number; y: number; income: number; expense: number; day: string } | null>(null);

// Chart dimensions
const chartWidth = 320;
const chartHeight = 200;
const padding = { top: 20, right: 20, bottom: 30, left: 50 };
const graphWidth = chartWidth - padding.left - padding.right;
const graphHeight = chartHeight - padding.top - padding.bottom;

// Calculate max value for scaling
const maxValue = computed(() => {
    if (!hasData.value) return 1000000;
    let max = 0;
    data.value.forEach(d => {
        max = Math.max(max, d.income, d.expense);
    });
    return Math.ceil(max / 500000) * 500000 || 1000000;
});

// Generate Y-axis ticks
const yTicks = computed(() => {
    const ticks = [];
    const step = maxValue.value / 4;
    for (let i = 0; i <= 4; i++) {
        ticks.push(i * step);
    }
    return ticks;
});

// Format currency
const formatCurrency = (value: number) => {
    if (value >= 1000000) {
        return `Rp ${(value / 1000000).toFixed(1)}jt`;
    } else if (value >= 1000) {
        return `Rp ${Math.round(value / 1000)}rb`;
    }
    return `Rp ${value.toLocaleString('id-ID')}`;
};

// Format currency for Y-axis
const formatYAxis = (value: number) => {
    if (value >= 1000000) {
        return `${(value / 1000000).toFixed(1)}jt`;
    } else if (value >= 1000) {
        return `${Math.round(value / 1000)}rb`;
    }
    return value.toString();
};

// Get point position
const getPointPosition = (index: number, key: 'income' | 'expense') => {
    const d = data.value[index];
    const x = padding.left + (index / Math.max(data.value.length - 1, 1)) * graphWidth;
    const y = padding.top + graphHeight - (d[key] / maxValue.value) * graphHeight;
    return { x, y };
};

// Generate path for line
const generatePath = (key: 'income' | 'expense') => {
    if (!hasData.value) return '';
    
    const points = data.value.map((d, i) => {
        const x = padding.left + (i / Math.max(data.value.length - 1, 1)) * graphWidth;
        const y = padding.top + graphHeight - (d[key] / maxValue.value) * graphHeight;
        return { x, y };
    });
    
    if (points.length === 0) return '';
    if (points.length === 1) return `M ${points[0].x} ${points[0].y}`;
    
    // Create smooth curve using cubic bezier
    let path = `M ${points[0].x} ${points[0].y}`;
    
    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i];
        const p1 = points[i + 1];
        const cp1x = p0.x + (p1.x - p0.x) / 3;
        const cp2x = p0.x + 2 * (p1.x - p0.x) / 3;
        path += ` C ${cp1x} ${p0.y}, ${cp2x} ${p1.y}, ${p1.x} ${p1.y}`;
    }
    
    return path;
};

const incomePath = computed(() => generatePath('income'));
const expensePath = computed(() => generatePath('expense'));

// Handle hover
const handlePointHover = (index: number) => {
    const d = data.value[index];
    const pos = getPointPosition(index, 'income');
    hoveredPoint.value = {
        x: pos.x,
        y: Math.min(getPointPosition(index, 'income').y, getPointPosition(index, 'expense').y) - 10,
        income: d.income,
        expense: d.expense,
        day: d.day
    };
};

const handlePointLeave = () => {
    hoveredPoint.value = null;
};
</script>

<template>
    <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-xl h-full rounded-[13px] flex flex-col border border-gray-100 dark:border-gray-700/30">
        <!-- Header -->
        <div class="p-4 md:p-5 border-b border-gray-100 dark:border-gray-700/50">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-lg text-gray-900 dark:text-white">Tren Keuangan</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Pemasukan vs Pengeluaran</p>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ period }}</span>
                </div>
            </div>
        </div>
        
        <!-- Chart -->
        <div class="flex-1 p-4 md:p-5 relative">
            <template v-if="hasData">
                <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="w-full h-auto" preserveAspectRatio="xMidYMid meet">
                    <!-- Gradient Definitions -->
                    <defs>
                        <!-- Income gradient (vertical) -->
                        <linearGradient id="incomeGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#22c55e" stop-opacity="0.3" />
                            <stop offset="100%" stop-color="#22c55e" stop-opacity="0" />
                        </linearGradient>
                        <!-- Expense gradient (vertical) -->
                        <linearGradient id="expenseGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#f97316" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#f97316" stop-opacity="0" />
                        </linearGradient>
                        <!-- Income line gradient (horizontal) -->
                        <linearGradient id="incomeLineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#86efac" />
                            <stop offset="50%" stop-color="#22c55e" />
                            <stop offset="100%" stop-color="#16a34a" />
                        </linearGradient>
                        <!-- Expense line gradient (horizontal) -->
                        <linearGradient id="expenseLineGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#fdba74" />
                            <stop offset="50%" stop-color="#f97316" />
                            <stop offset="100%" stop-color="#ea580c" />
                        </linearGradient>
                    </defs>

                    <!-- Grid lines -->
                    <g class="grid-lines">
                        <line 
                            v-for="(tick, i) in yTicks" 
                            :key="i"
                            :x1="padding.left" 
                            :y1="padding.top + graphHeight - (tick / maxValue) * graphHeight"
                            :x2="padding.left + graphWidth" 
                            :y2="padding.top + graphHeight - (tick / maxValue) * graphHeight"
                            stroke="currentColor" 
                            stroke-width="1" 
                            stroke-dasharray="4,4"
                            class="text-gray-100 dark:text-gray-700/50"
                        />
                    </g>
                    
                    <!-- Y-axis labels -->
                    <g class="y-axis">
                        <text 
                            v-for="(tick, i) in yTicks" 
                            :key="i"
                            :x="padding.left - 8" 
                            :y="padding.top + graphHeight - (tick / maxValue) * graphHeight + 4"
                            text-anchor="end"
                            class="text-[10px] fill-gray-400"
                        >
                            {{ formatYAxis(tick) }}
                        </text>
                    </g>
                    
                    <!-- X-axis labels -->
                    <g class="x-axis">
                        <text 
                            v-for="(d, i) in data" 
                            :key="i"
                            :x="padding.left + (i / Math.max(data.length - 1, 1)) * graphWidth"
                            :y="chartHeight - 8"
                            text-anchor="middle"
                            class="text-[10px] fill-gray-400"
                        >
                            {{ d.day }}
                        </text>
                    </g>
                    
                    <!-- Income area fill with gradient -->
                    <path 
                        :d="incomePath + ` L ${padding.left + graphWidth} ${padding.top + graphHeight} L ${padding.left} ${padding.top + graphHeight} Z`" 
                        fill="url(#incomeGradient)"
                        class="animate-fade-in"
                    />
                    
                    <!-- Expense area fill with gradient -->
                    <path 
                        :d="expensePath + ` L ${padding.left + graphWidth} ${padding.top + graphHeight} L ${padding.left} ${padding.top + graphHeight} Z`" 
                        fill="url(#expenseGradient)"
                        class="animate-fade-in"
                    />
                    
                    <!-- Income line with gradient stroke -->
                    <path 
                        :d="incomePath" 
                        fill="none" 
                        stroke="url(#incomeLineGradient)" 
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="animate-draw-line"
                        style="--line-length: 1000"
                    />
                    
                    <!-- Expense line with gradient stroke -->
                    <path 
                        :d="expensePath" 
                        fill="none" 
                        stroke="url(#expenseLineGradient)" 
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="animate-draw-line"
                        style="--line-length: 1000; animation-delay: 0.3s"
                    />
                    
                    <!-- Data points for income (always visible) -->
                    <g class="data-points">
                        <circle
                            v-for="(d, i) in data"
                            :key="`income-${i}`"
                            :cx="getPointPosition(i, 'income').x"
                            :cy="getPointPosition(i, 'income').y"
                            r="4"
                            fill="white"
                            stroke="#22c55e"
                            stroke-width="2"
                            class="cursor-pointer transition-all duration-200 hover:scale-150 animate-pop-in"
                            :style="`animation-delay: ${0.8 + i * 0.1}s`"
                            @mouseenter="handlePointHover(i)"
                            @mouseleave="handlePointLeave"
                        />
                    </g>
                    
                    <!-- Data points for expense (always visible) -->
                    <g class="data-points">
                        <circle
                            v-for="(d, i) in data"
                            :key="`expense-${i}`"
                            :cx="getPointPosition(i, 'expense').x"
                            :cy="getPointPosition(i, 'expense').y"
                            r="4"
                            fill="white"
                            stroke="#f97316"
                            stroke-width="2"
                            class="cursor-pointer transition-all duration-200 hover:scale-150 animate-pop-in"
                            :style="`animation-delay: ${1.1 + i * 0.1}s`"
                            @mouseenter="handlePointHover(i)"
                            @mouseleave="handlePointLeave"
                        />
                    </g>
                    
                    <!-- Invisible larger hit areas for easier hover -->
                    <g class="hit-areas">
                        <rect
                            v-for="(d, i) in data"
                            :key="`hit-${i}`"
                            :x="padding.left + (i / Math.max(data.length - 1, 1)) * graphWidth - 15"
                            :y="padding.top"
                            width="30"
                            :height="graphHeight"
                            fill="transparent"
                            class="cursor-pointer"
                            @mouseenter="handlePointHover(i)"
                            @mouseleave="handlePointLeave"
                        />
                    </g>
                </svg>
                
                <!-- Tooltip -->
                <div 
                    v-if="hoveredPoint"
                    class="absolute bg-gray-900 text-white text-xs rounded-lg px-3 py-2 shadow-lg z-10 pointer-events-none"
                    :style="{ 
                        left: `${(hoveredPoint.x / chartWidth) * 100}%`, 
                        top: `${(hoveredPoint.y / chartHeight) * 100}%`,
                        transform: 'translateX(-50%)'
                    }"
                >
                    <div class="font-semibold mb-1">{{ hoveredPoint.day }}</div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                        <span>{{ formatCurrency(hoveredPoint.income) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-red-500"></span>
                        <span>{{ formatCurrency(hoveredPoint.expense) }}</span>
                    </div>
                </div>
                
                <!-- Legend -->
                <div class="flex items-center justify-center gap-6 mt-3">
                    <div class="flex items-center gap-2">
                        <div class="w-5 h-1 rounded-full bg-gradient-to-r from-green-300 via-green-500 to-green-600"></div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Pemasukan</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-5 h-1 rounded-full bg-gradient-to-r from-orange-300 via-orange-500 to-orange-600"></div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Pengeluaran</span>
                    </div>
                </div>
            </template>
            
            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center h-48 text-center">
                <div class="w-14 h-14 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                    </svg>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada data tren</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Line drawing animation */
.animate-draw-line {
    stroke-dasharray: var(--line-length, 1000);
    stroke-dashoffset: var(--line-length, 1000);
    animation: draw-line 1.5s ease-out forwards;
}

@keyframes draw-line {
    to {
        stroke-dashoffset: 0;
    }
}

/* Fade in animation for area fills */
.animate-fade-in {
    opacity: 0;
    animation: fade-in 1s ease-out 0.5s forwards;
}

@keyframes fade-in {
    to {
        opacity: 1;
    }
}

/* Pop-in animation for data points */
.animate-pop-in {
    opacity: 0;
    transform: scale(0);
    transform-origin: center;
    animation: pop-in 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

@keyframes pop-in {
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* Data point hover effect */
.data-points circle {
    transform-origin: center;
}

.data-points circle:hover {
}
</style>
