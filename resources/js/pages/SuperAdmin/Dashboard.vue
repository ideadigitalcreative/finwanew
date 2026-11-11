<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Users, CreditCard, Clock, AlertTriangle, ChevronRight, TrendingUp, ArrowUpRight, Building2, Activity, DollarSign, Calendar, Package, ExternalLink } from 'lucide-vue-next';
import { onClickOutside } from '@vueuse/core';
import superadminRoutes from '@/routes/superadmin/index';

const selectedPeriod = ref('This Year');
const isPeriodDropdownOpen = ref(false);
const periodDropdownRef = ref(null);

onClickOutside(periodDropdownRef, () => isPeriodDropdownOpen.value = false);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Super Admin', href: '/superadmin/dashboard' },
    { title: 'Dashboard', href: '/superadmin/dashboard' },
];

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

interface UserGrowth {
    month: string;
    users: number;
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
    userGrowth: UserGrowth[];
}

const props = defineProps<Props>();

const tenantsWithUsers = computed(() => {
    return props.recentTenants.filter(tenant => {
        const userCount = Number(tenant.users_count);
        return !isNaN(userCount) && userCount > 0;
    });
});

const maxRevenue = computed(() => {
    return Math.max(...props.monthlyRevenue.map(m => m.revenue), 1);
});

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
        active: 'bg-[#51fac1]/15 text-[#007152] dark:text-emerald-400',
        pending: 'bg-[#ffd23f]/20 text-[#725a00] dark:text-amber-300',
        expired: 'bg-[#ffc9d0]/30 text-[#93000a] dark:text-rose-400',
        cancelled: 'bg-[#4d4634]/10 text-[#4d4634] dark:text-gray-400',
    };
    return styles[status] || styles.pending;
};

const BAR_AREA_HEIGHT = 128;
const BAR_MIN_HEIGHT = 8;

// 'Aug 2026' → 'Aug 26'
const shortMonthLabel = (month: string) => month.replace(/\d{2}(\d{2})$/, '$1');

const getRevenueBarHeight = (revenue: number) => {
    const max = maxRevenue.value;
    if (max <= 0 || revenue <= 0) return BAR_MIN_HEIGHT;
    const height = (revenue / max) * BAR_AREA_HEIGHT;
    return Math.max(BAR_MIN_HEIGHT, Math.round(height));
};

const maxUsers = computed(() => {
    return Math.max(...props.userGrowth.map(u => u.users), 1);
});

const getUserBarHeight = (users: number) => {
    const max = maxUsers.value;
    if (max <= 0 || users <= 0) return BAR_MIN_HEIGHT;
    const height = (users / max) * BAR_AREA_HEIGHT;
    return Math.max(BAR_MIN_HEIGHT, Math.round(height));
};
</script>

