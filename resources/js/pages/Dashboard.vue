<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import CuanCeriaHabitGamification from '@/components/dashboard/CuanCeriaHabitGamification.vue';
import CuanCeriaWeeklyChart from '@/components/dashboard/CuanCeriaWeeklyChart.vue';
import CuanCeriaExpensePortion from '@/components/dashboard/CuanCeriaExpensePortion.vue';
import IncomeCard from '@/components/dashboard/IncomeCard.vue';
import ExpenseCard from '@/components/dashboard/ExpenseCard.vue';
import BalanceCard from '@/components/dashboard/BalanceCard.vue';
const MoneyFlowChart = defineAsyncComponent(() => import('@/components/dashboard/MoneyFlowChart.vue'));
const ExpensePieChart = defineAsyncComponent(() => import('@/components/dashboard/ExpensePieChart.vue'));
import FinanceTrendCard from '@/components/dashboard/FinanceTrendCard.vue';
import TransactionHistory from '@/components/dashboard/TransactionHistory.vue';
import { Lock } from 'lucide-vue-next';
import MobileWeekCalendar from '@/components/dashboard/MobileWeekCalendar.vue';
import QuickInsightCard from '@/components/dashboard/QuickInsightCard.vue';
import FinancialHealthCard from '@/components/dashboard/FinancialHealthCard.vue';
import GoalTrackerCard from '@/components/dashboard/GoalTrackerCard.vue';
import WalletCards from '@/components/dashboard/WalletCards.vue';
import FloatingActionButton from '@/components/dashboard/FloatingActionButton.vue';
import QuickAddTransactionModal from '@/components/dashboard/QuickAddTransactionModal.vue';
import BudgetOverviewCard from '@/components/dashboard/BudgetOverviewCard.vue';

interface Props {
    cashflow?: {
        total_income: number;
        total_expense: number;
        net_cashflow: number;
        period_start: string;
        period_end: string;
        income_change?: number;
        expense_change?: number;
        net_change?: number;
        health_status?: string;
    };
    recentTransactions?: Array<{
        id: number;
        type: string;
        amount: number;
        transaction_date: string;
        description: string;
        category?: { name: string; type: string; } | null;
        status: string;
    }>;
    balances?: Array<{ account_name: string; balance: number; currency: string; balance_date: string; }>;
    chartData?: Array<{ month: string; income: number; expense: number; net: number; }>;
    topCategories?: Array<{ category_name: string; total_income: number; total_expense: number; count: number; }>;
    memberSummary?: Array<{
        number_id: number;
        name: string;
        whatsapp_number: string | null;
        total_income: number;
        total_expense: number;
        count: number;
    }>;
    period?: { start: string; end: string; label: string; };
    hasWhatsAppNumber?: boolean;
    subscription?: {
        isOnTrial: boolean;
        trialEndsAt: string | null;
        trialDaysRemaining: number | null;
        hasActiveSubscription: boolean;
        plan: string;
        endsAt: string | null;
    };
    budgetSummary?: {
        totalBudget: number;
        totalSpending: number;
        remaining: number;
        usagePercentage: number;
        items?: Array<{
            id: number;
            category_name: string;
            category_icon: string;
            amount: number;
            spending: number;
            remaining: number;
            usage_percent: number;
            is_over: boolean;
        }>;
    };
    monthlyTransactions?: Array<{ transaction_date: string; type: string; amount: number; }>;
    insights?: Array<{ icon: string; type: 'success' | 'warning' | 'danger' | 'info'; title: string; message: string; priority: number; }>;
    savingsGoals?: Array<{ label: string; current: number; target: number; deadline?: string; icon?: string; }>;
    streakDays?: number;
    userLevel?: { level: number; title: string; icon: string; };
    activeDays?: string[];
    weeklyFlowData?: Array<{ day: string; income: number; expense: number; }>;
    weeklyPeriodLabel?: string;
    weeklyPeakNote?: string;
}

