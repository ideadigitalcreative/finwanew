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
    <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-xl rounded-[13px] border border-gray-100 dark:border-gray-700/30 h-full flex flex-col">
        <div class="p-4 md:p-5 border-b border-gray-100 dark:border-gray-700/50">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-amber-100 dark:bg-amber-900/30">
                    <Lightbulb class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                </div>
                <div>
                    <h3 class="font-bold text-lg text-gray-900 dark:text-white">Quick Insight</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Analisis otomatis keuanganmu</p>
                </div>
            </div>
        </div>

        <div class="flex-1 p-4 md:p-5 space-y-3">
            <template v-if="hasInsights">
                <div
                    v-for="(insight, index) in insights"
                    :key="index"
                    :class="[
                        'rounded-xl border p-3.5 transition-all hover:shadow-sm',
                        getConfig(insight.type).bg,
                        getConfig(insight.type).border,
                    ]"
                >
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex-shrink-0">
                            <component
                                :is="getConfig(insight.type).icon"
                                :class="['w-5 h-5', getConfig(insight.type).iconColor]"
                            />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm text-gray-900 dark:text-white leading-snug">
                                {{ insight.title }}
                            </p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 leading-relaxed">
                                {{ insight.message }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <div class="p-3 rounded-full bg-gray-100 dark:bg-gray-700/50 mb-3">
                        <Lightbulb class="w-6 h-6 text-gray-400" />
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Belum ada insight tersedia
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        Catat lebih banyak transaksi untuk mendapat analisis
                    </p>
                </div>
            </template>
        </div>
    </div>
</template>
