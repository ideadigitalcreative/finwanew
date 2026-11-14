<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { computed, ref } from 'vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CurrencyInput from '@/components/CurrencyInput.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type BreadcrumbItem } from '@/types';

interface SavingsGoal {
    id: number;
    name: string;
    target_amount: number;
    current_amount: number;
    deadline: string | null;
    status: 'active' | 'completed' | 'cancelled';
    icon: string;
    progress_percentage: number;
    remaining_amount: number;
    days_remaining: number | null;
    suggested_monthly: number | null;
    is_completed: boolean;
}

interface SavingsTx {
    id: number;
    goal_id: number;
    goal_name: string;
    type: 'deposit' | 'withdrawal';
    amount: number;
    note: string | null;
    transaction_date: string | null;
}

interface Props {
    tenant_id: number;
    goals: SavingsGoal[];
    recentTransactions: SavingsTx[];
}

const props = defineProps<Props>();
const { showError, showSuccess, showDeleteConfirm } = useSweetAlert();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Tabungan',
        href: '/tabungan',
    },
];

// ---------- Helpers ----------
const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatShortDate = (date: string | null) => {
    if (!date) return '-';
    return format(new Date(date), 'dd MMM yyyy', { locale: id });
};

const formatMonthYear = (date: string | null) => {
    if (!date) return 'Tanpa tenggat';
    return format(new Date(date), 'MMMM yyyy', { locale: id });
};

const getGoalIcon = (icon: string) => {
    const map: Record<string, string> = {
        '🎯': 'center_focus_strong',
        '🌴': 'beach_access',
        '💻': 'laptop_mac',
        '🛡️': 'verified_user',
        '🎤': 'mic_external_on',
        '🚀': 'rocket_launch',
        '🧳': 'luggage',
        '🏠': 'home',
        '💍': 'diamond',
        '📱': 'smartphone',
        '✈️': 'flight',
        '🏝️': 'beach_access',
        '☕': 'coffee',
        '🎉': 'celebration',
        '🎪': 'festival',
        '🖥️': 'desktop_windows',
        '🚗': 'directions_car',
        '🎸': 'music_note',
        '🎓': 'school',
        '👶': 'child_care',
        '🏦': 'account_balance',
        '⛱️': 'beach_access',
    };
    return map[icon] || 'savings';
};

const getBadgeStyle = (goal: SavingsGoal) => {
    if (goal.is_completed) return 'bg-[#51fac1] text-[#007152]';
    if (goal.progress_percentage >= 75) return 'bg-[#ffe089] text-[#574500]';
    if (goal.progress_percentage >= 40) return 'bg-[#ffd23f]/30 text-[#725a00]';
    return 'bg-[#eae8e2] text-[#4d4634]';
};

const getIconBoxStyle = (goal: SavingsGoal) => {
    if (goal.is_completed) return 'bg-[#51fac1] text-[#007152]';
    if (goal.progress_percentage >= 75) return 'bg-[#ffe089] text-[#574500]';
    if (goal.status === 'active') return 'bg-[#ffd23f]/25 text-[#725a00]';
    return 'bg-[#ffc9d0] text-[#ad2c4f]';
};

const getProgressGradient = (goal: SavingsGoal) => {
    if (goal.is_completed) return 'bg-[#51fac1]';
    return 'bg-gradient-to-r from-[#ffd23f] via-[#ffe089] to-[#51fac1]';
};

const estimateDone = (goal: SavingsGoal) => {
    if (goal.is_completed) return 'Target tercapai';
    if (goal.deadline) return formatMonthYear(goal.deadline);
    if (goal.suggested_monthly) {
        const months = Math.ceil(goal.remaining_amount / goal.suggested_monthly);
        return months > 1 ? `± ${months} bulan lagi` : '± 1 bulan lagi';
    }
    return 'Terus menabung';
};

const getDaysLabel = (goal: SavingsGoal) => {
    if (!goal.deadline) return null;
    if (goal.days_remaining === null || goal.days_remaining === undefined) return null;
    if (goal.days_remaining <= 0) return 'Tenggat hari ini';
    if (goal.days_remaining === 1) return 'Sisa 1 hari';
    if (goal.days_remaining < 30) return `Sisa ${goal.days_remaining} hari`;
    const months = Math.floor(goal.days_remaining / 30);
    if (months === 1) return 'Sisa 1 bulan';
    return `Sisa ${months} bulan`;
};

// ---------- Filter & Sorting ----------
type TabKey = 'all' | 'active' | 'completed';
const activeTab = ref<TabKey>('all');
const sortKey = ref<'progress' | 'target' | 'name' | 'deadline'>('progress');

const filteredGoals = computed(() => {
    let list = props.goals.filter((g) => {
        if (activeTab.value === 'active') return g.status === 'active' && !g.is_completed;
        if (activeTab.value === 'completed') return g.is_completed;
        return true;
    });

    list = [...list].sort((a, b) => {
        switch (sortKey.value) {
            case 'target':
                return b.target_amount - a.target_amount;
            case 'name':
                return a.name.localeCompare(b.name);
            case 'deadline':
                return (a.deadline || '9999').localeCompare(b.deadline || '9999');
            case 'progress':
            default:
                return b.progress_percentage - a.progress_percentage;
        }
    });

    return list;
});