const props = withDefaults(defineProps<Props>(), {
    cashflow: () => ({ total_income: 0, total_expense: 0, net_cashflow: 0, period_start: new Date().toISOString(), period_end: new Date().toISOString() }),
    recentTransactions: () => [],
    balances: () => [],
    chartData: () => [],
    topCategories: () => [],
    period: () => ({ start: new Date().toISOString(), end: new Date().toISOString(), label: new Date().toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }) }),
    hasWhatsAppNumber: true,
    subscription: () => ({ isOnTrial: false, trialEndsAt: null, trialDaysRemaining: null, hasActiveSubscription: false, plan: 'free', endsAt: null }),
    monthlyTransactions: () => [],
    savingsGoals: () => [],
    streakDays: 0,
    userLevel: () => ({ level: 1, title: 'Pemula Cuan', icon: 'military_tech' }),
    activeDays: () => [],
    insights: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
};

const formatDate = (date: string) => format(new Date(date), 'dd MMM yyyy', { locale: id });

// Last activity calculated from recent transactions
const lastActivityDate = computed(() => {
    if (props.recentTransactions && props.recentTransactions.length > 0) {
        // Assuming recentTransactions is sorted by date desc
        return props.recentTransactions[0].transaction_date;
    }
    return null;
});


// Computed data for components
const balanceData = computed(() => {
    const totalBalance = props.balances?.reduce((sum, b) => sum + b.balance, 0) || 0;
    const lastIncomeTx = props.recentTransactions?.find(tx => tx.type === 'income');
    return {
        balance: totalBalance,
        lastIncome: lastIncomeTx?.amount || props.cashflow?.total_income || 0,
        lastIncomeDate: lastIncomeTx?.transaction_date || null,
        cashflow: props.cashflow?.net_cashflow || 0,
        bonus: 0,
        period: props.period?.label || 'Bulan ini',
        wallets: props.balances || [],
        userName: (auth.value?.user as any)?.name?.split(' ')[0] || 'Kawan',
    };
});

const incomeData = computed(() => ({
    income: props.cashflow?.total_income || 0,
    period: props.period?.label || '',
    changePercent: props.cashflow?.income_change || 0,
    gained: props.cashflow?.total_income || 0,
}));

const expenseData = computed(() => ({
    expense: props.cashflow?.total_expense || 0,
    period: props.period?.label || '',
    changePercent: props.cashflow?.expense_change || 0,
    saved: props.budgetSummary?.remaining || 0,
    targetPercent: props.budgetSummary?.usagePercentage || 0
}));

// Expense categories for pie chart
const expenseCategoryData = computed(() => {
    const categories = props.topCategories
        ?.filter(c => c.total_expense > 0)
        .sort((a, b) => b.total_expense - a.total_expense)
        .slice(0, 6) // Top 6 categories
        .map(c => ({
            label: c.category_name,
            amount: c.total_expense,
            percent: props.cashflow?.total_expense ? Math.round((c.total_expense / props.cashflow.total_expense) * 100) : 0
        })) || [];
    
    return categories;
});

// Weekly trend data for line chart (7 days)
const weeklyTrendData = computed(() => {
    
    // Fallback: Create 7-day distribution from recentTransactions
    const days = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    const today = new Date();
    const last7Days: { day: string; income: number; expense: number; date: string }[] = [];
    
    // Helper to format date as YYYY-MM-DD in local timezone
    const formatLocalDate = (d: Date) => {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    
    // Generate last 7 days
    for (let i = 6; i >= 0; i--) {
        const date = new Date(today);
        date.setDate(today.getDate() - i);
        const dayName = days[date.getDay()];
        const dateStr = formatLocalDate(date);
        last7Days.push({ day: dayName, income: 0, expense: 0, date: dateStr });
    }
    
    // Aggregate all transactions by day
    (props.recentTransactions || []).forEach(tx => {
        if (!tx.transaction_date) return;
        
        let txDate = tx.transaction_date;
        if (txDate.includes('T')) {
            txDate = txDate.split('T')[0];
        } else if (txDate.includes(' ')) {
            txDate = txDate.split(' ')[0];
        }
        
        const dayData = last7Days.find(d => d.date === txDate);
        if (dayData) {
            if (tx.type === 'income') {
                dayData.income += tx.amount;
            } else {
                dayData.expense += tx.amount;
            }
        }
    });
    
    // If all values are 0, return zeros (no fake distribution)
    return last7Days;
});

// Monthly trend data (12 months) for desktop
const monthlyTrendData = computed(() => {
    if (props.chartData && props.chartData.length > 0) {
        return props.chartData.slice(-12).map(item => ({
            day: item.month.substring(0, 3),
            income: item.income,
            expense: item.expense
        }));
    }
    return [];
});

// ─── Greeting dinamis berdasarkan waktu ───────────────────────
const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return 'Semangat Pagi';
    if (hour < 15) return 'Selamat Siang';
    if (hour < 18) return 'Selamat Sore';
    return 'Selamat Malam';
});

