<script setup lang="ts">
import { computed } from 'vue';
import { Lightbulb, TrendingUp, TrendingDown, AlertTriangle, AlertCircle, Target, Info } from 'lucide-vue-next';

interface Insight {
    icon: string;
    type: 'success' | 'warning' | 'danger' | 'info';
    title: string;
    message: string;
    priority: number;
}

interface Props {
    insights?: Insight[];
}

const props = withDefaults(defineProps<Props>(), {
    insights: () => []
});

const typeConfig: Record<string, { bg: string; border: string; iconColor: string; icon: typeof Lightbulb }> = {
    danger: {
        bg: 'bg-red-50 dark:bg-red-950/30',
        border: 'border-red-200 dark:border-red-800/40',
        iconColor: 'text-red-500 dark:text-red-400',
        icon: AlertCircle,
    },
    warning: {
        bg: 'bg-amber-50 dark:bg-amber-950/30',
        border: 'border-amber-200 dark:border-amber-800/40',
        iconColor: 'text-amber-500 dark:text-amber-400',
        icon: AlertTriangle,
    },
    success: {
        bg: 'bg-emerald-50 dark:bg-emerald-950/30',
        border: 'border-emerald-200 dark:border-emerald-800/40',
        iconColor: 'text-emerald-500 dark:text-emerald-400',
        icon: TrendingDown,
    },
    info: {
        bg: 'bg-blue-50 dark:bg-blue-950/30',
        border: 'border-blue-200 dark:border-blue-800/40',
        iconColor: 'text-blue-500 dark:text-blue-400',
        icon: Info,
    },
};

const getConfig = (type: string) => typeConfig[type] || typeConfig.info;

const hasInsights = computed(() => props.insights && props.insights.length > 0);
</script>

<template>
    <div class="p-4 md:p-5 rounded-2xl bg-card border border-border/60 flex flex-col gap-3.5 h-full">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-xl text-amber-500">lightbulb</span>
                <div>
                    <h3 class="text-sm md:text-base font-bold text-foreground">Insight Keuangan</h3>
                    <p class="text-[10px] text-muted-foreground">Analisis cerdas pola belanja</p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 text-[10px] font-bold shadow-xs">
                AI Radar
            </span>
        </div>

        <div class="flex-1 space-y-2.5">
            <template v-if="hasInsights">
                <div
                    v-for="(insight, index) in insights"
                    :key="index"
                    :class="[
                        'rounded-xl border p-3 transition-all hover:shadow-sm',
                        getConfig(insight.type).bg,
                        getConfig(insight.type).border,
                    ]"
                >
                    <div class="flex items-start gap-2.5">
                        <div class="mt-0.5 flex-shrink-0">
                            <component
                                :is="getConfig(insight.type).icon"
                                :class="['w-4 h-4', getConfig(insight.type).iconColor]"
                            />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-xs text-foreground leading-snug">
                                {{ insight.title }}
                            </p>
                            <p class="text-[11px] text-muted-foreground mt-0.5 leading-relaxed">
                                {{ insight.message }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <div class="p-2.5 rounded-full bg-muted mb-2">
                        <Lightbulb class="w-5 h-5 text-muted-foreground" />
                    </div>
                    <p class="text-xs font-semibold text-foreground">Belum ada insight</p>
                    <p class="text-[10px] text-muted-foreground mt-0.5">Catat lebih banyak transaksi untuk melihat rekomendasi hemat!</p>
                </div>
            </template>
        </div>
    </div>
</template>
