<script setup lang="ts">
interface Goal {
    label: string;
    current: number;
    target: number;
    deadline?: string;
    icon?: string;
}

interface Props {
    goals?: Goal[];
    period?: string;
}

const props = withDefaults(defineProps<Props>(), {
    goals: () => [],
    period: 'Tahun ini',
});

const formatCurrency = (amount: number) => {
    return `Rp ${amount.toLocaleString('id-ID')}`;
};

const getProgress = (current: number, target: number) => {
    if (target <= 0) return 0;
    return Math.min(100, Math.round((current / target) * 100));
};

const getProgressColor = (percent: number) => {
    if (percent >= 80) return 'bg-[#006c4f]';
    if (percent >= 50) return 'bg-[#51fac1]';
    if (percent >= 25) return 'bg-[#ffd23f]';
    return 'bg-[#ffc9d0]';
};

const iconMap: Record<string, string> = {
    '🎯': 'center_focus_strong',
    '🌴': 'beach_access',
    '💻': 'laptop_mac',
    '🛡️': 'verified_user',
    '🎤': 'mic_external_on',
    '🚀': 'rocket_launch',
    '🧳': 'luggage',
    '🏠': 'home',
    '💍': 'diamond',
    '📱': 'smartphone',
    '✈️': 'flight',
    '🏝️': 'beach_access',
    '☕': 'coffee',
    '🎉': 'celebration',
    '🎪': 'festival',
    '🖥️': 'desktop_windows',
    '🚗': 'directions_car',
    '🎸': 'music_note',
    '🎓': 'school',
    '👶': 'child_care',
    '🏦': 'account_balance',
    '⛱️': 'beach_access',
    '❤️': 'favorite',
    '💖': 'favorite',
    '🎓': 'school',
};

const getIcon = (icon?: string) => {
    if (!icon) return 'savings';
    return iconMap[icon] || 'savings';
};
</script>

<template>
    <!-- Celengan Impian Quick Glance (minimalis) -->
    <div class="p-4 md:p-5 rounded-2xl bg-white dark:bg-card border border-[#eae8e2] dark:border-border/60 flex flex-col gap-3 font-['Plus_Jakarta_Sans',sans-serif]">
        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
            <div class="flex items-center gap-1.5 min-w-0">
                <div class="w-7 h-7 rounded-lg bg-[#ffd23f]/25 text-[#745c00] dark:text-[#ffe089] flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-base">savings</span>
                </div>
                <span class="text-sm font-bold text-[#1b1c19] dark:text-foreground whitespace-nowrap truncate">Celengan Impian</span>
                <span v-if="period" class="text-[9px] text-[#4d4634] dark:text-muted-foreground font-semibold whitespace-nowrap shrink-0">({{ period }})</span>
            </div>
            <a href="/tabungan" class="text-[10px] text-[#006c4f] dark:text-emerald-400 hover:underline font-bold flex items-center gap-0.5 shrink-0 whitespace-nowrap">
                <span>Lihat Semua ({{ goals.length || 1 }})</span>
                <span class="material-symbols-outlined text-[13px]">chevron_right</span>
            </a>
        </div>

        <!-- Daftar celengan (compact) -->
        <div v-if="goals.length > 0" class="flex flex-col gap-2">
            <div
                v-for="(goal, index) in goals"
                :key="index"
                class="flex items-center gap-2.5 p-2.5 rounded-xl bg-[#fbf9f3] dark:bg-muted/30 border border-[#eae8e2] dark:border-border/40"
            >
                <div class="w-9 h-9 rounded-lg bg-[#51fac1] text-[#007152] flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-base">{{ getIcon(goal.icon) }}</span>
                </div>
                <div class="flex-1 min-w-0 flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-bold text-[#1b1c19] dark:text-foreground truncate">{{ goal.label }}</span>
                        <span class="text-[9px] font-extrabold text-[#006c4f] dark:text-emerald-400 shrink-0">{{ getProgress(goal.current, goal.target) }}% Siap</span>
                    </div>
                    <div class="w-full h-1.5 bg-[#f0eee8] dark:bg-muted rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full transition-all duration-500"
                            :class="getProgressColor(getProgress(goal.current, goal.target))"
                            :style="{ width: getProgress(goal.current, goal.target) + '%' }"
                        ></div>
                    </div>
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-[10px] font-extrabold text-[#006c4f] dark:text-emerald-400">{{ formatCurrency(goal.current) }}</span>
                        <span class="text-[9px] text-[#4d4634]/60 dark:text-muted-foreground font-medium truncate">dari {{ formatCurrency(goal.target) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state (compact) -->
        <div v-else class="p-3 rounded-xl bg-[#fbf9f3] dark:bg-muted/30 border border-dashed border-[#eae8e2] dark:border-border/40 flex items-center justify-between gap-2">
            <span class="text-xs text-[#4d4634] dark:text-muted-foreground font-medium">Belum ada tabungan</span>
            <a href="/tabungan" class="h-7 px-2.5 rounded-lg bg-[#ffd23f] text-[#725a00] text-[10px] font-bold flex items-center gap-0.5 shadow-sm active:scale-95 transition-transform shrink-0">
                <span class="material-symbols-outlined text-[13px]">add</span>
                <span>Buat</span>
            </a>
        </div>
    </div>
</template>
