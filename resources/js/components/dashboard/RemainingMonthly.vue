<script setup lang="ts">
import { ArrowRight, BarChart3 } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';

interface CategoryItem {
    label: string;
    percent: number;
    amount: number;
}

interface Props {
    percentage: number;
    averageChange: number;
    categories: CategoryItem[];
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) => {
    if (amount >= 1000000) {
        const val = (amount / 1000000).toFixed(1);
        return 'Rp' + (val.endsWith('.0') ? val.slice(0, -2) : val) + 'jt';
    }
    if (amount >= 1000) {
        const val = (amount / 1000).toFixed(0);
        return 'Rp' + val + 'rb';
    }
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(amount);
};
</script>

<template>
    <div class="group bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 transition-all duration-500 animate-fade-in-up" style="animation-delay: 0.4s">
        <div class="flex items-center justify-between mb-4 md:mb-5">
            <h3 class="text-base md:text-lg font-semibold text-foreground">Sisa Bulanan</h3>
            <button @click="router.visit('/budgets')" class="flex items-center gap-1 md:gap-1.5 text-xs text-muted-foreground hover:text-primary transition-colors bg-muted/30 backdrop-blur-sm px-3 py-1.5 rounded-full hover:bg-primary/10">
                Atur anggaran
                <ArrowRight class="w-3.5 h-3.5 md:w-4 md:h-4" />
            </button>
        </div>
        
        <div class="flex flex-col sm:flex-row items-start gap-4 md:gap-6 mb-4 md:mb-5">
            <div class="w-full sm:w-auto bg-gradient-to-br from-primary/20 to-accent/30 backdrop-blur-sm rounded-2xl p-4 md:p-5 border border-primary/10">
                <div class="flex flex-col">
                    <div class="flex items-baseline">
                        <span class="text-5xl md:text-6xl font-bold text-foreground">{{ percentage }}</span>
                        <span class="text-2xl md:text-3xl font-medium text-muted-foreground">%</span>
                    </div>
                    <span class="text-xs text-muted-foreground font-medium mt-1">Sisa Anggaran</span>
                </div>
                
                <div v-if="Math.abs(averageChange) < 900" class="flex items-center gap-1.5 md:gap-2 mt-3 bg-background/40 px-2 py-1 rounded-lg w-fit">
                    <BarChart3 class="w-3.5 h-3.5 md:w-4 md:h-4" :class="averageChange >= 0 ? 'text-primary' : 'text-destructive'" />
                    <span class="text-[10px] md:text-xs text-muted-foreground">vs Bulan Lalu</span>
                    <span class="text-xs md:text-sm font-semibold" :class="averageChange >= 0 ? 'text-primary' : 'text-destructive'">
                        {{ averageChange >= 0 ? '+' : '' }}{{ averageChange }}%
                    </span>
                </div>
            </div>
            
            <div class="flex-1 w-full sm:w-auto">
                <p class="text-sm md:text-base text-muted-foreground leading-relaxed mb-3">
                    Keuanganmu sehat! Pengeluaran bulanan masih dalam batas aman
                </p>
                
                <!-- Progress bar -->
                <div class="h-2.5 md:h-3.5 bg-muted/30 backdrop-blur-sm rounded-full overflow-hidden border border-border/20">
                    <div class="h-full bg-gradient-to-r from-primary via-primary to-primary/60 rounded-full transition-all duration-500" :style="{ width: percentage + '%' }" />
                </div>
            </div>
        </div>
        
        <!-- Category cards -->
        <div class="grid grid-cols-3 gap-3 md:gap-4">
            <div 
                v-for="(category, index) in categories" 
                :key="index"
                :class="[
                    index % 3 === 0 ? 'bg-muted/20 hover:bg-muted/40 border-border/20 hover:border-primary/20' : 
                    index % 3 === 1 ? 'bg-chart-expense/10 hover:bg-chart-expense/20 border-chart-expense/20 hover:border-chart-expense/40' :
                    'bg-accent/30 hover:bg-accent/50 border-accent/30 hover:border-accent/50'
                ]"
                class="backdrop-blur-md rounded-2xl p-3 md:p-4 transition-all duration-300 border"
            >
                <div class="flex items-baseline mb-0.5 md:mb-1">
                    <span class="text-xl md:text-3xl font-bold text-foreground">{{ category.percent }}</span>
                    <span class="text-sm md:text-base font-medium text-muted-foreground">%</span>
                </div>
                <p class="text-sm text-muted-foreground">{{ category.label }}</p>
                
                <div class="mt-2 md:mt-3 h-1.5 md:h-2.5 bg-muted/30 rounded-full overflow-hidden">
                    <div 
                        class="h-full rounded-full transition-all duration-300" 
                        :class="[
                            index % 3 === 0 ? 'bg-gradient-to-r from-primary to-primary/70' :
                            index % 3 === 1 ? 'bg-gradient-to-r from-chart-expense to-chart-expense/70' :
                            'bg-gradient-to-r from-primary to-accent-foreground'
                        ]"
                        :style="{ width: category.percent + '%' }" 
                    />
                </div>
                
                <p class="text-sm text-muted-foreground mt-1.5 md:mt-2 font-medium">{{ formatCurrency(category.amount) }}</p>
            </div>
            
            <div v-if="categories.length === 0" class="col-span-3 text-center text-sm text-muted-foreground py-4">
                Belum ada data kategori
            </div>
        </div>
    </div>
</template>

<style scoped>
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
