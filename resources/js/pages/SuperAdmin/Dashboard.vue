<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Users, CreditCard, MessageSquare, DollarSign, Wallet, TrendingUp, Activity, Clock, AlertTriangle, ArrowUpRight, ArrowDownRight, ChevronRight } from 'lucide-vue-next';
import { onClickOutside } from '@vueuse/core';
import superadminRoutes from '@/routes/superadmin/index';

const selectedPeriod = ref('This Year');
const isPeriodDropdownOpen = ref(false);
const periodDropdownRef = ref(null);

onClickOutside(periodDropdownRef, () => isPeriodDropdownOpen.value = false);



const superadmin = {
    dashboard: () => superadminRoutes.dashboard.url(),
    users: {
        index: () => superadminRoutes.users.index.url(),
    },
    subscriptions: {
        index: () => superadminRoutes.subscriptions.index.url(),
    },
    banks: {
        index: () => superadminRoutes.banks.index.url(),
    },
};

interface Stats {
    total_users: number;
    total_revenue: number;
    active_tenants: number;
    total_subscriptions: number;
    active_subscriptions: number;
    pending_subscriptions: number;
    expiring_soon: number;
    total_transactions: number;
    total_channels: number;
    total_banks: number;
    new_users_this_week: number;
    new_users_last_week: number;
    conversion_rate: number;
}

interface PendingSubscription {
    id: number;
    tenant_name: string;
    plan: string;
    status: string;
    price: number;
    duration_months: number;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string;
    payment_proof: string | null;
}

interface ExpiringSubscription {
    id: number;
    tenant_name: string;
    plan: string;
    ends_at: string | null;
    days_left: number;
}

interface MonthlyRevenue {
    month: string;
    revenue: number;
}

interface RecentSubscription {
    id: number;
    tenant_name: string;
    plan: string;
    status: string;
    price: number;
    duration_months: number;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string;
}

interface RecentTenant {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    users_count: number;
    subscriptions_count: number;
    created_at: string;
}

interface Props {
    stats: Stats;
    recentSubscriptions: RecentSubscription[];
    recentTenants: RecentTenant[];
    pendingSubscriptions: PendingSubscription[];
    expiringSubscriptions: ExpiringSubscription[];
    monthlyRevenue: MonthlyRevenue[];
}

const props = defineProps<Props>();

const tenantsWithUsers = computed(() => {
    return props.recentTenants.filter(tenant => {
        const userCount = Number(tenant.users_count);
        return !isNaN(userCount) && userCount > 0;
    });
});

// Calculate max revenue for chart scaling
const maxRevenue = computed(() => {
    return Math.max(...props.monthlyRevenue.map(m => m.revenue), 1);
});

// Generate chart data including path and Y-axis labels
const chartData = computed(() => {
    if (props.monthlyRevenue.length === 0) return { path: '', yLabels: [] };
    
    const width = 100;
    const height = 60;
    const paddingLeft = 12;  // Space for Y-axis labels
    const paddingRight = 2;
    const paddingTop = 5;
    const paddingBottom = 5;
    const max = maxRevenue.value;
    
    const points = props.monthlyRevenue.map((item, index) => {
        const x = paddingLeft + (index / (props.monthlyRevenue.length - 1)) * (width - paddingLeft - paddingRight);
        const safeMax = max === 0 ? 1 : max;
        const y = paddingTop + (1 - (item.revenue / safeMax)) * (height - paddingTop - paddingBottom);
        return { x, y, value: item.revenue, month: item.month };
    });
    
    // Create smooth curve using cubic bezier for smoother lines
    let path = `M ${points[0].x},${points[0].y}`;
    for (let i = 1; i < points.length; i++) {
        const prev = points[i - 1];
        const curr = points[i];
        const cpX = (prev.x + curr.x) / 2;
        path += ` C ${cpX},${prev.y} ${cpX},${curr.y} ${curr.x},${curr.y}`;
    }
    
    // Generate Y-axis labels
    const yLabels = [];
    const steps = 5;
    for (let i = 0; i <= steps; i++) {
        const value = (max / steps) * (steps - i);
        const yPos = paddingTop + (i / steps) * (height - paddingTop - paddingBottom);
        yLabels.push({ value, yPos });
    }
    
    return { points, path, yLabels, height, paddingLeft, paddingRight, paddingTop, paddingBottom };
});

