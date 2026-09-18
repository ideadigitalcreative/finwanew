<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';

interface Wallet {
    account_name: string;
    balance: number;
    currency: string;
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

// Variasi ikon & warna sub-wallet (mengikuti template CuanCeria)
const walletStyles = [
    { icon: 'account_balance', chipClass: 'bg-[#ffe089] text-[#241a00]' },
    { icon: 'send_to_mobile', chipClass: 'bg-[#51fac1] text-[#007152]' },
    { icon: 'monetization_on', chipClass: 'bg-[#ffd23f] text-[#574500]' },
];

const growthPercent = ((props.cashflow / (props.balance || 1)) * 100).toFixed(1);

const financialStatusNote = computed(() => {
    if (props.cashflow > 0) {
        return {
            title: 'Kondisi keuanganmu super prima!',
            body: `Arus kas tercatat surplus ${formatCurrency(props.cashflow)}. Mantap ${props.userName}! ✨`,
            highlightClass: 'text-[#006c4f] dark:text-emerald-400',
        };
    } else if (props.cashflow < 0) {
        return {
            title: 'Arus kasmu sedang defisit',
            body: `Pengeluaran melebihi pemasukan sebesar ${formatCurrency(Math.abs(props.cashflow))}. Yuk, rem jajan sejenak ya ${props.userName}! 💡`,
            highlightClass: 'text-[#93000a] dark:text-rose-400',
        };
    }
    return {
        title: 'Arus kasmu seimbang',
        body: `Pemasukan dan pengeluaran sama besar bulan ini. Tetap pantau pos jajanmu ya ${props.userName}!`,
        highlightClass: 'text-[#745c00] dark:text-amber-400',
    };
});
</script>

<template>
    <!-- Kartu Saldo Utama Playful (Hero Card CuanCeria 1:1) -->
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
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634] dark:text-muted-foreground">Total Saldo Bersih</span>
                        <button
                            @click="toggleBalance"
                            type="button"
                            aria-label="Sembunyikan Saldo"
                            class="p-1 rounded-full hover:bg-[#f0eee8] dark:hover:bg-white/10 transition-colors inline-flex items-center justify-center text-[#4d4634] dark:text-muted-foreground active:scale-95"
                        >
                            <span class="material-symbols-outlined text-[15px]">
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
                <span
                    class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-lg text-[10px] font-extrabold bg-[#f0eee8] dark:bg-white/10"
                    :class="cashflow >= 0 ? 'text-[#006c4f]' : 'text-[#93000a]'"
                >
                    <span class="material-symbols-outlined text-[14px]">{{ cashflow >= 0 ? 'trending_up' : 'trending_down' }}</span>
                    <span>{{ cashflow >= 0 ? '+' : '' }}{{ growthPercent }}% bln ini</span>
                </span>
            </div>

            <!-- Descriptive Dynamic Playful Text -->
            <p class="text-xs text-[#4d4634] dark:text-muted-foreground leading-relaxed max-w-[94%]">
                <span class="font-bold" :class="financialStatusNote.highlightClass">{{ financialStatusNote.title }}</span>
                {{ financialStatusNote.body }}
            </p>

            <!-- Sub-wallets Grid (Quick Sub-balances) -->
            <div v-if="wallets.length > 0" class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <div
                    v-for="(wallet, idx) in wallets.slice(0, 3)"
                    :key="wallet.account_name + idx"
                    class="p-2.5 rounded-xl bg-[#f5f3ee] dark:bg-white/5 hover:bg-[#f0eee8] dark:hover:bg-white/10 transition-all flex flex-col justify-between gap-1.5"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span class="w-6 h-6 rounded-lg flex items-center justify-center" :class="walletStyles[idx % 3].chipClass">
                                <span class="material-symbols-outlined text-[15px]">{{ walletStyles[idx % 3].icon }}</span>
                            </span>
                            <span class="text-[11px] font-bold text-[#1b1c19] dark:text-foreground truncate">{{ wallet.account_name }}</span>
                        </div>
                        <span v-if="idx === 0" class="material-symbols-outlined text-[15px] text-[#7f7661]">verified</span>
                        <span v-else-if="idx === 1" class="text-[9px] px-1.5 py-0.5 rounded-full bg-[#006c4f] text-white font-bold">Aktif</span>
                        <span v-else class="material-symbols-outlined text-[15px] text-[#745c00]">lock</span>
                    </div>
                    <div>
                        <div class="text-base font-extrabold text-[#1b1c19] dark:text-foreground leading-tight">
                            {{ balanceHidden ? '•••••' : formatCurrency(wallet.balance) }}
                        </div>
                        <span class="text-[10px] font-medium text-[#4d4634] dark:text-muted-foreground">Saldo saat ini</span>
                    </div>
                </div>
            </div>

            <!-- Footer Quick Action inside Card -->
            <div class="flex flex-wrap items-center justify-between gap-2.5 pt-1.5 border-t border-[#eae8e2] dark:border-white/10">
                <div class="flex items-center gap-2 text-[11px] font-medium text-[#4d4634] dark:text-muted-foreground">
                    <span class="inline-block w-2 h-2 rounded-full bg-[#006c4f] animate-pulse"></span>
                    Sinkronisasi realtime
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        href="/balances"
                        class="h-9 px-3 rounded-xl bg-[#f0eee8] dark:bg-white/10 text-[#1b1c19] dark:text-foreground text-xs font-bold flex items-center gap-1.5 shadow-sm active:scale-95 transition-transform hover:bg-[#e4e2dd] dark:hover:bg-white/15"
                    >
                        <span class="material-symbols-outlined text-[16px] text-[#006c4f]">sync_alt</span>
                        <span>Transfer / Top Up</span>
                    </Link>
                    <Link
                        href="/balances"
                        class="h-9 px-3 rounded-xl bg-[#ffd23f] text-[#725a00] text-xs font-bold flex items-center gap-1.5 shadow-sm active:scale-95 transition-transform hover:brightness-95"
                    >
                        <span class="material-symbols-outlined text-[16px]">add</span>
                        <span>Tambah Pos</span>
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>