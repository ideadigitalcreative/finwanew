<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed, ref } from 'vue';
import { type BreadcrumbItem } from '@/types';


interface HistoryEntry {
    id: number;
    transaction_date: string | null;
    type: string;
    amount: number;
    effect: number;
    description: string;
}

interface Row {
    counterparty: string;
    counterparty_normalized: string;
    outstanding: number;
    history: HistoryEntry[];
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

const selected = ref<{ kind: 'hutang' | 'piutang'; key: string; label: string } | null>(null);

const filter = ref<'semua' | 'hutang' | 'piutang'>('semua');

const select = (kind: 'hutang' | 'piutang', row: Row) => {
    if (selected.value?.kind === kind && selected.value.key === row.counterparty_normalized) {
        selected.value = null;
        return;
    }
    selected.value = { kind, key: row.counterparty_normalized, label: row.counterparty };
};

const selectedHistory = computed(() => {
    if (!selected.value) return null;
    const list = selected.value.kind === 'hutang' ? props.summary.hutang : props.summary.piutang;
    const row = list.find((r) => r.counterparty_normalized === selected.value!.key);
    if (!row) return null;
    let running = 0;
    return row.history.map((e) => {
        running += e.effect * e.amount;
        return { ...e, running };
    });
});

const amountClass = (kind: 'hutang' | 'piutang', outstanding: number) => {
    if (outstanding <= 0) {
        return 'text-[#006c4f]';
    }
    return kind === 'hutang'
        ? 'text-[#ad2c4f]'
        : 'text-[#725a00]';
};

const settleOpen = ref(false);
const settleAmount = ref('');
const settleSubmitting = ref(false);

const addOpen = ref(false);
const addKind = ref<'hutang' | 'piutang'>('hutang');
const addCounterparty = ref('');
const addAmount = ref('');
const addSubmitting = ref(false);

const today = () => new Date().toISOString().slice(0, 10);

const submitAdd = () => {
    const amount = Number(addAmount.value);
    const counterparty = addCounterparty.value.trim();
    if (!counterparty || !Number.isFinite(amount) || amount <= 0) return;
    addSubmitting.value = true;
    router.post(
        '/hutang-piutang/store',
        {
            kind: addKind.value,
            counterparty,
            amount,
            transaction_date: today(),
        },
        {
            onSuccess: () => {
                addOpen.value = false;
                addCounterparty.value = '';
                addAmount.value = '';
            },
            onFinish: () => {
                addSubmitting.value = false;
            },
        },
    );
};

const openSettle = () => {
    settleAmount.value = '';
    settleOpen.value = !settleOpen.value;
};

const submitSettle = () => {
    if (!selected.value) return;
    const amount = Number(settleAmount.value);
    if (!Number.isFinite(amount) || amount <= 0) return;
    settleSubmitting.value = true;
    router.post(
        '/hutang-piutang/settle',
        {
            kind: selected.value.kind,
            counterparty: selected.value.label,
            counterparty_normalized: selected.value.key,
            amount,
            transaction_date: today(),
        },
        {
            onSuccess: () => {
                settleOpen.value = false;
                settleAmount.value = '';
            },
            onFinish: () => {
                settleSubmitting.value = false;
            },
        },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Hutang & Piutang" />

        <div class="bg-[#fbf9f3] flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:gap-6 md:p-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="flex items-center gap-2 text-xl font-bold tracking-tight text-[#1b1c19] md:text-2xl">
                        <span class="material-symbols-outlined text-[#ffd23f] text-2xl md:text-3xl">balance</span>
                        Hutang & Piutang
                    </h2>
                    <p class="mt-1 text-xs text-[#4d4634]/60 md:text-sm">
                        Ringkasan dari transaksi hutang/piutang. Outstanding dihitung dari empat tipe kategori resmi.
                    </p>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-colors shadow-md"
                    @click="addOpen = !addOpen"
                >
                    {{ addOpen ? 'Tutup' : 'Catat Hutang/Piutang' }}
                </button>
            </div>

            <!-- Form catat hutang/piutang manual -->
            <form
                v-if="addOpen"
                class="flex flex-col gap-3 rounded-2xl border border-[#eae8e2] bg-white p-4 md:p-5"
                @submit.prevent="submitAdd"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label class="text-xs font-medium text-[#4d4634]/60">Jenis</label>
                        <div class="mt-1 flex gap-2">
                            <button
                                type="button"
                                class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                                :class="addKind === 'hutang' ? 'border-[#ffd23f] bg-[#ffd23f]/20 text-[#725a00]' : 'border-[#eae8e2] text-[#4d4634]/60 hover:bg-[#f5f3ee]'"
                                @click="addKind = 'hutang'"
                            >
                                Hutang (saya berutang)
                            </button>
                            <button
                                type="button"
                                class="flex-1 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                                :class="addKind === 'piutang' ? 'border-[#ffd23f] bg-[#ffd23f]/20 text-[#725a00]' : 'border-[#eae8e2] text-[#4d4634]/60 hover:bg-[#f5f3ee]'"
                                @click="addKind = 'piutang'"
                            >
                                Piutang (orang berutang ke saya)
                            </button>
                        </div>
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-medium text-[#4d4634]/60">Nama pihak</label>
                        <input
                            v-model="addCounterparty"
                            type="text"
                            required
                            maxlength="80"
                            placeholder="Contoh: Budi"
                            class="mt-1 w-full rounded-lg border border-[#eae8e2] bg-white px-3 py-2 text-sm text-[#1b1c19] focus:border-[#ffd23f] focus:ring-[#ffd23f] focus:outline-none"
                        />
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-medium text-[#4d4634]/60">Nominal (Rp)</label>
                        <input
                            v-model="addAmount"
                            type="number"
                            min="1"
                            step="1"
                            required
                            placeholder="Contoh: 100000"
                            class="mt-1 w-full rounded-lg border border-[#eae8e2] bg-white px-3 py-2 text-sm text-[#1b1c19] focus:border-[#ffd23f] focus:ring-[#ffd23f] focus:outline-none"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="addSubmitting"
                        class="shrink-0 rounded-lg bg-[#ffd23f] px-4 py-2 text-sm font-semibold text-[#725a00] transition-colors hover:bg-[#ffe089] disabled:opacity-50"
                    >
                        {{ addSubmitting ? 'Menyimpan...' : 'Simpan' }}
                    </button>
                </div>
            </form>

            <!-- Ringkasan cepat mobile-first -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                <div class="rounded-2xl border border-[#eae8e2] bg-white p-4 md:p-5">
                    <p class="text-xs font-medium text-[#4d4634]/60">Net outstanding hutang (semua pihak)</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-[#1b1c19] md:text-2xl">
                        {{ formatCurrency(totalHutangOutstanding) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-[#eae8e2] bg-white p-4 md:p-5">
                    <p class="text-xs font-medium text-[#4d4634]/60">Net outstanding piutang (semua pihak)</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-[#1b1c19] md:text-2xl">
                        {{ formatCurrency(totalPiutangOutstanding) }}
                    </p>
                </div>
            </div>

            <!-- Info -->
            <div
                class="flex gap-3 rounded-2xl border border-[#eae8e2] bg-white p-4 md:p-5"
            >
                <div
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#ffd23f]/20"
                >
                    <span class="material-symbols-outlined text-xl text-[#725a00]">info</span>
                </div>
                <div class="min-w-0 text-sm text-[#4d4634]/60">
                    <p class="font-semibold text-[#1b1c19]">Mode baca saja</p>
                    <p class="mt-1 leading-relaxed text-[#4d4634]/60">
                        <span class="font-medium text-[#1b1c19]">Hutang:</span>
                        (+) terima pinjaman, (−) bayar hutang per pihak.
                        <span class="font-medium text-[#1b1c19]">Piutang:</span>
                        (+) kasih pinjam, (−) terima pelunasan. Nama pihak dari metadata atau pola
                        <span class="whitespace-nowrap">«dari / ke / sama»</span>.
                    </p>
                </div>
            </div>

            <!-- Filter tab -->
            <div class="flex w-full max-w-md gap-1 rounded-2xl border border-[#eae8e2] bg-white p-1">
                <button
                    v-for="tab in (['semua', 'hutang', 'piutang'] as const)"
                    :key="tab"
                    type="button"
                    class="flex-1 rounded-xl px-3 py-2 text-sm font-medium transition-colors"
                    :class="
                        filter === tab
                            ? 'bg-[#ffd23f] text-[#725a00]'
                            : 'text-[#4d4634]/60 hover:bg-[#f5f3ee]'
                    "
                    @click="filter = tab"
                >
                    {{ tab === 'semua' ? 'Semua' : tab === 'hutang' ? 'Hutang' : 'Piutang' }}
                </button>
            </div>

            <!-- Hutang & Piutang -->
            <div
                class="grid grid-cols-1 gap-4 md:gap-6"
                :class="filter === 'semua' ? 'md:grid-cols-2' : 'md:grid-cols-1'"
            >
                <!-- Hutang -->
                <section
                    v-if="filter !== 'piutang'"
                    class="overflow-hidden rounded-2xl border border-[#eae8e2] bg-white shadow-sm"
                >
                    <div class="border-b border-[#eae8e2] px-4 py-3 md:px-5 md:py-4">
                        <h3 class="text-base font-bold text-[#1b1c19] md:text-lg">Hutang per pihak</h3>
                        <p class="mt-0.5 text-xs text-[#4d4634]/60">Saldo positif = masih berutang</p>
                    </div>

                    <!-- Mobile: kartu -->
                    <div class="divide-y divide-[#eae8e2] md:hidden">
                        <div v-if="!props.summary.hutang.length" class="px-4 py-8 text-center text-sm text-[#4d4634]/50">
                            Belum ada data hutang.
                        </div>
                        <div
                            v-for="row in props.summary.hutang"
                            :key="'hm-' + row.counterparty_normalized"
                            class="flex cursor-pointer items-start justify-between gap-3 px-4 py-3 transition-colors"
                            :class="selected?.kind === 'hutang' && selected.key === row.counterparty_normalized ? 'bg-[#ffd23f]/10' : 'active:bg-[#f5f3ee]'"
                            @click="select('hutang', row)"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="break-words font-medium leading-snug text-[#1b1c19]">{{ row.counterparty }}</p>
                                <p class="mt-0.5 text-xs text-[#4d4634]/50">Outstanding</p>
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
                                    class="border-b border-[#eae8e2] bg-[#f5f3ee] text-left text-[#4d4634]/60"
                                >
                                    <th class="px-5 py-2.5 font-medium">Pihak</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!props.summary.hutang.length">
                                    <td colspan="2" class="px-5 py-8 text-center text-[#4d4634]/50">Belum ada data hutang.</td>
                                </tr>
                                <tr
                                    v-for="row in props.summary.hutang"
                                    :key="'hd-' + row.counterparty_normalized"
                                    class="cursor-pointer border-b border-[#eae8e2] transition-colors"
                                    :class="selected?.kind === 'hutang' && selected.key === row.counterparty_normalized ? 'bg-[#ffd23f]/10' : 'hover:bg-[#f5f3ee]'"
                                    @click="select('hutang', row)"
                                >
                                    <td class="max-w-[16rem] break-words px-5 py-3 text-[#1b1c19]">{{ row.counterparty }}</td>
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
                    v-if="filter !== 'hutang'"
                    class="overflow-hidden rounded-2xl border border-[#eae8e2] bg-white shadow-sm"
                >
                    <div class="border-b border-[#eae8e2] px-4 py-3 md:px-5 md:py-4">
                        <h3 class="text-base font-bold text-[#1b1c19] md:text-lg">Piutang per pihak</h3>
                        <p class="mt-0.5 text-xs text-[#4d4634]/60">Saldo positif = mereka masih berutang ke Anda</p>
                    </div>

                    <div class="divide-y divide-[#eae8e2] md:hidden">
                        <div v-if="!props.summary.piutang.length" class="px-4 py-8 text-center text-sm text-[#4d4634]/50">
                            Belum ada data piutang.
                        </div>
                        <div
                            v-for="row in props.summary.piutang"
                            :key="'pm-' + row.counterparty_normalized"
                            class="flex cursor-pointer items-start justify-between gap-3 px-4 py-3 transition-colors"
                            :class="selected?.kind === 'piutang' && selected.key === row.counterparty_normalized ? 'bg-[#ffd23f]/10' : 'active:bg-[#f5f3ee]'"
                            @click="select('piutang', row)"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="break-words font-medium leading-snug text-[#1b1c19]">{{ row.counterparty }}</p>
                                <p class="mt-0.5 text-xs text-[#4d4634]/50">Outstanding</p>
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
                                    class="border-b border-[#eae8e2] bg-[#f5f3ee] text-left text-[#4d4634]/60"
                                >
                                    <th class="px-5 py-2.5 font-medium">Pihak</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!props.summary.piutang.length">
                                    <td colspan="2" class="px-5 py-8 text-center text-[#4d4634]/50">Belum ada data piutang.</td>
                                </tr>
                                <tr
                                    v-for="row in props.summary.piutang"
                                    :key="'pd-' + row.counterparty_normalized"
                                    class="cursor-pointer border-b border-[#eae8e2] transition-colors"
                                    :class="selected?.kind === 'piutang' && selected.key === row.counterparty_normalized ? 'bg-[#ffd23f]/10' : 'hover:bg-[#f5f3ee]'"
                                    @click="select('piutang', row)"
                                >
                                    <td class="max-w-[16rem] break-words px-5 py-3 text-[#1b1c19]">{{ row.counterparty }}</td>
                                    <td class="px-5 py-3 text-right tabular-nums font-semibold" :class="amountClass('piutang', row.outstanding)">
                                        {{ formatCurrency(row.outstanding) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Detail pihak terpilih -->
            <section
                v-if="selected && selectedHistory"
                class="overflow-hidden rounded-2xl border border-[#eae8e2] bg-white shadow-sm"
            >
                <div class="flex items-center justify-between border-b border-[#eae8e2] px-4 py-3 md:px-5 md:py-4">
                    <div class="min-w-0">
                        <h3 class="text-base font-bold text-[#1b1c19] md:text-lg">Riwayat: {{ selected.label }}</h3>
                        <p class="mt-0.5 text-xs text-[#4d4634]/60">
                            {{ selected.kind === 'hutang' ? 'Hutang' : 'Piutang' }} · saldo berjalan dari transaksi terlama
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-[#ffd23f]/40 bg-[#ffd23f]/10 px-3 py-1.5 text-sm font-medium text-[#725a00] transition-colors hover:bg-[#ffd23f]/20"
                            @click="openSettle"
                        >
                            {{ selected.kind === 'hutang' ? 'Bayar Hutang' : 'Terima Pelunasan' }}
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-[#4d4634]/60 transition-colors hover:bg-[#f5f3ee]"
                            @click="selected = null"
                        >
                            Tutup
                        </button>
                    </div>
                </div>

                <form
                    v-if="settleOpen"
                    class="flex flex-col gap-3 border-b border-[#eae8e2] px-4 py-4 sm:flex-row sm:items-end md:px-5"
                    @submit.prevent="submitSettle"
                >
                    <div class="flex-1">
                        <label class="text-xs font-medium text-[#4d4634]/60">
                            Nominal {{ selected.kind === 'hutang' ? 'pembayaran hutang' : 'pelunasan piutang' }} (Rp)
                        </label>
                        <input
                            v-model="settleAmount"
                            type="number"
                            min="1"
                            step="1"
                            required
                            placeholder="Contoh: 100000"
                            class="mt-1 w-full rounded-lg border border-[#eae8e2] bg-white px-3 py-2 text-sm text-[#1b1c19] focus:border-[#ffd23f] focus:ring-[#ffd23f] focus:outline-none"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="settleSubmitting"
                        class="rounded-lg bg-[#ffd23f] px-4 py-2 text-sm font-semibold text-[#725a00] transition-colors hover:bg-[#ffe089] disabled:opacity-50"
                    >
                        {{ settleSubmitting ? 'Menyimpan...' : (selected.kind === 'hutang' ? 'Catat Bayar Hutang' : 'Catat Terima Piutang') }}
                    </button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-[640px] w-full text-sm">
                        <thead>
                            <tr class="border-b border-[#eae8e2] bg-[#f5f3ee] text-left text-[#4d4634]/60">
                                <th class="px-5 py-2.5 font-medium">Tanggal</th>
                                <th class="px-5 py-2.5 font-medium">Keterangan</th>
                                <th class="px-5 py-2.5 font-medium">Jenis</th>
                                <th class="px-5 py-2.5 text-right font-medium">Nominal</th>
                                <th class="px-5 py-2.5 text-right font-medium">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in selectedHistory" :key="e.id" class="border-b border-[#eae8e2]">
                                <td class="whitespace-nowrap px-5 py-3 text-[#4d4634]/70">{{ formatDate(e.transaction_date) }}</td>
                                <td class="max-w-xs px-5 py-3 text-[#1b1c19]">{{ e.description }}</td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="e.type === 'income' ? 'bg-[#51fac1]/20 text-[#006c4f]' : 'bg-[#ffc9d0]/40 text-[#ad2c4f]'"
                                    >
                                        {{ e.type === 'income' ? 'Masuk' : 'Keluar' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums text-[#1b1c19]">
                                    {{ (e.effect >= 0 ? '+' : '-') + formatCurrency(e.amount) }}
                                </td>
                                <td
                                    class="whitespace-nowrap px-5 py-3 text-right tabular-nums font-semibold"
                                    :class="e.running > 0 ? 'text-[#ad2c4f]' : 'text-[#006c4f]'"
                                >
                                    {{ formatCurrency(e.running) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Transaksi terkait -->
            <section
                class="overflow-hidden rounded-2xl border border-[#eae8e2] bg-white shadow-sm"
            >
                <div class="border-b border-[#eae8e2] px-4 py-3 md:px-5 md:py-4">
                    <h3 class="text-base font-bold text-[#1b1c19] md:text-lg">Transaksi terkait (terbaru)</h3>
                    <p class="mt-0.5 text-xs text-[#4d4634]/60">Hanya transaksi dengan kategori hutang/piutang</p>
                </div>

                <!-- Mobile: kartu stack -->
                <div class="space-y-3 p-4 md:hidden">
                    <div v-if="!props.summary.recent.length" class="py-8 text-center text-sm text-[#4d4634]/50">
                        Belum ada transaksi.
                    </div>
                    <Link
                        v-for="tx in props.summary.recent"
                        :key="'m-' + tx.id"
                        :href="`/transactions/${tx.id}`"
                        class="block rounded-xl border border-[#eae8e2] bg-white p-4 transition-colors active:bg-[#f5f3ee]"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs text-[#4d4634]/60">{{ formatDate(tx.transaction_date) }}</p>
                                <p class="mt-1 line-clamp-2 text-sm font-medium text-[#1b1c19]">
                                    {{ tx.description || '—' }}
                                </p>
                                <p class="mt-1 truncate text-xs text-[#4d4634]/60">
                                    {{ tx.category_name ?? tx.category_type ?? '—' }}
                                    <span v-if="tx.counterparty"> · {{ tx.counterparty }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span
                                    class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="
                                        tx.type === 'income'
                                            ? 'bg-[#51fac1]/20 text-[#006c4f]'
                                            : 'bg-[#ffc9d0]/40 text-[#ad2c4f]'
                                    "
                                >
                                    <span v-if="tx.type === 'income'" class="material-symbols-outlined text-sm">arrow_downward</span>
                                    <span v-else class="material-symbols-outlined text-sm">arrow_upward</span>
                                    {{ tx.type === 'income' ? 'Masuk' : 'Keluar' }}
                                </span>
                                <p class="text-sm font-bold tabular-nums text-[#1b1c19]">
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
                                class="border-b border-[#eae8e2] bg-[#f5f3ee] text-left text-[#4d4634]/60"
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
                                <td colspan="6" class="px-5 py-8 text-center text-[#4d4634]/50">Belum ada transaksi.</td>
                            </tr>
                            <tr
                                v-for="tx in props.summary.recent"
                                :key="'d-' + tx.id"
                                class="border-b border-[#eae8e2] transition-colors hover:bg-[#f5f3ee]"
                            >
                                <td class="whitespace-nowrap px-5 py-3 text-[#4d4634]/70">
                                    {{ formatDate(tx.transaction_date) }}
                                </td>
                                <td class="max-w-[10rem] truncate px-5 py-3 text-[#1b1c19]" :title="tx.category_name ?? undefined">
                                    {{ tx.category_name ?? tx.category_type ?? '—' }}
                                </td>
                                <td class="max-w-[14rem] min-w-[6rem] break-words px-5 py-3 text-[#4d4634]/70">
                                    {{ tx.counterparty ?? '—' }}
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            tx.type === 'income'
                                                ? 'bg-[#51fac1]/20 text-[#006c4f]'
                                                : 'bg-[#ffc9d0]/40 text-[#ad2c4f]'
                                        "
                                    >
                                        {{ tx.type === 'income' ? 'Masuk' : 'Keluar' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums font-semibold text-[#1b1c19]">
                                    {{ formatCurrency(tx.amount) }}
                                </td>
                                <td class="max-w-xs px-5 py-3">
                                    <Link
                                        :href="`/transactions/${tx.id}`"
                                        class="line-clamp-2 text-[#4d4634]/70 underline decoration-[#eae8e2] underline-offset-2 transition-colors hover:text-[#725a00]"
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
