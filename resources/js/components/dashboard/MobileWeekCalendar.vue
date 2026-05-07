<script setup lang="ts">
import { ref, computed } from 'vue';
import { format, addDays, subDays, isSameDay } from 'date-fns';
import { id } from 'date-fns/locale';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{
    transactions?: Array<{ transaction_date: string; type?: string; amount?: number }>;
}>();

// Format currency in compact form (e.g., 50rb, 1.2jt)
const formatCompact = (amount: number): string => {
    if (amount === 0) return '';
    if (amount >= 1_000_000) {
        const val = amount / 1_000_000;
        return val % 1 === 0 ? `${val}jt` : `${val.toFixed(1)}jt`;
    }
    if (amount >= 1_000) {
        const val = amount / 1_000;
        return val % 1 === 0 ? `${val}rb` : `${val.toFixed(0)}rb`;
    }
    return `${amount}`;
};

// Reference date for centering (today is in the middle)
const centerDate = ref(new Date());

// Navigate weeks
const prevWeek = () => {
    centerDate.value = subDays(centerDate.value, 7);
};

const nextWeek = () => {
    centerDate.value = addDays(centerDate.value, 7);
};

// Go to today
const goToToday = () => {
    centerDate.value = new Date();
};

// Generate week days with today in center (position 3, 0-indexed)
const weekDays = computed(() => {
    const days = [];
    const today = new Date();
    
    // Group transactions by date
    const txCounts: Record<string, number> = {};
    const txExpenses: Record<string, number> = {};
    (props.transactions || []).forEach(tx => {
        if (!tx.transaction_date) return;
        const dateStr = tx.transaction_date.substring(0, 10);
        txCounts[dateStr] = (txCounts[dateStr] || 0) + 1;
        if (tx.type === 'expense' && tx.amount) {
            txExpenses[dateStr] = (txExpenses[dateStr] || 0) + tx.amount;
        }
    });

    // Start 3 days before center date so center date is in middle
    const startDate = subDays(centerDate.value, 3);

    for (let i = 0; i < 7; i++) {
        const date = addDays(startDate, i);
        const dateStr = format(date, 'yyyy-MM-dd');
        const expense = txExpenses[dateStr] || 0;
        days.push({
            date,
            dayName: format(date, 'EEE', { locale: id }),
            dayNum: format(date, 'd'),
            isToday: isSameDay(date, today),
            isCenterDate: isSameDay(date, centerDate.value),
            hasActivity: (txCounts[dateStr] || 0) > 0,
            activityCount: txCounts[dateStr] || 0,
            expense,
            expenseLabel: formatCompact(expense)
        });
    }
    return days;
});

// Current month label
const monthLabel = computed(() => {
    return format(centerDate.value, 'MMMM yyyy', { locale: id });
});

// Activity Status - Same as ActivityCard
const lastActivityDate = computed(() => {
    if (!props.transactions || props.transactions.length === 0) return null;
    const sorted = [...props.transactions].sort((a, b) => 
        new Date(b.transaction_date).getTime() - new Date(a.transaction_date).getTime()
    );
    return sorted[0].transaction_date;
});

const formattedLastDate = computed(() => {
    if (!lastActivityDate.value) return '-';
    try {
        return format(new Date(lastActivityDate.value), 'dd MMM', { locale: id });
    } catch {
        return '-';
    }
});

const totalThisMonth = computed(() => {
    return (props.transactions || []).filter(tx => {
        if (!tx.transaction_date) return false;
        const txDate = new Date(tx.transaction_date);
        const now = new Date();
        return txDate.getMonth() === now.getMonth() && txDate.getFullYear() === now.getFullYear();
    }).length;
});

const isActive = computed(() => {
    return lastActivityDate.value !== null;
});
</script>

