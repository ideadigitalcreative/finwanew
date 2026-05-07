<script setup lang="ts">
import { TrendingDown, TrendingUp } from 'lucide-vue-next';

interface BreakdownItem {
    label: string;
    amount: number;
}

interface Props {
    income: number;
    period: string;
    changePercent: number;
    gained: number;
    breakdown: BreakdownItem[];
}

const props = withDefaults(defineProps<Props>(), {
    breakdown: () => []
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
    <div class="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 transition-all duration-500 animate-fade-in-up" style="animation-delay: 0.2s">
        <div class="flex items-center justify-between mb-3 md:mb-4">
            <h3 class="text-sm md:text-base font-medium text-muted-foreground">Pemasukan Saya</h3>
            <span class="text-xs text-muted-foreground bg-muted/30 backdrop-blur-sm px-2 py-1 rounded-full">{{ period }}</span>
        </div>
        
        <div class="flex items-end justify-between mb-4 md:mb-5">
            <div>
                <span class="amount-primary text-3xl md:text-4xl">{{ formatFull(income) }}</span>
            </div>
        </div>
        
        <div class="flex items-center gap-2 md:gap-3 mb-4 md:mb-5 flex-wrap">
            <!-- Dynamic Change Indicator -->
            <div v-if="changePercent >= 0" class="flex items-center gap-1.5 md:gap-2 bg-primary/10 backdrop-blur-sm px-2.5 py-1 rounded-full">
                <TrendingUp class="w-3.5 h-3.5 md:w-4 md:h-4 text-primary" />
                <span class="text-xs text-muted-foreground">Naik</span>
                <span class="text-sm font-medium text-primary">{{ changePercent }}%</span>
            </div>
            <div v-else class="flex items-center gap-1.5 md:gap-2 bg-destructive/10 backdrop-blur-sm px-2.5 py-1 rounded-full">
                <TrendingDown class="w-3.5 h-3.5 md:w-4 md:h-4 text-destructive" />
                <span class="text-xs text-muted-foreground">Turun</span>
                <span class="text-sm font-medium text-destructive">{{ Math.abs(changePercent) }}%</span>
            </div>

            <div class="flex items-center gap-1.5 md:gap-2 bg-primary/10 backdrop-blur-sm px-2.5 py-1 rounded-full">
                <TrendingUp class="w-3.5 h-3.5 md:w-4 md:h-4 text-primary" />
                <span class="text-xs text-muted-foreground">Diperoleh</span>
                <span class="text-sm font-medium text-primary">+{{ formatCurrency(gained) }}</span>
            </div>
        </div>
        
        <div class="grid grid-cols-3 gap-2 md:gap-3 pt-3 md:pt-4 border-t border-border/30">
            <div v-for="(item, index) in breakdown" :key="index" class="text-center p-2 rounded-lg bg-muted/20 backdrop-blur-sm group-hover:bg-muted/30 transition-all">
                <p class="text-xs text-muted-foreground mb-1">{{ item.label }}</p>
                <p class="text-sm md:text-base font-semibold text-foreground">{{ formatCurrency(item.amount) }}</p>
            </div>
            <!-- Empty filler -->
            <div v-if="breakdown.length === 0" class="col-span-3 text-center text-xs text-muted-foreground py-2">
                Belum ada data
            </div>
        </div>
    </div>
</template>

<style scoped>
.amount-primary {
    font-variant-numeric: tabular-nums;
}

.animate-fade-in-up {
    animation: fade-in-up 0.5s ease-out forwards;
    opacity: 0;
}

@keyframes fade-in-up {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
