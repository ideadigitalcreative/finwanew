<script setup lang="ts">
import { computed, ref } from 'vue';

interface DayData {
    day: string;
    income: number;
    expense: number;
}

interface Props {
    dailyData?: DayData[];
    monthlyData?: DayData[];
    // Legacy support — jika hanya chartData yang dikirim
    chartData?: DayData[];
    period?: string;
    uid?: string;
}

const props = withDefaults(defineProps<Props>(), {
    dailyData: () => [],
    monthlyData: () => [],
    chartData: () => [],
    period: 'Bulan Ini',
    uid: 'default',
});

// Toggle state — default: bulanan
type ViewMode = 'monthly' | 'daily';
const viewMode = ref<ViewMode>('monthly');

// Data aktif berdasarkan toggle
const data = computed<DayData[]>(() => {
    if (viewMode.value === 'daily') {
        return props.dailyData.length > 0 ? props.dailyData : props.chartData;
    }
    return props.monthlyData.length > 0 ? props.monthlyData : props.chartData;
});

const hasData = computed(() => data.value.length > 0);

// Label periode
const periodLabel = computed(() => {
    if (viewMode.value === 'daily') return '7 hari terakhir';
    return props.period || '6 bulan terakhir';
});

// Tooltip state
const hoveredIndex = ref<number | null>(null);

// Chart dimensions — viewBox lebih lebar agar elemen proporsional di desktop
const chartWidth = 600;
const chartHeight = 200;
const padding = { top: 20, right: 20, bottom: 30, left: 50 };
const graphWidth = chartWidth - padding.left - padding.right;
const graphHeight = chartHeight - padding.top - padding.bottom;

// Max value untuk scaling
const maxValue = computed(() => {
    if (!hasData.value) return 1000000;
    let max = 0;
    data.value.forEach(d => {
        max = Math.max(max, d.income, d.expense);
    });
    return Math.ceil(max / 500000) * 500000 || 1000000;
});

// Y-axis ticks
const yTicks = computed(() => {
    const ticks = [];
    const step = maxValue.value / 4;
    for (let i = 0; i <= 4; i++) {
        ticks.push(i * step);
    }
    return ticks;
});

const formatCurrency = (value: number) => {
    if (value >= 1000000) return `Rp ${(value / 1000000).toFixed(1)}jt`;
    if (value >= 1000) return `Rp ${Math.round(value / 1000)}rb`;
    return `Rp ${value.toLocaleString('id-ID')}`;
};

const formatYAxis = (value: number) => {
    if (value >= 1000000) return `${(value / 1000000).toFixed(1)}jt`;
    if (value >= 1000) return `${Math.round(value / 1000)}rb`;
    return value.toString();
};

const getX = (index: number) =>
    padding.left + (index / Math.max(data.value.length - 1, 1)) * graphWidth;

const getY = (value: number) =>
    padding.top + graphHeight - (value / maxValue.value) * graphHeight;

const getPointPosition = (index: number, key: 'income' | 'expense') => ({
    x: getX(index),
    y: getY(data.value[index][key]),
});

