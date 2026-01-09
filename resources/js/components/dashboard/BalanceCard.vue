<script setup lang="ts">
import { ChevronDown, Wallet, TrendingUp } from 'lucide-vue-next';

interface Props {
    balance: number;
    lastIncome: number;
    bonus: number;
    period?: string;
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
};
</script>

<template>
    <div class="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 animate-fade-in-up" style="animation-delay: 0.1s">
        <div class="flex items-center justify-between mb-3 md:mb-4">
            <h3 class="text-sm md:text-base font-medium text-muted-foreground">Saldo Saya</h3>
            <button class="flex items-center gap-1 md:gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors bg-muted/30 backdrop-blur-sm px-2 py-1 rounded-full">
                {{ period || 'Bulan ini' }}
                <ChevronDown class="w-3 h-3 md:w-3.5 md:h-3.5" />
            </button>
        </div>
        
        <div class="mb-4 md:mb-5">
            <span class="amount-primary text-3xl md:text-4xl bg-gradient-to-r from-foreground to-foreground/80 bg-clip-text">{{ formatCurrency(balance) }}</span>
        </div>
        
        <div class="space-y-2 md:space-y-3">
            <div class="flex items-center gap-3 p-2.5 rounded-xl bg-accent/30 backdrop-blur-sm border border-accent/20 group-hover:bg-accent/40 transition-all">
                <div class="w-7 h-7 md:w-8 md:h-8 rounded-lg bg-accent/50 backdrop-blur-sm flex items-center justify-center flex-shrink-0">
                    <Wallet class="w-4 h-4 md:w-4.5 md:h-4.5 text-accent-foreground" />
                </div>
                <span class="text-sm text-muted-foreground flex-1 min-w-0 truncate">Pendapatan terakhir</span>
                <span class="text-sm font-semibold text-primary flex-shrink-0">+{{ formatCurrency(lastIncome) }}</span>
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
