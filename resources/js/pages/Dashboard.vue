<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed, onMounted, ref } from 'vue';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import BalanceCard from '@/components/dashboard/BalanceCard.vue';
import IncomeCard from '@/components/dashboard/IncomeCard.vue';
import ExpenseCard from '@/components/dashboard/ExpenseCard.vue';
import MoneyFlowChart from '@/components/dashboard/MoneyFlowChart.vue';
import TransactionHistory from '@/components/dashboard/TransactionHistory.vue';
import ExpensePieChart from '@/components/dashboard/ExpensePieChart.vue';
import FinanceTrendCard from '@/components/dashboard/FinanceTrendCard.vue';
import { Lock } from 'lucide-vue-next';
import ActivityCard from '@/components/dashboard/ActivityCard.vue';
import MobileWeekCalendar from '@/components/dashboard/MobileWeekCalendar.vue';
import QuickInsightCard from '@/components/dashboard/QuickInsightCard.vue';
import FloatingActionButton from '@/components/dashboard/FloatingActionButton.vue';
import QuickAddTransactionModal from '@/components/dashboard/QuickAddTransactionModal.vue';

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
    budgetSummary?: { totalBudget: number; totalSpending: number; remaining: number; usagePercentage: number; };
    monthlyTransactions?: Array<{ transaction_date: string; type: string; amount: number; }>;
    insights?: Array<{ icon: string; type: string; title: string; message: string; priority: number; }>;
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
        period: props.period?.label || 'Bulan ini'
    };
});

const incomeData = computed(() => {
    const topIncomes = props.topCategories
        ?.filter(c => c.total_income > 0)
        .sort((a, b) => b.total_income - a.total_income)
        .slice(0, 3)
        .map(c => ({
            label: c.category_name,
            amount: c.total_income
        })) || [];
        
    return {
        income: props.cashflow?.total_income || 0,
        period: props.period?.label || '',
        changePercent: props.cashflow?.income_change || 0,
        gained: props.cashflow?.total_income || 0,
        breakdown: topIncomes
    };
});

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

