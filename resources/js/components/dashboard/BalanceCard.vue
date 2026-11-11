<script setup lang="ts">
import { ref, computed } from 'vue';

interface Wallet {
    account_name: string;
    balance: number;
    currency: string;
    updated_at?: string | null;
}

interface Props {
    balance: number;
    lastIncome: number;
    lastIncomeDate?: string | null;
    cashflow: number;
    bonus: number;
    period?: string;
    userName?: string;
    wallets?: Wallet[];
}

const props = withDefaults(defineProps<Props>(), {
    userName: 'Kawan',
    period: 'Bulan ini',
    wallets: () => [],
});

const emit = defineEmits<{
    (e: 'transfer'): void;
    (e: 'add-wallet'): void;
}>();

const balanceHidden = ref(localStorage.getItem('finwa_hide_balance') === 'true');
const toggleBalance = () => {
    balanceHidden.value = !balanceHidden.value;
    localStorage.setItem('finwa_hide_balance', balanceHidden.value ? 'true' : 'false');
    window.dispatchEvent(new CustomEvent('balance-visibility-changed', { detail: balanceHidden.value }));
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
};

const formatCompact = (amount: number) => {
    return new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(amount);
};

// Waktu pembaruan saldo terakhir (dari data rekening, bukan teks statis)
const lastUpdatedLabel = computed(() => {
    const times = props.wallets
        .map(w => (w.updated_at ? new Date(w.updated_at).getTime() : NaN))
        .filter(t => !isNaN(t));
    if (times.length === 0) return null;
    return new Date(Math.max(...times)).toLocaleString('id-ID', {
        day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
    });
});

const financialStatusNote = computed(() => {
    if (props.cashflow > 0) {
        return {
            title: 'Surplus bulan ini.',
            body: `Pemasukan melebihi pengeluaran sebesar ${formatCurrency(props.cashflow)}.`,
            highlightClass: 'text-[#006c4f] dark:text-emerald-400',
        };
    } else if (props.cashflow < 0) {
        return {
            title: 'Defisit bulan ini.',
            body: `Pengeluaran melebihi pemasukan sebesar ${formatCurrency(Math.abs(props.cashflow))}.`,
            highlightClass: 'text-[#93000a] dark:text-rose-400',
        };
    }
    return {
        title: 'Arus kas seimbang.',
        body: 'Pemasukan dan pengeluaran bulan ini sama besar.',
        highlightClass: 'text-[#745c00] dark:text-amber-400',
    };
});
</script>

<template>
    <!-- Kartu Saldo Utama -->
    <section class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 flex flex-col justify-between font-['Plus_Jakarta_Sans',sans-serif] transition-all duration-300">

        <!-- Decorative Backdrop Glow -->
        <div class="absolute -right-12 -top-12 w-48 h-48 rounded-full bg-[#ffd23f]/30 blur-2xl pointer-events-none"></div>
        <div class="absolute -left-10 -bottom-10 w-40 h-40 rounded-full bg-[#51fac1]/40 blur-2xl pointer-events-none"></div>

        <div class="relative flex flex-col gap-3.5">
            <!-- Top Label & Status Pill -->
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-[#ffd23f] text-[#574500]">
                        <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
                    </span>
                    <div class="flex items-center gap-1">
                        <span class="text-xs font-bold uppercase tracking-wider text-[#4d4634] dark:text-muted-foreground">Total Saldo Bersih</span>
                        <button
                            @click="toggleBalance"
                            type="button"
                            :aria-label="balanceHidden ? 'Tampilkan saldo' : 'Sembunyikan saldo'"
                            :aria-pressed="balanceHidden"
                            class="min-w-10 min-h-10 -my-2 rounded-full hover:bg-[#f0eee8] dark:hover:bg-white/10 transition-colors inline-flex items-center justify-center text-[#4d4634] dark:text-muted-foreground active:scale-95"
                        >
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">
                                {{ balanceHidden ? 'visibility_off' : 'visibility' }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Amount Display & Growth Pill -->
            <div class="flex flex-wrap items-baseline gap-2.5">
                <span
                    class="text-3xl md:text-[40px] font-extrabold tracking-tight text-[#1b1c19] dark:text-foreground leading-none transition-opacity duration-150"
                    :class="{ 'opacity-80': balanceHidden }"
                >
                    {{ balanceHidden ? '••••••••••' : formatCurrency(balance) }}
                </span>
                <!-- Arus kas bersih bulan ini (pemasukan − pengeluaran), dalam rupiah -->
                <span
                    v-if="!balanceHidden"
                    class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-lg text-xs font-extrabold bg-[#f0eee8] dark:bg-white/10"
                    :class="cashflow >= 0 ? 'text-[#006c4f] dark:text-emerald-400' : 'text-[#93000a] dark:text-rose-400'"
                    :title="`Arus kas bersih ${period}: ${formatCurrency(cashflow)}`"
                >
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ cashflow >= 0 ? 'trending_up' : 'trending_down' }}</span>
                    <span>{{ cashflow > 0 ? '+' : cashflow < 0 ? '−' : '' }}Rp {{ formatCompact(Math.abs(cashflow)) }} arus kas bln ini</span>
                </span>
            </div>

            <!-- Ringkasan status arus kas -->
            <p class="text-xs text-[#4d4634] dark:text-muted-foreground leading-relaxed max-w-[94%]">
                <span class="font-bold" :class="financialStatusNote.highlightClass">{{ financialStatusNote.title }}</span>
                {{ financialStatusNote.body }}
            </p>

            <!-- Saldo per rekening ditampilkan di WalletCards (Kantong Aktif) agar tidak dobel -->

            <!-- Footer Quick Action inside Card -->
            <div class="flex flex-wrap items-center justify-between gap-2.5 pt-1.5 border-t border-[#eae8e2] dark:border-white/10">
                <div class="flex items-center gap-1.5 text-xs font-medium text-[#4d4634] dark:text-muted-foreground">
                    <template v-if="lastUpdatedLabel">
                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>
                        Saldo diperbarui {{ lastUpdatedLabel }}
                    </template>
                    <template v-else>{{ wallets.length }} rekening aktif</template>
                </div>
            </div>
        </div>
    </section>
</template>