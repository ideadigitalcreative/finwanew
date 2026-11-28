<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { PiggyBank, Plus, Pencil, Trash2, ToggleLeft, ToggleRight, Utensils, Home, Car, GraduationCap, ShoppingBag, Heart, Gamepad2, Shirt, Sparkles, Coffee, Film, Music, Dumbbell, Plane, Gift, Zap, Smartphone, Wallet } from 'lucide-vue-next';
import Swal from 'sweetalert2';
import BudgetVsActualChart from '@/components/dashboard/BudgetVsActualChart.vue';

const props = defineProps({
    budgets: Array,
    categories: Array,
    budgetGlobal: Object,
    comparisonData: { type: Array, default: () => [] },
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingBudget = ref(null);
const showGlobalModal = ref(false);
const globalBudget = ref(props.budgetGlobal);
const globalForm = useForm({
    amount: '',
    period: 'monthly',
    start_date: new Date().toISOString().split('T')[0],
    end_date: null,
    alert_enabled: true,
    alert_threshold: 80,
});

const createForm = useForm({
    category_id: '',
    amount: '',
    period: 'monthly',
    start_date: new Date().toISOString().split('T')[0],
    end_date: null,
    alert_enabled: true,
    alert_threshold: 80,
    rollover_enabled: false,
});

const editForm = useForm({
    amount: '',
    alert_enabled: true,
    alert_threshold: 80,
    rollover_enabled: false,
});

const activeBudgets = computed(() => props.budgets.filter(b => b.is_active));
const inactiveBudgets = computed(() => props.budgets.filter(b => !b.is_active));

const totalBudget = computed(() => activeBudgets.value.reduce((sum, b) => sum + parseFloat(b.amount), 0));
const totalSpending = computed(() => activeBudgets.value.reduce((sum, b) => sum + parseFloat(b.current_spending), 0));
const totalRemaining = computed(() => Math.max(0, totalBudget.value - totalSpending.value));
const overallPercentage = computed(() => totalBudget.value > 0 ? (totalSpending.value / totalBudget.value) * 100 : 0);

function openCreateModal() {
    createForm.reset();
    createForm.start_date = new Date().toISOString().split('T')[0];
    showCreateModal.value = true;
}

function closeCreateModal() {
    showCreateModal.value = false;
    createForm.reset();
}

function submitCreate() {
    createForm.post('/budgets', {
        onSuccess: () => closeCreateModal(),
    });
}

function openEditModal(budget) {
    editingBudget.value = budget;
    editForm.amount = budget.amount;
    editForm.alert_enabled = budget.alert_enabled;
    editForm.alert_threshold = budget.alert_threshold;
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingBudget.value = null;
    editForm.reset();
}

function submitEdit() {
    editForm.put(`/budgets/${editingBudget.value.id}`, {
        onSuccess: () => closeEditModal(),
    });
}

function deleteBudget(budget) {
    Swal.fire({
        title: 'Hapus Budget?',
        html: `Apakah Anda yakin ingin menghapus budget <strong>${budget.category.name}</strong>?<br><small class="text-gray-500">Budget: ${formatCurrency(budget.amount)}</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(`/budgets/${budget.id}`, {
                onSuccess: () => {
                    Swal.fire({
                        title: 'Terhapus!',
                        text: 'Budget berhasil dihapus.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                },
                onError: () => {
                    Swal.fire({
                        title: 'Gagal!',
                        text: 'Terjadi kesalahan saat menghapus budget.',
                        icon: 'error'
                    });
                }
            });
        }
    });
}

function toggleBudget(budget) {
    router.post(`/budgets/${budget.id}/toggle`);
}

// ─── Budget Global Methods ────────────────────────────────────

function openGlobalModal() {
    if (globalBudget.value) {
        globalForm.amount = globalBudget.value.amount;
        globalForm.period = globalBudget.value.period;
        globalForm.start_date = globalBudget.value.start_date;
        globalForm.end_date = globalBudget.value.end_date;
        globalForm.alert_threshold = globalBudget.value.alert_threshold;
        globalForm.alert_enabled = globalBudget.value.alert_enabled;
    } else {
        globalForm.reset();
        globalForm.start_date = new Date().toISOString().split('T')[0];
        globalForm.period = 'monthly';
        globalForm.alert_threshold = 80;
        globalForm.alert_enabled = true;
    }
    showGlobalModal.value = true;
}

function closeGlobalModal() {
    showGlobalModal.value = false;
}

function submitGlobal() {
    if (globalBudget.value) {
        globalForm.put(`/budgets/global/${globalBudget.value.id}`, {
            preserveState: true,
            onSuccess: (page) => {
                globalBudget.value = page.props.budgetGlobal;
                showGlobalModal.value = false;
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Budget global berhasil diupdate.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false,
                });
            },
        });
    } else {
        globalForm.post('/budgets/global', {
            preserveState: true,
            onSuccess: (page) => {
                globalBudget.value = page.props.budgetGlobal;
                showGlobalModal.value = false;
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Budget global berhasil dibuat.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false,
                });
            },
        });
    }
}

function deleteGlobal() {
    if (!globalBudget.value) return;
    Swal.fire({
        title: 'Hapus Budget Global?',
        html: 'Semua anggaran global akan dihapus. Anggaran per kategori tetap tersimpan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal',
        reverseButtons: true,
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(`/budgets/global/${globalBudget.value.id}`, {
                preserveState: true,
                onSuccess: (page) => {
                    globalBudget.value = page.props.budgetGlobal;
                    Swal.fire({
                        title: 'Terhapus!',
                        text: 'Budget global berhasil dihapus.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false,
                    });
                },
            });
        }
    });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(amount);
}

function getProgressBarColor(percentage) {
    if (percentage >= 100) return 'bg-[#e36c8b]';
    if (percentage >= 80) return 'bg-[#ffd23f]';
    return 'bg-[#51fac1]';
}

function getCategoryIcon(iconEmoji) {
    const iconMap = {
        // Makanan
        '🍔': Utensils, '🍕': Utensils, '🍜': Utensils, '🍽️': Utensils,
        // Hunian
        '🏠': Home, '🏡': Home, '🏘️': Home,
        // Transport
        '🚗': Car, '🚙': Car, '🚕': Car, '🔧': Car,
        // Pendidikan
        '📚': GraduationCap, '🎓': GraduationCap,
        // Belanja
        '🛍️': ShoppingBag, '🛒': ShoppingBag, '📦': ShoppingBag, '🏪': ShoppingBag,
        // Kesehatan / Donasi / Sosial
        '❤️': Heart, '💊': Heart, '🏥': Heart, '🤝': Heart, '🛡️': Heart, '🐾': Heart, '👶': Heart,
        // Hiburan
        '🎮': Gamepad2, '🎯': Gamepad2, '🎬': Film,
        // Pakaian
        '👕': Shirt, '👔': Shirt,
        // Perawatan diri
        '✨': Sparkles, '💇': Sparkles,
        // Kopi
        '☕': Coffee,
        // Musik
        '🎵': Music,
        // Olahraga
        '🏋️': Dumbbell,
        // Travel
        '✈️': Plane,
        // Hadiah
        '🎁': Gift, '🎊': Gift,
        // Utilitas / Operasional
        '⚡': Zap, '⚙️': Zap,
        // Pulsa / Gadget / Langganan
        '📱': Smartphone, '🔄': Smartphone,
        // Keuangan
        '💳': Wallet, '💰': Wallet, '💵': Wallet, '💸': Wallet, '💼': Wallet,
        '📄': Wallet, '📊': Wallet, '📝': Wallet,
        '📤': Wallet, '📥': Wallet, '✅': Wallet,
        '🏦': Wallet, '👷': Wallet,
        // Investasi
        '📈': PiggyBank,
        // Keluarga
        '👨‍👩‍👧‍👦': Home,
    };
    return iconMap[iconEmoji] || PiggyBank;
}
</script>

<template>
    <AppLayout title="Budget Management">
        <Head title="Budget Management" />

        <div class="bg-background flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-hidden p-4 md:p-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 relative z-10">
                <div class="flex-1">
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] dark:text-white flex items-center gap-2.5 font-['Plus_Jakarta_Sans',sans-serif]">
                        <span class="w-9 h-9 md:w-10 md:h-10 rounded-xl bg-[#ffd23f] text-[#574500] flex items-center justify-center">
                            <span class="material-symbols-outlined text-[22px]">savings</span>
                        </span>
                        Budget Management
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634] dark:text-gray-400 mt-1 font-['Plus_Jakarta_Sans',sans-serif]">Kelola budget pengeluaran Anda per kategori</p>
                </div>
                <button
                    @click="openCreateModal"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-[#ffd23f] text-[#725a00] rounded-xl hover:brightness-95 transition-all font-bold w-full md:w-auto font-['Plus_Jakarta_Sans',sans-serif] active:scale-95"
                >
                    <Plus class="w-4 h-4" />
                    Tambah Budget
                </button>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5 relative z-10 font-['Plus_Jakarta_Sans',sans-serif]">
                <div class="bg-[#ffe089] border-t-4 border-[#e09f00] rounded-2xl p-4 md:p-5 bg-opacity-70">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs font-bold uppercase tracking-wide text-[#574500]">Total Budget</div>
                        <span class="material-symbols-outlined text-[18px] text-[#574500]">account_balance_wallet</span>
                    </div>
                    <div class="text-2xl font-bold text-[#241a00]">
                        {{ formatCurrency(totalBudget) }}
                    </div>
                </div>
                <div class="bg-[#ffc9d0] border-t-4 border-[#e36c8b] rounded-2xl p-4 md:p-5 bg-opacity-70">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs font-bold uppercase tracking-wide text-[#93000a]">Total Terpakai</div>
                        <span class="material-symbols-outlined text-[18px] text-[#93000a]">shopping_cart</span>
                    </div>
                    <div class="text-2xl font-bold text-[#5d0714]">
                        {{ formatCurrency(totalSpending) }}
                    </div>
                </div>
                <div class="bg-[#51fac1] border-t-4 border-[#19b382] rounded-2xl p-4 md:p-5 bg-opacity-70">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs font-bold uppercase tracking-wide text-[#007152]">Sisa Budget</div>
                        <span class="material-symbols-outlined text-[18px] text-[#007152]">savings</span>
                    </div>
                    <div class="text-2xl font-bold text-[#00452f]">
                        {{ formatCurrency(totalRemaining) }}
                    </div>
                </div>
                <div class="bg-[#f5f3ee] border-t-4 border-[#c9c2b0] rounded-2xl p-4 md:p-5 bg-opacity-70">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs font-bold uppercase tracking-wide text-[#4d4634]">Penggunaan</div>
                        <span class="material-symbols-outlined text-[18px] text-[#4d4634]">pie_chart</span>
                    </div>
                    <div class="text-2xl font-bold text-[#1b1c19]">
                        {{ overallPercentage.toFixed(1) }}%
                    </div>
                </div>
            </div>

            <!-- Budget Global Section -->
            <div class="bg-white dark:bg-[#23231f] rounded-2xl p-4 md:p-5 border border-[#eae8e2] dark:border-white/10 relative z-10 font-['Plus_Jakarta_Sans',sans-serif]">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-xl bg-[#ffe089] text-[#574500] flex items-center justify-center">
                            <span class="material-symbols-outlined text-[18px]">globe</span>
                        </span>
                        <h3 class="text-lg font-bold text-[#1b1c19] dark:text-foreground">Budget Global</h3>
                    </div>
                    <div class="flex gap-2">
                        <button
                            @click="openGlobalModal"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg bg-[#ffd23f] text-[#725a00] hover:brightness-95 transition-colors"
                        >
                            <Pencil class="w-3.5 h-3.5" />
                            {{ globalBudget ? 'Edit' : 'Atur Budget Global' }}
                        </button>
                        <button
                            v-if="globalBudget"
                            @click="deleteGlobal"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg bg-[#ffc9d0] text-[#93000a] hover:bg-[#ffc9d0]/70 transition-colors"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>

                <template v-if="globalBudget">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                        <div class="bg-[#ffe089] rounded-xl p-3">
                            <p class="text-[10px] text-[#574500] uppercase tracking-wide mb-1">Total Anggaran</p>
                            <p class="text-sm font-bold text-[#241a00]">{{ formatCurrency(globalBudget.amount) }}</p>
                        </div>
                        <div class="bg-[#ffc9d0] rounded-xl p-3">
                            <p class="text-[10px] text-[#93000a] uppercase tracking-wide mb-1">Terpakai</p>
                            <p class="text-sm font-bold text-[#5d0714]">{{ formatCurrency(globalBudget.current_spending) }}</p>
                        </div>
                        <div class="bg-[#51fac1] rounded-xl p-3">
                            <p class="text-[10px] text-[#007152] uppercase tracking-wide mb-1">Sisa</p>
                            <p class="text-sm font-bold text-[#00452f]">{{ formatCurrency(globalBudget.remaining) }}</p>
                        </div>
                        <div class="bg-[#f5f3ee] dark:bg-white/5 rounded-xl p-3">
                            <p class="text-[10px] text-[#4d4634] uppercase tracking-wide mb-1">Persentase</p>
                            <p class="text-sm font-bold" :class="globalBudget.is_over_budget ? 'text-[#93000a]' : 'text-[#1b1c19] dark:text-gray-200'">
                                {{ globalBudget.usage_percentage }}%
                            </p>
                        </div>
                    </div>
                    <!-- Progress Bar -->
                    <div class="w-full bg-[#eae8e2] dark:bg-white/10 rounded-full h-3">
                        <div
                            :class="globalBudget.is_over_budget ? 'bg-[#e36c8b]' : getProgressBarColor(globalBudget.usage_percentage)"
                            class="h-3 rounded-full transition-all duration-500"
                            :style="{ width: Math.min(globalBudget.usage_percentage, 100) + '%' }"
                        ></div>
                    </div>
                    <p class="text-xs text-[#4d4634] dark:text-muted-foreground mt-2">
                        Periode {{ globalBudget.period }} &middot; Mulai {{ globalBudget.start_date }}
                        <span v-if="globalBudget.is_over_budget" class="text-[#93000a] font-semibold"> &mdash; Melebihi anggaran!</span>
                    </p>
                </template>

                <template v-else>
                    <div class="flex flex-col items-center justify-center py-4 text-center gap-2">
                        <div class="w-10 h-10 rounded-full bg-[#ffe089] text-[#574500] flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px]">globe</span>
                        </div>
                        <p class="text-sm text-[#4d4634] dark:text-muted-foreground">Belum ada budget global. Atur batas pengeluaran total Anda.</p>
                    </div>
                </template>
            </div>

            <!-- Active Budgets -->
            <div class="bg-white dark:bg-[#23231f] rounded-2xl p-4 md:p-5 border border-[#eae8e2] dark:border-white/10 relative z-10 font-['Plus_Jakarta_Sans',sans-serif]">
                <div class="mb-5">
                    <h3 class="text-lg font-bold text-[#1b1c19] dark:text-foreground">Budget Aktif</h3>
                </div>
                <div v-if="activeBudgets.length === 0" class="text-center py-12 text-[#4d4634] dark:text-muted-foreground">
                    Belum ada budget aktif. Klik "Tambah Budget" untuk membuat budget baru.
                </div>
                <div v-else class="space-y-4">
                    <div
                        v-for="budget in activeBudgets"
                        :key="budget.id"
                        class="border border-[#eae8e2] dark:border-white/10 rounded-2xl p-4 bg-[#f5f3ee]/50 dark:bg-white/[0.03] hover:bg-[#f0eee8]/70 dark:hover:bg-white/[0.06] transition-all"
                    >
                        <div class="flex justify-between items-start mb-3">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-[#51fac1] text-[#007152] flex items-center justify-center flex-shrink-0">
                                    <component :is="getCategoryIcon(budget.category.icon)" class="w-6 h-6" />
                                </div>
                                <div>
                                    <h4 class="font-bold text-[#1b1c19] dark:text-foreground">
                                        {{ budget.category.name }}
                                    </h4>
                                    <p class="text-sm text-[#4d4634] dark:text-muted-foreground">
                                        Budget: {{ formatCurrency(budget.amount) }} / {{ budget.period }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    @click="openEditModal(budget)"
                                    class="p-2 rounded-lg hover:bg-[#51fac1]/30 text-[#4d4634] hover:text-[#007152] transition-colors"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </button>
                                <button
                                    @click="toggleBudget(budget)"
                                    class="p-2 rounded-lg hover:bg-[#f0eee8] dark:hover:bg-white/10 text-[#4d4634] hover:text-gray-600 transition-colors"
                                    title="Nonaktifkan"
                                >
                                    <ToggleRight class="w-4 h-4" />
                                </button>
                                <button
                                    @click="deleteBudget(budget)"
                                    class="p-2 rounded-lg hover:bg-[#ffc9d0] text-[#4d4634] hover:text-[#93000a] transition-colors"
                                    title="Hapus"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Progress Info -->
                        <div class="mb-2">
                            <div class="flex justify-between text-sm mb-2">
                                <span class="text-sm text-[#4d4634] dark:text-muted-foreground">
                                    Terpakai: {{ formatCurrency(budget.current_spending) }}
                                    ({{ budget.usage_percentage.toFixed(1) }}%)
                                    <span v-if="budget.alert_level === 'critical'" class="text-[#93000a]">🚨</span>
                                    <span v-else-if="budget.alert_level === 'warning'" class="text-[#e36c8b]">⚠️</span>
                                    <span v-else-if="budget.alert_level === 'info'" class="text-[#b98a00]">i️</span>
                                </span>
                                <span class="text-[#4d4634] dark:text-muted-foreground">
                                    Sisa: {{ formatCurrency(budget.remaining) }}
                                </span>
                            </div>
                            <!-- Progress Bar -->
                            <div class="w-full bg-[#eae8e2] dark:bg-white/10 rounded-full h-2.5">
                                <div
                                    :class="getProgressBarColor(budget.usage_percentage)"
                                    class="h-2.5 rounded-full transition-all duration-500"
                                    :style="{ width: Math.min(budget.usage_percentage, 100) + '%' }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inactive Budgets (if any) -->
            <div v-if="inactiveBudgets.length > 0" class="bg-white dark:bg-[#23231f] rounded-2xl p-4 md:p-5 border border-[#eae8e2] dark:border-white/10 relative z-10 font-['Plus_Jakarta_Sans',sans-serif]">
                <div class="mb-5 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-[#f0eee8] dark:bg-white/10 text-[#4d4634] dark:text-gray-400 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">toggle_off</span>
                    </span>
                    <h3 class="text-lg font-bold text-[#1b1c19] dark:text-foreground">Budget Nonaktif</h3>
                </div>
                <div class="space-y-3">
                    <div
                        v-for="budget in inactiveBudgets"
                        :key="budget.id"
                        class="border border-[#eae8e2] dark:border-white/10 rounded-xl p-3 flex justify-between items-center bg-[#f5f3ee]/50 dark:bg-white/[0.03] opacity-70 hover:opacity-100 transition-opacity"
                    >
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#51fac1]/60 text-[#007152] flex items-center justify-center flex-shrink-0">
                                <component :is="getCategoryIcon(budget.category.icon)" class="w-5 h-5" />
                            </div>
                            <span class="text-[#1b1c19] dark:text-foreground font-medium">{{ budget.category.name }}</span>
                            <span class="text-sm text-[#4d4634] dark:text-muted-foreground">{{ formatCurrency(budget.amount) }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="toggleBudget(budget)"
                                class="p-2 rounded-lg hover:bg-[#51fac1]/30 text-[#4d4634] hover:text-[#007152] transition-colors"
                                title="Aktifkan"
                            >
                                <ToggleLeft class="w-4 h-4" />
                            </button>
                            <button
                                @click="deleteBudget(budget)"
                                class="p-2 rounded-lg hover:bg-[#ffc9d0] text-[#4d4634] hover:text-[#93000a] transition-colors"
                                title="Hapus"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Budget vs Actual Chart -->
            <BudgetVsActualChart :data="comparisonData" />
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-[#23231f] rounded-2xl p-6 w-full max-w-md border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif]">
                <h3 class="text-xl font-bold mb-6 text-[#1b1c19] dark:text-white flex items-center gap-2.5">
                    <span class="w-9 h-9 rounded-xl bg-[#ffd23f] text-[#574500] flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">add_card</span>
                    </span>
                    Tambah Budget Baru
                </h3>
                <form @submit.prevent="submitCreate" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Kategori</label>
                        <select
                            v-model="createForm.category_id"
                            required
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        >
                            <option value="">Pilih Kategori</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                {{ cat.icon }} {{ cat.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Jumlah Budget</label>
                        <input
                            v-model="createForm.amount"
                            type="number"
                            required
                            min="0"
                            step="1000"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                            placeholder="500000"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Periode</label>
                        <select
                            v-model="createForm.period"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        >
                            <option value="daily">Harian</option>
                            <option value="weekly">Mingguan</option>
                            <option value="monthly">Bulanan</option>
                            <option value="yearly">Tahunan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Alert Threshold (%)</label>
                        <input
                            v-model="createForm.alert_threshold"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        />
                        <p class="text-xs text-[#4d4634] dark:text-gray-400 mt-1">Notifikasi akan muncul saat mencapai persentase ini</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="createForm.rollover_enabled"
                            type="checkbox"
                            class="rounded border-[#eae8e2] dark:border-gray-600 text-[#e09f00] focus:ring-[#ffd23f]"
                        />
                        <label class="text-sm text-[#4d4634] dark:text-gray-300">Aktifkan Rollover</label>
                    </div>
                    <p class="text-xs text-[#4d4634] dark:text-gray-400">Sisa budget akan otomatis dibawa ke periode berikutnya</p>
                    <div class="flex gap-3 justify-end mt-6 pt-4 border-t border-[#eae8e2] dark:border-gray-700">
                        <button
                            type="button"
                            @click="closeCreateModal"
                            class="px-6 py-2.5 text-[#4d4634] dark:text-gray-300 hover:bg-[#f5f3ee] dark:hover:bg-gray-700 rounded-xl transition-colors font-medium"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="px-6 py-2.5 bg-[#ffd23f] text-[#725a00] rounded-xl hover:brightness-95 disabled:opacity-50 transition-all font-bold"
                        >
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-[#23231f] rounded-2xl p-6 w-full max-w-md border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif]">
                <h3 class="text-xl font-bold mb-6 text-[#1b1c19] dark:text-white flex items-center gap-2.5">
                    <span class="w-9 h-9 rounded-xl bg-[#51fac1] text-[#007152] flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">edit_square</span>
                    </span>
                    Edit Budget
                </h3>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Jumlah Budget</label>
                        <input
                            v-model="editForm.amount"
                            type="number"
                            required
                            min="0"
                            step="1000"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Alert Threshold (%)</label>
                        <input
                            v-model="editForm.alert_threshold"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        />
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="editForm.alert_enabled"
                            type="checkbox"
                            class="rounded border-[#eae8e2] dark:border-gray-600 text-[#e09f00] focus:ring-[#ffd23f]"
                        />
                        <label class="text-sm text-[#4d4634] dark:text-gray-300">Aktifkan Alert</label>
                    </div>
                    <div class="flex gap-3 justify-end mt-6 pt-4 border-t border-[#eae8e2] dark:border-gray-700">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="px-6 py-2.5 text-[#4d4634] dark:text-gray-300 hover:bg-[#f5f3ee] dark:hover:bg-gray-700 rounded-xl transition-colors font-medium"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="px-6 py-2.5 bg-[#ffd23f] text-[#725a00] rounded-xl hover:brightness-95 disabled:opacity-50 transition-all font-bold"
                        >
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Budget Global Modal -->
        <div v-if="showGlobalModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-[#23231f] rounded-2xl p-6 w-full max-w-md border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif]">
                <h3 class="text-xl font-bold mb-6 text-[#1b1c19] dark:text-white flex items-center gap-2.5">
                    <span class="w-9 h-9 rounded-xl bg-[#ffe089] text-[#574500] flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">globe</span>
                    </span>
                    {{ globalBudget ? 'Edit' : 'Atur' }} Budget Global
                </h3>
                <form @submit.prevent="submitGlobal" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Total Anggaran (Semua Kategori)</label>
                        <input
                            v-model="globalForm.amount"
                            type="number"
                            required
                            min="0"
                            step="10000"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                            placeholder="Contoh: 10000000"
                        />
                        <p class="text-xs text-[#4d4634] dark:text-gray-400 mt-1">Batas maksimum total pengeluaran bulanan/tahunan</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Periode</label>
                        <select
                            v-model="globalForm.period"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        >
                            <option value="monthly">Bulanan</option>
                            <option value="yearly">Tahunan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Tanggal Mulai</label>
                        <input
                            v-model="globalForm.start_date"
                            type="date"
                            required
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Tanggal Akhir (Opsional)</label>
                        <input
                            v-model="globalForm.end_date"
                            type="date"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-[#4d4634] dark:text-gray-300 mb-2">Alert Threshold (%)</label>
                        <input
                            v-model="globalForm.alert_threshold"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full rounded-xl border-[#eae8e2] dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-[#ffd23f] focus:ring-[#ffd23f] bg-[#f5f3ee]/30 dark:bg-gray-700"
                        />
                        <p class="text-xs text-[#4d4634] dark:text-gray-400 mt-1">Notifikasi akan muncul saat mencapai persentase ini</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="globalForm.alert_enabled"
                            type="checkbox"
                            class="rounded border-[#eae8e2] dark:border-gray-600 text-[#e09f00] focus:ring-[#ffd23f]"
                        />
                        <label class="text-sm text-[#4d4634] dark:text-gray-300">Aktifkan Alert</label>
                    </div>
                    <div class="flex gap-3 justify-end mt-6 pt-4 border-t border-[#eae8e2] dark:border-gray-700">
                        <button
                            type="button"
                            @click="closeGlobalModal"
                            class="px-6 py-2.5 text-[#4d4634] dark:text-gray-300 hover:bg-[#f5f3ee] dark:hover:bg-gray-700 rounded-xl transition-colors font-medium"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            :disabled="globalForm.processing"
                            class="px-6 py-2.5 bg-[#ffd23f] text-[#725a00] rounded-xl hover:brightness-95 disabled:opacity-50 transition-all font-bold"
                        >
                            {{ globalBudget ? 'Update' : 'Simpan' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
