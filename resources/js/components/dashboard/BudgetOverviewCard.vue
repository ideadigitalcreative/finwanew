<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    PiggyBank, Utensils, Home, Car, GraduationCap, ShoppingBag,
    Heart, Shirt, Sparkles, Film,
    Zap, Smartphone, Wallet, Gift,
} from 'lucide-vue-next';

interface BudgetItem {
    id: number;
    category_name: string;
    category_icon: string;
    category_type: string;
    amount: number;
    spending: number;
    remaining: number;
    usage_percent: number;
    is_over: boolean;
    alert_level?: string;
}

interface Props {
    totalBudget?: number;
    totalSpending?: number;
    remaining?: number;
    usagePercentage?: number;
    items?: BudgetItem[];
    period?: string;
}

const props = withDefaults(defineProps<Props>(), {
    totalBudget: 0,
    totalSpending: 0,
    remaining: 0,
    usagePercentage: 0,
    items: () => [],
    period: 'Bulan ini',
});

const hasBudget = computed(() => props.totalBudget > 0);
const hasItems = computed(() => props.items.length > 0);

// Tampilkan max 4 item, sisanya di-collapse
const visibleItems = computed(() => props.items.slice(0, 4));
const hiddenCount = computed(() => Math.max(0, props.items.length - 4));

const formatCurrency = (v: number) => {
    if (v >= 1_000_000) return `Rp ${(v / 1_000_000).toFixed(1)}jt`;
    if (v >= 1_000) return `Rp ${Math.round(v / 1_000)}rb`;
    return `Rp ${v.toLocaleString('id-ID')}`;
};

// Warna progress bar berdasarkan persentase
const barColor = (pct: number, isOver: boolean) => {
    if (isOver) return 'bg-red-500';
    if (pct >= 85) return 'bg-orange-400';
    if (pct >= 60) return 'bg-yellow-400';
    return 'bg-emerald-500';
};

const barBg = (pct: number, isOver: boolean) => {
    if (isOver) return 'bg-red-100 dark:bg-red-900/20';
    if (pct >= 85) return 'bg-orange-100 dark:bg-orange-900/20';
    if (pct >= 60) return 'bg-yellow-100 dark:bg-yellow-900/20';
    return 'bg-emerald-100 dark:bg-emerald-900/20';
};

const statusText = (pct: number, isOver: boolean, alertLevel?: string) => {
    const level = alertLevel || (isOver ? 'critical' : pct >= 85 ? 'warning' : pct >= 60 ? 'info' : 'none');
    if (level === 'critical' || isOver) return { label: 'Melebihi', cls: 'text-red-500 dark:text-red-400' };
    if (level === 'warning' || pct >= 85) return { label: 'Hampir habis', cls: 'text-orange-500 dark:text-orange-400' };
    if (level === 'info' || pct >= 60) return { label: 'Perlu perhatian', cls: 'text-yellow-600 dark:text-yellow-400' };
    return { label: 'Aman', cls: 'text-emerald-600 dark:text-emerald-400' };
};

// Warna ring total usage
const totalRingColor = computed(() => {
    if (props.usagePercentage > 100) return '#ef4444';
    if (props.usagePercentage >= 85) return '#f97316';
    if (props.usagePercentage >= 60) return '#eab308';
    return '#22c55e';
});

// SVG donut untuk total usage
const donutRadius = 28;
const donutCircumference = 2 * Math.PI * donutRadius;
const donutOffset = computed(() => {
    const pct = Math.min(props.usagePercentage, 100) / 100;
    return donutCircumference * (1 - pct);
});