// ─── Search bar mobile ────────────────────────────────────────
const searchQuery = ref('');
const filteredTransactions = computed(() => {
    if (!searchQuery.value.trim()) return props.recentTransactions ?? [];
    const q = searchQuery.value.toLowerCase();
    return (props.recentTransactions ?? []).filter(tx =>
        tx.description?.toLowerCase().includes(q) ||
        tx.category?.name?.toLowerCase().includes(q)
    );
});

const showWhatsAppModal = ref(false);
const showQuickAddModal = ref(false);
const page = usePage();
const auth = computed(() => page.props.auth as any);
const isSuperAdmin = computed(() => (auth.value?.user as any)?.is_super_admin ?? false);

const startTour = () => {
    const hasSeenTour = localStorage.getItem('has_seen_dashboard_tour');
    if (hasSeenTour) return;
    const driverObj = driver({
        showProgress: true,
        steps: [
            { element: '#desktop-menu-dashboard', popover: { title: 'Dashboard', description: 'Lihat ringkasan keuangan Anda di sini.', side: 'right', align: 'start' } },
            { element: '#desktop-menu-transaksi', popover: { title: 'Transaksi', description: 'Kelola semua transaksi keuangan Anda.', side: 'right', align: 'start' } }
        ],
        onDestroyStarted: () => { localStorage.setItem('has_seen_dashboard_tour', 'true'); driverObj.destroy(); }
    });
    driverObj.drive();
};

const closeWhatsAppModal = () => { showWhatsAppModal.value = false; startTour(); };