// Calculate user growth percentage
const userGrowthPercentage = computed(() => {
    if (props.stats.new_users_last_week === 0) {
        return props.stats.new_users_this_week > 0 ? 100 : 0;
    }
    return Math.round(((props.stats.new_users_this_week - props.stats.new_users_last_week) / props.stats.new_users_last_week) * 100);
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatCurrencyShort = (amount: number) => {
    if (amount >= 1000000) {
        return `${(amount / 1000000).toFixed(1)}jt`;
    } else if (amount >= 1000) {
        return `${(amount / 1000).toFixed(0)}rb`;
    }
    return formatCurrency(amount);
};

const formatTimeAgo = (date: string) => {
    const now = new Date();
    const past = new Date(date);
    const diffInHours = Math.floor((now.getTime() - past.getTime()) / (1000 * 60 * 60));
    
    if (diffInHours < 1) return 'Baru saja';
    if (diffInHours < 24) return `${diffInHours} jam lalu`;
    const diffInDays = Math.floor(diffInHours / 24);
    if (diffInDays === 1) return 'Kemarin';
    return `${diffInDays} hari lalu`;
};

const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
        active: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        expired: 'bg-red-500/10 text-red-600 dark:text-red-400',
        cancelled: 'bg-gray-500/10 text-gray-600 dark:text-gray-400',
    };
    return styles[status] || styles.pending;
};

// Calculate bar height percentage
const getBarHeight = (revenue: number) => {
    const max = maxRevenue.value;
    if (max <= 0 || revenue <= 0) return 0;
    return (revenue / max) * 100;
};
</script>

