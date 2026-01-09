<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { PieChart } from 'lucide-vue-next';

interface Category {
    label: string;
    amount: number;
    percent: number;
}

interface Props {
    categories: Category[];
    totalExpense?: number;
}

const props = withDefaults(defineProps<Props>(), {
    categories: () => [],
    totalExpense: 0
});

// Colors for pie chart segments - vibrant and modern
const colors = [
    '#10b981', // emerald-500
    '#3b82f6', // blue-500
    '#f59e0b', // amber-500
    '#ef4444', // red-500
    '#8b5cf6', // violet-500
    '#ec4899', // pink-500
    '#06b6d4', // cyan-500
    '#84cc16', // lime-500
];

const formatCurrency = (amount: number) => {
    if (amount >= 1000000) {
        return `Rp ${(amount / 1000000).toFixed(1)}jt`;
    } else if (amount >= 1000) {
        return `Rp ${(amount / 1000).toFixed(0)}rb`;
    }
    return `Rp ${amount.toLocaleString('id-ID')}`;
};

// Calculate pie chart segments
const pieSegments = computed(() => {
    if (!props.categories || props.categories.length === 0) {
        return [];
    }
    
    let cumulativePercent = 0;
    return props.categories.map((cat, index) => {
        const startPercent = cumulativePercent;
        cumulativePercent += cat.percent;
        
        return {
            ...cat,
            color: colors[index % colors.length],
            startPercent,
            endPercent: cumulativePercent
        };
    });
});

// SVG path for pie segment
const getSegmentPath = (startPercent: number, endPercent: number, radius: number = 80) => {
    const centerX = 100;
    const centerY = 100;
    
    // Convert percentages to angles
    const startAngle = (startPercent / 100) * 360 - 90;
    const endAngle = (endPercent / 100) * 360 - 90;
    
    // Convert angles to radians
    const startRad = (startAngle * Math.PI) / 180;
    const endRad = (endAngle * Math.PI) / 180;
    
    // Calculate arc points
    const x1 = centerX + radius * Math.cos(startRad);
    const y1 = centerY + radius * Math.sin(startRad);
    const x2 = centerX + radius * Math.cos(endRad);
    const y2 = centerY + radius * Math.sin(endRad);
    
    // Determine if it's a large arc (> 180 degrees)
    const largeArc = endPercent - startPercent > 50 ? 1 : 0;
    
    // Create SVG path
    return `M ${centerX} ${centerY} L ${x1} ${y1} A ${radius} ${radius} 0 ${largeArc} 1 ${x2} ${y2} Z`;
};

const hasData = computed(() => props.categories && props.categories.length > 0);
</script>

<template>
    <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-xl h-full rounded-[13px] flex flex-col shadow-lg shadow-gray-200/50 dark:shadow-gray-900/30 border border-gray-100 dark:border-gray-700/30">
        <!-- Header -->
        <div class="p-4 md:p-5 border-b border-gray-100 dark:border-gray-700/50">
            <div>
                <h3 class="font-bold text-lg text-gray-900 dark:text-white">Kategori Pengeluaran</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Distribusi belanja bulan ini</p>
            </div>
        </div>
        
        <!-- Content -->
        <div class="flex-1 p-4 md:p-5">
            <div v-if="hasData" class="flex flex-col md:flex-row items-center gap-6">
                <!-- Pie Chart -->
                <div class="relative w-56 h-56 flex-shrink-0">
                    <svg viewBox="0 0 200 200" class="w-full h-full transform -rotate-90">
                        <!-- Background circle -->
                        <circle cx="100" cy="100" r="80" fill="none" stroke="currentColor" stroke-width="2" class="text-gray-100 dark:text-gray-700" />
                        
                        <!-- Pie segments -->
                        <path
                            v-for="(segment, index) in pieSegments"
                            :key="index"
                            :d="getSegmentPath(segment.startPercent, segment.endPercent)"
                            :fill="segment.color"
                            class="transition-all duration-500 hover:opacity-80 cursor-pointer"
                            :style="{ filter: 'drop-shadow(0 2px 4px rgba(0,0,0,0.1))' }"
                        />
                        
                        <!-- Center hole (donut style - thinner ring) -->
                        <circle cx="100" cy="100" r="55" class="fill-white dark:fill-gray-800" />
                    </svg>
                    
                    <!-- Center text -->
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-lg font-bold text-gray-900 dark:text-white">{{ formatCurrency(totalExpense) }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Total</span>
                    </div>
                </div>
                
                <!-- Legend -->
                <div class="flex-1 w-full">
                    <div class="space-y-3">
                        <div
                            v-for="(segment, index) in pieSegments"
                            :key="index"
                            class="flex items-center justify-between gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <div 
                                    class="w-3 h-3 rounded-full flex-shrink-0"
                                    :style="{ backgroundColor: segment.color }"
                                />
                                <span class="text-sm text-gray-700 dark:text-gray-300 truncate">{{ segment.label }}</span>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ segment.percent }}%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center h-40 text-center">
                <div class="w-14 h-14 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-3">
                    <PieChart class="w-7 h-7 text-gray-400" />
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada data pengeluaran</p>
            </div>
        </div>
    </div>
</template>