<template>
    <Head title="Super Admin Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif] bg-[#fbf9f3] dark:bg-background text-foreground">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#1b1c19] dark:text-foreground">Dashboard Super Admin</h1>
                <p class="text-sm text-[#4d4634] dark:text-muted-foreground mt-0.5">Pantau performa sistem, revenue, dan aktivitas tenant secara real-time.</p>
            </div>

            <!-- Alert Cards -->
            <div v-if="stats.pending_subscriptions > 0 || stats.expiring_soon > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Pending Subscriptions -->
                <Link v-if="stats.pending_subscriptions > 0" :href="superadmin.subscriptions.index()"
                    class="group relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 transition-all duration-300 hover:shadow-[0_4px_24px_rgba(255,210,63,0.25)]">
                    <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#ffd23f]/20 blur-2xl pointer-events-none"></div>
                    <div class="relative flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#ffe089] text-[#241a00]">
                                <Clock class="h-5 w-5" />
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-[#4d4634] dark:text-muted-foreground">Menunggu Approval</p>
                                <p class="text-2xl font-extrabold text-[#1b1c19] dark:text-foreground">{{ stats.pending_subscriptions }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-[#f5f3ee] dark:bg-white/10 text-[#4d4634] dark:text-muted-foreground transition-all group-hover:translate-x-1 group-hover:bg-[#ffd23f] group-hover:text-[#574500]"><ChevronRight class="h-4 w-4" /></span>
                    </div>
                    <div v-if="pendingSubscriptions.length > 0" class="relative mt-4 pt-4 border-t border-[#eae8e2] dark:border-white/10 space-y-2.5">
                        <div v-for="sub in pendingSubscriptions.slice(0, 2)" :key="sub.id" class="flex items-center justify-between text-sm">
                            <span class="font-semibold text-[#1b1c19] dark:text-foreground truncate max-w-[180px]">{{ sub.tenant_name }}</span>
                            <span class="text-xs text-[#4d4634] dark:text-muted-foreground">{{ formatTimeAgo(sub.created_at) }}</span>
                        </div>
                    </div>
                </Link>

                <!-- Expiring Soon -->
                <Link v-if="stats.expiring_soon > 0" :href="superadmin.subscriptions.index()"
                    class="group relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 transition-all duration-300 hover:shadow-[0_4px_24px_rgba(255,150,170,0.25)]">
                    <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#ffc9d0]/40 blur-2xl pointer-events-none"></div>
                    <div class="relative flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#ffc9d0] text-[#93000a]">
                                <AlertTriangle class="h-5 w-5" />
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-[#4d4634] dark:text-muted-foreground">Akan Expired</p>
                                <p class="text-2xl font-extrabold text-[#1b1c19] dark:text-foreground">{{ stats.expiring_soon }}</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-[#f5f3ee] dark:bg-white/10 text-[#4d4634] dark:text-muted-foreground transition-all group-hover:translate-x-1 group-hover:bg-[#ffc9d0] group-hover:text-[#93000a]"><ChevronRight class="h-4 w-4" /></span>
                    </div>
                    <div v-if="expiringSubscriptions.length > 0" class="relative mt-4 pt-4 border-t border-[#eae8e2] dark:border-white/10 space-y-2.5">
                        <div v-for="sub in expiringSubscriptions.slice(0, 2)" :key="sub.id" class="flex items-center justify-between text-sm">
                            <span class="font-semibold text-[#1b1c19] dark:text-foreground truncate max-w-[180px]">{{ sub.tenant_name }}</span>
                            <span class="text-xs font-bold"
                                :class="sub.days_left <= 1 ? 'text-[#93000a] dark:text-rose-400' : 'text-[#725a00] dark:text-amber-300'">
                                {{ sub.days_left }} hari lagi
                            </span>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Main Stats Grid -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Total Users -->
                <div class="relative overflow-hidden rounded-2xl bg-[#ffd23f] text-[#574500] p-5 border border-white/60 shadow-[0_2px_20px_rgba(0,0,0,0.04)] transition-all duration-300 hover:shadow-[0_4px_24px_rgba(255,210,63,0.25)]">
                    <div class="absolute -right-6 -bottom-6 w-24 h-24 rounded-full bg-white/30 blur-xl pointer-events-none"></div>
                    <div class="relative z-10 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider">Total Users</span>
                            <div class="w-7 h-7 rounded-full bg-white/60 flex items-center justify-center">
                                <Users class="h-4 w-4 text-[#574500]" />
                            </div>
                        </div>
                        <p class="text-2xl md:text-3xl font-extrabold tracking-tight">{{ stats.total_users }}</p>
                        <div class="flex items-center gap-1 text-[11px] font-bold">
                            <TrendingUp class="h-3 w-3" />
                            <span>{{ userGrowthPercentage }}% minggu ini</span>
                        </div>
                    </div>
                </div>

                <!-- Revenue -->
                <div class="relative overflow-hidden rounded-2xl bg-[#51fac1] text-[#007152] p-5 border border-white/60 shadow-[0_2px_20px_rgba(0,0,0,0.04)] transition-all duration-300 hover:shadow-[0_4px_24px_rgba(81,130,203,0.25)]">
                    <div class="absolute -right-6 -bottom-6 w-24 h-24 rounded-full bg-white/30 blur-xl pointer-events-none"></div>
                    <div class="relative z-10 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider">Total Revenue</span>
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-white/60 text-[#007152]">
                                <DollarSign class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="text-2xl md:text-3xl font-extrabold tracking-tight">{{ formatCurrencyShort(stats.total_revenue) }}</p>
                        <div class="flex items-center gap-1 text-[11px] font-bold">
                            <ArrowUpRight class="h-3 w-3" />
                            <span>Dari semua tenant</span>
                        </div>
                    </div>
                </div>

                <!-- Active Subscriptions -->
                <div class="relative overflow-hidden rounded-2xl bg-[#ffc9d0] text-[#93000a] p-5 border border-white/60 shadow-[0_2px_20px_rgba(0,0,0,0.04)] transition-all duration-300 hover:shadow-[0_4px_24px_rgba(255,150,170,0.25)]">
                    <div class="absolute -right-6 -bottom-6 w-24 h-24 rounded-full bg-white/30 blur-xl pointer-events-none"></div>
                    <div class="relative z-10 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider">Langganan Aktif</span>
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-white/60 text-[#93000a]">
                                <CreditCard class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="text-2xl md:text-3xl font-extrabold tracking-tight">{{ stats.active_subscriptions }}</p>
                        <div class="flex items-center gap-1 text-[11px] font-bold">
                            <Activity class="h-3 w-3" />
                            <span>{{ stats.total_subscriptions }} langganan</span>
                        </div>
                    </div>
                </div>

                <!-- Conversion Rate -->
                <div class="relative overflow-hidden rounded-2xl bg-[#fbf9f3] text-[#1b1c19] p-5 border border-[#eae8e2] dark:border-white/10 dark:bg-[#23231f] dark:text-foreground shadow-[0_2px_20px_rgba(0,0,0,0.04)] transition-all duration-300 hover:shadow-[0_4px_24px_rgba(255,210,63,0.25)]">
                    <div class="absolute -right-6 -bottom-6 w-24 h-24 rounded-full bg-[#51fac1]/15 blur-xl pointer-events-none"></div>
                    <div class="relative z-10 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-[#4d4634] dark:text-muted-foreground">Conversion Rate</span>
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-[#51fac1]/20 text-[#007152] dark:text-emerald-400">
                                <Package class="h-4 w-4" />
                            </span>
                        </div>
                        <p class="text-2xl md:text-3xl font-extrabold tracking-tight">{{ stats.conversion_rate }}%</p>
                        <div class="flex items-center gap-1 text-[11px] font-bold text-[#4d4634] dark:text-muted-foreground">
                            <Building2 class="h-3 w-3" />
                            <span>Tenant dengan langganan</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Period Dropdown -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-sm text-[#4d4634] dark:text-muted-foreground">
                    <Calendar class="h-4 w-4" />
                    <span>Periode: <span class="font-bold text-[#1b1c19] dark:text-foreground">{{ selectedPeriod }}</span></span>
                </div>
                <div ref="periodDropdownRef" class="relative">
                    <button
                        @click="isPeriodDropdownOpen = !isPeriodDropdownOpen"
                        class="inline-flex items-center gap-2 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-1.5 text-xs font-bold text-[#1b1c19] dark:text-foreground transition-colors hover:bg-[#f5f3ee] dark:hover:bg-white/10">
                        <Calendar class="h-3.5 w-3.5" />
                        {{ selectedPeriod }}
                        <ChevronRight class="h-3.5 w-3.5 transition-transform" :class="isPeriodDropdownOpen ? 'rotate-90' : ''" />
                    </button>
                    <div v-if="isPeriodDropdownOpen"
                        class="absolute right-0 top-full z-20 mt-1.5 w-40 overflow-hidden rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] shadow-lg">
                        <button v-for="period in ['This Month', 'Last Month', 'This Year']" :key="period"
                            @click="selectedPeriod = period; isPeriodDropdownOpen = false"
                            class="block w-full px-3 py-2 text-left text-xs font-semibold text-[#1b1c19] dark:text-foreground transition-colors hover:bg-[#f5f3ee] dark:hover:bg-white/10"
                            :class="selectedPeriod === period ? 'bg-[#ffd23f]/20 text-[#574500] dark:bg-[#ffd23f]/10 dark:text-[#ffd23f]' : ''">
                            {{ period }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Revenue Chart -->
                <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10">
                    <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#ffd23f]/20 blur-2xl pointer-events-none"></div>
                    <div class="relative flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#ffe089] text-[#241a00]">
                                <DollarSign class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="font-bold text-[#1b1c19] dark:text-foreground">Revenue Bulanan</h3>
                                <p class="text-xs text-[#4d4634] dark:text-muted-foreground">6 bulan terakhir</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#ffd23f]/20 px-2.5 py-1 text-[11px] font-bold text-[#725a00] dark:bg-[#ffd23f]/10 dark:text-amber-300">
                            <TrendingUp class="h-3 w-3" />
                            {{ formatCurrencyShort(stats.total_revenue) }}
                        </span>
                    </div>
                    <div class="relative z-10">
                        <div class="flex h-48 items-end gap-2">
                            <div v-for="(item, index) in monthlyRevenue" :key="index"
                                class="group relative flex flex-1 flex-col items-center gap-1.5">
                                <div class="relative w-full flex flex-col items-center"
                                    :style="{ height: getRevenueBarHeight(item.revenue) + 'px' }">
                                    <span class="pointer-events-none absolute -top-5 left-1/2 z-20 -translate-x-1/2 whitespace-nowrap text-[10px] font-bold text-[#1b1c19] dark:text-foreground">
                                        {{ formatCurrencyShort(item.revenue) }}
                                    </span>
                                    <div class="absolute inset-y-0 left-1/2 w-4 sm:w-7 md:w-10 -translate-x-1/2 rounded-full bg-gradient-to-t from-[#ffd23f] to-[#ffe089] transition-all duration-500 group-hover:from-[#ffb800] group-hover:to-[#ffd23f]"></div>
                                </div>
                                <span class="text-[10px] font-semibold text-[#4d4634] dark:text-muted-foreground">{{ shortMonthLabel(item.month) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Growth Chart -->
                <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10">
                    <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#51fac1]/20 blur-2xl pointer-events-none"></div>
                    <div class="relative flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#51fac1] text-[#007152]">
                                <Users class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="font-bold text-[#1b1c19] dark:text-foreground">Pertumbuhan User</h3>
                                <p class="text-xs text-[#4d4634] dark:text-muted-foreground">User baru per bulan</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#51fac1]/20 px-2.5 py-1 text-[11px] font-bold text-[#007152] dark:bg-emerald-500/10 dark:text-emerald-400">
                            <Users class="h-3 w-3" />
                            {{ stats.total_users }} user
                        </span>
                    </div>
                    <div class="relative z-10">
                        <div class="flex h-48 items-end gap-2">
                            <div v-for="(item, index) in userGrowth" :key="index"
                                class="group relative flex flex-1 flex-col items-center gap-1.5">
                                <div class="relative w-full flex flex-col items-center"
                                    :style="{ height: getUserBarHeight(item.users) + 'px' }">
                                    <span class="pointer-events-none absolute -top-5 left-1/2 z-20 -translate-x-1/2 whitespace-nowrap text-[10px] font-bold text-[#1b1c19] dark:text-foreground">
                                        {{ item.users }} user
                                    </span>
                                    <div class="absolute inset-y-0 left-1/2 w-4 sm:w-7 md:w-10 -translate-x-1/2 rounded-full bg-gradient-to-t from-[#51fac1] to-[#a5ffd0] transition-all duration-500 group-hover:from-[#2ee6a8] group-hover:to-[#51fac1]"></div>
                                </div>
                                <span class="text-[10px] font-semibold text-[#4d4634] dark:text-muted-foreground">{{ shortMonthLabel(item.month) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Recent Tenants -->
                <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10">
                    <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#ffc9d0]/30 blur-2xl pointer-events-none"></div>
                    <div class="relative flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#ffc9d0] text-[#93000a]">
                                <Building2 class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="font-bold text-[#1b1c19] dark:text-foreground">Recent Tenants</h3>
                                <p class="text-xs text-[#4d4634] dark:text-muted-foreground">Tenant terbaru dengan user</p>
                            </div>
                        </div>
                        <Link :href="superadmin.users.index()" class="inline-flex items-center gap-1 text-xs font-bold text-[#725a00] dark:text-amber-300 transition-colors hover:text-[#574500]">
                            <ExternalLink class="h-3.5 w-3.5" />
                            Lihat Semua
                        </Link>
                    </div>
                    <div class="relative z-10 space-y-3">
                        <div v-if="tenantsWithUsers.length === 0" class="rounded-xl bg-[#f5f3ee] dark:bg-white/5 p-4 text-center text-sm text-[#4d4634] dark:text-muted-foreground">
                            Belum ada tenant dengan user.
                        </div>
                        <div v-for="tenant in tenantsWithUsers" :key="tenant.id"
                            class="flex items-center justify-between gap-3 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-[#fbf9f3] dark:bg-white/5 p-3.5 transition-all duration-200 hover:border-[#ffd23f] hover:shadow-[0_2px_12px_rgba(255,210,63,0.15)]">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#ffd23f] text-[#574500]">
                                    <Building2 class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-[#1b1c19] dark:text-foreground">{{ tenant.name }}</p>
                                    <p class="text-[11px] text-[#4d4634] dark:text-muted-foreground">{{ tenant.users_count }} user</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                    :class="getStatusBadge(tenant.is_active ? 'active' : 'cancelled')">
                                    {{ tenant.is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                                <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground">{{ formatTimeAgo(tenant.created_at) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Subscriptions -->
                <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10">
                    <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-[#51fac1]/25 blur-2xl pointer-events-none"></div>
                    <div class="relative flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#51fac1] text-[#007152]">
                                <CreditCard class="h-5 w-5" />
                            </div>
                            <div>
                                <h3 class="font-bold text-[#1b1c19] dark:text-foreground">Recent Subscriptions</h3>
                                <p class="text-xs text-[#4d4634] dark:text-muted-foreground">Langganan terbaru</p>
                            </div>
                        </div>
                        <Link :href="superadmin.subscriptions.index()" class="inline-flex items-center gap-1 text-xs font-bold text-[#725a00] dark:text-amber-300 transition-colors hover:text-[#574500]">
                            <ExternalLink class="h-3.5 w-3.5" />
                            Lihat Semua
                        </Link>
                    </div>
                    <div class="relative z-10 space-y-3">
                        <div v-if="recentSubscriptions.length === 0" class="rounded-xl bg-[#f5f3ee] dark:bg-white/5 p-4 text-center text-sm text-[#4d4634] dark:text-muted-foreground">
                            Belum ada langganan.
                        </div>
                        <div v-for="sub in recentSubscriptions" :key="sub.id"
                            class="flex items-center justify-between gap-3 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-[#fbf9f3] dark:bg-white/5 p-3.5 transition-all duration-200 hover:border-[#51fac1] hover:shadow-[0_2px_12px_rgba(81,250,193,0.15)]">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#51fac1] text-[#007152]">
                                    <Package class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-[#1b1c19] dark:text-foreground">{{ sub.tenant_name }}</p>
                                    <p class="text-[11px] text-[#4d4634] dark:text-muted-foreground capitalize">{{ sub.plan }} · {{ sub.duration_months }} bulan</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="text-sm font-extrabold text-[#1b1c19] dark:text-foreground">{{ formatCurrency(sub.price) }}</span>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                                    :class="getStatusBadge(sub.status)">
                                    {{ sub.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>