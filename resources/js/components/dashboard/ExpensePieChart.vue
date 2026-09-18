<script setup lang="ts">
import { computed } from 'vue';

const formatNumber = (num: number) => {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
};

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

// Color palette tema CuanCeria
const colors = [
    '#ffd23f', // kuning utama
    '#51fac1', // mint
    '#e36c8b', // pink
    '#ffe089', // kuning muda
    '#ffc9d0', // pink muda
    '#96a785', // sage
    '#f2b8c6', // rose muda
    '#b9d8c4', // mint muda
];

const hasData = computed(() => props.categories && props.categories.length > 0);

// Calculate pie chart segments with more details
const pieSegments = computed(() => {
    if (!props.categories || props.categories.length === 0) {
        return [];
    }
    
    let cumulativePercent = 0;
    const segments = props.categories.map((cat, index) => {
        const startPercent = cumulativePercent;
        cumulativePercent += cat.percent;
        const midPercent = startPercent + (cat.percent / 2);
        
        return {
            ...cat,
            color: colors[index % colors.length],
            startPercent,
            endPercent: cumulativePercent,
            midPercent,
            index
        };
    });

    const centerX = 200;
    const centerY = 150;
    const labelRadius = 135; // Increased from 120
    const leaderRadius = 110; // Increased from 95

    const positions = segments.map(segment => {
        const midRad = ((segment.midPercent / 100) * 360 - 90) * (Math.PI / 180);
        const leaderX = centerX + leaderRadius * Math.cos(midRad);
        const leaderY = centerY + leaderRadius * Math.sin(midRad);
        let labelX = centerX + labelRadius * Math.cos(midRad);
        let labelY = centerY + labelRadius * Math.sin(midRad);
        const isLeftSide = midRad < -Math.PI / 2 || midRad > Math.PI / 2;
        const extensionX = labelX + (isLeftSide ? -25 : 25);
        
        return {
            ...segment,
            leaderX, leaderY, labelX, labelY, extensionX, isLeftSide
        };
    });

    // Simple vertical collision resolution
    const leftSide = positions.filter(p => p.isLeftSide).sort((a, b) => a.labelY - b.labelY);
    const rightSide = positions.filter(p => !p.isLeftSide).sort((a, b) => a.labelY - b.labelY);
    const minSpacing = 28;

    for (let i = 1; i < rightSide.length; i++) {
        if (rightSide[i].labelY - rightSide[i-1].labelY < minSpacing) {
            rightSide[i].labelY = rightSide[i-1].labelY + minSpacing;
        }
    }
    for (let i = 1; i < leftSide.length; i++) {
        if (leftSide[i].labelY - leftSide[i-1].labelY < minSpacing) {
            leftSide[i].labelY = leftSide[i-1].labelY + minSpacing;
        }
    }

    // Reassemble and return
    return positions;
});

// Convert percentage to angle in radians
const percentToRad = (percent: number) => {
    return ((percent / 100) * 360 - 90) * (Math.PI / 180);
};

// SVG path for donut segment
const getDonutPath = (startPercent: number, endPercent: number, outerRadius: number = 105, innerRadius: number = 75) => {
    const centerX = 200;
    const centerY = 150;
    
    const startRad = percentToRad(startPercent);
    const endRad = percentToRad(endPercent);
    
    const x1 = centerX + outerRadius * Math.cos(startRad);
    const y1 = centerY + outerRadius * Math.sin(startRad);
    const x2 = centerX + outerRadius * Math.cos(endRad);
    const y2 = centerY + outerRadius * Math.sin(endRad);
    
    const x3 = centerX + innerRadius * Math.cos(endRad);
    const y3 = centerY + innerRadius * Math.sin(endRad);
    const x4 = centerX + innerRadius * Math.cos(startRad);
    const y4 = centerY + innerRadius * Math.sin(startRad);
    
    const largeArc = endPercent - startPercent > 50 ? 1 : 0;
    
    return [
        `M ${x1} ${y1}`,
        `A ${outerRadius} ${outerRadius} 0 ${largeArc} 1 ${x2} ${y2}`,
        `L ${x3} ${y3}`,
        `A ${innerRadius} ${innerRadius} 0 ${largeArc} 0 ${x4} ${y4}`,
        'Z'
    ].join(' ');
};

