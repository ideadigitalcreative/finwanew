<script setup lang="ts">
interface Props {
    income: number;
    period: string;
    changePercent: number;
    gained: number;
}

defineProps<Props>();

const formatFull = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
};
</script>

<template>
    <div class="bg-[#51fac1] text-[#007152] rounded-2xl p-4 border border-white/60 relative overflow-hidden flex flex-col justify-between font-['Plus_Jakarta_Sans',sans-serif] flex-1 transition-all duration-300">
        <!-- Decorative Glow -->
        <div class="absolute -right-6 -bottom-6 w-24 h-24 rounded-full bg-white/30 blur-xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-extrabold uppercase tracking-wider">Pemasukan Bulan Ini</span>
                <div class="w-7 h-7 rounded-full bg-white flex items-center justify-center shadow-sm">
                    <span class="material-symbols-outlined text-[16px]">arrow_downward</span>
                </div>
            </div>
            <div class="text-xl md:text-2xl font-extrabold tracking-tight leading-tight truncate">
                {{ formatFull(income) }}
            </div>
            <div class="flex items-center gap-1 text-[11px] font-semibold truncate">
                <span class="material-symbols-outlined text-[14px]">check_circle</span>
                {{ period }} • <span class="font-extrabold">+{{ changePercent }}%</span>
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