const countAll = computed(() => props.goals.length);
const countActive = computed(() => props.goals.filter((g) => g.status === 'active' && !g.is_completed).length);
const countCompleted = computed(() => props.goals.filter((g) => g.is_completed).length);

const totalCollected = computed(() => props.goals.reduce((sum, g) => sum + g.current_amount, 0));
const totalTarget = computed(() => props.goals.reduce((sum, g) => sum + g.target_amount, 0));
const overallProgress = computed(() => {
    if (totalTarget.value <= 0) return 0;
    return Math.min(100, (totalCollected.value / totalTarget.value) * 100);
});

const overallRemaining = computed(() => Math.max(0, totalTarget.value - totalCollected.value));

// ---------- Create Goal Dialog ----------
const isCreateOpen = ref(false);
const goalForm = useForm({
    tenant_id: props.tenant_id,
    name: '',
    target_amount: 0,
    current_amount: 0,
    deadline: '',
});

const openCreate = () => {
    goalForm.reset();
    goalForm.clearErrors();
    goalForm.tenant_id = props.tenant_id;
    goalForm.deadline = '';
    isCreateOpen.value = true;
};

const submitCreate = () => {
    goalForm.post('/tabungan', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Celengan impian berhasil dibuat');
            isCreateOpen.value = false;
        },
        onError: () => {
            showError('Error', 'Gagal membuat celengan');
        },
    });
};

// ---------- Deposit (Nabung Cepat) Dialog ----------
const isDepositOpen = ref(false);
const depositGoal = ref<SavingsGoal | null>(null);
const depositForm = useForm({
    tenant_id: props.tenant_id,
    amount: 0,
    note: '',
});

const openDeposit = (goal: SavingsGoal) => {
    depositGoal.value = goal;
    depositForm.reset();
    depositForm.clearErrors();
    depositForm.tenant_id = props.tenant_id;
    isDepositOpen.value = true;
};

const submitDeposit = () => {
    if (!depositGoal.value) return;
    depositForm.post(`/tabungan/${depositGoal.value.id}/add-savings`, {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Tabungan berhasil ditambahkan');
            isDepositOpen.value = false;
        },
        onError: () => {
            showError('Error', 'Gagal menambahkan tabungan');
        },
    });
};

// ---------- Withdraw (Klaim) Dialog ----------
const isWithdrawOpen = ref(false);
const withdrawGoal = ref<SavingsGoal | null>(null);
const withdrawForm = useForm({
    tenant_id: props.tenant_id,
    amount: 0,
    note: '',
});

const openWithdraw = (goal: SavingsGoal) => {
    withdrawGoal.value = goal;
    withdrawForm.reset();
    withdrawForm.clearErrors();
    withdrawForm.tenant_id = props.tenant_id;
    withdrawForm.amount = goal.current_amount;
    isWithdrawOpen.value = true;
};

const submitWithdraw = () => {
    if (!withdrawGoal.value) return;
    withdrawForm.post(`/tabungan/${withdrawGoal.value.id}/withdraw`, {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Saldo celengan berhasil ditarik');
            isWithdrawOpen.value = false;
        },
        onError: () => {
            showError('Error', 'Gagal menarik saldo celengan');
        },
    });
};