const generatePath = (key: 'income' | 'expense') => {
    if (!hasData.value) return '';
    const points = data.value.map((d, i) => ({ x: getX(i), y: getY(d[key]) }));
    if (points.length === 0) return '';
    if (points.length === 1) return `M ${points[0].x} ${points[0].y}`;
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

// Tooltip data
const tooltipData = computed(() => {
    if (hoveredIndex.value === null || !hasData.value) return null;
    const i = hoveredIndex.value;
    const d = data.value[i];
    const incomePos = getPointPosition(i, 'income');
    const expensePos = getPointPosition(i, 'expense');
    const topY = Math.min(incomePos.y, expensePos.y);
    return {
        x: getX(i),
        y: topY - 10,
        income: d.income,
        expense: d.expense,
        day: d.day,
    };
});

const handleHover = (index: number) => { hoveredIndex.value = index; };
const handleLeave = () => { hoveredIndex.value = null; };
</script>

<template>
    <div class="bg-white dark:bg-[#23231f] rounded-2xl flex flex-col border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif] transition-all duration-300">
        <!-- Header -->
        <div class="p-4 md:p-5 border-b border-[#f0eee8] dark:border-white/10">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="font-bold text-base md:text-lg text-[#1b1c19] dark:text-foreground leading-tight flex items-center gap-2">
                        <span class="w-6 h-6 rounded-lg bg-[#ffd23f] text-[#574500] flex items-center justify-center">
                            <span class="material-symbols-outlined text-[14px]">trending_up</span>
                        </span>
                        Tren Keuangan
                    </h3>
                    <p class="text-xs md:text-sm text-[#4d4634] dark:text-muted-foreground">Pemasukan vs Pengeluaran</p>
                </div>

                <!-- Toggle Harian / Bulanan -->
                <div class="flex items-center flex-shrink-0 rounded-full bg-[#f0eee8] dark:bg-white/10 p-0.5 border border-[#eae8e2] dark:border-white/10">
                    <button
                        @click="viewMode = 'daily'"
                        :class="[
                            'px-2.5 py-1 rounded-full text-xs font-semibold transition-all duration-200',
                            viewMode === 'daily'
                                ? 'bg-white dark:bg-[#1b1c19] text-[#725a00] dark:text-white shadow-sm'
                                : 'text-[#4d4634] dark:text-muted-foreground hover:text-[#1b1c19] dark:hover:text-white'
                        ]"
                    >
                        Harian
                    </button>
                    <button
                        @click="viewMode = 'monthly'"
                        :class="[
                            'px-2.5 py-1 rounded-full text-xs font-semibold transition-all duration-200',
                            viewMode === 'monthly'
                                ? 'bg-white dark:bg-[#1b1c19] text-[#725a00] dark:text-white shadow-sm'
                                : 'text-[#4d4634] dark:text-muted-foreground hover:text-[#1b1c19] dark:hover:text-white'
                        ]"
                    >
                        Bulanan
                    </button>
                </div>
            </div>

            <!-- Sub-label periode -->
            <p class="mt-1.5 text-[11px] text-[#9ca3af]">{{ periodLabel }}</p>
        </div>

        <!-- Chart -->
        <div class="p-3 md:p-5 relative overflow-hidden">
            <template v-if="hasData">
                <svg
                    :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                    class="w-full h-auto"
                    preserveAspectRatio="xMidYMid meet"
                >
                    <defs>
                        <linearGradient :id="`${uid}-${viewMode}-incomeGrad`" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#0d9488" stop-opacity="0.3" />
                            <stop offset="100%" stop-color="#0d9488" stop-opacity="0" />
                        </linearGradient>
                        <linearGradient :id="`${uid}-${viewMode}-expenseGrad`" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#e11d48" stop-opacity="0.25" />
                            <stop offset="100%" stop-color="#e11d48" stop-opacity="0" />
                        </linearGradient>
                        <linearGradient :id="`${uid}-${viewMode}-incomeLineGrad`" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#5eead4" />
                            <stop offset="50%" stop-color="#14b8a6" />
                            <stop offset="100%" stop-color="#0f766e" />
                        </linearGradient>
                        <linearGradient :id="`${uid}-${viewMode}-expenseLineGrad`" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#fda4af" />
                            <stop offset="50%" stop-color="#f43f5e" />
                            <stop offset="100%" stop-color="#be123c" />
                        </linearGradient>
                    </defs>

                    <!-- Grid lines -->
                    <g>
                        <line
                            v-for="(tick, i) in yTicks"
                            :key="i"
                            :x1="padding.left"
                            :y1="getY(tick)"
                            :x2="padding.left + graphWidth"
                            :y2="getY(tick)"
                            stroke="currentColor"
                            stroke-width="1"
                            stroke-dasharray="4,4"
                            vector-effect="non-scaling-stroke"
                            class="text-gray-100 dark:text-white/10"
                        />
                    </g>

                    <!-- Y-axis labels -->
                    <g>
                        <text
                            v-for="(tick, i) in yTicks"
                            :key="i"
                            :x="padding.left - 8"
                            :y="getY(tick) + 4"
                            text-anchor="end"
                            class="text-[10px] fill-gray-400"
                        >{{ formatYAxis(tick) }}</text>
                    </g>

                    <!-- X-axis labels -->
                    <g>
                        <text
                            v-for="(d, i) in data"
                            :key="i"
                            :x="getX(i)"
                            :y="chartHeight - 8"
                            text-anchor="middle"
                            class="text-[10px] fill-gray-400"
                        >{{ d.day }}</text>
                    </g>

                    <!-- Income area -->
                    <path
                        :key="`income-area-${viewMode}`"
                        :d="incomePath + ` L ${padding.left + graphWidth} ${padding.top + graphHeight} L ${padding.left} ${padding.top + graphHeight} Z`"
                        :fill="`url(#${uid}-${viewMode}-incomeGrad)`"
                        class="animate-fade-in"
                    />

                    <!-- Expense area -->
                    <path
                        :key="`expense-area-${viewMode}`"
                        :d="expensePath + ` L ${padding.left + graphWidth} ${padding.top + graphHeight} L ${padding.left} ${padding.top + graphHeight} Z`"
                        :fill="`url(#${uid}-${viewMode}-expenseGrad)`"
                        class="animate-fade-in"
                    />

                    <!-- Income line -->
                    <path
                        :key="`income-line-${viewMode}`"
                        :d="incomePath"
                        fill="none"
                        :stroke="`url(#${uid}-${viewMode}-incomeLineGrad)`"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                        class="animate-draw-line"
                    />

                    <!-- Expense line -->
                    <path
                        :key="`expense-line-${viewMode}`"
                        :d="expensePath"
                        fill="none"
                        :stroke="`url(#${uid}-${viewMode}-expenseLineGrad)`"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                        class="animate-draw-line"
                        style="animation-delay: 0.3s"
                    />

                    <!-- Income dots -->
                    <circle
                        v-for="(d, i) in data"
                        :key="`income-dot-${viewMode}-${i}`"
                        :cx="getPointPosition(i, 'income').x"
                        :cy="getPointPosition(i, 'income').y"
                        r="4"
                        fill="white"
                        stroke="#0d9488"
                        stroke-width="2"
                        vector-effect="non-scaling-stroke"
                        :class="['cursor-pointer transition-all duration-200 animate-pop-in', hoveredIndex === i ? 'r-6' : '']"
                        :style="`animation-delay: ${0.8 + i * 0.08}s`"
                        @mouseenter="handleHover(i)"
                        @mouseleave="handleLeave"
                        @touchstart.prevent="handleHover(i)"
                        @touchend.prevent="handleLeave"
                    />

                    <!-- Expense dots -->
                    <circle
                        v-for="(d, i) in data"
                        :key="`expense-dot-${viewMode}-${i}`"
                        :cx="getPointPosition(i, 'expense').x"
                        :cy="getPointPosition(i, 'expense').y"
                        r="4"
                        fill="white"
                        stroke="#f97316"
                        stroke-width="2"
                        vector-effect="non-scaling-stroke"
                        :class="['cursor-pointer transition-all duration-200 animate-pop-in', hoveredIndex === i ? 'r-6' : '']"
                        :style="`animation-delay: ${1.1 + i * 0.08}s`"
                        @mouseenter="handleHover(i)"
                        @mouseleave="handleLeave"
                        @touchstart.prevent="handleHover(i)"
                        @touchend.prevent="handleLeave"
                    />

                    <!-- Hit areas (invisible, wider for easier touch/hover) -->
                    <rect
                        v-for="(d, i) in data"
                        :key="`hit-${i}`"
                        :x="getX(i) - 15"
                        :y="padding.top"
                        width="30"
                        :height="graphHeight"
                        fill="transparent"
                        class="cursor-pointer"
                        @mouseenter="handleHover(i)"
                        @mouseleave="handleLeave"
                        @touchstart.prevent="handleHover(i)"
                        @touchend.prevent="handleLeave"
                    />
                </svg>

                <!-- Tooltip -->
                <Transition name="tooltip-fade">
                    <div
                        v-if="tooltipData"
                        class="absolute bg-gray-900/95 dark:bg-gray-950/95 text-white text-xs rounded-xl px-3 py-2.5 shadow-xl z-20 pointer-events-none border border-white/10"
                        :style="{
                            left: `${Math.min(Math.max((tooltipData.x / chartWidth) * 100, 10), 80)}%`,
                            top: `${Math.max((tooltipData.y / chartHeight) * 100, 5)}%`,
                            transform: 'translateX(-50%)',
                        }"
                    >
                        <div class="font-bold mb-1.5 text-gray-200">{{ tooltipData.day }}</div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-2 h-2 rounded-full bg-[#14b8a6] flex-shrink-0"></span>
                            <span class="text-[#5eead4]">{{ formatCurrency(tooltipData.income) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-[#f43f5e] flex-shrink-0"></span>
                            <span class="text-[#fda4af]">{{ formatCurrency(tooltipData.expense) }}</span>
                        </div>
                    </div>
                </Transition>

                <!-- Legend -->
                <div class="flex items-center justify-center gap-5 mt-2">
                    <div class="flex items-center gap-1.5">
                        <div class="w-5 h-1 rounded-full bg-[#51fac1]"></div>
                        <span class="text-xs text-[#4d4634] dark:text-muted-foreground">Pemasukan</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-5 h-1 rounded-full bg-[#ffc9d0]"></div>
                        <span class="text-xs text-[#4d4634] dark:text-muted-foreground">Pengeluaran</span>
                    </div>
                </div>
            </template>

            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center h-48 text-center">
                <div class="w-14 h-14 rounded-full bg-[#f0eee8] dark:bg-white/10 flex items-center justify-center mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-[#9ca3af]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                    </svg>
                </div>
                <p class="text-sm font-medium text-[#4d4634] dark:text-muted-foreground">Belum ada data tren</p>
                <p class="text-xs text-[#9ca3af] mt-1">
                    {{ viewMode === 'daily' ? 'Catat transaksi hari ini' : 'Data akan muncul setelah ada transaksi' }}
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.animate-draw-line {
    stroke-dasharray: 1000;
    stroke-dashoffset: 1000;
    animation: draw-line 1.5s ease-out forwards;
}
@keyframes draw-line {
    to { stroke-dashoffset: 0; }
}

.animate-fade-in {
    opacity: 0;
    animation: fade-in 1s ease-out 0.5s forwards;
}
@keyframes fade-in {
    to { opacity: 1; }
}

.animate-pop-in {
    opacity: 0;
    transform: scale(0);
    transform-origin: center;
    animation: pop-in 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
@keyframes pop-in {
    to { opacity: 1; transform: scale(1); }
}

.tooltip-fade-enter-active,
.tooltip-fade-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.tooltip-fade-enter-from,
.tooltip-fade-leave-to {
    opacity: 0;
    transform: translateX(-50%) translateY(-4px);
}
</style>