<template>
    <!-- Smooth Gradient Container - No borders, blends smoothly -->
    <div class="w-full rounded-3xl p-4 relative overflow-hidden">
        
        <!-- Base gradient: green concentrated at top, fading to white at bottom -->
        <div class="absolute inset-0 bg-gradient-to-b from-emerald-100 via-white/80 to-white dark:from-emerald-900/40 dark:via-gray-800/80 dark:to-gray-800"></div>
        
        <!-- White overlay at top corners for softer look -->
        <div class="absolute -top-10 -left-8 w-32 h-32 bg-white/60 dark:bg-gray-800/40 rounded-full blur-[50px]"></div>
        <div class="absolute -top-10 -right-8 w-32 h-32 bg-white/60 dark:bg-gray-800/40 rounded-full blur-[50px]"></div>
        
        <!-- Green blobs - concentrated at top left -->
        <div class="absolute -top-8 left-4 w-36 h-36 bg-emerald-400/40 dark:bg-emerald-500/30 rounded-full blur-[55px]"></div>
        
        <!-- Green blob - top right -->
        <div class="absolute -top-6 right-8 w-32 h-32 bg-teal-400/35 dark:bg-teal-400/25 rounded-full blur-[50px]"></div>
        
        <!-- Intense green blob - slightly above center -->
        <div class="absolute top-2 left-1/3 w-28 h-28 bg-emerald-500/30 dark:bg-emerald-400/25 rounded-full blur-[45px]"></div>
        
        <!-- Small accent blob - right side -->
        <div class="absolute top-4 -right-4 w-24 h-24 bg-emerald-300/25 dark:bg-emerald-400/15 rounded-full blur-[40px]"></div>
        
        <!-- White fade at bottom for clean finish -->
        <div class="absolute bottom-0 inset-x-0 h-16 bg-gradient-to-t from-white via-white/80 to-transparent dark:from-gray-800 dark:via-gray-800/80 dark:to-transparent"></div>
        
        <!-- Activity Status Row -->
        <div class="flex items-center justify-between mb-3 relative z-10">
            <!-- Status Badge -->
            <div 
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold"
                :class="isActive 
                    ? 'bg-emerald-500/20 dark:bg-emerald-500/30 text-emerald-700 dark:text-emerald-300' 
                    : 'bg-gray-200/50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400'"
            >
                <span class="relative flex h-2 w-2">
                    <span 
                        v-if="isActive" 
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
                    ></span>
                    <span 
                        class="relative inline-flex rounded-full h-2 w-2" 
                        :class="isActive ? 'bg-emerald-500' : 'bg-gray-400'"
                    ></span>
                </span>
                {{ isActive ? 'Aktif' : 'Tidak Aktif' }}
            </div>

            <!-- Transaction Count & Last Activity -->
            <div class="flex items-center gap-3 text-[11px]">
                <span class="text-emerald-700 dark:text-emerald-300 font-semibold">
                    {{ totalThisMonth }} transaksi
                </span>
                <span class="text-gray-400 dark:text-gray-500">•</span>
                <span class="text-gray-500 dark:text-gray-400">
                    Terakhir: <span class="font-semibold text-gray-700 dark:text-gray-300">{{ formattedLastDate }}</span>
                </span>
            </div>
        </div>

        <!-- Month Navigation Header -->
        <div class="flex items-center justify-center gap-4 mb-4 relative z-10">
            <button 
                @click="prevWeek"
                class="p-2 rounded-full hover:bg-emerald-500/15 transition-all text-emerald-700 dark:text-emerald-300 hover:scale-105"
            >
                <ChevronLeft class="w-5 h-5" />
            </button>
            <button 
                @click="goToToday"
                class="text-emerald-800 dark:text-emerald-200 font-bold text-lg capitalize hover:text-emerald-600 transition-colors"
            >
                {{ monthLabel }}
            </button>
            <button 
                @click="nextWeek"
                class="p-2 rounded-full hover:bg-emerald-500/15 transition-all text-emerald-700 dark:text-emerald-300 hover:scale-105"
            >
                <ChevronRight class="w-5 h-5" />
            </button>
        </div>

        <!-- Week Days -->
        <div class="flex justify-between gap-1.5 relative z-10">
            <div 
                v-for="day in weekDays" 
                :key="day.dayNum + day.dayName"
                class="flex-1 flex flex-col items-center"
            >
                <!-- Day Container -->
                <div 
                    class="w-full py-2.5 px-0.5 rounded-2xl flex flex-col items-center transition-all duration-300"
                    :class="day.isToday 
                        ? 'bg-white/70 dark:bg-white/10' 
                        : 'hover:bg-white/40 dark:hover:bg-white/5'"
                >
                    <!-- Day Name -->
                    <span 
                        class="text-[9px] font-semibold uppercase tracking-wider mb-1.5"
                        :class="day.isToday ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-400 dark:text-gray-500'"
                    >
                        {{ day.dayName }}
                    </span>

                    <!-- Date Number - All have rounded circles now -->
                    <div class="relative flex items-center justify-center">
                        <!-- Today's date - larger circle -->
                        <div 
                            v-if="day.isToday"
                            class="w-11 h-11 rounded-full bg-emerald-500 dark:bg-emerald-600 flex items-center justify-center shadow-lg shadow-emerald-500/40 dark:shadow-emerald-600/30"
                        >
                            <span class="text-white font-bold text-lg">{{ day.dayNum }}</span>
                            <!-- Activity indicator -->
                            <span 
                                v-if="day.hasActivity" 
                                class="absolute -bottom-0.5 w-1.5 h-1.5 rounded-full bg-white shadow-sm"
                            ></span>
                        </div>
                        
                        <!-- Other dates - smaller circle -->
                        <div 
                            v-else
                            class="w-9 h-9 rounded-full flex items-center justify-center transition-all"
                            :class="day.hasActivity 
                                ? 'bg-emerald-100 dark:bg-emerald-800/40' 
                                : 'bg-gray-100/80 dark:bg-gray-700/30'"
                        >
                            <span 
                                class="font-semibold text-base"
                                :class="day.hasActivity ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'"
                            >
                                {{ day.dayNum }}
                            </span>
                            <!-- Activity dot -->
                            <span 
                                v-if="day.hasActivity" 
                                class="absolute -bottom-0.5 w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"
                            ></span>
                        </div>
                    </div>

                    <!-- Daily expense label below circle -->
                    <span 
                        v-if="day.expenseLabel"
                        class="text-[11px] font-bold mt-1.5 text-red-500 dark:text-red-400 leading-none"
                    >
                        -{{ day.expenseLabel }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