onMounted(() => {
    if (isSuperAdmin.value) { startTour(); return; }
    if (!props.hasWhatsAppNumber) showWhatsAppModal.value = true; else startTour();
});
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="bg-background flex flex-col h-full flex-1 gap-4 md:gap-6 p-4 md:p-6 overflow-x-hidden" v-if="cashflow">
            <!-- 1. Header Sapaan & Quick Actions + Mini Search Filter Bar (Mobile Only CuanCeria 1:1) -->
            <section class="flex flex-col gap-3 pt-2 lg:hidden font-['Plus_Jakarta_Sans',sans-serif]">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-col min-w-0">
                        <span class="text-base md:text-sm font-bold text-[#1b1c19] dark:text-foreground flex items-center gap-1.5 truncate">
                            {{ greeting }}{{ auth?.user?.name ? `, ${auth.user.name.split(' ')[0]}` : '' }}!
                        </span>
                        <div class="flex items-center gap-1.5 mt-2.5">
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-[#ffe089] text-[#241a00] text-[10px] font-extrabold shadow-sm">
                                <span class="material-symbols-outlined text-[12px]">{{ props.userLevel?.icon || 'military_tech' }}</span>
                                Level {{ props.userLevel?.level || 1 }}: {{ props.userLevel?.title || 'Pemula Cuan' }}
                            </span>
                        </div>
                    </div>
                    <img
                        src="/maskot.png"
                        alt="Maskot CuanCeria"
                        class="flex-shrink-0 relative z-20 -mb-6 md:-mb-7 w-28 h-28 md:w-32 md:h-32 object-contain drop-shadow-md self-end"
                    />
                </div>
            </section>
            <!-- WhatsApp Modal -->
            <div v-if="showWhatsAppModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                <div class="w-full max-w-md rounded-xl bg-white p-6 border border-gray-200/50 dark:bg-gray-800 dark:border-gray-700/50">
                    <div class="text-center">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                        <h3 class="mb-2 text-xl font-bold text-gray-900 dark:text-white">Hubungkan WhatsApp</h3>
                        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Daftarkan nomor WhatsApp untuk pencatatan otomatis.</p>
                        <div class="flex flex-col gap-3">
                            <Link href="/whatsapp" class="inline-flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 transition-all">Daftarkan Sekarang</Link>
                            <button @click="closeWhatsAppModal" class="inline-flex w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">Nanti Saja</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content + Right Panel Layout -->
            <div class="flex flex-1 flex-col lg:flex-row gap-4 md:gap-5 relative z-10 overflow-y-auto overflow-x-hidden">

                <!-- Main Content Area -->
                <div class="flex-1 flex flex-col gap-4 md:gap-5 min-w-0 overflow-x-hidden">

                    <!-- Playful Hero Balance Card -->
                    <BalanceCard v-bind="balanceData" />

                    <!-- Wallet Cards (Mobile only - horizontal snap carousel) -->
                    <div class="lg:hidden">
                        <WalletCards
                            :wallets="balances || []"
                            :period="period?.label || 'Bulan ini'"
                        />
                    </div>

                    <!-- Income & Expense Cards (Mobile only - below wallet) -->
                    <div class="lg:hidden grid grid-cols-2 gap-3">
                        <IncomeCard v-bind="incomeData" />
                        <ExpenseCard v-bind="expenseData" />
                    </div>

                    <!-- Gamifikasi & Habit Highlights (Mobile only) -->
                    <div class="lg:hidden">
                        <CuanCeriaHabitGamification :streak-days="streakDays" :active-days="activeDays" />
                    </div>

                    <!-- Arus Kas Mingguan Dual-Bar Chart (Mobile only) -->
                    <div class="lg:hidden">
                        <CuanCeriaWeeklyChart
                            :period-label="props.weeklyPeriodLabel || period?.label || '7 hari terakhir'"
                            :flow-data="props.weeklyFlowData || []"
                            :peak-expense-note="props.weeklyPeakNote || ''"
                        />
                    </div>

                    <!-- Porsi Pengeluaran Segmented Track (Mobile only) -->
                    <div class="lg:hidden">
                        <CuanCeriaExpensePortion :categories="expenseCategoryData" :total-expense="cashflow?.total_expense || 0" />
                    </div>

                    <!-- Celengan Impian Quick Glance (Mobile only) -->
                    <div class="lg:hidden">
                        <GoalTrackerCard
                            :goals="savingsGoals"
                            :period="period?.label || 'Tahun ini'"
                        />
                    </div>

                    <!-- Catatan Teranyar Live Activity (Mobile only) -->
                    <div class="lg:hidden">
                        <TransactionHistory :transactions="searchQuery ? filteredTransactions : (recentTransactions || [])" />
                    </div>


                    <!-- Upgrade Banner -->
                    <div v-if="subscription.plan === 'free' || subscription.plan === 'trial'" class="relative overflow-hidden rounded-xl bg-gradient-to-r from-emerald-500 via-emerald-400 to-green-400 p-4 border border-emerald-400/20">
                        <div class="absolute right-0 top-0 h-full w-1/2 overflow-hidden pointer-events-none">
                            <div class="absolute -right-[20%] -top-[60%] h-[300px] w-[300px] rounded-full bg-white/5"></div>
                            <div class="absolute -right-[15%] -top-[45%] h-[240px] w-[240px] rounded-full bg-white/10"></div>
                        </div>
                        <div class="relative z-10 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <img src="/premium.png" alt="Premium" class="h-10 w-10 object-contain" />
                                <div>
                                    <span v-if="subscription.isOnTrial" class="inline-flex items-center rounded-full bg-white/20 px-2 py-0.5 text-xs font-bold text-white">Trial • {{ subscription.trialDaysRemaining }} hari</span>
                                    <p class="text-sm font-bold text-white">Upgrade untuk fitur premium</p>
                                </div>
                            </div>
                            <a href="/subscriptions" class="inline-flex items-center gap-1 rounded-lg bg-white px-3 py-2 text-sm font-bold text-emerald-600 hover:bg-emerald-50 transition-all">Upgrade</a>
                        </div>
                    </div>

                    <!-- Mini summary cards: Pemasukan & Pengeluaran (desktop, ringkas 2 kolom) -->
                    <div class="hidden lg:grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5 emerald-fade-in">
                        <IncomeCard v-bind="incomeData" class="flex-1" />
                        <ExpenseCard v-bind="expenseData" class="flex-1" />
                    </div>

                    <!-- Money Flow Chart -->
                    <div class="relative group overflow-hidden rounded-[13px] flex emerald-fade-in">
                        <Suspense>
                            <template #default>
                                <MoneyFlowChart
                                    :data="chartData || []"
                                    :transactions="monthlyTransactions || []"
                                    class="flex-1"
                                    :class="{ 'blur-md opacity-50 grayscale-[0.3] pointer-events-none select-none': subscription.plan === 'free' }"
                                />
                            </template>
                            <template #fallback>
                                <div class="flex-1 h-64 rounded-2xl bg-muted animate-pulse" />
                            </template>
                        </Suspense>
                        <div v-if="subscription.plan === 'free'" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-black/5 dark:bg-black/20 backdrop-blur-[2px] p-6 text-center transition-all duration-500">
                            <div class="relative mb-3">
                                <div class="w-14 h-14 bg-white/20 dark:bg-white/5 backdrop-blur-2xl rounded-2xl flex items-center justify-center border border-white/30 dark:border-white/10 shadow-lg relative z-10 overflow-hidden group-hover:scale-110 transition-transform duration-500">
                                    <Lock class="w-7 h-7 text-emerald-500" />
                                </div>
                                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 bg-emerald-500/20 rounded-full blur-2xl -z-0"></div>
                            </div>
                            <div class="emerald-glass-card px-4 py-3 rounded-xl border border-white/20 scale-95 group-hover:scale-100 transition-transform duration-500">
                                <h3 class="text-sm font-bold text-foreground mb-0.5">Arus Uang (Pro)</h3>
                                <p class="text-muted-foreground text-[10px]">Visualisasi arus kas otomatis.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Mid Row: Expense Pie Chart + Goal Tracker (Desktop only untuk GoalTrackerCard karena mobile sudah ada di atas) -->
                    <div class="grid grid-cols-1 lg:grid-cols-[2fr_1fr] gap-4 md:gap-5">
                        <div class="relative group overflow-hidden rounded-[13px]">
                            <Suspense>
                                <template #default>
                                    <ExpensePieChart
                                        :categories="expenseCategoryData"
                                        :total-expense="cashflow?.total_expense || 0"
                                        class="h-full"
                                        :class="{ 'blur-md opacity-50 grayscale-[0.3] pointer-events-none select-none': subscription.plan === 'free' }"
                                    />
                                </template>
                                <template #fallback>
                                    <div class="h-64 rounded-2xl bg-muted animate-pulse" />
                                </template>
                            </Suspense>
                            <div v-if="subscription.plan === 'free'" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-black/5 dark:bg-black/20 backdrop-blur-[2px] p-6 text-center transition-all duration-500">
                                <div class="relative mb-3">
                                    <div class="w-12 h-12 bg-white/20 dark:bg-white/5 backdrop-blur-2xl rounded-2xl flex items-center justify-center border border-white/30 dark:border-white/10 shadow-lg relative z-10 overflow-hidden group-hover:scale-110 transition-transform duration-500">
                                        <Lock class="w-6 h-6 text-emerald-500" />
                                    </div>
                                </div>
                                <p class="text-xs font-semibold text-foreground">Kategori (Pro)</p>
                            </div>
                        </div>
                        <GoalTrackerCard
                            class="hidden lg:flex"
                            :goals="savingsGoals"
                            :period="period?.label || 'Tahun ini'"
                        />
                    </div>

                    <!-- Finance Trend — satu komponen, bisa toggle Harian/Bulanan, tampil di semua ukuran layar -->
                    <FinanceTrendCard
                        :daily-data="weeklyTrendData"
                        :monthly-data="monthlyTrendData"
                        :period="period?.label || '6 bulan terakhir'"
                        uid="trend"
                    />

                    <!-- Ringkasan per Anggota (nomor WhatsApp) -->
                    <div v-if="memberSummary && memberSummary.length > 0">
                        <h3 class="mb-2 text-sm font-semibold text-foreground">Ringkasan per Anggota — {{ period?.label || 'Bulan ini' }}</h3>
                        <div class="-mx-1 flex gap-3 overflow-x-auto px-1 pb-1">
                            <Link
                                v-for="member in memberSummary"
                                :key="member.number_id"
                                :href="`/whatsapp-numbers/${member.number_id}/transactions`"
                                class="min-w-[220px] flex-1 rounded-2xl border border-border bg-card p-4 transition-colors hover:bg-accent/50"
                            >
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <p class="truncate text-sm font-semibold text-foreground" :title="member.whatsapp_number || ''">{{ member.name }}</p>
                                    <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ member.count }} transaksi</span>
                                </div>
                                <p class="text-sm font-bold text-green-600 dark:text-green-400">+{{ formatCurrency(member.total_income) }}</p>
                                <p class="text-sm font-bold text-red-600 dark:text-red-400">-{{ formatCurrency(member.total_expense) }}</p>
                                <p
                                    class="mt-1 text-xs font-semibold"
                                    :class="member.total_income - member.total_expense >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400'"
                                >
                                    Selisih: {{ formatCurrency(member.total_income - member.total_expense) }}
                                </p>
                            </Link>
                        </div>
                    </div>

                    <!-- Budget Overview — di bawah tren, hanya tampil di Desktop (lg ke atas) karena mobile sudah memiliki Porsi Pengeluaran di bagian atas -->
                    <BudgetOverviewCard
                        class="hidden lg:flex"
                        :total-budget="budgetSummary?.totalBudget || 0"
                        :total-spending="budgetSummary?.totalSpending || 0"
                        :remaining="budgetSummary?.remaining || 0"
                        :usage-percentage="budgetSummary?.usagePercentage || 0"
                        :items="budgetSummary?.items || []"
                        :period="period?.label || 'Bulan ini'"
                    />

                    <!-- Recent Transactions sudah ditampilkan di atas (Mobile only) -->
                </div>

                <!-- Right Panel: Desktop sticky sidebar + Mobile bottom section -->
                <div class="lg:w-[320px] xl:w-[360px] flex-shrink-0 lg:sticky lg:top-0 lg:h-fit">
                    <div class="flex flex-col gap-4 md:gap-5">
                        <!-- Wallet Cards (Desktop only — mobile sudah ada di atas) -->
                        <WalletCards
                            class="hidden lg:block"
                            :wallets="balances || []"
                            :period="period?.label || 'Bulan ini'"
                        />

                        <!-- Financial Health (tampil di semua ukuran layar, satu instance) -->
                        <FinancialHealthCard
                            :total-income="cashflow?.total_income || 0"
                            :total-expense="cashflow?.total_expense || 0"
                            :income-change="cashflow?.income_change || 0"
                            :expense-change="cashflow?.expense_change || 0"
                            :period="period?.label || 'Bulan ini'"
                        />

                        <!-- Week Calendar (Desktop only) -->
                        <MobileWeekCalendar class="hidden lg:block" :transactions="monthlyTransactions" />

                        <!-- Quick Insight (tampil di semua ukuran layar, satu instance) -->
                        <QuickInsightCard :insights="insights || []" />

                        <!-- Recent Transactions (Desktop only — mobile sudah ada di atas) -->
                        <TransactionHistory
                            class="hidden lg:block"
                            :transactions="recentTransactions || []"
                        />
                    </div>
                </div>
            </div>

            <!-- Financial Health + Quick Insight sudah ada di Right Panel (Desktop) -->
        </div>

        <!-- Floating Action Button -->
        <FloatingActionButton @click="showQuickAddModal = true" />

        <!-- Quick Add Transaction Modal -->
        <QuickAddTransactionModal
            :show="showQuickAddModal"
            @close="showQuickAddModal = false"
            @saved="showQuickAddModal = false"
        />
    </AppLayout>
</template>

<style scoped>
.emerald-glass-card {
    background: rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.dark .emerald-glass-card {
    background: rgba(31, 41, 55, 0.6);
    border: 1px solid rgba(75, 85, 99, 0.2);
}
.emerald-fade-in {
    animation: fade-in-up 0.5s ease-out forwards;
    opacity: 0;
}
@keyframes fade-in-up {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
