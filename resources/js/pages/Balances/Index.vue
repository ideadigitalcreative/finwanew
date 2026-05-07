<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ref, computed } from 'vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type BreadcrumbItem } from '@/types';
import { Wallet, Plus, Pencil, Trash2, Check, Coins, Landmark, Banknote, Smartphone, TrendingUp, Briefcase } from 'lucide-vue-next';

interface Balance {
    id: number;
    account_name: string;
    account_number: string | null;
    account_type: 'bank' | 'cash' | 'wallet' | 'investment' | 'other';
    currency: string;
    balance: number;
    balance_date: string;
    is_active: boolean;
    is_default: boolean;
}

interface Props {
    tenant_id: number;
    balances: Balance[];
}

const props = defineProps<Props>();
const { showError, showSuccess, showDeleteConfirm } = useSweetAlert();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Saldo Akun',
        href: '/balances',
    },
];

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatDate = (date: string) => {
    if (!date) return '-';
    return format(new Date(date), 'dd MMM yyyy', { locale: id });
};

const getAccountTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
        bank: 'Bank',
        cash: 'Cash',
        wallet: 'Dompet Digital',
        investment: 'Investasi',
        other: 'Lainnya',
    };
    return labels[type] || type;
};

const getAccountTypeIcon = (type: string) => {
    const icons: Record<string, any> = {
        bank: Landmark,
        cash: Banknote,
        wallet: Smartphone,
        investment: TrendingUp,
        other: Briefcase,
    };
    return icons[type] || Briefcase;
};

// Dialog state
const isDialogOpen = ref(false);
const editingBalance = ref<Balance | null>(null);
const isDeleting = ref(false);

// Form
const form = useForm({
    tenant_id: props.tenant_id,
    account_name: '',
    account_number: '',
    account_type: 'bank' as Balance['account_type'],
    currency: 'IDR',
    balance: 0,
    balance_date: new Date().toISOString().split('T')[0],
    is_default: false,
});

const dialogTitle = computed(() => {
    return editingBalance.value ? 'Edit Saldo Akun' : 'Tambah Saldo Akun';
});

const openDialog = (balance?: Balance) => {
    if (balance) {
        editingBalance.value = balance;
        form.account_name = balance.account_name;
        form.account_number = balance.account_number || '';
        form.account_type = balance.account_type;
        form.currency = balance.currency;
        form.balance = balance.balance;
        form.balance_date = balance.balance_date;
        form.is_default = balance.is_default || false;
        form.tenant_id = props.tenant_id;
    } else {
        editingBalance.value = null;
        form.reset();
        form.tenant_id = props.tenant_id;
        form.account_type = 'bank';
        form.currency = 'IDR';
        form.balance = 0;
        form.balance_date = new Date().toISOString().split('T')[0];
        form.is_default = false;
    }
    isDialogOpen.value = true;
};

const closeDialog = () => {
    isDialogOpen.value = false;
    editingBalance.value = null;
    form.reset();
};

const submitForm = () => {
    // Ensure tenant_id is always set
    form.tenant_id = props.tenant_id;
    
    if (editingBalance.value) {
        form.put(`/balances/${editingBalance.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showSuccess('Berhasil', 'Saldo akun berhasil diperbarui');
                closeDialog();
            },
            onError: (errors) => {
                showError('Error', 'Gagal memperbarui saldo akun');
            },
        });
    } else {
        form.post('/balances', {
            preserveScroll: true,
            onSuccess: () => {
                showSuccess('Berhasil', 'Saldo akun berhasil ditambahkan');
                closeDialog();
            },
            onError: (errors) => {
                showError('Error', 'Gagal menambahkan saldo akun');
            },
        });
    }
};

const setDefaultBalance = (balance: Balance) => {
    router.post(`/balances/${balance.id}/set-default`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', `${balance.account_name} berhasil dijadikan dompet utama`);
        },
        onError: () => {
            showError('Error', 'Gagal mengatur dompet utama');
        },
    });
};

const deleteBalance = (balance: Balance) => {
    showDeleteConfirm(
        'Hapus dompet permanen?',
        `Dompet "${balance.account_name}" akan dihapus dari database dan tidak bisa dikembalikan. Riwayat transaksi tetap ada, tetapi tidak lagi terhubung ke dompet ini.`
    ).then((confirmed) => {
        if (confirmed) {
            isDeleting.value = true;
            router.delete(`/balances/${balance.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    showSuccess('Berhasil', 'Dompet berhasil dihapus permanen');
                },
                onError: () => {
                    showError('Error', 'Gagal menghapus saldo akun');
                },
                onFinish: () => {
                    isDeleting.value = false;
                },
            });
        }
    });
};

