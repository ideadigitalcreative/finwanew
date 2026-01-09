<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { PiggyBank, Plus, Pencil, Trash2, ToggleLeft, ToggleRight, Utensils, Home, Car, GraduationCap, ShoppingBag, Heart, Gamepad2, Shirt, Sparkles, Coffee, Film, Music, Dumbbell, Plane, Gift, Zap } from 'lucide-vue-next';
import Swal from 'sweetalert2';

const props = defineProps({
    budgets: Array,
    categories: Array,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingBudget = ref(null);

const createForm = useForm({
    category_id: '',
    amount: '',
    period: 'monthly',
    start_date: new Date().toISOString().split('T')[0],
    end_date: null,
    alert_enabled: true,
    alert_threshold: 80,
});

const editForm = useForm({
    amount: '',
    alert_enabled: true,
    alert_threshold: 80,
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

function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(amount);
}

function getProgressBarColor(percentage) {
    if (percentage >= 100) return 'bg-red-500';
    if (percentage >= 80) return 'bg-yellow-500';
    return 'bg-emerald-500';
}

function getCategoryIcon(iconEmoji) {
    const iconMap = {
        '🍔': Utensils,
        '🍕': Utensils,
        '🍜': Utensils,
        '🏠': Home,
        '🏡': Home,
        '🚗': Car,
        '🚙': Car,
        '🚕': Car,
        '📚': GraduationCap,
        '🎓': GraduationCap,
        '🛍️': ShoppingBag,
        '🛒': ShoppingBag,
        '❤️': Heart,
        '💊': Heart,
        '🎮': Gamepad2,
        '🎯': Gamepad2,
        '👕': Shirt,
        '👔': Shirt,
        '✨': Sparkles,
        '☕': Coffee,
        '🎬': Film,
        '🎵': Music,
        '🏋️': Dumbbell,
        '✈️': Plane,
        '🎁': Gift,
        '⚡': Zap,
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
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <PiggyBank class="w-6 h-6 md:w-7 md:h-7 text-emerald-600" />
                        Budget Management
                    </h2>
                    <p class="text-xs md:text-sm text-gray-500 mt-1">Kelola budget pengeluaran Anda per kategori</p>
                </div>
                <button
                    @click="openCreateModal"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-500/20 font-medium w-full md:w-auto"
                >
                    <Plus class="w-4 h-4" />
                    Tambah Budget
                </button>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5 relative z-10">
                <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10">
                    <div class="text-sm text-muted-foreground mb-2">Total Budget</div>
                    <div class="text-2xl font-bold text-foreground">
                        {{ formatCurrency(totalBudget) }}
                    </div>
                </div>
                <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10">
                    <div class="text-sm text-muted-foreground mb-2">Total Terpakai</div>
                    <div class="text-2xl font-bold text-foreground">
                        {{ formatCurrency(totalSpending) }}
                    </div>
                </div>
                <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10">
                    <div class="text-sm text-muted-foreground mb-2">Sisa Budget</div>
                    <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ formatCurrency(totalRemaining) }}
                    </div>
                </div>
                <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10">
                    <div class="text-sm text-muted-foreground mb-2">Penggunaan</div>
                    <div class="text-2xl font-bold text-foreground">
                        {{ overallPercentage.toFixed(1) }}%
                    </div>
                </div>
            </div>

            <!-- Active Budgets -->
            <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 relative z-10">
                <div class="mb-5">
                    <h3 class="text-lg font-semibold text-foreground">Budget Aktif</h3>
                </div>
                <div v-if="activeBudgets.length === 0" class="text-center py-12 text-muted-foreground">
                    Belum ada budget aktif. Klik "Tambah Budget" untuk membuat budget baru.
                </div>
                <div v-else class="space-y-4">
                    <div
                        v-for="budget in activeBudgets"
                        :key="budget.id"
                        class="border border-gray-200/50 dark:border-gray-700/30 rounded-xl p-4 bg-muted/10 backdrop-blur-sm hover:bg-muted/20 transition-all"
                    >
                        <div class="flex justify-between items-start mb-3">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 backdrop-blur-sm flex items-center justify-center flex-shrink-0 border border-emerald-500/20">
                                    <component :is="getCategoryIcon(budget.category.icon)" class="w-6 h-6 text-emerald-600" />
                                </div>
                                <div>
                                    <h4 class="font-semibold text-foreground">
                                        {{ budget.category.name }}
                                    </h4>
                                    <p class="text-sm text-muted-foreground">
                                        Budget: {{ formatCurrency(budget.amount) }} / {{ budget.period }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    @click="openEditModal(budget)"
                                    class="p-2 rounded-lg hover:bg-emerald-500/10 text-muted-foreground hover:text-emerald-600 transition-colors"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </button>
                                <button
                                    @click="toggleBudget(budget)"
                                    class="p-2 rounded-lg hover:bg-gray-500/10 text-muted-foreground hover:text-gray-600 transition-colors"
                                    title="Nonaktifkan"
                                >
                                    <ToggleRight class="w-4 h-4" />
                                </button>
                                <button
                                    @click="deleteBudget(budget)"
                                    class="p-2 rounded-lg hover:bg-red-500/10 text-muted-foreground hover:text-red-600 transition-colors"
                                    title="Hapus"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Progress Info -->
                        <div class="mb-2">
                            <div class="flex justify-between text-sm mb-2">
                                <span class="text-muted-foreground">
                                    Terpakai: {{ formatCurrency(budget.current_spending) }}
                                    ({{ budget.usage_percentage.toFixed(1) }}%)
                                    <span v-if="budget.is_over_budget" class="text-red-600">🚨</span>
                                    <span v-else-if="budget.should_alert" class="text-yellow-600">⚠️</span>
                                </span>
                                <span class="text-muted-foreground">
                                    Sisa: {{ formatCurrency(budget.remaining) }}
                                </span>
                            </div>
                            <!-- Progress Bar -->
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
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
            <div v-if="inactiveBudgets.length > 0" class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 relative z-10">
                <div class="mb-5">
                    <h3 class="text-lg font-semibold text-muted-foreground">Budget Nonaktif</h3>
                </div>
                <div class="space-y-3">
                    <div
                        v-for="budget in inactiveBudgets"
                        :key="budget.id"
                        class="flex justify-between items-center p-3 border border-gray-200/50 dark:border-gray-700/30 rounded-xl opacity-60 hover:opacity-100 transition-opacity"
                    >
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 backdrop-blur-sm flex items-center justify-center flex-shrink-0 border border-emerald-500/20">
                                <component :is="getCategoryIcon(budget.category.icon)" class="w-5 h-5 text-emerald-600" />
                            </div>
                            <span class="text-foreground font-medium">{{ budget.category.name }}</span>
                            <span class="text-sm text-muted-foreground">{{ formatCurrency(budget.amount) }}</span>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="toggleBudget(budget)"
                                class="p-2 rounded-lg hover:bg-emerald-500/10 text-muted-foreground hover:text-emerald-600 transition-colors"
                                title="Aktifkan"
                            >
                                <ToggleLeft class="w-4 h-4" />
                            </button>
                            <button
                                @click="deleteBudget(budget)"
                                class="p-2 rounded-lg hover:bg-red-500/10 text-muted-foreground hover:text-red-600 transition-colors"
                                title="Hapus"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 w-full max-w-md shadow-2xl">
                <h3 class="text-xl font-bold mb-6 text-gray-900 dark:text-white">Tambah Budget Baru</h3>
                <form @submit.prevent="submitCreate" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Kategori</label>
                        <select
                            v-model="createForm.category_id"
                            required
                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500"
                        >
                            <option value="">Pilih Kategori</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                {{ cat.icon }} {{ cat.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Jumlah Budget</label>
                        <input
                            v-model="createForm.amount"
                            type="number"
                            required
                            min="0"
                            step="1000"
                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500"
                            placeholder="500000"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Periode</label>
                        <select
                            v-model="createForm.period"
                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500"
                        >
                            <option value="daily">Harian</option>
                            <option value="weekly">Mingguan</option>
                            <option value="monthly">Bulanan</option>
                            <option value="yearly">Tahunan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Alert Threshold (%)</label>
                        <input
                            v-model="createForm.alert_threshold"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500"
                        />
                        <p class="text-xs text-gray-500 mt-1">Notifikasi akan muncul saat mencapai persentase ini</p>
                    </div>
                    <div class="flex gap-3 justify-end mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button
                            type="button"
                            @click="closeCreateModal"
                            class="px-6 py-2.5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition-colors font-medium"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="px-6 py-2.5 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20 font-medium"
                        >
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 w-full max-w-md shadow-2xl">
                <h3 class="text-xl font-bold mb-6 text-gray-900 dark:text-white">Edit Budget</h3>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Jumlah Budget</label>
                        <input
                            v-model="editForm.amount"
                            type="number"
                            required
                            min="0"
                            step="1000"
                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Alert Threshold (%)</label>
                        <input
                            v-model="editForm.alert_threshold"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:border-emerald-500 focus:ring-emerald-500"
                        />
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="editForm.alert_enabled"
                            type="checkbox"
                            class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500"
                        />
                        <label class="text-sm text-gray-700 dark:text-gray-300">Aktifkan Alert</label>
                    </div>
                    <div class="flex gap-3 justify-end mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button
                            type="button"
                            @click="closeEditModal"
                            class="px-6 py-2.5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition-colors font-medium"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="px-6 py-2.5 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 disabled:opacity-50 transition-all shadow-lg shadow-emerald-500/20 font-medium"
                        >
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
