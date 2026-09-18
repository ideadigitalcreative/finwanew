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
    const icons: Record<string, string> = {
        bank: 'account_balance',
        cash: 'payments',
        wallet: 'account_balance_wallet',
        investment: 'trending_up',
        other: 'briefcase',
    };
    return icons[type] || 'briefcase';
};

const getTypeBadgeStyle = (type: string) => {
    const styles: Record<string, string> = {
        bank: 'bg-[#ffe089] text-[#241a00]',
        cash: 'bg-[#51fac1] text-[#007152]',
        wallet: 'bg-[#ffd23f] text-[#574500]',
        investment: 'bg-[#ffc9d0] text-[#ad2c4f]',
        other: 'bg-[#eae8e2] text-[#4d4634]',
    };
    return styles[type] || 'bg-[#eae8e2] text-[#4d4634]';
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

        <div class="bg-white dark:bg-[#23231f] flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-hidden p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ffd23f] text-2xl md:text-3xl">account_balance_wallet</span>
                        Saldo Akun
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634]/60 mt-1">
                        Kelola saldo akun bank, cash, dan dompet digital Anda
                    </p>
                </div>
                <button 
                    @click="openDialog()" 
                    class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-all w-full md:w-auto"
                >
                    <span class="material-symbols-outlined text-lg">add</span>
                    Tambah Saldo Akun
                </button>
            </div>

            <!-- Total Balance Card -->
            <div class="relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-5 border border-[#eae8e2] dark:border-white/10 flex flex-col justify-between font-['Plus_Jakarta_Sans',sans-serif] transition-all duration-300">
                <div class="absolute -right-12 -top-12 w-48 h-48 rounded-full bg-[#ffd23f]/30 blur-2xl pointer-events-none"></div>
                <div class="absolute -left-10 -bottom-10 w-40 h-40 rounded-full bg-[#51fac1]/40 blur-2xl pointer-events-none"></div>
                <div class="relative flex flex-col gap-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-[#ffd23f] text-[#574500]">
                                <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
                            </span>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634] dark:text-[#b0ad9e]">Total Saldo Keseluruhan</span>
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-[#51fac1] text-[#007152] text-[10px] font-extrabold">
                            <span class="material-symbols-outlined text-[14px]">account_balance_wallet</span>
                            {{ activeBalances.length }} Dompet
                        </span>
                    </div>
                    <div class="flex flex-wrap items-baseline gap-2.5">
                        <span class="text-3xl md:text-[40px] font-extrabold tracking-tight text-[#1b1c19] dark:text-white leading-none">
                            {{ formatCurrency(totalBalance) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Balances List -->
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="balance in activeBalances"
                    :key="balance.id"
                    class="group relative overflow-hidden rounded-2xl bg-white dark:bg-[#23231f] p-6 border border-[#eae8e2] dark:border-white/10 transition-all"
                    :class="{ 'ring-2 ring-[#ffd23f]/50': balance.is_default }"
                >
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div :class="['w-12 h-12 rounded-xl flex items-center justify-center', getTypeBadgeStyle(balance.account_type)]">
                                <span class="material-symbols-outlined text-2xl">{{ getAccountTypeIcon(balance.account_type) }}</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-[#1b1c19]">{{ balance.account_name }}</h3>
                                <p class="text-xs text-[#4d4634]/60">{{ getAccountTypeLabel(balance.account_type) }}</p>
                            </div>
                        </div>
                        <div v-if="balance.is_default" class="rounded-full bg-[#51fac1]/30 px-2.5 py-0.5 text-xs font-medium text-[#006c4f]">
                            Utama
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <p class="text-2xl font-bold text-[#1b1c19]">{{ formatCurrency(balance.balance) }}</p>
                        <p class="text-xs text-[#4d4634]/50 mt-1">
                            {{ balance.account_number ? `No. ${balance.account_number} • ` : '' }} {{ balance.currency }}
                        </p>
                    </div>

                    <div class="flex items-center justify-between border-t border-[#eae8e2] pt-4">
                        <p class="text-xs text-[#4d4634]/50">Update: {{ formatDate(balance.balance_date) }}</p>
                        <div class="flex gap-2">
                            <button 
                                @click="openDialog(balance)"
                                class="p-1.5 text-[#4d4634]/40 hover:text-[#725a00] hover:bg-[#f5f3ee] rounded-lg transition-colors"
                                title="Edit"
                            >
                                <span class="material-symbols-outlined text-lg">edit</span>
                            </button>
                            <button 
                                v-if="!balance.is_default"
                                @click="setDefaultBalance(balance)"
                                class="p-1.5 text-[#4d4634]/40 hover:text-[#725a00] hover:bg-[#f5f3ee] rounded-lg transition-colors"
                                title="Jadikan Utama"
                            >
                                <span class="material-symbols-outlined text-lg">check</span>
                            </button>
                            <button 
                                @click="deleteBalance(balance)"
                                class="p-1.5 text-[#4d4634]/40 hover:text-[#ad2c4f] hover:bg-[#ffc9d0]/30 rounded-lg transition-colors"
                                title="Hapus"
                            >
                                <span class="material-symbols-outlined text-lg">delete</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Add New Card Placeholder -->
                <button
                    @click="openDialog()"
                    class="group flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee]/50 dark:bg-white/5 p-6 transition-all hover:border-[#ffd23f] hover:bg-[#ffd23f]/10 h-full min-h-[200px]"
                >
                    <div class="mb-3 rounded-xl bg-white dark:bg-[#23231f] p-4 group-hover:scale-110 transition-transform border border-[#eae8e2] dark:border-white/10">
                        <span class="material-symbols-outlined text-2xl text-[#4d4634]/40 group-hover:text-[#ffd23f] transition-colors">add</span>
                    </div>
                    <p class="font-medium text-[#4d4634]/60 group-hover:text-[#725a00]">Tambah Akun Baru</p>
                </button>
            </div>

            <!-- Legacy soft-deactivated accounts (before permanent delete); can be removed or edited -->
            <div v-if="balances.filter(b => !b.is_active).length > 0" class="mt-8">
                <h3 class="mb-4 text-lg font-bold text-[#1b1c19] dark:text-white">Akun Nonaktif (data lama)</h3>
                <p class="mb-4 text-sm text-[#4d4634]/60 dark:text-white/50">
                    Akun yang sebelumnya dinonaktifkan. Anda bisa menghapus permanen atau mengaktifkan kembali lewat Edit.
                </p>
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="balance in balances.filter(b => !b.is_active)"
                        :key="balance.id"
                        class="rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 p-6 opacity-70"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <h4 class="font-semibold text-[#4d4634] dark:text-white/70">{{ balance.account_name }}</h4>
                                <p class="text-sm text-[#4d4634]/60 dark:text-white/50">{{ formatCurrency(balance.balance) }}</p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <button
                                    @click="openDialog(balance)"
                                    class="rounded-lg bg-white dark:bg-[#23231f] px-3 py-1.5 text-xs font-medium text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/10"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    @click="deleteBalance(balance)"
                                    class="rounded-lg p-1.5 text-[#4d4634]/40 hover:bg-[#ffc9d0]/30 hover:text-[#ad2c4f]"
                                    title="Hapus permanen"
                                >
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialog Form -->
        <Dialog v-model:open="isDialogOpen">
            <DialogContent class="!max-w-[95vw] sm:!max-w-[500px] !max-h-[90vh] overflow-y-auto rounded-2xl p-0 gap-0 overflow-hidden bg-white">
                <DialogHeader class="p-6 pb-0">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19]">{{ dialogTitle }}</DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]/60">
                        {{ editingBalance ? 'Perbarui informasi saldo akun' : 'Tambahkan saldo akun baru' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="p-6">
                    <form @submit.prevent="submitForm" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="account_name" class="text-sm font-medium text-[#4d4634]">Nama Akun *</Label>
                            <Input
                                id="account_name"
                                v-model="form.account_name"
                                placeholder="Contoh: Bank BCA, Cash, GoPay"
                                required
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="form.errors.account_name" class="text-xs text-[#ad2c4f]">
                                {{ form.errors.account_name }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="account_number" class="text-sm font-medium text-[#4d4634]">Nomor Akun</Label>
                            <Input
                                id="account_number"
                                v-model="form.account_number"
                                placeholder="Opsional: Nomor rekening"
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="form.errors.account_number" class="text-xs text-[#ad2c4f]">
                                {{ form.errors.account_number }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="account_type" class="text-sm font-medium text-[#4d4634]">Tipe Akun *</Label>
                            <select
                                id="account_type"
                                v-model="form.account_type"
                                class="w-full rounded-xl border-[#eae8e2] bg-[#f5f3ee] px-3 py-2 text-sm focus:border-[#ffd23f] focus:ring-[#ffd23f] text-[#1b1c19]"
                                required
                            >
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                                <option value="wallet">Dompet Digital</option>
                                <option value="investment">Investasi</option>
                                <option value="other">Lainnya</option>
                            </select>
                            <p v-if="form.errors.account_type" class="text-xs text-[#ad2c4f]">
                                {{ form.errors.account_type }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Label for="currency" class="text-sm font-medium text-[#4d4634]">Mata Uang</Label>
                                <Input
                                    id="currency"
                                    v-model="form.currency"
                                    placeholder="IDR"
                                    maxlength="3"
                                    class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                                />
                                <p v-if="form.errors.currency" class="text-xs text-[#ad2c4f]">
                                    {{ form.errors.currency }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="balance" class="text-sm font-medium text-[#4d4634]">Saldo *</Label>
                                <Input
                                    id="balance"
                                    v-model.number="form.balance"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="0"
                                    required
                                    class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                                />
                                <p v-if="form.errors.balance" class="text-xs text-[#ad2c4f]">
                                    {{ form.errors.balance }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="balance_date" class="text-sm font-medium text-[#4d4634]">Tanggal Update *</Label>
                            <Input
                                id="balance_date"
                                v-model="form.balance_date"
                                type="date"
                                required
                                class="rounded-xl border-[#eae8e2] bg-[#f5f3ee] focus:ring-[#ffd23f] focus:border-[#ffd23f]"
                            />
                            <p v-if="form.errors.balance_date" class="text-xs text-[#ad2c4f]">
                                {{ form.errors.balance_date }}
                            </p>
                        </div>

                        <div class="flex items-center space-x-2 rounded-xl bg-[#f5f3ee] p-3">
                            <input
                                id="is_default"
                                v-model="form.is_default"
                                type="checkbox"
                                class="h-4 w-4 rounded border-[#eae8e2] text-[#ffd23f] focus:ring-[#ffd23f]"
                            />
                            <div class="flex flex-col">
                                <Label for="is_default" class="text-sm font-medium text-[#1b1c19] cursor-pointer">
                                    Jadikan Dompet Utama
                                </Label>
                                <span class="text-xs text-[#4d4634]/60">Digunakan untuk transaksi tanpa akun spesifik</span>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3 pt-2">
                            <button
                                type="button"
                                @click="closeDialog"
                                class="w-full rounded-xl border border-[#eae8e2] bg-white px-4 py-2.5 text-sm font-semibold text-[#4d4634] shadow-sm hover:bg-[#f5f3ee] sm:order-1"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] shadow-sm disabled:opacity-50 sm:order-2"
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