// Split label into 2 lines if longer than maxLength
const splitLabel = (label: string, maxLength: number = 14) => {
    if (label.length <= maxLength) {
        return [label];
    }

    const words = label.split(' ');
    if (words.length === 1) {
        // Single long word - split in the middle
        const mid = Math.ceil(label.length / 2);
        return [label.slice(0, mid) + '-', label.slice(mid)];
    }

    // Find best split point
    let line1 = '';
    let line2 = '';
    const midPoint = Math.floor(words.length / 2);

    line1 = words.slice(0, midPoint).join(' ');
    line2 = words.slice(midPoint).join(' ');

    return [line1, line2];
};
</script>

<template>
    <div class="bg-gradient-to-br from-[#fffdfa] via-white to-[#f4fbf7] dark:from-[#1f201c] dark:via-[#23231f] dark:to-[#1a2520] h-full rounded-2xl flex flex-col border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif] shadow-sm">
        <!-- Header -->
        <div class="p-4 md:p-5 border-b border-[#eae8e2]/70 dark:border-white/10">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-[#51fac1]/30 text-[#006c4f] dark:text-[#51fac1]">
                    <span class="material-symbols-outlined text-[20px]">donut_small</span>
                </span>
                <div>
                    <h3 class="font-bold text-[15px] text-[#1b1c19] dark:text-white leading-tight">Kategori Pengeluaran</h3>
                    <p class="text-[11px] text-[#4d4634] dark:text-white/50">Distribusi belanja bulan ini</p>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="flex-1 p-2 md:p-4 overflow-hidden">
            <div v-if="hasData" class="relative">
                <!-- Donut Chart with Labels - Responsive size -->
                <svg viewBox="0 0 500 300" class="w-full max-w-full mx-auto max-w-[500px]">
                    <!-- Donut segments -->
                    <g transform="translate(50, 0)">
                        <path
                            v-for="(segment, index) in pieSegments"
                            :key="index"
                            :d="getDonutPath(segment.startPercent, segment.endPercent)"
                            :fill="segment.color"
                            class="transition-all duration-500 hover:opacity-80 cursor-pointer stroke-[#f5f3ee] dark:stroke-[#23231f]"
                            stroke-width="3"
                        />
                    </g>
                    
                    <!-- Leader lines and labels -->
                    <g v-for="(segment, index) in pieSegments" :key="'label-' + index">
                        <g v-if="segment.percent > 0" transform="translate(50, 0)">
                            <line
                                :x1="segment.leaderX"
                                :y1="segment.leaderY"
                                :x2="segment.labelX"
                                :y2="segment.labelY"
                                stroke="#c7c2b4"
                                stroke-width="1"
                            />
                            <line
                                :x1="segment.labelX"
                                :y1="segment.labelY"
                                :x2="segment.extensionX"
                                :y2="segment.labelY"
                                stroke="#c7c2b4"
                                stroke-width="1"
                            />
                            <text
                                :x="segment.extensionX + (segment.isLeftSide ? -6 : 6)"
                                :y="segment.labelY - 3"
                                :text-anchor="segment.isLeftSide ? 'end' : 'start'"
                                class="text-[10px] font-extrabold fill-[#1b1c19] dark:fill-white"
                            >
                                {{ segment.percent.toFixed(1) }}%
                            </text>
                            <text
                                :x="segment.extensionX + (segment.isLeftSide ? -6 : 6)"
                                :y="segment.labelY + 9"
                                :text-anchor="segment.isLeftSide ? 'end' : 'start'"
                                class="text-[10px] fill-[#4d4634] dark:fill-white/60"
                            >
                                <tspan
                                    v-for="(line, lineIndex) in splitLabel(segment.label)"
                                    :key="lineIndex"
                                    :x="segment.extensionX + (segment.isLeftSide ? -6 : 6)"
                                    :dy="lineIndex === 0 ? 0 : '1.1em'"
                                >{{ line }}</tspan>
                            </text>
                        </g>
                    </g>
                    
                    <!-- Center total -->
                    <g transform="translate(50, 0)">
                        <text x="200" y="140" text-anchor="middle" class="text-sm font-medium fill-[#4d4634] dark:fill-white/50">
                            Total
                        </text>
                        <text x="200" y="170" text-anchor="middle" class="text-2xl font-extrabold fill-[#1b1c19] dark:fill-white">
                            {{ formatNumber(totalExpense) }}
                        </text>
                    </g>
                </svg>
            </div>
            
            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center h-40 text-center">
                <div class="w-14 h-14 rounded-full bg-[#f5f3ee] dark:bg-white/5 flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-[28px] text-[#4d4634] dark:text-white/50">donut_small</span>
                </div>
                <p class="text-sm text-[#4d4634] dark:text-white/50">Belum ada data pengeluaran</p>
            </div>
        </div>
    </div>
</template>
