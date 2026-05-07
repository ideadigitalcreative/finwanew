<script setup lang="ts">
import { TrendingDown, TrendingUp, Zap } from 'lucide-vue-next';

interface Props {
    expense: number;
    period: string;
    changePercent: number;
    saved: number;
    targetPercent: number;
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
};
</script>

<template>
    <div class="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 transition-all duration-500 animate-fade-in-up" style="animation-delay: 0.3s">
        <div class="flex items-center justify-between mb-2 md:mb-2.5">
            <span class="text-xs text-muted-foreground bg-muted/30 backdrop-blur-sm px-2 py-0.5 rounded-full">{{ period }}</span>
        </div>
        
        <div class="mb-3 md:mb-4">
            <span class="amount-primary text-2xl md:text-3xl">{{ formatCurrency(expense) }}</span>
            <p class="text-sm text-muted-foreground mt-0.5">Total pengeluaran</p>
        </div>
        
        <div class="flex items-center gap-2 md:gap-3 mb-4 md:mb-5 flex-wrap">
            <!-- Dynamic Change Indicator -->
            <div v-if="changePercent <= 0" class="flex items-center gap-1 md:gap-1.5 bg-primary/10 backdrop-blur-sm px-2 py-0.5 rounded-full">
                <TrendingDown class="w-3.5 h-3.5 md:w-3.5 md:h-3.5 text-primary" />
                <span class="text-xs text-muted-foreground">Turun</span>
                <span class="text-sm font-medium text-primary">{{ Math.abs(changePercent) }}%</span>
            </div>
            <div v-else class="flex items-center gap-1 md:gap-1.5 bg-destructive/10 backdrop-blur-sm px-2 py-0.5 rounded-full">
                <TrendingUp class="w-3.5 h-3.5 md:w-3.5 md:h-3.5 text-destructive" />
                <span class="text-xs text-muted-foreground">Naik</span>
                <span class="text-sm font-medium text-destructive">{{ changePercent }}%</span>
            </div>

            <div class="flex items-center gap-1 md:gap-1.5 bg-primary/10 backdrop-blur-sm px-2 py-0.5 rounded-full">
                <TrendingUp class="w-3.5 h-3.5 md:w-3.5 md:h-3.5 text-primary" />
                <span class="text-xs text-muted-foreground">Hemat</span>
                <span class="text-sm font-medium text-primary">+{{ formatCurrency(saved) }}</span>
            </div>
        </div>
        
        <!-- Scale indicator -->
        <div class="flex items-center justify-between text-xs text-muted-foreground mb-1.5 px-1">
            <span>0</span>
            <span>50</span>
            <span>100</span>
        </div>
        
        <!-- Progress bar with markers -->
        <div class="relative h-2.5 md:h-3 bg-muted/30 backdrop-blur-sm rounded-full overflow-hidden mb-3 md:mb-4 border border-border/20">
            <div 
                class="absolute top-0 left-0 h-full bg-gradient-to-r from-primary/60 via-primary to-chart-expense rounded-full transition-all duration-500 group-hover:from-primary/80"
                :style="{ width: targetPercent + '%' }"
            />
            <!-- Marker lines -->
            <div class="absolute top-0 left-0 w-full h-full flex">
                <div v-for="i in 20" :key="i" class="flex-1 border-r border-background/10 last:border-r-0" />
            </div>
        </div>
        
        <div class="text-center bg-accent/20 backdrop-blur-sm rounded-lg p-2 border border-accent/20">
            <p class="text-xs text-muted-foreground mb-0.5">{{ period }}</p>
            <p class="text-sm text-foreground font-medium flex items-center justify-center gap-1">
                Target tercapai {{ targetPercent }}% <Zap class="w-3.5 h-3.5 text-warning" />
            </p>
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