const activeBalances = computed(() => {
    return props.balances.filter(b => b.is_active);
});

const totalBalance = computed(() => {
    return activeBalances.value.reduce((sum, balance) => sum + balance.balance, 0);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Saldo Akun" />

        <div class="bg-background flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-hidden p-4 md:p-6">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <Wallet class="w-6 h-6 md:w-7 md:h-7 text-emerald-600" />
                        Saldo Akun
                    </h2>
                    <p class="text-xs md:text-sm text-gray-500 mt-1">
                        Kelola saldo akun bank, cash, dan dompet digital Anda
                    </p>
                </div>
                <button 
                    @click="openDialog()" 
                    class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium text-white shadow-lg shadow-emerald-500/20 bg-emerald-600 hover:bg-emerald-700 transition-all w-full md:w-auto"
                >
                    <Plus class="w-4 h-4" />
                    Tambah Saldo Akun
                </button>
            </div>

            <!-- Total Balance Card -->
            <div class="bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-[13px] p-6 md:p-8 text-white border border-emerald-500/20">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-emerald-100">Total Saldo Keseluruhan</p>
                        <h3 class="mt-2 text-3xl md:text-4xl font-bold tracking-tight">
                            {{ formatCurrency(totalBalance) }}
                        </h3>
                    </div>
                    <div class="rounded-xl bg-white/10 backdrop-blur-sm p-3 md:p-4 border border-white/20">
                        <Coins class="w-6 h-6 md:w-8 md:h-8 text-white" />
                    </div>
                </div>
            </div>

            <!-- Balances List -->
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="balance in activeBalances"
                    :key="balance.id"
                    class="group relative overflow-hidden rounded-[13px] bg-card/60 backdrop-blur-2xl p-6 border border-gray-200/50 dark:border-gray-700/30 transition-all hover:bg-card/80"
                    :class="{ 'ring-2 ring-emerald-500/30': balance.is_default }"
                >
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl bg-emerald-500/10 backdrop-blur-sm flex items-center justify-center border border-emerald-500/20">
                                <component :is="getAccountTypeIcon(balance.account_type)" class="w-6 h-6 text-emerald-600" />
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900 dark:text-white">{{ balance.account_name }}</h3>
                                <p class="text-xs text-gray-500">{{ getAccountTypeLabel(balance.account_type) }}</p>
                            </div>
                        </div>
                        <div v-if="balance.is_default" class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                            Utama
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatCurrency(balance.balance) }}</p>
                        <p class="text-xs text-gray-400 mt-1">
                            {{ balance.account_number ? `No. ${balance.account_number} • ` : '' }} {{ balance.currency }}
                        </p>
                    </div>

                    <div class="flex items-center justify-between border-t border-gray-100 pt-4 dark:border-gray-700">
                        <p class="text-xs text-gray-400">Update: {{ formatDate(balance.balance_date) }}</p>
                        <div class="flex gap-2">
                            <button 
                                @click="openDialog(balance)"
                                class="p-1.5 text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors"
                                title="Edit"
                            >
                                <Pencil class="w-4 h-4" />
                            </button>
                            <button 
                                v-if="!balance.is_default"
                                @click="setDefaultBalance(balance)"
                                class="p-1.5 text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors"
                                title="Jadikan Utama"
                            >
                                <Check class="w-4 h-4" />
                            </button>
                            <button 
                                @click="deleteBalance(balance)"
                                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors"
                                title="Hapus"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Add New Card Placeholder -->
                <button
                    @click="openDialog()"
                    class="group flex flex-col items-center justify-center rounded-[13px] border-2 border-dashed border-gray-200/50 dark:border-gray-700/30 bg-muted/10 backdrop-blur-sm p-6 transition-all hover:border-emerald-500 hover:bg-emerald-50/50 dark:hover:border-emerald-500/50 dark:hover:bg-emerald-900/10 h-full min-h-[200px]"
                >
                    <div class="mb-3 rounded-xl bg-white dark:bg-gray-800 p-4 shadow-sm group-hover:scale-110 transition-transform border border-gray-200/50 dark:border-gray-700/30">
                        <Plus class="w-6 h-6 text-gray-400 group-hover:text-emerald-600 transition-colors" />
                    </div>
                    <p class="font-medium text-gray-600 group-hover:text-emerald-700 dark:text-gray-400 dark:group-hover:text-emerald-400">Tambah Akun Baru</p>
                </button>
            </div>

            <!-- Legacy soft-deactivated accounts (before permanent delete); can be removed or edited -->
            <div v-if="balances.filter(b => !b.is_active).length > 0" class="mt-8">
                <h3 class="mb-4 text-lg font-bold text-gray-800 dark:text-white">Akun Nonaktif (data lama)</h3>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    Akun yang sebelumnya dinonaktifkan. Anda bisa menghapus permanen atau mengaktifkan kembali lewat Edit.
                </p>
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="balance in balances.filter(b => !b.is_active)"
                        :key="balance.id"
                        class="rounded-2xl border border-gray-200 bg-gray-50 p-6 opacity-75 dark:border-gray-700 dark:bg-gray-800/50"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <h4 class="font-semibold text-gray-700 dark:text-gray-300">{{ balance.account_name }}</h4>
                                <p class="text-sm text-gray-500">{{ formatCurrency(balance.balance) }}</p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button
                                    @click="openDialog(balance)"
                                    class="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    @click="deleteBalance(balance)"
                                    class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                                    title="Hapus permanen"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialog Form -->
        <Dialog v-model:open="isDialogOpen">
            <DialogContent class="!max-w-[95vw] sm:!max-w-[500px] !max-h-[90vh] overflow-y-auto rounded-2xl p-0 gap-0 overflow-hidden bg-white dark:bg-gray-800">
                <DialogHeader class="p-6 pb-0">
                    <DialogTitle class="text-xl font-bold text-gray-900 dark:text-white">{{ dialogTitle }}</DialogTitle>
                    <DialogDescription class="text-sm text-gray-500">
                        {{ editingBalance ? 'Perbarui informasi saldo akun' : 'Tambahkan saldo akun baru' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="p-6">
                    <form @submit.prevent="submitForm" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="account_name" class="text-sm font-medium text-gray-700 dark:text-gray-300">Nama Akun *</Label>
                            <Input
                                id="account_name"
                                v-model="form.account_name"
                                placeholder="Contoh: Bank BCA, Cash, GoPay"
                                required
                                class="rounded-xl border-gray-200 bg-gray-50 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                            />
                            <p v-if="form.errors.account_name" class="text-xs text-red-600">
                                {{ form.errors.account_name }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="account_number" class="text-sm font-medium text-gray-700 dark:text-gray-300">Nomor Akun</Label>
                            <Input
                                id="account_number"
                                v-model="form.account_number"
                                placeholder="Opsional: Nomor rekening"
                                class="rounded-xl border-gray-200 bg-gray-50 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                            />
                            <p v-if="form.errors.account_number" class="text-xs text-red-600">
                                {{ form.errors.account_number }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="account_type" class="text-sm font-medium text-gray-700 dark:text-gray-300">Tipe Akun *</Label>
                            <select
                                id="account_type"
                                v-model="form.account_type"
                                class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                required
                            >
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                                <option value="wallet">Dompet Digital</option>
                                <option value="investment">Investasi</option>
                                <option value="other">Lainnya</option>
                            </select>
                            <p v-if="form.errors.account_type" class="text-xs text-red-600">
                                {{ form.errors.account_type }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="currency" class="text-sm font-medium text-gray-700 dark:text-gray-300">Mata Uang</Label>
                                <Input
                                    id="currency"
                                    v-model="form.currency"
                                    placeholder="IDR"
                                    maxlength="3"
                                    class="rounded-xl border-gray-200 bg-gray-50 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                                />
                                <p v-if="form.errors.currency" class="text-xs text-red-600">
                                    {{ form.errors.currency }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="balance" class="text-sm font-medium text-gray-700 dark:text-gray-300">Saldo *</Label>
                                <Input
                                    id="balance"
                                    v-model.number="form.balance"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="0"
                                    required
                                    class="rounded-xl border-gray-200 bg-gray-50 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                                />
                                <p v-if="form.errors.balance" class="text-xs text-red-600">
                                    {{ form.errors.balance }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="balance_date" class="text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Update *</Label>
                            <Input
                                id="balance_date"
                                v-model="form.balance_date"
                                type="date"
                                required
                                class="rounded-xl border-gray-200 bg-gray-50 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                            />
                            <p v-if="form.errors.balance_date" class="text-xs text-red-600">
                                {{ form.errors.balance_date }}
                            </p>
                        </div>

                        <div class="flex items-center space-x-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900">
                            <input
                                id="is_default"
                                v-model="form.is_default"
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
                            />
                            <div class="flex flex-col">
                                <Label for="is_default" class="text-sm font-medium text-gray-900 cursor-pointer dark:text-white">
                                    Jadikan Dompet Utama
                                </Label>
                                <span class="text-xs text-gray-500">Digunakan untuk transaksi tanpa akun spesifik</span>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 pt-2">
                            <button
                                type="button"
                                @click="closeDialog"
                                class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 sm:order-1"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:opacity-50 sm:order-2"
                                style="background-color: oklch(0.65 0.19 137.46);"
                            >
                                {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
                            </button>
                        </div>
                    </form>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