<template>
    <Head title="Super Admin Dashboard" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-5 p-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
                <p class="text-sm text-muted-foreground">Overview sistem dan statistik</p>
            </div>

            <!-- Alert Cards -->
            <div v-if="stats.pending_subscriptions > 0 || stats.expiring_soon > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Pending Subscriptions -->
                <Link 
                    v-if="stats.pending_subscriptions > 0"
                    :href="superadmin.subscriptions.index()"
                    class="group rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800 hover:shadow-md transition-all"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10">
                                <Clock class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">Menunggu Approval</p>
                                <p class="text-xl font-bold">{{ stats.pending_subscriptions }}</p>
                            </div>
                        </div>
                        <ChevronRight class="h-5 w-5 text-gray-300 group-hover:text-gray-500 group-hover:translate-x-1 transition-all" />
                    </div>
                    <div v-if="pendingSubscriptions.length > 0" class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-2">
                        <div v-for="sub in pendingSubscriptions.slice(0, 2)" :key="sub.id" class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400 truncate max-w-[180px]">{{ sub.tenant_name }}</span>
                            <span class="text-xs text-gray-400">{{ formatTimeAgo(sub.created_at) }}</span>
                        </div>
                    </div>
                </Link>

                <!-- Expiring Soon -->
                <Link 
                    v-if="stats.expiring_soon > 0"
                    :href="superadmin.subscriptions.index()"
                    class="group rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800 hover:shadow-md transition-all"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-500/10">
                                <AlertTriangle class="h-5 w-5 text-rose-600 dark:text-rose-400" />
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">Akan Expired</p>
                                <p class="text-xl font-bold">{{ stats.expiring_soon }}</p>
                            </div>
                        </div>
                        <ChevronRight class="h-5 w-5 text-gray-300 group-hover:text-gray-500 group-hover:translate-x-1 transition-all" />
                    </div>
                    <div v-if="expiringSubscriptions.length > 0" class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 space-y-2">
                        <div v-for="sub in expiringSubscriptions.slice(0, 2)" :key="sub.id" class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 dark:text-gray-400 truncate max-w-[180px]">{{ sub.tenant_name }}</span>
                            <span class="text-xs font-medium" :class="sub.days_left <= 1 ? 'text-rose-500' : 'text-amber-500'">
                                {{ sub.days_left }} hari lagi
                            </span>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Main Stats Grid -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Total Users -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-500/10">
                            <Users class="h-4 w-4 text-blue-600 dark:text-blue-400" />
                        </div>
                        <span class="text-xs text-muted-foreground">Total Users</span>
                    </div>
                    <p class="text-2xl font-bold">{{ stats.total_users }}</p>
                </div>

                <!-- Revenue -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/10">
                            <DollarSign class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <span class="text-xs text-muted-foreground">Revenue</span>
                    </div>
                    <p class="text-2xl font-bold">{{ formatCurrencyShort(stats.total_revenue) }}</p>
                </div>

                <!-- Active Subscriptions -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-purple-500/10">
                            <CreditCard class="h-4 w-4 text-purple-600 dark:text-purple-400" />
                        </div>
                        <span class="text-xs text-muted-foreground">Active Subs</span>
                    </div>
                    <p class="text-2xl font-bold">{{ stats.active_subscriptions }}</p>
                </div>

                <!-- Conversion Rate -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-500/10">
                            <Activity class="h-4 w-4 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <span class="text-xs text-muted-foreground">Conversion</span>
                    </div>
                    <p class="text-2xl font-bold">{{ stats.conversion_rate }}%</p>
                </div>
            </div>

            <!-- Revenue Chart + Growth -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Revenue Bar Chart - DealDeck Style -->
                <div class="lg:col-span-2 rounded-3xl bg-white dark:bg-gray-900 p-8 shadow-[0_2px_20px_rgba(0,0,0,0.04)] border border-gray-100 dark:border-gray-800">
                    <div class="flex flex-col md:flex-row md:items-start justify-between mb-8">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Revenue Overview</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Track your monthly revenue
                            </p>
                            <!-- Legend -->
                            <div class="flex items-center gap-4 mt-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-gray-200"></span>
                                    <span class="text-xs text-gray-500 font-medium">Target</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-600"></span>
                                    <span class="text-xs text-gray-500 font-medium">Actual</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Time Filter -->
                        <div class="relative mt-4 md:mt-0 z-20" ref="periodDropdownRef">
                            <button 
                                @click="isPeriodDropdownOpen = !isPeriodDropdownOpen"
                                class="flex items-center gap-2 text-sm font-semibold text-gray-700 bg-gray-50 hover:bg-gray-100 px-4 py-2 rounded-xl transition-colors border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                            >
                                {{ selectedPeriod }}
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': isPeriodDropdownOpen }"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <transition
                                enter-active-class="transition ease-out duration-100"
                                enter-from-class="transform opacity-0 scale-95"
                                enter-to-class="transform opacity-100 scale-100"
                                leave-active-class="transition ease-in duration-75"
                                leave-from-class="transform opacity-100 scale-100"
                                leave-to-class="transform opacity-0 scale-95"
                            >
                                <div v-if="isPeriodDropdownOpen" class="absolute right-0 mt-2 w-36 origin-top-right rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none z-50 border border-gray-100 dark:border-gray-700 overflow-hidden">
                                    <div class="py-1">
                                        <button
                                            v-for="period in ['This Week', 'This Month', 'This Year']"
                                            :key="period"
                                            @click="selectedPeriod = period; isPeriodDropdownOpen = false"
                                            class="flex w-full items-center px-4 py-2.5 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                            :class="{ 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400 font-medium': selectedPeriod === period }"
                                        >
                                            {{ period }}
                                        </button>
                                    </div>
                                </div>
                            </transition>
                        </div>
                    </div>
                    
                    <!-- Bar Chart Container -->
                    <div class="relative mt-2">
                        <!-- Y-axis Labels -->
                        <div class="absolute left-0 top-0 bottom-8 w-8 flex flex-col justify-between text-right z-10">
                            <span v-for="(label, index) in chartData.yLabels" :key="index" class="text-[11px] text-gray-400 font-medium leading-none">
                                {{ formatCurrencyShort(label.value).replace('rb', 'K').replace('jt', 'M') }}
                            </span>
                        </div>
                        
                        <!-- Chart Area -->
                        <div class="ml-10 relative h-64">
                            <!-- Horizontal Grid Lines -->
                            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none">
                                <div v-for="i in 6" :key="i" class="w-full border-t border-dashed border-gray-100 dark:border-gray-700/50"></div>
                            </div>
                            
                            <!-- Bar Chart -->
                            <div class="relative h-full flex items-end justify-between px-2 gap-3 sm:gap-4 md:gap-6">
                                <div 
                                    v-for="(item, index) in monthlyRevenue" 
                                    :key="item.month"
                                    class="flex-1 h-full flex items-end justify-center group relative"
                                >
                                    <!-- Back Bar (Target - Dummy visual 80% of max) -->
                                    <div class="absolute w-full max-w-[12px] sm:max-w-[24px] bg-gray-100 dark:bg-gray-800 rounded-full h-[80%] bottom-0 -z-10"></div>

                                    <!-- Front Bar (Actual) -->
                                    <div 
                                        class="w-full max-w-[12px] sm:max-w-[24px] rounded-full bg-gradient-to-b from-indigo-500 to-indigo-700 hover:from-indigo-400 hover:to-indigo-600 transition-all duration-300 cursor-pointer relative shadow-[0_4px_10px_rgba(79,70,229,0.3)]"
                                        :style="{ 
                                            height: getBarHeight(item.revenue) + '%',
                                            minHeight: item.revenue > 0 ? '6px' : '0'
                                        }"
                                    >
                                        <!-- Floating Tooltip -->
                                        <div class="absolute -top-12 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-[11px] font-medium px-3 py-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition-all duration-200 whitespace-nowrap z-50 shadow-xl scale-90 group-hover:scale-100 translate-y-2 group-hover:translate-y-0">
                                            {{ formatCurrency(item.revenue) }}
                                            <div class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-gray-900 rotate-45"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- X-axis Month labels -->
                        <div class="ml-10 flex justify-between mt-4 px-2 gap-3 sm:gap-4 md:gap-6">
                            <span 
                                v-for="item in monthlyRevenue" 
                                :key="item.month" 
                                class="flex-1 text-center text-[11px] text-gray-400 font-medium"
                            >
                                {{ item.month.split(' ')[0].substring(0, 3) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Growth Metrics -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 p-5 border border-gray-100 dark:border-gray-800">
                    <h3 class="font-semibold mb-4">Growth</h3>
                    
                    <div class="space-y-3">
                        <!-- New Users -->
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs text-muted-foreground">User Baru</span>
                                <span 
                                    :class="userGrowthPercentage >= 0 ? 'text-emerald-600' : 'text-red-600'"
                                    class="flex items-center text-xs font-medium gap-0.5"
                                >
                                    <ArrowUpRight v-if="userGrowthPercentage >= 0" class="h-3 w-3" />
                                    <ArrowDownRight v-else class="h-3 w-3" />
                                    {{ Math.abs(userGrowthPercentage) }}%
                                </span>
                            </div>
                            <p class="text-xl font-bold">{{ stats.new_users_this_week }}</p>
                            <p class="text-[10px] text-muted-foreground">minggu ini</p>
                        </div>

                        <!-- Active Tenants -->
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <span class="text-xs text-muted-foreground">Tenant Aktif</span>
                            <p class="text-xl font-bold mt-1">{{ stats.active_tenants }}</p>
                        </div>

                        <!-- Banks -->
                        <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <span class="text-xs text-muted-foreground">Bank Aktif</span>
                            <p class="text-xl font-bold mt-1">{{ stats.total_banks }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Recent Tenants -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 border border-gray-100 dark:border-gray-800 overflow-hidden">
                    <div class="flex items-center justify-between p-5 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="font-semibold">Tenant Terbaru</h3>
                        <Link :href="superadmin.users.index()" class="text-xs text-primary hover:underline flex items-center gap-1">
                            Lihat semua <ChevronRight class="h-3 w-3" />
                        </Link>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        <div
                            v-for="tenant in tenantsWithUsers"
                            :key="tenant.id"
                            class="flex items-center justify-between p-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <div class="h-8 w-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center text-white text-xs font-medium">
                                    {{ tenant.name.charAt(0).toUpperCase() }}
                                </div>
                                <div>
                                    <p class="font-medium text-sm">{{ tenant.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ tenant.users_count }} users</p>
                                </div>
                            </div>
                            <span
                                :class="tenant.is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-gray-500/10 text-gray-600'"
                                class="px-2 py-0.5 text-[10px] font-medium rounded-full"
                            >
                                {{ tenant.is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </div>
                        <div v-if="tenantsWithUsers.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                            Belum ada tenant
                        </div>
                    </div>
                </div>

                <!-- Recent Subscriptions -->
                <div class="rounded-2xl bg-white dark:bg-gray-900/50 border border-gray-100 dark:border-gray-800 overflow-hidden">
                    <div class="flex items-center justify-between p-5 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="font-semibold">Subscription Terbaru</h3>
                        <Link :href="superadmin.subscriptions.index()" class="text-xs text-primary hover:underline flex items-center gap-1">
                            Lihat semua <ChevronRight class="h-3 w-3" />
                        </Link>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        <div
                            v-for="sub in recentSubscriptions"
                            :key="sub.id"
                            class="flex items-center justify-between p-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                        >
                            <div>
                                <p class="font-medium text-sm">{{ sub.tenant_name }}</p>
                                <p class="text-xs text-muted-foreground mt-0.5">
                                    {{ formatCurrencyShort(sub.price) }} • {{ sub.duration_months }} bulan
                                </p>
                            </div>
                            <span :class="getStatusBadge(sub.status)" class="px-2 py-0.5 text-[10px] font-medium rounded-full">
                                {{ sub.status }}
                            </span>
                        </div>
                        <div v-if="recentSubscriptions.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                            Belum ada subscription
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