// ---------- Delete Goal ----------
const deleteGoal = (goal: SavingsGoal) => {
    showDeleteConfirm(
        'Hapus celengan?',
        `Celengan "${goal.name}" akan dihapus dari daftar dan tidak bisa dikembalikan.`
    ).then((confirmed) => {
        if (confirmed) {
            router.delete(`/tabungan/${goal.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    showSuccess('Berhasil', 'Celengan berhasil dihapus');
                },
                onError: () => {
                    showError('Error', 'Gagal menghapus celengan');
                },
            });
        }
    });
};

// ---------- Simulator ----------
const simGoalId = ref<number | null>(props.goals[0]?.id ?? null);
const simDaily = ref(50000);

const simGoal = computed(() => {
    return props.goals.find((g) => g.id === simGoalId.value) ?? null;
});

const simRemaining = computed(() => simGoal.value?.remaining_amount ?? 0);
const simDays = computed(() => {
    if (!simGoal.value || simRemaining.value <= 0) return 0;
    if (simDaily.value <= 0) return 0;
    return Math.ceil(simRemaining.value / simDaily.value);
});

const simEstimatedDate = computed(() => {
    if (simDays.value <= 0) return null;
    const d = new Date();
    d.setDate(d.getDate() + simDays.value);
    return format(d, 'dd MMM yyyy', { locale: id });
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tabungan" />

        <div class="bg-white dark:bg-[#23231f] flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-y-auto p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ffd23f] text-2xl md:text-3xl">savings</span>
                        Tabungan
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1">
                        Celengan impian Anda untuk liburan, gadget, dan masa depan
                    </p>
                </div>
                <button
                    @click="openCreate"
                    class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-all w-full md:w-auto"
                >
                    <span class="material-symbols-outlined text-lg">add</span>
                    Buat Celengan Baru
                </button>
            </div>

            <!-- Top Banner: Ringkasan Progress -->
            <section class="grid grid-cols-1 lg:grid-cols-12 gap-4 lg:gap-5 items-stretch">
                <!-- Main Progress Summary Card -->
                <div class="lg:col-span-8 relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 md:p-6 border border-[#eae8e2] dark:border-white/10 flex flex-col justify-between">
                    <div class="absolute -right-12 -top-12 w-48 h-48 rounded-full bg-[#ffd23f]/30 blur-2xl pointer-events-none"></div>
                    <div class="absolute left-8 top-8 opacity-[0.06] pointer-events-none">
                        <span class="material-symbols-outlined text-[120px] text-[#745c00]">savings</span>
                    </div>

                    <div class="relative z-10 flex flex-col gap-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-[#51fac1] text-[#007152] text-[10px] font-extrabold uppercase tracking-wider rounded-full">
                                <span class="material-symbols-outlined text-[14px]">bolt</span>
                                Super Konsisten
                            </div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/60 dark:text-white/40">Progress seluruh celengan</span>
                        </div>

                        <div class="flex flex-col md:flex-row md:items-baseline justify-between gap-2">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/70 dark:text-white/50">Total Terkumpul di Seluruh Celengan</span>
                                <div class="flex flex-wrap items-baseline gap-2 mt-1">
                                    <span class="text-3xl md:text-[38px] font-extrabold tracking-tight text-[#1b1c19] dark:text-white leading-none">
                                        {{ formatCurrency(totalCollected) }}
                                    </span>
                                    <span class="text-sm md:text-base text-[#4d4634]/60 dark:text-white/50 font-semibold">
                                        / {{ formatCurrency(totalTarget) }}
                                    </span>
                                </div>
                            </div>
                            <div class="self-start md:self-auto px-3 py-1.5 bg-[#ffd23f] text-[#574500] rounded-xl flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[20px]">trending_up</span>
                                <span class="text-lg font-black">{{ overallProgress.toFixed(1) }}%</span>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <div class="w-full h-5 bg-[#eae8e2] dark:bg-white/10 rounded-full overflow-hidden p-0.5">
                                <div
                                    class="h-full bg-gradient-to-r from-[#ffd23f] via-[#ffe089] to-[#51fac1] rounded-full transition-all duration-700 relative"
                                    :style="{ width: overallProgress + '%' }"
                                >
                                    <div class="absolute inset-0 opacity-20 bg-[radial-gradient(#ffffff_2px,transparent_2px)] [background-size:8px_8px]"></div>
                                </div>
                            </div>
                            <div class="flex justify-between items-center text-xs text-[#4d4634]/70 dark:text-white/50">
                                <span>Rp 0</span>
                                <span v-if="overallRemaining > 0" class="font-bold text-[#006c4f] dark:text-[#51fac1]">
                                    Tersisa {{ formatCurrency(overallRemaining) }} untuk 100%
                                </span>
                                <span v-else class="font-bold text-[#006c4f] dark:text-[#51fac1]">
                                    Semua target tercapai
                                </span>
                                <span>Target {{ formatCurrency(totalTarget) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="relative z-10 mt-4 pt-3 bg-[#f5f3ee] dark:bg-white/5 rounded-xl px-3 py-2.5 flex items-center gap-3 border border-[#eae8e2] dark:border-white/10">
                        <div class="w-10 h-10 rounded-full bg-[#51fac1]/30 flex items-center justify-center text-[#007152] dark:text-[#51fac1] flex-shrink-0">
                            <span class="material-symbols-outlined text-[20px]">rocket_launch</span>
                        </div>
                        <p class="text-sm text-[#1b1c19] dark:text-white font-semibold">
                            Konsisten menabung sedikit demi sedikit, impian besar akan tercapai.
                        </p>
                    </div>
                </div>

                <!-- Right: Quick Action Card -->
                <div class="lg:col-span-4 bg-[#ffd23f] rounded-2xl p-5 md:p-6 flex flex-col justify-between relative overflow-hidden border border-[#eae8e2] dark:border-white/10">
                    <div class="relative z-10 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 bg-white/80 text-[#574500] rounded-full">Celengan Impian</span>
                            <span class="material-symbols-outlined text-[26px] text-[#574500]">stars</span>
                        </div>
                        <h3 class="text-lg md:text-xl font-extrabold leading-tight text-[#574500]">
                            Punya Impian Baru yang Mau Diwujudkan?
                        </h3>
                        <p class="text-xs text-[#574500]/80 leading-relaxed">
                            Mulai pisahkan tabungan liburan, gadget, atau kebutuhan lain tanpa tercampur saldo sehari-hari.
                        </p>
                    </div>
                    <div class="relative z-10 pt-4 flex flex-col gap-1.5">
                        <button
                            @click="openCreate"
                            class="w-full py-2.5 px-4 bg-white text-[#574500] font-bold text-sm rounded-xl hover:bg-[#f5f3ee] transition-all flex items-center justify-center gap-1.5"
                        >
                            <span class="material-symbols-outlined text-[20px] text-[#745c00]">add_circle</span>
                            Buat Celengan Baru
                        </button>
                        <span class="text-center text-[10px] font-bold text-[#574500]/70">Gratis biaya admin selamanya</span>
                    </div>
                    <div class="absolute -right-6 -bottom-6 w-36 h-36 bg-white/20 rounded-full pointer-events-none"></div>
                </div>
            </section>

            <!-- Filter & Sort Strip -->
            <section class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2 overflow-x-auto pb-1">
                    <button
                        @click="activeTab = 'all'"
                        class="px-3.5 py-1.5 rounded-full text-xs font-bold flex items-center gap-1.5 transition-all"
                        :class="activeTab === 'all' ? 'bg-[#1b1c19] text-[#f5f3ee] dark:bg-[#ffe089] dark:text-[#241a00]' : 'bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634] dark:text-white/60 border border-[#eae8e2] dark:border-white/10 hover:bg-[#eae8e2]'"
                    >
                        Semua Celengan ({{ countAll }})
                    </button>
                    <button
                        @click="activeTab = 'active'"
                        class="px-3.5 py-1.5 rounded-full text-xs font-bold flex items-center gap-1.5 transition-all"
                        :class="activeTab === 'active' ? 'bg-[#1b1c19] text-[#f5f3ee] dark:bg-[#51fac1] dark:text-[#002116]' : 'bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634] dark:text-white/60 border border-[#eae8e2] dark:border-white/10 hover:bg-[#eae8e2]'"
                    >
                        <span class="w-2 h-2 rounded-full bg-[#006c4f] dark:bg-[#51fac1]"></span>
                        Sedang Berjalan ({{ countActive }})
                    </button>
                    <button
                        @click="activeTab = 'completed'"
                        class="px-3.5 py-1.5 rounded-full text-xs font-bold flex items-center gap-1.5 transition-all"
                        :class="activeTab === 'completed' ? 'bg-[#1b1c19] text-[#f5f3ee] dark:bg-[#ffd23f] dark:text-[#241a00]' : 'bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634] dark:text-white/60 border border-[#eae8e2] dark:border-white/10 hover:bg-[#eae8e2]'"
                    >
                        <span class="w-2 h-2 rounded-full bg-[#745c00] dark:bg-[#ffd23f]"></span>
                        Tercapai ({{ countCompleted }})
                    </button>
                </div>

                <div class="flex items-center gap-2 text-xs text-[#4d4634]/70 dark:text-white/50 font-semibold">
                    <span>Urutkan:</span>
                    <div class="relative">
                        <select
                            v-model="sortKey"
                            class="appearance-none bg-[#f5f3ee] dark:bg-white/5 border border-[#eae8e2] dark:border-white/10 text-[#1b1c19] dark:text-white/80 rounded-lg py-1.5 pl-3 pr-8 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-[#ffd23f] cursor-pointer"
                        >
                            <option value="progress">Persentase Tertinggi</option>
                            <option value="target">Target Terbesar</option>
                            <option value="deadline">Tenggat Terdekat</option>
                            <option value="name">Nama A-Z</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-2 top-1/2 -translate-y-1/2 text-[14px] text-[#4d4634]/60 pointer-events-none">expand_more</span>
                    </div>
                </div>
            </section>

            <!-- Empty State -->
            <section v-if="filteredGoals.length === 0" class="rounded-2xl border-2 border-dashed border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee]/50 dark:bg-white/5 p-10 flex flex-col items-center justify-center text-center gap-3">
                <div class="w-16 h-16 rounded-2xl bg-[#ffd23f]/20 flex items-center justify-center">
                    <span class="material-symbols-outlined text-3xl text-[#745c00]">savings</span>
                </div>
                <h3 class="text-base font-bold text-[#1b1c19] dark:text-white">Belum ada celengan</h3>
                <p class="text-sm text-[#4d4634]/60 dark:text-white/50 max-w-sm">
                    Buat celengan pertama Anda untuk menabung secara terpisah demi impian Anda.
                </p>
                <button
                    @click="openCreate"
                    class="mt-2 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-all"
                >
                    <span class="material-symbols-outlined text-lg">add</span>
                    Buat Celengan Baru
                </button>
            </section>

            <!-- Savings Cards Grid -->
            <section v-if="filteredGoals.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4 lg:gap-5">
                <div
                    v-for="goal in filteredGoals"
                    :key="goal.id"
                    class="rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 flex flex-col justify-between relative overflow-hidden transition-all hover:border-[#ffd23f]/60"
                >
                    <!-- Celebration Ribbon -->
                    <div
                        v-if="goal.is_completed"
                        class="absolute top-0 right-0 bg-[#51fac1] px-3 py-1 rounded-bl-xl text-[#002116] text-[10px] font-extrabold uppercase tracking-wider flex items-center gap-1"
                    >
                        <span class="material-symbols-outlined text-[14px]">celebration</span>
                        Target Selesai
                    </div>

                    <div class="flex flex-col gap-4">
                        <!-- Header & Icon -->
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div :class="['w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0', getIconBoxStyle(goal)]">
                                    <span class="material-symbols-outlined text-2xl">{{ getGoalIcon(goal.icon) }}</span>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span :class="['px-2 py-0.5 text-[10px] font-bold rounded-full', getBadgeStyle(goal)]">
                                            {{ goal.is_completed ? 'Tercapai' : goal.progress_percentage >= 75 ? 'Hampir Selesai' : goal.progress_percentage >= 40 ? 'Di Jalur Tepat' : 'Baru Dimulai' }}
                                        </span>
                                        <span v-if="goal.days_remaining !== null" class="text-[10px] font-bold text-[#006c4f] dark:text-[#51fac1] flex items-center gap-0.5">
                                            <span class="material-symbols-outlined text-[13px]">schedule</span>
                                            {{ getDaysLabel(goal) }}
                                        </span>
                                    </div>
                                    <h3 class="font-bold text-[#1b1c19] dark:text-white mt-0.5 truncate">{{ goal.name }}</h3>
                                </div>
                            </div>
                            <button
                                @click="deleteGoal(goal)"
                                class="p-1 rounded-lg hover:bg-[#f5f3ee] dark:hover:bg-white/10 text-[#4d4634]/40 dark:text-white/40 hover:text-[#ad2c4f] transition-colors"
                                title="Hapus celengan"
                            >
                                <span class="material-symbols-outlined text-[18px]">more_vert</span>
                            </button>
                        </div>

                        <!-- Target Numbers & Progress -->
                        <div class="bg-[#f5f3ee] dark:bg-white/5 rounded-xl px-4 py-3 flex flex-col gap-1.5 border border-[#eae8e2] dark:border-white/10">
                            <div class="flex items-baseline justify-between">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/70 dark:text-white/50">Terkumpul</span>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/70 dark:text-white/50">Target Akhir</span>
                            </div>
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-xl font-extrabold text-[#1b1c19] dark:text-white">{{ formatCurrency(goal.current_amount) }}</span>
                                <span class="text-sm font-semibold text-[#4d4634]/60 dark:text-white/50">{{ formatCurrency(goal.target_amount) }}</span>
                            </div>
                            <div class="w-full h-4 bg-[#eae8e2] dark:bg-white/10 rounded-full overflow-hidden mt-1 p-0.5">
                                <div
                                    :class="['h-full rounded-full relative transition-all duration-700', getProgressGradient(goal)]"
                                    :style="{ width: goal.progress_percentage + '%' }"
                                >
                                    <div class="absolute inset-0 bg-white/20 [background-image:linear-gradient(45deg,rgba(255,255,255,.25)_25%,transparent_25%,transparent_50%,rgba(255,255,255,.25)_50%,rgba(255,255,255,.25)_75%,transparent_75%,transparent)] [background-size:12px_12px]"></div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-xs pt-0.5">
                                <span class="font-bold flex items-center gap-1" :class="goal.is_completed ? 'text-[#006c4f] dark:text-[#51fac1]' : 'text-[#725a00] dark:text-[#ffe089]'">
                                    <span class="material-symbols-outlined text-[14px]">{{ goal.is_completed ? 'verified' : 'check_circle' }}</span>
                                    {{ Math.round(goal.progress_percentage) }}% Tercapai
                                </span>
                                <span v-if="goal.remaining_amount > 0" class="text-[#4d4634]/70 dark:text-white/50">
                                    Sisa <strong class="text-[#1b1c19] dark:text-white">{{ formatCurrency(goal.remaining_amount) }}</strong> lagi
                                </span>
                                <span v-else class="font-bold text-[#006c4f] dark:text-[#51fac1]">Target Terpenuhi</span>
                            </div>
                        </div>

                        <!-- Forecast -->
                        <div class="flex items-center gap-2 text-xs text-[#4d4634]/70 dark:text-white/50">
                            <span class="material-symbols-outlined text-[16px] text-[#745c00] dark:text-[#ffe089]">event_available</span>
                            <span>Estimasi selesai: <strong class="text-[#1b1c19] dark:text-white">{{ estimateDone(goal) }}</strong></span>
                        </div>
                    </div>

                    <!-- Celebration Message for completed -->
                    <div v-if="goal.is_completed" class="mt-4 px-3 py-2.5 bg-[#ffe089]/60 dark:bg-[#ffe089]/10 rounded-lg flex items-center gap-2">
                        <span class="material-symbols-outlined text-[20px] text-[#745c00]">volunteer_activism</span>
                        <p class="text-xs font-semibold text-[#574500] dark:text-[#ffe089]">
                            Selamat! Saldo sudah siap untuk diwujudkan menjadi kenyataan.
                        </p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="mt-5 pt-4 flex items-center gap-2 border-t border-[#eae8e2] dark:border-white/10">
                        <!-- Completed: Claim -->
                        <button
                            v-if="goal.is_completed"
                            @click="openWithdraw(goal)"
                            class="flex-1 py-2 px-3 bg-[#51fac1] text-[#002116] font-bold text-xs rounded-xl hover:bg-[#27e0a9] transition-all flex items-center justify-center gap-1.5"
                        >
                            <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
                            Klaim & Tarik
                        </button>
                        <!-- Active: Nabung Cepat -->
                        <template v-else>
                            <button
                                @click="openDeposit(goal)"
                                class="flex-1 py-2 px-3 bg-[#ffd23f] text-[#574500] font-bold text-xs rounded-xl hover:bg-[#ffe089] transition-all flex items-center justify-center gap-1.5"
                            >
                                <span class="material-symbols-outlined text-[18px]">add</span>
                                Nabung Cepat
                            </button>
                        </template>
                    </div>
                </div>
            </section>

            <!-- Bottom Bento: Simulator & Riwayat -->
            <section class="grid grid-cols-1 lg:grid-cols-12 gap-4 lg:gap-5 items-start">
                <!-- Simulator (7 cols) -->
                <div class="lg:col-span-7 rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 flex flex-col gap-4 relative overflow-hidden">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-[#ffd23f] text-[#574500] flex items-center justify-center">
                                <span class="material-symbols-outlined text-[20px]">calculate</span>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-[#1b1c19] dark:text-white">Simulasi Waktu Celengan</h4>
                                <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Geser dan lihat seberapa cepat impian Anda terwujud</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 bg-[#ffe089] text-[#574500] text-[10px] font-bold rounded-full">Interaktif</span>
                    </div>

                    <!-- Goal selector -->
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-2 bg-[#f5f3ee] dark:bg-white/5 p-3 rounded-xl border border-[#eae8e2] dark:border-white/10">
                        <span class="text-xs font-semibold text-[#1b1c19] dark:text-white">Pilih Celengan Tujuan:</span>
                        <select
                            v-model="simGoalId"
                            class="w-full sm:w-auto px-3 py-1.5 bg-white dark:bg-[#23231f] text-[#1b1c19] dark:text-white/80 border border-[#eae8e2] dark:border-white/10 rounded-lg text-xs font-bold focus:outline-none focus:ring-2 focus:ring-[#ffd23f] cursor-pointer"
                        >
                            <option v-for="g in props.goals.filter(x => !x.is_completed)" :key="g.id" :value="g.id">
                                {{ g.name }} (Sisa {{ formatCurrency(g.remaining_amount) }})
                            </option>
                        </select>
                    </div>

                    <!-- Slider -->
                    <div class="flex flex-col gap-2 pt-1">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-[#4d4634]/70 dark:text-white/50">Jika saya menyisihkan per hari:</span>
                            <span class="text-sm font-black text-[#006c4f] dark:text-[#51fac1] bg-[#51fac1]/20 px-2.5 py-0.5 rounded-lg">
                                {{ formatCurrency(simDaily) }} / hari
                            </span>
                        </div>
                        <input
                            v-model.number="simDaily"
                            type="range"
                            min="10000"
                            max="150000"
                            step="5000"
                            class="w-full h-3 bg-[#eae8e2] dark:bg-white/10 rounded-lg appearance-none cursor-pointer accent-[#745c00]"
                        />
                        <div class="flex justify-between text-[10px] font-bold text-[#4d4634]/60 dark:text-white/40">
                            <span>Rp 10.000</span>
                            <span>Rp 75.000</span>
                            <span>Rp 150.000</span>
                        </div>
                    </div>

                    <!-- Result -->
                    <div class="bg-[#f5f3ee] dark:bg-white/5 p-4 rounded-xl flex items-center justify-between gap-3 border border-[#eae8e2] dark:border-white/10">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-[#ffd23f] text-[#574500] flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-[22px]">bolt</span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/60 dark:text-white/50">Hasil Prediksi</span>
                                <h5 v-if="simGoal" class="text-sm font-extrabold text-[#1b1c19] dark:text-white mt-0.5">
                                    <span v-if="simRemaining <= 0">Target sudah tercapai</span>
                                    <template v-else>
                                        {{ simGoal.name }} selesai dalam
                                        <span class="text-[#006c4f] dark:text-[#51fac1] underline decoration-2">{{ simDays }} hari</span>
                                        <span v-if="simEstimatedDate" class="block text-xs font-semibold text-[#4d4634]/60 dark:text-white/50 mt-0.5">
                                            Estimasi selesai {{ simEstimatedDate }}
                                        </span>
                                    </template>
                                </h5>
                                <h5 v-else class="text-sm font-extrabold text-[#1b1c19] dark:text-white mt-0.5">
                                    Buat celengan aktif terlebih dahulu
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Riwayat Setoran (5 cols) -->
                <div class="lg:col-span-5 rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-[#51fac1]/30 text-[#007152] dark:text-[#51fac1] flex items-center justify-center">
                                <span class="material-symbols-outlined text-[20px]">history_edu</span>
                            </div>
                            <h4 class="font-bold text-sm text-[#1b1c19] dark:text-white">Riwayat Setoran</h4>
                        </div>
                    </div>

                    <div v-if="props.recentTransactions.length === 0" class="py-8 flex flex-col items-center gap-2 text-center">
                        <span class="material-symbols-outlined text-3xl text-[#4d4634]/30 dark:text-white/30">history</span>
                        <p class="text-xs text-[#4d4634]/60 dark:text-white/50">Belum ada setoran tercatat</p>
                    </div>

                    <div v-else class="flex flex-col gap-2 max-h-[360px] overflow-y-auto pr-1">
                        <div
                            v-for="tx in props.recentTransactions"
                            :key="tx.id"
                            class="flex items-center justify-between p-2.5 bg-[#f5f3ee] dark:bg-white/5 rounded-xl hover:bg-[#eae8e2] dark:hover:bg-white/10 transition-all border border-[#eae8e2] dark:border-white/10"
                        >
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div
                                    class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                                    :class="tx.type === 'deposit' ? 'bg-[#51fac1]/30 text-[#007152] dark:text-[#51fac1]' : 'bg-[#ffc9d0]/40 text-[#ad2c4f]'"
                                >
                                    <span class="material-symbols-outlined text-[18px]">{{ tx.type === 'deposit' ? 'south_east' : 'north_east' }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-[#1b1c19] dark:text-white truncate">{{ tx.goal_name }}</p>
                                    <p class="text-[10px] text-[#4d4634]/60 dark:text-white/50 truncate">
                                        {{ formatShortDate(tx.transaction_date) }}<template v-if="tx.note"> • {{ tx.note }}</template>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="text-xs font-black" :class="tx.type === 'deposit' ? 'text-[#006c4f] dark:text-[#51fac1]' : 'text-[#ad2c4f]'">
                                    {{ tx.type === 'deposit' ? '+' : '-' }}{{ formatCurrency(tx.amount) }}
                                </span>
                                <span class="block text-[10px] text-[#4d4634]/60 dark:text-white/50">{{ tx.type === 'deposit' ? 'Setoran' : 'Penarikan' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick hint -->
                    <div class="mt-1 p-2.5 bg-[#f5f3ee] dark:bg-white/5 rounded-xl flex items-center justify-between gap-2 border border-[#eae8e2] dark:border-white/10">
                        <span class="text-xs text-[#4d4634]/70 dark:text-white/50">Punya uang sisa? Langsung tabungkan.</span>
                        <button
                            v-if="props.goals.some((g) => !g.is_completed)"
                            @click="openDeposit(props.goals.find((g) => !g.is_completed)!)"
                            class="px-2.5 py-1 bg-white dark:bg-[#23231f] text-[#574500] dark:text-[#ffe089] text-[10px] font-bold rounded-lg border border-[#eae8e2] dark:border-white/10 hover:bg-[#ffd23f] dark:hover:bg-[#ffd23f] dark:hover:text-[#574500] transition-all"
                        >
                            + Tabung Sekarang
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <!-- Dialog: Buat Celengan -->
        <Dialog v-model:open="isCreateOpen">
            <DialogContent class="!max-w-[95vw] sm:!max-w-[500px] !max-h-[90vh] overflow-y-auto rounded-2xl p-0 gap-0 bg-white">
                <DialogHeader class="p-6 pb-0">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19]">Buat Celengan Baru</DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]/60">
                        Tentukan impian, target nominal, dan tenggat waktu (opsional)
                    </DialogDescription>
                </DialogHeader>

                <div class="p-6">
                    <form @submit.prevent="submitCreate" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="goal_name" class="text-sm font-medium text-[#4d4634]">Nama Impian *</Label>
                            <Input
                                id="goal_name"
                                v-model="goalForm.name"
                                placeholder="Contoh: Liburan ke Bali, Beli Laptop, Dana Darurat"
                                required
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="goalForm.errors.name" class="text-xs text-[#ad2c4f]">{{ goalForm.errors.name }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="target_amount" class="text-sm font-medium text-[#4d4634]">Target Nominal *</Label>
                                <CurrencyInput
                                    id="target_amount"
                                    v-model="goalForm.target_amount"
                                    placeholder="10.000.000"
                                    required
                                    class="rounded-xl"
                                />
                                <p v-if="goalForm.errors.target_amount" class="text-xs text-[#ad2c4f]">{{ goalForm.errors.target_amount }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="current_amount" class="text-sm font-medium text-[#4d4634]">Saldo Awal</Label>
                                <CurrencyInput
                                    id="current_amount"
                                    v-model="goalForm.current_amount"
                                    placeholder="0"
                                    class="rounded-xl"
                                />
                                <p v-if="goalForm.errors.current_amount" class="text-xs text-[#ad2c4f]">{{ goalForm.errors.current_amount }}</p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="deadline" class="text-sm font-medium text-[#4d4634]">Target Selesai</Label>
                            <Input
                                id="deadline"
                                v-model="goalForm.deadline"
                                type="date"
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="goalForm.errors.deadline" class="text-xs text-[#ad2c4f]">{{ goalForm.errors.deadline }}</p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 pt-2">
                            <button
                                type="button"
                                @click="isCreateOpen = false"
                                class="w-full rounded-xl border border-[#eae8e2] bg-white px-4 py-2.5 text-sm font-semibold text-[#4d4634] hover:bg-[#f5f3ee] sm:order-1"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="goalForm.processing"
                                class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] disabled:opacity-50 sm:order-2"
                            >
                                {{ goalForm.processing ? 'Menyimpan...' : 'Buat Celengan' }}
                            </button>
                        </div>
                    </form>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Dialog: Nabung Cepat -->
        <Dialog v-model:open="isDepositOpen">
            <DialogContent class="!max-w-[95vw] sm:!max-w-[460px] !max-h-[90vh] overflow-y-auto rounded-2xl p-0 gap-0 bg-white">
                <DialogHeader class="p-6 pb-0">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19]">Nabung Cepat</DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]/60">
                        Tambahkan tabungan ke "{{ depositGoal?.name }}"
                    </DialogDescription>
                </DialogHeader>

                <div class="p-6">
                    <form @submit.prevent="submitDeposit" class="space-y-4">
                        <div v-if="depositGoal" class="rounded-xl bg-[#f5f3ee] dark:bg-white/5 p-3 flex items-center justify-between border border-[#eae8e2] dark:border-white/10">
                            <div class="flex items-center gap-2">
                                <div class="w-9 h-9 rounded-lg bg-[#ffd23f]/25 text-[#725a00] flex items-center justify-center">
                                    <span class="material-symbols-outlined text-[18px]">{{ getGoalIcon(depositGoal.icon) }}</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-[#1b1c19] dark:text-white">{{ depositGoal.name }}</p>
                                    <p class="text-[10px] text-[#006c4f] dark:text-[#51fac1] font-bold">
                                        Terkumpul {{ formatCurrency(depositGoal.current_amount) }} / {{ formatCurrency(depositGoal.target_amount) }}
                                    </p>
                                </div>
                            </div>
                            <span class="text-xs font-black text-[#725a00] dark:text-[#ffe089]">{{ Math.round(depositGoal.progress_percentage) }}%</span>
                        </div>

                        <div class="space-y-2">
                            <Label for="deposit_amount" class="text-sm font-medium text-[#4d4634]">Nominal *</Label>
                            <CurrencyInput
                                id="deposit_amount"
                                v-model="depositForm.amount"
                                placeholder="50.000"
                                required
                                autofocus
                                class="rounded-xl"
                            />
                            <p v-if="depositForm.errors.amount" class="text-xs text-[#ad2c4f]">{{ depositForm.errors.amount }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="deposit_note" class="text-sm font-medium text-[#4d4634]">Catatan</Label>
                            <Input
                                id="deposit_note"
                                v-model="depositForm.note"
                                placeholder="Opsional, misal: bonus proyek, sisa uang jajan"
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="depositForm.errors.note" class="text-xs text-[#ad2c4f]">{{ depositForm.errors.note }}</p>
                        </div>

                        <div class="flex gap-2 pt-1">
                            <button
                                v-for="quick in [10000, 25000, 50000, 100000]"
                                :key="quick"
                                type="button"
                                @click="depositForm.amount = quick"
                                class="flex-1 rounded-lg border border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 py-1.5 text-xs font-bold text-[#574500] dark:text-[#ffe089] hover:bg-[#ffd23f]/30 transition-all"
                            >
                                {{ formatCurrency(quick) }}
                            </button>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 pt-2">
                            <button
                                type="button"
                                @click="isDepositOpen = false"
                                class="w-full rounded-xl border border-[#eae8e2] bg-white px-4 py-2.5 text-sm font-semibold text-[#4d4634] hover:bg-[#f5f3ee] sm:order-1"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="depositForm.processing"
                                class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] disabled:opacity-50 sm:order-2"
                            >
                                {{ depositForm.processing ? 'Menyimpan...' : 'Tabung Sekarang' }}
                            </button>
                        </div>
                    </form>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Dialog: Klaim & Tarik -->
        <Dialog v-model:open="isWithdrawOpen">
            <DialogContent class="!max-w-[95vw] sm:!max-w-[460px] !max-h-[90vh] overflow-y-auto rounded-2xl p-0 gap-0 bg-white">
                <DialogHeader class="p-6 pb-0">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19]">Klaim & Tarik Celengan</DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]/60">
                        Tarik saldo dari "{{ withdrawGoal?.name }}"
                    </DialogDescription>
                </DialogHeader>

                <div class="p-6">
                    <form @submit.prevent="submitWithdraw" class="space-y-4">
                        <div v-if="withdrawGoal" class="rounded-xl bg-[#51fac1]/20 p-3 flex items-center gap-3 border border-[#51fac1]/40">
                            <div class="w-10 h-10 rounded-lg bg-[#51fac1] text-[#002116] flex items-center justify-center">
                                <span class="material-symbols-outlined text-[22px]">celebration</span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-[#007152]">Saldo tersedia</p>
                                <p class="text-lg font-black text-[#007152]">{{ formatCurrency(withdrawGoal.current_amount) }}</p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="withdraw_amount" class="text-sm font-medium text-[#4d4634]">Nominal Penarikan *</Label>
                            <CurrencyInput
                                id="withdraw_amount"
                                v-model="withdrawForm.amount"
                                required
                                class="rounded-xl"
                            />
                            <p v-if="withdrawForm.errors.amount" class="text-xs text-[#ad2c4f]">{{ withdrawForm.errors.amount }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="withdraw_note" class="text-sm font-medium text-[#4d4634]">Catatan</Label>
                            <Input
                                id="withdraw_note"
                                v-model="withdrawForm.note"
                                placeholder="Opsional, misal: buat beli tiket, DP rumah"
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="withdrawForm.errors.note" class="text-xs text-[#ad2c4f]">{{ withdrawForm.errors.note }}</p>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 pt-2">
                            <button
                                type="button"
                                @click="isWithdrawOpen = false"
                                class="w-full rounded-xl border border-[#eae8e2] bg-white px-4 py-2.5 text-sm font-semibold text-[#4d4634] hover:bg-[#f5f3ee] sm:order-1"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="withdrawForm.processing"
                                class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-[#002116] bg-[#51fac1] hover:bg-[#27e0a9] disabled:opacity-50 sm:order-2"
                            >
                                {{ withdrawForm.processing ? 'Memproses...' : 'Tarik Saldo' }}
                            </button>
                        </div>
                    </form>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>