<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed } from 'vue';
import { type BreadcrumbItem } from '@/types';
import { Scale, Info, ArrowDownRight, ArrowUpRight } from 'lucide-vue-next';

interface Row {
    counterparty: string;
    counterparty_normalized: string;
    outstanding: number;
}

interface RecentRow {
    id: number;
    transaction_date: string | null;
    type: string;
    amount: number;
    description: string;
    category_type: string | null;
    category_name: string | null;
    counterparty: string | null;
}

interface Summary {
    hutang: Row[];
    piutang: Row[];
    recent: RecentRow[];
}

const props = defineProps<{
    summary: Summary;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Hutang & Piutang', href: '/hutang-piutang' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);

const formatDate = (date: string | null) => {
    if (!date) return '—';
    try {
        return format(new Date(date), 'dd MMM yyyy', { locale: id });
    } catch {
        return date;
    }
};

const totalHutangOutstanding = computed(() => props.summary.hutang.reduce((s, r) => s + r.outstanding, 0));
const totalPiutangOutstanding = computed(() => props.summary.piutang.reduce((s, r) => s + r.outstanding, 0));

const amountClass = (kind: 'hutang' | 'piutang', outstanding: number) => {
    if (outstanding <= 0) {
        return 'text-emerald-600 dark:text-emerald-400';
    }
    return kind === 'hutang'
        ? 'text-amber-600 dark:text-amber-400'
        : 'text-sky-600 dark:text-sky-400';
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Hutang & Piutang" />

        <div class="bg-background flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:gap-6 md:p-6">
            <!-- Header (selaras Saldo Akun / Balances) -->
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="flex items-center gap-2 text-xl font-bold tracking-tight text-gray-900 dark:text-white md:text-2xl">
                        <Scale class="h-6 w-6 shrink-0 text-emerald-600 md:h-7 md:w-7" />
                        Hutang & Piutang
                    </h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 md:text-sm">
                        Ringkasan dari transaksi hutang/piutang. Outstanding dihitung dari empat tipe kategori resmi.
                    </p>
                </div>
            </div>

            <!-- Ringkasan cepat mobile-first -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                <div class="rounded-[13px] border border-gray-200/50 bg-card/60 p-4 dark:border-gray-700/30 md:p-5">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Net outstanding hutang (semua pihak)</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-gray-900 dark:text-white md:text-2xl">
                        {{ formatCurrency(totalHutangOutstanding) }}
                    </p>
                </div>
                <div class="rounded-[13px] border border-gray-200/50 bg-card/60 p-4 dark:border-gray-700/30 md:p-5">
                    <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Net outstanding piutang (semua pihak)</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-gray-900 dark:text-white md:text-2xl">
                        {{ formatCurrency(totalPiutangOutstanding) }}
                    </p>
                </div>
            </div>

            <!-- Info -->
            <div
                class="flex gap-3 rounded-[13px] border border-gray-200/50 bg-card/60 p-4 backdrop-blur-2xl dark:border-gray-700/30 md:p-5"
            >
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-emerald-500/20 bg-emerald-500/10"
                >
                    <Info class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div class="min-w-0 text-sm text-gray-600 dark:text-gray-300">
                    <p class="font-semibold text-gray-900 dark:text-white">Mode baca saja</p>
                    <p class="mt-1 leading-relaxed text-gray-600 dark:text-gray-400">
                        <span class="font-medium text-gray-800 dark:text-gray-200">Hutang:</span>
                        (+) terima pinjaman, (−) bayar hutang per pihak.
                        <span class="font-medium text-gray-800 dark:text-gray-200">Piutang:</span>
                        (+) kasih pinjam, (−) terima pelunasan. Nama pihak dari metadata atau pola
                        <span class="whitespace-nowrap">«dari / ke / sama»</span>.
                    </p>
                </div>
            </div>

            <!-- Hutang & Piutang -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
                <!-- Hutang -->
                <section
                    class="overflow-hidden rounded-[13px] border border-gray-200/50 bg-card/60 shadow-xl shadow-primary/5 backdrop-blur-2xl dark:border-gray-700/30"
                >
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700 md:px-5 md:py-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white md:text-lg">Hutang per pihak</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Saldo positif = masih berutang</p>
                    </div>

                    <!-- Mobile: kartu -->
                    <div class="divide-y divide-gray-100 dark:divide-gray-800 md:hidden">
                        <div v-if="!props.summary.hutang.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Belum ada data hutang.
                        </div>
                        <div
                            v-for="row in props.summary.hutang"
                            :key="'hm-' + row.counterparty_normalized"
                            class="flex items-start justify-between gap-3 px-4 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="break-words font-medium leading-snug text-gray-900 dark:text-white">{{ row.counterparty }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Outstanding</p>
                            </div>
                            <p
                                class="shrink-0 text-right text-sm font-bold tabular-nums"
                                :class="amountClass('hutang', row.outstanding)"
                            >
                                {{ formatCurrency(row.outstanding) }}
                            </p>
                        </div>
                    </div>

                    <!-- Desktop: tabel -->
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-gray-100 bg-gray-50/80 text-left text-gray-600 dark:border-gray-800 dark:bg-gray-800/40 dark:text-gray-400"
                                >
                                    <th class="px-5 py-2.5 font-medium">Pihak</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!props.summary.hutang.length">
                                    <td colspan="2" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">Belum ada data hutang.</td>
                                </tr>
                                <tr
                                    v-for="row in props.summary.hutang"
                                    :key="'hd-' + row.counterparty_normalized"
                                    class="border-b border-gray-100 dark:border-gray-800"
                                >
                                    <td class="max-w-[16rem] break-words px-5 py-3 text-gray-900 dark:text-white">{{ row.counterparty }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold" :class="amountClass('hutang', row.outstanding)">
                                        {{ formatCurrency(row.outstanding) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Piutang -->
                <section
                    class="overflow-hidden rounded-[13px] border border-gray-200/50 bg-card/60 shadow-xl shadow-primary/5 backdrop-blur-2xl dark:border-gray-700/30"
                >
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700 md:px-5 md:py-4">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white md:text-lg">Piutang per pihak</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Saldo positif = mereka masih berutang ke Anda</p>
                    </div>

                    <div class="divide-y divide-gray-100 dark:divide-gray-800 md:hidden">
                        <div v-if="!props.summary.piutang.length" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Belum ada data piutang.
                        </div>
                        <div
                            v-for="row in props.summary.piutang"
                            :key="'pm-' + row.counterparty_normalized"
                            class="flex items-start justify-between gap-3 px-4 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="break-words font-medium leading-snug text-gray-900 dark:text-white">{{ row.counterparty }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Outstanding</p>
                            </div>
                            <p
                                class="shrink-0 text-right text-sm font-bold tabular-nums"
                                :class="amountClass('piutang', row.outstanding)"
                            >
                                {{ formatCurrency(row.outstanding) }}
                            </p>
                        </div>
                    </div>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr
                                    class="border-b border-gray-100 bg-gray-50/80 text-left text-gray-600 dark:border-gray-800 dark:bg-gray-800/40 dark:text-gray-400"
                                >
                                    <th class="px-5 py-2.5 font-medium">Pihak</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!props.summary.piutang.length">
                                    <td colspan="2" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">Belum ada data piutang.</td>
                                </tr>
                                <tr
                                    v-for="row in props.summary.piutang"
                                    :key="'pd-' + row.counterparty_normalized"
                                    class="border-b border-gray-100 dark:border-gray-800"
                                >
                                    <td class="max-w-[16rem] break-words px-5 py-3 text-gray-900 dark:text-white">{{ row.counterparty }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold" :class="amountClass('piutang', row.outstanding)">
                                        {{ formatCurrency(row.outstanding) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Transaksi terkait -->
            <section
                class="overflow-hidden rounded-[13px] border border-gray-200/50 bg-card/60 shadow-xl shadow-primary/5 backdrop-blur-2xl dark:border-gray-700/30"
            >
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700 md:px-5 md:py-4">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white md:text-lg">Transaksi terkait (terbaru)</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Hanya transaksi dengan kategori hutang/piutang</p>
                </div>

                <!-- Mobile: kartu stack -->
                <div class="space-y-3 p-4 md:hidden">
                    <div v-if="!props.summary.recent.length" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        Belum ada transaksi.
                    </div>
                    <Link
                        v-for="tx in props.summary.recent"
                        :key="'m-' + tx.id"
                        :href="`/transactions/${tx.id}`"
                        class="block rounded-xl border border-gray-200/60 bg-white/80 p-4 transition-colors active:bg-gray-50 dark:border-gray-700/50 dark:bg-gray-900/40 dark:active:bg-gray-800/80"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(tx.transaction_date) }}</p>
                                <p class="mt-1 line-clamp-2 text-sm font-medium text-gray-900 dark:text-white">
                                    {{ tx.description || '—' }}
                                </p>
                                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ tx.category_name ?? tx.category_type ?? '—' }}
                                    <span v-if="tx.counterparty"> · {{ tx.counterparty }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span
                                    class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="
                                        tx.type === 'income'
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                            : 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
                                    "
                                >
                                    <ArrowDownRight v-if="tx.type === 'income'" class="h-3 w-3" />
                                    <ArrowUpRight v-else class="h-3 w-3" />
                                    {{ tx.type === 'income' ? 'Masuk' : 'Keluar' }}
                                </span>
                                <p class="text-sm font-bold tabular-nums text-gray-900 dark:text-white">
                                    {{ formatCurrency(tx.amount) }}
                                </p>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Desktop: tabel + scroll horizontal aman -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-[640px] w-full text-sm lg:min-w-0">
                        <thead>
                            <tr
                                class="border-b border-gray-100 bg-gray-50/80 text-left text-gray-600 dark:border-gray-800 dark:bg-gray-800/40 dark:text-gray-400"
                            >
                                <th class="px-5 py-2.5 font-medium whitespace-nowrap">Tanggal</th>
                                <th class="px-5 py-2.5 font-medium">Kategori</th>
                                <th class="px-5 py-2.5 font-medium">Pihak</th>
                                <th class="px-5 py-2.5 font-medium">Jenis</th>
                                <th class="px-5 py-2.5 text-right font-medium whitespace-nowrap">Nominal</th>
                                <th class="min-w-[8rem] px-5 py-2.5 font-medium">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!props.summary.recent.length">
                                <td colspan="6" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">Belum ada transaksi.</td>
                            </tr>
                            <tr
                                v-for="tx in props.summary.recent"
                                :key="'d-' + tx.id"
                                class="border-b border-gray-100 transition-colors hover:bg-gray-50/50 dark:border-gray-800 dark:hover:bg-gray-800/30"
                            >
                                <td class="whitespace-nowrap px-5 py-3 text-gray-600 dark:text-gray-300">
                                    {{ formatDate(tx.transaction_date) }}
                                </td>
                                <td class="max-w-[10rem] truncate px-5 py-3 text-gray-800 dark:text-gray-200" :title="tx.category_name ?? undefined">
                                    {{ tx.category_name ?? tx.category_type ?? '—' }}
                                </td>
                                <td class="max-w-[14rem] min-w-[6rem] break-words px-5 py-3 text-gray-600 dark:text-gray-300">
                                    {{ tx.counterparty ?? '—' }}
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            tx.type === 'income'
                                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                                : 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
                                        "
                                    >
                                        {{ tx.type === 'income' ? 'Masuk' : 'Keluar' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums font-semibold text-gray-900 dark:text-white">
                                    {{ formatCurrency(tx.amount) }}
                                </td>
                                <td class="max-w-xs px-5 py-3">
                                    <Link
                                        :href="`/transactions/${tx.id}`"
                                        class="line-clamp-2 text-gray-600 underline decoration-gray-300 underline-offset-2 transition-colors hover:text-emerald-600 dark:text-gray-400 dark:decoration-gray-600 dark:hover:text-emerald-400"
                                        :title="tx.description"
                                    >
                                        {{ tx.description }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
