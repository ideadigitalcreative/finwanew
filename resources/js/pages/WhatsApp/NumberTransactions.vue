<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

interface Props {
    number: {
        id: number;
        whatsapp_number: string;
        name: string | null;
        is_primary: boolean;
        is_lid: boolean;
    };
    totals: {
        total_income: number;
        total_expense: number;
        net: number;
        count: number;
    };
    transactions: {
        data: Array<{
            id: number;
            type: string;
            amount: number;
            transaction_date: string;
            description: string | null;
            category: { id: number; name: string; type: string } | null;
        }>;
        links: Array<{ label: string; url: string | null; active: boolean }>;
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
    };
    filters: { type: string };
}

const props = defineProps<Props>();

const displayName = props.number.name || props.number.whatsapp_number;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'WhatsApp', href: '/whatsapp' },
    { title: displayName, href: `/whatsapp-numbers/${props.number.id}/transactions` },
];

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
};

const formatDate = (date: string) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

const setType = (type: string) => {
    router.get(`/whatsapp-numbers/${props.number.id}/transactions`, type ? { type } : {}, { preserveState: true });
};
</script>

<template>
    <Head :title="`Transaksi ${displayName}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="bg-white dark:bg-[#23231f] flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-x-auto p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Header Nomor -->
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#006c4f] text-2xl">contact_phone</span>
                        {{ displayName }}
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1 flex items-center gap-1.5 flex-wrap">
                        {{ number.whatsapp_number }}
                        <span v-if="number.is_primary" class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#51fac1] text-[#007152]">
                            <span class="material-symbols-outlined text-[12px]">star</span>
                            Utama
                        </span>
                        <span v-if="number.is_lid" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#f5f3ee] text-[#4d4634]/60 dark:bg-white/10 dark:text-white/60">
                            LID
                        </span>
                    </p>
                </div>
                <Link
                    href="/whatsapp"
                    class="inline-flex items-center gap-1.5 self-start px-3.5 py-2 rounded-xl border border-[#eae8e2] dark:border-white/10 text-xs font-semibold text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5 transition-colors"
                >
                    <span class="material-symbols-outlined text-base">arrow_back</span>
                    Kembali ke WhatsApp
                </Link>
            </div>

            <!-- Kartu Total -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4 flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-[#4d4634]/60 dark:text-white/50 uppercase tracking-wider">Pemasukan</span>
                        <span class="w-8 h-8 rounded-xl bg-[#51fac1]/20 text-[#007152] flex items-center justify-center">
                            <span class="material-symbols-outlined text-base">arrow_downward</span>
                        </span>
                    </div>
                    <p class="mt-3 text-lg md:text-xl font-black text-[#006c4f] dark:text-[#51fac1]">+{{ formatCurrency(totals.total_income) }}</p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4 flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-[#4d4634]/60 dark:text-white/50 uppercase tracking-wider">Pengeluaran</span>
                        <span class="w-8 h-8 rounded-xl bg-[#ffc9d0]/30 text-[#ad2c4f] flex items-center justify-center">
                            <span class="material-symbols-outlined text-base">arrow_upward</span>
                        </span>
                    </div>
                    <p class="mt-3 text-lg md:text-xl font-black text-[#ad2c4f]">-{{ formatCurrency(totals.total_expense) }}</p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4 flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-[#4d4634]/60 dark:text-white/50 uppercase tracking-wider">Selisih Bersih</span>
                        <span class="w-8 h-8 rounded-xl bg-[#ffd23f]/20 text-[#745c00] flex items-center justify-center">
                            <span class="material-symbols-outlined text-base">account_balance_wallet</span>
                        </span>
                    </div>
                    <p
                        class="mt-3 text-lg md:text-xl font-black"
                        :class="totals.net >= 0 ? 'text-[#006c4f] dark:text-[#51fac1]' : 'text-[#ad2c4f]'"
                    >
                        {{ formatCurrency(totals.net) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] p-4 flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-[#4d4634]/60 dark:text-white/50 uppercase tracking-wider">Jumlah Transaksi</span>
                        <span class="w-8 h-8 rounded-xl bg-[#f5f3ee] text-[#4d4634] dark:bg-white/10 dark:text-white flex items-center justify-center">
                            <span class="material-symbols-outlined text-base">receipt_long</span>
                        </span>
                    </div>
                    <p class="mt-3 text-lg md:text-xl font-black text-[#1b1c19] dark:text-white">{{ totals.count }} Transaksi</p>
                </div>
            </div>

            <!-- Filter Tipe -->
            <div class="flex flex-wrap gap-2">
                <button
                    :class="[
                        'rounded-full px-4 py-1.5 text-xs md:text-sm font-bold transition-colors',
                        filters.type === '' ? 'bg-[#ffd23f] text-[#574500]' : 'border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5',
                    ]"
                    @click="setType('')"
                >
                    Semua
                </button>
                <button
                    :class="[
                        'rounded-full px-4 py-1.5 text-xs md:text-sm font-bold transition-colors',
                        filters.type === 'income' ? 'bg-[#006c4f] text-white' : 'border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5',
                    ]"
                    @click="setType('income')"
                >
                    Pemasukan
                </button>
                <button
                    :class="[
                        'rounded-full px-4 py-1.5 text-xs md:text-sm font-bold transition-colors',
                        filters.type === 'expense' ? 'bg-[#ad2c4f] text-white' : 'border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5',
                    ]"
                    @click="setType('expense')"
                >
                    Pengeluaran
                </button>
            </div>

            <!-- Tabel Transaksi -->
            <div class="overflow-hidden rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f]">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee]/50 dark:bg-white/5 text-[11px] font-bold uppercase tracking-wider text-[#4d4634]/60 dark:text-white/50">
                            <tr>
                                <th class="px-4 py-3">Tanggal</th>
                                <th class="px-4 py-3">Kategori</th>
                                <th class="px-4 py-3">Deskripsi</th>
                                <th class="px-4 py-3 text-right">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#eae8e2] dark:divide-white/10">
                            <tr v-if="transactions.data.length === 0">
                                <td colspan="4" class="px-4 py-10 text-center text-[#4d4634]/50 dark:text-white/40">
                                    <span class="material-symbols-outlined text-3xl mb-2 block">receipt</span>
                                    Belum ada transaksi untuk nomor ini.
                                </td>
                            </tr>
                            <tr v-for="tx in transactions.data" :key="tx.id" class="hover:bg-[#f5f3ee]/30 dark:hover:bg-white/5 transition-colors">
                                <td class="whitespace-nowrap px-4 py-3.5 text-xs font-semibold text-[#4d4634] dark:text-white/70">{{ formatDate(tx.transaction_date) }}</td>
                                <td class="px-4 py-3.5">
                                    <span v-if="tx.category" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-[#f5f3ee] text-[#4d4634] dark:bg-white/10 dark:text-white/80">
                                        {{ tx.category.name }}
                                    </span>
                                    <span v-else class="text-[#4d4634]/40 dark:text-white/40">-</span>
                                </td>
                                <td class="max-w-xs truncate px-4 py-3.5 text-xs font-semibold text-[#1b1c19] dark:text-white" :title="tx.description || ''">{{ tx.description || '-' }}</td>
                                <td
                                    class="whitespace-nowrap px-4 py-3.5 text-right font-bold text-xs"
                                    :class="tx.type === 'income' ? 'text-[#006c4f] dark:text-[#51fac1]' : 'text-[#ad2c4f]'"
                                >
                                    {{ tx.type === 'income' ? '+' : '-' }}{{ formatCurrency(tx.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="transactions.links && transactions.links.length > 3" class="border-t border-[#eae8e2] dark:border-white/10 px-4 py-3.5 bg-[#f5f3ee]/30 dark:bg-white/5">
                    <div class="flex flex-col items-center justify-between gap-3 md:flex-row">
                        <div class="text-xs text-[#4d4634]/60 dark:text-white/50">
                            Menampilkan {{ transactions.meta?.from || 0 }}–{{ transactions.meta?.to || 0 }} dari {{ transactions.meta?.total || 0 }} transaksi
                        </div>
                        <div class="flex gap-1.5">
                            <Link
                                v-for="(link, index) in transactions.links"
                                :key="index"
                                :href="link.url || '#'"
                                :class="[
                                    'rounded-xl px-3 py-1.5 text-xs font-bold transition-colors',
                                    link.url ? 'text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/10' : 'text-[#4d4634]/30 dark:text-white/30 cursor-not-allowed',
                                    link.active ? '!bg-[#ffd23f] !text-[#574500] hover:!bg-[#ffd23f]' : '',
                                ]"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