// Map category type ke Lucide icon modern
function getCategoryIcon(type: string) {
    const typeMap: Record<string, any> = {
        // Makanan
        'pengeluaran_makanan': Utensils,
        // Transport
        'pengeluaran_transport': Car,
        // Hunian
        'pengeluaran_hunian': Home,
        // Utilitas
        'pengeluaran_utilitas': Zap,
        // Kesehatan
        'pengeluaran_kesehatan': Heart,
        // Pendidikan
        'pengeluaran_pendidikan': GraduationCap,
        // Belanja
        'pengeluaran_belanja': ShoppingBag,
        // Hiburan
        'pengeluaran_hiburan': Film,
        // Pulsa & Token
        'pengeluaran_pulsa_token': Smartphone,
        // Tagihan
        'pengeluaran_tagihan': Wallet,
        // Investasi
        'pengeluaran_investasi': PiggyBank,
        // Pinjaman / Hutang
        'pengeluaran_pinjaman': Wallet,
        'pengeluaran_bayar_hutang': Wallet,
        'pengeluaran_piutang': Wallet,
        'pengeluaran_cicilan': Wallet,
        // Asuransi
        'pengeluaran_asuransi': Heart,
        // Pajak
        'pengeluaran_pajak': Wallet,
        // Donasi
        'pengeluaran_donasi': Heart,
        // Gaji karyawan
        'pengeluaran_gaji': Wallet,
        // Keluarga
        'pengeluaran_keluarga': Home,
        // Baby
        'pengeluaran_baby': Heart,
        // Langganan
        'pengeluaran_langganan': Smartphone,
        // Pakaian
        'pengeluaran_pakaian': Shirt,
        // Perawatan diri
        'pengeluaran_perawatan_diri': Sparkles,
        // Acara
        'pengeluaran_acara': Gift,
        // Otomotif
        'pengeluaran_otomotif': Car,
        // Sosial
        'pengeluaran_sosial': Heart,
        // Hadiah
        'pengeluaran_hadiah': Gift,
        // Hewan
        'pengeluaran_hewan': Heart,
        // Gadget
        'pengeluaran_gadget': Smartphone,
        // Modal & Stok
        'pengeluaran_modal': ShoppingBag,
        // Operasional
        'pengeluaran_operasional': Zap,
        // Transfer
        'pengeluaran_transfer': Wallet,
        'debit_internal': Wallet,
        // Lainnya
        'pengeluaran_lainnya': Wallet,
        // Pendapatan
        'pendapatan_gaji': Wallet,
        'pendapatan_bonus': Gift,
        'pendapatan_investasi': PiggyBank,
        'pendapatan_transfer': Wallet,
        'pendapatan_usaha': ShoppingBag,
        'pendapatan_sewa': Home,
        'pendapatan_refund': Wallet,
        'pendapatan_hutang': Wallet,
        'pendapatan_terima_piutang': Wallet,
        'pendapatan_lainnya': Wallet,
        'kredit_internal': Wallet,
    };
    return typeMap[type] ?? PiggyBank;
}
</script>

<template>
    <div class="p-4 md:p-5 rounded-2xl bg-card border border-border/60 flex flex-col gap-3.5">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5">
                <span class="text-sm md:text-base font-bold text-foreground">Porsi Pengeluaran</span>
                <span class="text-[11px] text-muted-foreground">({{ period }})</span>
            </div>
            <Link
                href="/budgets"
                class="text-xs font-bold text-primary hover:underline flex items-center gap-0.5"
            >
                <span>Atur Batas</span>
                <span class="material-symbols-outlined text-[14px]">settings</span>
            </Link>
        </div>

        <!-- Jika belum ada budget -->
        <template v-if="!hasBudget">
            <div class="flex flex-col items-center justify-center py-6 text-center gap-3">
                <div class="w-12 h-12 rounded-full bg-muted flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl text-muted-foreground">pie_chart</span>
                </div>
                <div>
                    <p class="text-xs font-semibold text-foreground">Belum ada anggaran</p>
                    <p class="text-[10px] text-muted-foreground mt-0.5">Buat batas pos belanja untuk mengontrol keuangan</p>
                </div>
                <Link
                    href="/budgets"
                    class="px-4 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold shadow-sm"
                >
                    + Buat Anggaran
                </Link>
            </div>
        </template>

        <!-- Jika ada budget -->
        <template v-else>
            <!-- Multicolored Single Progress Track -->
            <div class="w-full h-3.5 bg-muted rounded-full overflow-hidden flex shadow-inner">
                <div
                    v-for="(item, idx) in visibleItems"
                    :key="item.id"
                    class="h-full transition-all duration-500"
                    :class="[
                        idx === 0 ? 'bg-amber-400 dark:bg-amber-500' :
                        idx === 1 ? 'bg-rose-400 dark:bg-rose-500' :
                        idx === 2 ? 'bg-emerald-400 dark:bg-emerald-500' : 'bg-blue-400 dark:bg-blue-500'
                    ]"
                    :style="{ width: `${Math.min(item.usage_percent, 100) / (visibleItems.length || 1)}%` }"
                    :title="`${item.category_name} (${Math.round(item.usage_percent)}%)`"
                ></div>
            </div>

            <!-- Category Items List -->
            <div class="flex flex-col gap-2.5 pt-1">
                <div
                    v-for="(item, idx) in visibleItems"
                    :key="item.id"
                    class="flex items-center justify-between"
                >
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div
                            class="w-3 h-3 rounded-full flex-shrink-0"
                            :class="[
                                idx === 0 ? 'bg-amber-400' :
                                idx === 1 ? 'bg-rose-400' :
                                idx === 2 ? 'bg-emerald-400' : 'bg-blue-400'
                            ]"
                        ></div>
                        <span class="text-xs font-semibold text-foreground truncate">{{ item.category_name }}</span>
                    </div>
                    <div class="flex items-baseline gap-2 flex-shrink-0">
                        <span class="text-xs font-bold text-foreground">{{ formatCurrency(item.spending) }}</span>
                        <span class="text-[10px] text-muted-foreground w-8 text-right">{{ Math.round(item.usage_percent) }}%</span>
                    </div>
                </div>

                <Link
                    v-if="hiddenCount > 0"
                    href="/budgets"
                    class="text-center text-xs text-primary font-bold py-1 hover:underline"
                >
                    + {{ hiddenCount }} kategori lainnya →
                </Link>
            </div>
        </template>
    </div>
</template>
