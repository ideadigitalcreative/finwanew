<script setup lang="ts">
import { computed } from 'vue';
import { format, startOfMonth, endOfMonth, eachDayOfInterval, getDay, isToday } from 'date-fns';
import { id } from 'date-fns/locale';

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

// Current month calendar data
const calendarData = computed(() => {
    const today = new Date();
    const monthStart = startOfMonth(today);
    const monthEnd = endOfMonth(today);
    
    const days = eachDayOfInterval({ start: monthStart, end: monthEnd });
    
    // Group transactions by date - count and expense total
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

    // Get the day of week for the first day (0 = Sunday, 1 = Monday, etc.)
    const firstDayOfWeek = getDay(monthStart);
    // Adjust for Monday start (0 = Monday, 6 = Sunday)
    const startOffset = firstDayOfWeek === 0 ? 6 : firstDayOfWeek - 1;

    return {
        monthLabel: format(today, 'MMMM yyyy', { locale: id }),
        days: days.map(day => {
            const dateStr = format(day, 'yyyy-MM-dd');
            const expense = txExpenses[dateStr] || 0;
            return {
                date: day,
                dayNum: format(day, 'd'),
                count: txCounts[dateStr] || 0,
                expense,
                expenseLabel: formatCompact(expense),
                isToday: isToday(day)
            };
        }),
        startOffset
    };
});

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
        return format(new Date(lastActivityDate.value), 'dd MMM yyyy', { locale: id });
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

// Total monthly expense
const totalMonthlyExpense = computed(() => {
    return (props.transactions || []).reduce((sum, tx) => {
        if (!tx.transaction_date || tx.type !== 'expense') return sum;
        const txDate = new Date(tx.transaction_date);
        const now = new Date();
        if (txDate.getMonth() === now.getMonth() && txDate.getFullYear() === now.getFullYear()) {
            return sum + (tx.amount || 0);
        }
        return sum;
    }, 0);
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
};

const getColor = (count: number, isToday: boolean, expense: number) => {
    if (isToday) {
        if (count === 0) return 'bg-blue-100 dark:bg-blue-900/50 ring-2 ring-blue-400';
        return 'bg-emerald-500 dark:bg-emerald-500 ring-2 ring-emerald-400 text-white';
    }
    if (count === 0) return 'bg-gray-50 dark:bg-gray-700/30';
    if (expense > 0) return 'bg-orange-50 dark:bg-orange-900/20 ring-1 ring-orange-200 dark:ring-orange-800/50';
    if (count <= 1) return 'bg-emerald-100 dark:bg-emerald-900/50';
    if (count <= 3) return 'bg-emerald-300 dark:bg-emerald-700';
    return 'bg-emerald-500 dark:bg-emerald-600 text-white';
};

const dayNames = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
</script>

<template>
    <div class="flex flex-col h-full bg-white dark:bg-gray-800 p-4 rounded-[13px] border border-gray-100 dark:border-gray-700 relative overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-0.5">Status Aktivitas</h3>
                <p class="text-sm font-bold text-gray-900 dark:text-white capitalize">{{ calendarData.monthLabel }}</p>
            </div>
            <div class="text-right">
                <div 
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold"
                    :class="lastActivityDate ? 'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-500'"
                >
                    <span class="relative flex h-2 w-2">
                        <span v-if="lastActivityDate" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2" :class="lastActivityDate ? 'bg-emerald-500' : 'bg-gray-400'"></span>
                    </span>
                    {{ lastActivityDate ? 'Aktif' : 'Tidak Aktif' }}
                </div>
                <p class="text-[10px] text-gray-400 mt-1">{{ totalThisMonth }} transaksi bulan ini</p>
            </div>
        </div>

        <!-- Day Names Header -->
        <div class="grid grid-cols-7 gap-1 mb-1">
            <div v-for="day in dayNames" :key="day" class="text-center text-[9px] font-medium text-gray-400 uppercase">
                {{ day }}
            </div>
        </div>

        <!-- Calendar Grid -->
        <div class="grid grid-cols-7 gap-1 flex-1">
            <!-- Empty cells for offset -->
            <div v-for="n in calendarData.startOffset" :key="'empty-' + n" class="aspect-square"></div>
            
            <!-- Day cells -->
            <div 
                v-for="day in calendarData.days" 
                :key="day.dayNum"
                class="flex flex-col items-center justify-center rounded-lg text-[11px] font-bold transition-all duration-200 cursor-help hover:scale-105 relative min-h-[36px]"
                :class="getColor(day.count, day.isToday, day.expense)"
                :title="`${format(day.date, 'dd MMMM yyyy', { locale: id })}: ${day.count} transaksi${day.expense > 0 ? ', Pengeluaran: ' + formatCurrency(day.expense) : ''}`"
            >
                <span class="leading-none">{{ day.dayNum }}</span>
                <!-- Daily expense label -->
                <span 
                    v-if="day.expenseLabel" 
                    class="text-[9px] font-bold leading-none mt-0.5 truncate max-w-full px-0.5"
                    :class="day.isToday 
                        ? 'text-red-100' 
                        : 'text-red-500 dark:text-red-400'"
                >
                    {{ day.expenseLabel }}
                </span>
            </div>
        </div>

        <!-- Footer Legend -->
        <div class="flex items-center justify-between text-[9px] text-gray-400 mt-2 pt-2 border-t border-gray-50 dark:border-gray-700/50">
            <span>Terakhir: <span class="font-semibold text-gray-600 dark:text-gray-300">{{ formattedLastDate }}</span></span>
            <div class="flex items-center gap-1.5">
                <div class="flex items-center gap-0.5">
                    <div class="w-2 h-2 rounded-sm bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800/50"></div>
                    <span class="text-red-500 dark:text-red-400">Keluar</span>
                </div>
                <div class="flex items-center gap-0.5">
                    <div class="w-2 h-2 rounded-sm bg-emerald-300 dark:bg-emerald-700"></div>
                    <span>Masuk</span>
                </div>
            </div>
        </div>
    </div>
</template>
