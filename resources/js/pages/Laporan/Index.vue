<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface Props {
    tenantName?: string;
    summary: {
        totalIncome: number;
        totalExpense: number;
        netCashflow: number;
        transactionCount: number;
        periodLabel: string;
    };
    memberSummary?: Array<{
        number_id: number;
        name: string;
        whatsapp_number: string | null;
        total_income: number;
        total_expense: number;
        count: number;
    }>;
    whatsappNumbers?: Array<{
        id: number;
        whatsapp_number: string;
        name: string | null;
        is_primary: boolean;
        is_lid: boolean;
    }>;
    filters: { start_date: string; end_date: string };
}

const props = withDefaults(defineProps<Props>(), {
    tenantName: '',
    memberSummary: () => [],
    whatsappNumbers: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Laporan', href: '/laporan' },
];

const form = reactive({
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
});

const applyFilters = () => {
    router.get('/laporan', { start_date: form.start_date, end_date: form.end_date }, { preserveState: true });
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
};
</script>

<template>
    <Head title="Laporan" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 md:gap-6 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Filter Periode -->
            <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-bold text-[#1b1c19] dark:text-white">Laporan Keuangan</h2>
                            <span class="material-symbols-outlined text-xl text-[#ffd23f]">monitoring</span>
                        </div>
                        <p class="text-sm text-[#4d4634] dark:text-white/50 mt-0.5">Periode: {{ summary.periodLabel }}</p>
                    </div>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="applyFilters">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[#4d4634] dark:text-white/50">Dari</label>
                            <input v-model="form.start_date" type="date" class="rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2 text-sm text-[#1b1c19] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#ffd23f]" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[#4d4634] dark:text-white/50">Sampai</label>
                            <input v-model="form.end_date" type="date" class="rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2 text-sm text-[#1b1c19] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#ffd23f]" />
                        </div>
                        <button type="submit" class="rounded-xl bg-[#ffd23f] px-4 py-2 text-sm font-bold text-[#725a00] transition-colors hover:bg-[#ffe089]">
                            Terapkan
                        </button>
                    </form>
                </div>
            </div>

            <!-- Kartu Ringkasan Total -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4">
                    <p class="text-xs font-[800] uppercase tracking-wider text-[#4d4634] dark:text-white/50">Pemasukan</p>
                    <p class="mt-1 text-lg font-bold text-[#006c4f] dark:text-[#51fac1]">+{{ formatCurrency(summary.totalIncome) }}</p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4">
                    <p class="text-xs font-[800] uppercase tracking-wider text-[#4d4634] dark:text-white/50">Pengeluaran</p>
                    <p class="mt-1 text-lg font-bold text-[#ad2c4f] dark:text-[#e36c8b]">-{{ formatCurrency(summary.totalExpense) }}</p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4">
                    <p class="text-xs font-[800] uppercase tracking-wider text-[#4d4634] dark:text-white/50">Selisih (Arus Kas)</p>
                    <p
                        class="mt-1 text-lg font-bold"
                        :class="summary.netCashflow >= 0 ? 'text-[#006c4f] dark:text-[#51fac1]' : 'text-[#ad2c4f] dark:text-[#e36c8b]'"
                    >
                        {{ formatCurrency(summary.netCashflow) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4">
                    <p class="text-xs font-[800] uppercase tracking-wider text-[#4d4634] dark:text-white/50">Jumlah Transaksi</p>
                    <p class="mt-1 text-lg font-bold text-[#1b1c19] dark:text-white">{{ summary.transactionCount }}</p>
                </div>
            </div>

            <!-- Ringkasan per Anggota -->
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-[#1b1c19] dark:text-white">Ringkasan per Anggota</h3>
                    <Link href="/whatsapp" class="text-xs font-medium text-[#725a00] dark:text-[#ffe089] hover:underline">Kelola Nomor</Link>
                </div>

                <div v-if="memberSummary.length === 0" class="rounded-2xl border border-dashed border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 p-6 text-center">
                    <p class="text-sm text-[#4d4634] dark:text-white/50">
                        Belum ada transaksi yang terhubung ke nomor WhatsApp pada periode ini.
                    </p>
                </div>

                <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Link
                        v-for="member in memberSummary"
                        :key="member.number_id"
                        :href="`/whatsapp-numbers/${member.number_id}/transactions`"
                        class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4 transition-colors hover:bg-[#f5f3ee] dark:hover:bg-white/5"
                    >
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold text-[#1b1c19] dark:text-white" :title="member.whatsapp_number || ''">{{ member.name }}</p>
                            <span class="shrink-0 rounded-full bg-[#f5f3ee] dark:bg-white/10 px-2 py-0.5 text-[10px] font-medium text-[#4d4634] dark:text-white/50">{{ member.count }} transaksi</span>
                        </div>
                        <p class="text-sm font-bold text-[#006c4f] dark:text-[#51fac1]">+{{ formatCurrency(member.total_income) }}</p>
                        <p class="text-sm font-bold text-[#ad2c4f] dark:text-[#e36c8b]">-{{ formatCurrency(member.total_expense) }}</p>
                        <p
                            class="mt-1 text-xs font-semibold"
                            :class="member.total_income - member.total_expense >= 0 ? 'text-[#006c4f] dark:text-[#51fac1]' : 'text-[#ad2c4f] dark:text-[#e36c8b]'"
                        >
                            Selisih: {{ formatCurrency(member.total_income - member.total_expense) }}
                        </p>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