// Weekly/Monthly trend data for line chart
const weeklyTrendData = computed(() => {
    // If chartData is available (monthly data), convert to chart format
    if (props.chartData && props.chartData.length > 0) {
        return props.chartData.slice(-7).map(item => ({
            day: item.month.substring(0, 3), // Jan -> Jan
            income: item.income,
            expense: item.expense
        }));
    }
    
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
        <div class="bg-background flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-hidden p-4 md:p-6" v-if="cashflow">
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

            <!-- Header -->
            <div class="flex items-center justify-between relative z-10 w-full">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Dasbor</h2>
                    <p class="text-sm text-gray-500 mt-1">Pantau, analisis, dan tingkatkan performa keuanganmu</p>
                </div>
            </div>

            <!-- Mobile Week Calendar (Only visible on mobile) -->
            <div class="lg:hidden relative z-10">
                <MobileWeekCalendar :transactions="monthlyTransactions" />
            </div>

            <!-- Upgrade Banner -->
            <div v-if="subscription.plan === 'free' || subscription.plan === 'trial'" class="relative overflow-hidden rounded-xl bg-gradient-to-r from-emerald-500 via-emerald-400 to-green-400 p-4 border border-emerald-400/20 relative z-10">
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

            <!-- Main Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5 relative z-10 emerald-fade-in">
                <BalanceCard v-bind="balanceData" />
                <IncomeCard v-bind="incomeData" />
                <ExpenseCard v-bind="expenseData" />
            </div>

            <!-- Charts Grid (3 Cards in 1 Row) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-5 relative z-10">
                <!-- 1. Activity Card (Hidden on mobile, replaced by MobileWeekCalendar) -->
                <div class="hidden lg:block lg:col-span-1 relative group overflow-hidden rounded-[13px]">
                     <ActivityCard :transactions="monthlyTransactions" class="h-full" />
                </div>

                <!-- 2. Money Flow Chart -->
                <div class="lg:col-span-2 relative group overflow-hidden rounded-[13px]">
                    <MoneyFlowChart 
                        :data="chartData || []" 
                        :class="{ 'blur-md opacity-50 grayscale-[0.3] pointer-events-none select-none': subscription.plan === 'free' }"
                    />
                    
                    <div v-if="subscription.plan === 'free'" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-black/5 dark:bg-black/20 backdrop-blur-[2px] p-6 text-center transition-all duration-500">
                        <div class="relative mb-3">
                            <div class="w-14 h-14 bg-white/20 dark:bg-white/5 backdrop-blur-2xl rounded-2xl flex items-center justify-center border border-white/30 dark:border-white/10 shadow-lg relative z-10 overflow-hidden group-hover:scale-110 transition-transform duration-500">
                                <Lock class="w-7 h-7 text-emerald-500" />
                            </div>
                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-20 h-20 bg-emerald-500/20 rounded-full blur-2xl -z-0"></div>
                        </div>

                        <div class="emerald-glass-card px-4 py-3 rounded-xl border border-white/20 scale-95 group-hover:scale-100 transition-transform duration-500">
                            <h3 class="text-sm font-bold text-foreground mb-0.5">Arus Uang (Pro)</h3>
                            <p class="text-muted-foreground text-[10px]">
                                Visualisasi arus kas otomatis.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pro Charts Grid (Side by Side) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-5 relative z-10">
                <!-- Expense Pie Chart (Pro Only) -->
                <div class="relative group overflow-hidden rounded-[13px]">
                    <ExpensePieChart 
                        :categories="expenseCategoryData" 
                        :total-expense="cashflow?.total_expense || 0"
                        :class="{ 'blur-md opacity-50 grayscale-[0.3] pointer-events-none select-none': subscription.plan === 'free' }"
                    />
                    
                    <div v-if="subscription.plan === 'free'" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-black/5 dark:bg-black/20 backdrop-blur-[2px] p-8 text-center transition-all duration-500">
                        <div class="relative mb-4">
                            <div class="w-16 h-16 bg-white/20 dark:bg-white/5 backdrop-blur-2xl rounded-2xl flex items-center justify-center border border-white/30 dark:border-white/10 shadow-lg relative z-10 overflow-hidden group-hover:scale-110 transition-transform duration-500">
                                <Lock class="w-8 h-8 text-emerald-500" />
                            </div>
                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-24 h-24 bg-emerald-500/20 rounded-full blur-2xl -z-0"></div>
                        </div>

                        <div class="emerald-glass-card px-6 py-5 rounded-2xl border border-white/20 scale-95 group-hover:scale-100 transition-transform duration-500">
                            <h3 class="text-lg font-bold text-foreground mb-1">Kategori Pengeluaran</h3>
                            <p class="text-muted-foreground text-xs">
                                Lihat distribusi pengeluaran per kategori secara visual.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Finance Trend Card (Pro Only) -->
                <div class="relative group overflow-hidden rounded-[13px]">
                    <FinanceTrendCard 
                        :chart-data="weeklyTrendData"
                        :period="period?.label || 'Minggu Ini'"
                        :class="{ 'blur-md opacity-50 grayscale-[0.3] pointer-events-none select-none': subscription.plan === 'free' }"
                    />
                    
                    <div v-if="subscription.plan === 'free'" class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-black/5 dark:bg-black/20 backdrop-blur-[2px] p-8 text-center transition-all duration-500">
                        <div class="relative mb-4">
                            <div class="w-16 h-16 bg-white/20 dark:bg-white/5 backdrop-blur-2xl rounded-2xl flex items-center justify-center border border-white/30 dark:border-white/10 shadow-lg relative z-10 overflow-hidden group-hover:scale-110 transition-transform duration-500">
                                <Lock class="w-8 h-8 text-emerald-500" />
                            </div>
                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-24 h-24 bg-emerald-500/20 rounded-full blur-2xl -z-0"></div>
                        </div>

                        <div class="emerald-glass-card px-6 py-5 rounded-2xl border border-white/20 scale-95 group-hover:scale-100 transition-transform duration-500">
                            <h3 class="text-lg font-bold text-foreground mb-1">Tren Keuangan</h3>
                            <p class="text-muted-foreground text-xs">
                                Bandingkan pemasukan dan pengeluaran secara visual.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Insight Card -->
            <div class="relative z-10">
                <QuickInsightCard :insights="insights || []" />
            </div>

            <!-- Transaction History -->
            <div class="relative z-10">
                <TransactionHistory :transactions="recentTransactions || []" />
            </div>
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
.emerald-gradient-bg {
    position: relative;
    background: rgb(249, 250, 251);
}
.dark .emerald-gradient-bg {
    background: rgb(17, 24, 39);
}
.emerald-gradient-bg::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 20% 20%, rgba(34, 197, 94, 0.08) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(134, 239, 172, 0.06) 0%, transparent 50%), radial-gradient(circle at 40% 60%, rgba(74, 222, 128, 0.1) 0%, transparent 40%);
    animation: gradient-shift 20s ease-in-out infinite;
    pointer-events: none;
    z-index: 0;
}
.dark .emerald-gradient-bg::before {
    background: radial-gradient(circle at 20% 20%, rgba(34, 197, 94, 0.12) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(134, 239, 172, 0.08) 0%, transparent 50%), radial-gradient(circle at 40% 60%, rgba(74, 222, 128, 0.15) 0%, transparent 40%);
}
@keyframes gradient-shift {
    0%, 100% { transform: translate(0%, 0%) rotate(0deg); }
    25% { transform: translate(5%, 5%) rotate(2deg); }
    50% { transform: translate(0%, 10%) rotate(0deg); }
    75% { transform: translate(-5%, 5%) rotate(-2deg); }
}
.emerald-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: 0.4;
    animation: float 15s ease-in-out infinite;
    pointer-events: none;
    z-index: 0;
}
.emerald-orb-1 {
    width: 300px;
    height: 300px;
    background: rgba(34, 197, 94, 0.15);
    top: 10%;
    right: 10%;
    animation-delay: 0s;
}
.emerald-orb-2 {
    width: 250px;
    height: 250px;
    background: rgba(134, 239, 172, 0.12);
    bottom: 20%;
    left: 5%;
    animation-delay: -5s;
}
.emerald-orb-3 {
    width: 200px;
    height: 200px;
    background: rgba(74, 222, 128, 0.2);
    top: 50%;
    left: 40%;
    animation-delay: -10s;
}
@keyframes float {
    0%, 100% { transform: translate(0, 0) scale(1); }
    25% { transform: translate(20px, -30px) scale(1.05); }
    50% { transform: translate(-10px, 20px) scale(0.95); }
    75% { transform: translate(-30px, -10px) scale(1.02); }
}
.dark .emerald-orb { opacity: 0.3; }
.emerald-fade-in {
    animation: fade-in-up 0.5s ease-out forwards;
    opacity: 0;
}
@keyframes fade-in-up {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
