<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Receipt, Upload, Download, Pencil, Trash2, Check, X } from 'lucide-vue-next';

interface Props {
    transactions: {
        data: Array<{
            id: number;
            type: string;
            amount: number;
            transaction_date: string;
            description: string;
            source: string | null;
            status: string;
            confidence_score: number;
            category: {
                id: number;
                name: string;
                type: string;
            };
        }>;
        links: any;
        meta: any;
    };
    categories: Array<{
        id: number;
        name: string;
        type: string;
    }>;
    filters: {
        type?: string;
        category_id?: string;
        status?: string;
        start_date?: string;
        end_date?: string;
        search?: string;
    };
    transactionLimit?: {
        can_create: boolean;
        current: number;
        limit: number;
        remaining: number;
        plan: string;
        is_unlimited: boolean;
    };
}

const props = defineProps<Props>();
const { showError, showSuccess, showDeleteConfirm } = useSweetAlert();

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
    // Use string parsing to avoid timezone shifts (e.g., from YYYY-MM-DD)
    const d = new Date(date);
    // If browser interpreted '2026-04-10' as UTC, and we are in GMT-7, it becomes 9th.
    // Instead, if the date string is just YYYY-MM-DD, it's safer to use format(parseISO(date)...)
    // but the simplest way is to ensure we format the date correctly.
    try {
        return format(d, 'dd MMM yyyy', { locale: id });
    } catch (e) {
        return date;
    }
};

const form = useForm({
    type: props.filters.type || '',
    category_id: props.filters.category_id || '',
    status: props.filters.status || '',
    start_date: props.filters.start_date || '',
    end_date: props.filters.end_date || '',
    search: props.filters.search || '',
});

const exportForm = useForm({
    format: 'excel',
    start_date: '',
    end_date: '',
    type: '',
    status: '',
});

const importForm = useForm({
    file: null as File | null,
    skip_header: true,
});

const showExportModal = ref(false);
const showImportModal = ref(false);
const showEditModal = ref(false);
const selectedTransaction = ref<any>(null);

const editForm = useForm({
    type: 'expense',
    amount: 0,
    transaction_date: '',
    description: '',
    source: '',
    category_id: '',
    status: 'confirmed',
});

const applyFilters = () => {
    form.get('/transactions', {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    form.reset();
    applyFilters();
};

const getCsrfToken = (): string => {
    // Method 1: From meta tag (most reliable and always up-to-date)
    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (metaToken) {
        return metaToken;
    }
    
    // Method 2: From XSRF-TOKEN cookie (need to decode)
    const xsrfCookie = document.cookie
        .split('; ')
        .find(row => row.startsWith('XSRF-TOKEN='));
    if (xsrfCookie) {
        try {
            const token = xsrfCookie.split('=')[1];
            // Decode URL-encoded token
            return decodeURIComponent(token);
        } catch (e) {
            console.warn('Failed to decode XSRF-TOKEN cookie:', e);
        }
    }
    
    return '';
};

const exportData = async () => {
    // Get fresh CSRF token
    const csrfToken = getCsrfToken();
    
    if (!csrfToken) {
        showError('Gagal Mengekspor Data', 'CSRF token tidak ditemukan. Silakan refresh halaman dan coba lagi.');
        return;
    }
    
    // Create form data
    const formData = new FormData();
    formData.append('_token', csrfToken); // Add _token to form data as fallback
    formData.append('format', exportForm.format);
    if (exportForm.start_date) formData.append('start_date', exportForm.start_date);
    if (exportForm.end_date) formData.append('end_date', exportForm.end_date);
    if (exportForm.type) formData.append('type', exportForm.type);
    if (exportForm.status) formData.append('status', exportForm.status);
    
    try {
        // Use fetch to download file
        const response = await fetch('/export/transactions', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': exportForm.format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            },
            credentials: 'same-origin',
            body: formData,
        });
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Export error response:', text);
            console.error('Response status:', response.status);
            console.error('CSRF token used:', csrfToken.substring(0, 20) + '...');
            
            // If it's a 419 error, suggest refreshing
            if (response.status === 419) {
                throw new Error('Session expired atau CSRF token tidak valid. Silakan refresh halaman dan coba lagi.');
            }
            
            throw new Error(`Export failed: ${response.status} ${response.statusText}`);
        }
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const extension = exportForm.format === 'pdf' ? 'pdf' : 'xlsx';
        a.download = `transactions_${new Date().toISOString().split('T')[0]}.${extension}`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        showExportModal.value = false;
        exportForm.reset();
    } catch (error: any) {
        console.error('Export error:', error);
        showError('Gagal Mengekspor Data', error.message || 'Terjadi kesalahan saat mengekspor data. Silakan coba lagi.');
    }
};

const importData = () => {
    if (!importForm.file) {
        return;
    }

    importForm.post('/import/transactions', {
        preserveState: false,
        preserveScroll: false,
        onSuccess: () => {
            showImportModal.value = false;
            importForm.reset();
        },
    });
};

const updateStatus = (transactionId: number, status: string) => {
    router.patch(`/transactions/${transactionId}/status`, {
        status,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const openEditModal = (transaction: any) => {
    try {
        console.log('Opening edit modal for transaction:', transaction);
        selectedTransaction.value = transaction;
        editForm.type = transaction.type || 'expense';
        editForm.amount = transaction.amount || 0;
        
        // Handle date format - ensure consistency with the display list
        // We use the Local Date parts (Year, Month, Day) from the browser to match what's shown in the table
        let dateValue = transaction.transaction_date;
        if (dateValue) {
            const d = new Date(dateValue);
            if (!isNaN(d.getTime())) {
                // Extract local date parts to avoid UTC shift (the "10th vs 9th" issue)
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                editForm.transaction_date = `${y}-${m}-${day}`;
            } else {
                // Last resort fallback: raw string extraction
                const match = dateValue.match(/^(\d{4}-\d{2}-\d{2})/);
                if (match) {
                    editForm.transaction_date = match[1];
                } else {
                    editForm.transaction_date = dateValue;
                }
            }
        } else {
            // Use local today's date
            const today = new Date();
            const y = today.getFullYear();
            const m = String(today.getMonth() + 1).padStart(2, '0');
            const d = String(today.getDate()).padStart(2, '0');
            editForm.transaction_date = `${y}-${m}-${d}`;
        }
        
        editForm.description = transaction.description || '';
        editForm.source = transaction.source || '';
        // Ensure category_id is set correctly - could be number or string
        const categoryId = transaction.category?.id;
        editForm.category_id = categoryId ? String(categoryId) : '';
        editForm.status = transaction.status || 'confirmed';
        
        console.log('Edit form data:', editForm.data());
        showEditModal.value = true;
        console.log('Modal should be visible now, showEditModal:', showEditModal.value);
    } catch (error) {
        console.error('Error opening edit modal:', error);
        showError('Error', 'Gagal membuka form edit. Silakan coba lagi.');
    }
};

const updateTransaction = () => {
    if (!selectedTransaction.value) return;
    
    editForm.put(`/transactions/${selectedTransaction.value.id}`, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            selectedTransaction.value = null;
            editForm.reset();
            showSuccess('Transaksi Berhasil Diperbarui', 'Data transaksi telah berhasil diperbarui.');
        },
        onError: (errors) => {
            showError('Gagal Memperbarui Transaksi', Object.values(errors).flat().join(', ') || 'Terjadi kesalahan saat memperbarui transaksi.');
        },
    });
};

const deleteTransaction = async (transactionId: number, description: string) => {
    const confirmed = await showDeleteConfirm(
        'Hapus Transaksi?',
        `Apakah Anda yakin ingin menghapus transaksi "${description}"? Tindakan ini tidak dapat dibatalkan.`
    );
    
    if (confirmed) {
        router.delete(`/transactions/${transactionId}`, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                showSuccess('Transaksi Berhasil Dihapus', 'Transaksi telah berhasil dihapus.');
            },
            onError: () => {
                showError('Gagal Menghapus Transaksi', 'Terjadi kesalahan saat menghapus transaksi.');
            },
        });
    }
};

watch(() => form.type, applyFilters);
watch(() => form.category_id, applyFilters);
watch(() => form.status, applyFilters);

// Auto-refresh transactions every 15 seconds to show latest data
let refreshInterval: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    // Refresh transactions every 15 seconds to show latest data
    refreshInterval = setInterval(() => {
        router.reload({
            only: ['transactions']
        });
    }, 15000); // 15 seconds
});

onBeforeUnmount(() => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});
</script>

<template>
    <Head title="Transaksi" />

    <AppLayout>
        <div class="bg-background flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-hidden p-4 md:p-6">
            <!-- Free Plan Transaction Limit Banner -->
            <div v-if="transactionLimit && !transactionLimit.is_unlimited" class="rounded-xl border p-4" :class="transactionLimit.can_create ? 'bg-cyan-50 border-cyan-200 dark:bg-cyan-950/30 dark:border-cyan-800' : 'bg-red-50 border-red-200 dark:bg-red-950/30 dark:border-red-800'">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <div class="text-2xl">{{ transactionLimit.can_create ? '📊' : '⚠️' }}</div>
                        <div>
                            <p class="text-sm font-semibold" :class="transactionLimit.can_create ? 'text-cyan-800 dark:text-cyan-200' : 'text-red-800 dark:text-red-200'">
                                {{ transactionLimit.can_create ? 'Paket Gratis' : 'Batas Transaksi Tercapai' }}
                            </p>
                            <p class="text-xs" :class="transactionLimit.can_create ? 'text-cyan-600 dark:text-cyan-400' : 'text-red-600 dark:text-red-400'">
                                {{ transactionLimit.current }}/{{ transactionLimit.limit }} transaksi bulan ini
                                <span v-if="transactionLimit.can_create"> &mdash; sisa {{ transactionLimit.remaining }} transaksi</span>
                            </p>
                        </div>
                    </div>
                    <Link href="/subscriptions" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 transition-colors shadow-sm">
                        Upgrade Paket
                    </Link>
                </div>
                <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                    <div class="h-1.5 rounded-full transition-all duration-500" :class="transactionLimit.can_create ? 'bg-cyan-500' : 'bg-red-500'" :style="{ width: Math.min((transactionLimit.current / transactionLimit.limit) * 100, 100) + '%' }"></div>
                </div>
            </div>

            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <Receipt class="w-6 h-6 md:w-7 md:h-7 text-emerald-600" />
                        Daftar Transaksi
                    </h2>
                    <p class="text-xs md:text-sm text-gray-500 mt-1">Kelola semua transaksi keuangan Anda</p>
                </div>
                <div class="flex gap-3">
                    <button
                        @click="showImportModal = true"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200/50 dark:border-gray-700/30 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-all"
                    >
                        <Upload class="w-4 h-4" />
                        Import
                    </button>
                    <button
                        @click="showExportModal = true"
                        class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium text-white shadow-lg shadow-emerald-500/20 bg-emerald-600 hover:bg-emerald-700 transition-all"
                    >
                        <Download class="w-4 h-4" />
                        Export
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-6 border border-gray-200/50 dark:border-gray-700/30">
                <div class="grid gap-3 grid-cols-2 md:grid-cols-6">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Tipe</label>
                        <select
                            v-model="form.type"
                            class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="">Semua</option>
                            <option value="income">Pendapatan</option>
                            <option value="expense">Pengeluaran</option>
                            <option value="debit_internal">Debit Antar Dompet</option>
                            <option value="kredit_internal">Kredit Antar Dompet</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Kategori</label>
                        <select
                            v-model="form.category_id"
                            class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="">Semua</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                    <div class="hidden md:block">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                        <select
                            v-model="form.status"
                            class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="">Semua</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="review">Review</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Mulai</label>
                        <input
                            v-model="form.start_date"
                            type="date"
                            class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            @change="applyFilters"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Akhir</label>
                        <input
                            v-model="form.end_date"
                            type="date"
                            class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            @change="applyFilters"
                        />
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Pencarian</label>
                        <input
                            v-model="form.search"
                            type="text"
                            placeholder="Cari..."
                            class="w-full rounded-xl border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button
                        @click="resetFilters"
                        class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        Reset Filter
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] border border-gray-200/50 dark:border-gray-700/30 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Tanggal</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Deskripsi</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Kategori</th>
                                <th class="hidden md:table-cell px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sumber</th>
                                <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Jumlah</th>
                                <th class="hidden md:table-cell px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 w-[180px]">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr
                                v-for="transaction in transactions.data"
                                :key="transaction.id"
                                class="hover:bg-gray-50/50 dark:hover:bg-gray-700/50 transition-colors"
                            >
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ formatDate(transaction.transaction_date) }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <Link
                                        :href="`/transactions/${transaction.id}`"
                                        class="font-medium text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                    >
                                        {{ transaction.description }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ transaction.category?.name || '-' }}</td>
                                <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ transaction.source || '-' }}</td>
                                <td
                                    class="px-6 py-4 text-right text-sm font-bold"
                                    :class="transaction.type === 'income' || transaction.type === 'kredit_internal' ? 'text-green-600' : 'text-red-600'"
                                >
                                    {{ transaction.type === 'income' || transaction.type === 'kredit_internal' ? '+' : '-' }}{{ formatCurrency(transaction.amount) }}
                                </td>
                                <td class="hidden md:table-cell px-6 py-4 text-center">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400': transaction.status === 'confirmed',
                                            'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': transaction.status === 'review',
                                            'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400': transaction.status === 'rejected',
                                        }"
                                    >
                                        {{ transaction.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center whitespace-nowrap w-[180px]">
                                    <div class="flex justify-center items-center gap-2">
                                        <button
                                            @click="openEditModal(transaction)"
                                            class="p-1.5 text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 rounded-lg transition-colors dark:text-gray-400 dark:hover:text-emerald-400"
                                            title="Edit Transaksi"
                                        >
                                            <Pencil class="w-4 h-4" />
                                        </button>
                                        <button
                                            @click="deleteTransaction(transaction.id, transaction.description)"
                                            class="p-1.5 text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors dark:text-gray-400 dark:hover:text-red-400"
                                            title="Hapus Transaksi"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-if="transaction.status === 'review'"
                                            @click="updateStatus(transaction.id, 'confirmed')"
                                            class="p-1.5 text-gray-500 hover:text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors dark:text-gray-400 dark:hover:text-green-400"
                                            title="Setujui Transaksi"
                                        >
                                            <Check class="w-4 h-4" />
                                        </button>
                                        <button
                                            v-if="transaction.status === 'review'"
                                            @click="updateStatus(transaction.id, 'rejected')"
                                            class="p-1.5 text-gray-500 hover:text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-900/30 rounded-lg transition-colors dark:text-gray-400 dark:hover:text-orange-400"
                                            title="Tolak Transaksi"
                                        >
                                            <X class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="transactions.data.length === 0" class="md:hidden">
                                <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="mb-3 rounded-full bg-gray-100 p-3 dark:bg-gray-800">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                        </div>
                                        <p class="font-medium">Tidak ada transaksi</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="transactions.data.length === 0" class="hidden md:table-row">
                                <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="mb-3 rounded-full bg-gray-100 p-3 dark:bg-gray-800">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                        </div>
                                        <p class="font-medium">Tidak ada transaksi ditemukan</p>
                                        <p class="text-xs mt-1">Coba ubah filter atau tambah transaksi baru</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="transactions.links && transactions.links.length > 3" class="border-t border-gray-100 px-4 md:px-6 py-4 dark:border-gray-700">
                    <!-- Mobile Pagination (Simple) -->
                    <div class="flex md:hidden items-center justify-between">
                        <Link
                            :href="transactions.links[0]?.url || '#'"
                            :class="[
                                'rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                                !transactions.links[0]?.url ? 'text-gray-400 cursor-not-allowed' : 'text-gray-700 bg-gray-100 dark:bg-gray-700 dark:text-gray-200'
                            ]"
                        >
                            ← Prev
                        </Link>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ transactions.meta?.current_page || 1 }} / {{ transactions.meta?.last_page || 1 }}
                        </span>
                        <Link
                            :href="transactions.links[transactions.links.length - 1]?.url || '#'"
                            :class="[
                                'rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                                !transactions.links[transactions.links.length - 1]?.url ? 'text-gray-400 cursor-not-allowed' : 'text-gray-700 bg-gray-100 dark:bg-gray-700 dark:text-gray-200'
                            ]"
                        >
                            Next →
                        </Link>
                    </div>
                    
                    <!-- Desktop Pagination (Full) -->
                    <div class="hidden md:flex items-center justify-between">
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Menampilkan {{ transactions.meta?.from || 0 }} hingga {{ transactions.meta?.to || 0 }} dari {{ transactions.meta?.total || 0 }} transaksi
                        </div>
                        <div class="flex gap-2">
                            <Link
                                v-for="link in (transactions.links || [])"
                                :key="link.label"
                                :href="link.url || '#'"
                                :class="[
                                    'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                    link.active
                                        ? 'text-white shadow-sm'
                                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700',
                                    !link.url ? 'cursor-not-allowed opacity-50' : ''
                                ]"
                                :style="link.active ? 'background-color: oklch(0.65 0.19 137.46);' : ''"
                            >
                                <span v-html="link.label" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Modal -->
        <div
            v-if="showExportModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="showExportModal = false"
        >
            <div class="w-full max-w-md rounded-lg bg-card p-6 shadow-lg">
                <h3 class="mb-4 text-lg font-semibold">Export Transaksi</h3>
                <form @submit.prevent="exportData">
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Format</label>
                            <select v-model="exportForm.format" class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border">
                                <option value="excel">Excel (XLSX)</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Tanggal Mulai</label>
                                <input v-model="exportForm.start_date" type="date" class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Tanggal Akhir</label>
                                <input v-model="exportForm.end_date" type="date" class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Tipe</label>
                                <select v-model="exportForm.type" class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border">
                                    <option value="">Semua</option>
                                    <option value="income">Pendapatan</option>
                                    <option value="expense">Pengeluaran</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Status</label>
                                <select v-model="exportForm.status" class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border">
                                    <option value="">Semua</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="review">Review</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                @click="showExportModal = false"
                                class="rounded-lg border border-sidebar-border/70 bg-card px-4 py-2 text-sm font-medium hover:bg-accent dark:border-sidebar-border"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                class="rounded-lg px-4 py-2 text-sm font-medium text-white hover:opacity-90 transition-opacity"
                                style="background-color: oklch(0.65 0.19 137.46);"
                            >
                                Export
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Import Modal -->
        <div
            v-if="showImportModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="showImportModal = false"
        >
            <div class="w-full max-w-md rounded-lg bg-card p-6 shadow-lg">
                <h3 class="mb-4 text-lg font-semibold">Import Transaksi</h3>
                <form @submit.prevent="importData">
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium">File (CSV/XLSX)</label>
                            <input
                                type="file"
                                accept=".csv,.xlsx,.xls"
                                @input="importForm.file = ($event.target as HTMLInputElement).files?.[0] || null"
                                class="w-full rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-sm dark:border-sidebar-border"
                                required
                            />
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="importForm.skip_header"
                                    type="checkbox"
                                    class="rounded"
                                />
                                <span class="text-sm">Skip header row</span>
                            </label>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                @click="showImportModal = false"
                                class="rounded-lg border border-sidebar-border/70 bg-card px-4 py-2 text-sm font-medium hover:bg-accent dark:border-sidebar-border"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="!importForm.file"
                                class="rounded-lg px-4 py-2 text-sm font-medium text-white hover:opacity-90 transition-opacity disabled:opacity-50"
                                style="background-color: oklch(0.65 0.19 137.46);"
                            >
                                Import
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Modal (Simplified) -->
        <div
            v-if="showEditModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="showEditModal = false"
        >
            <div class="w-full max-w-md rounded-2xl bg-white dark:bg-gray-800 p-6 shadow-2xl border border-gray-200 dark:border-gray-700">
                <h3 class="mb-6 text-xl font-bold text-gray-900 dark:text-white">Edit Transaksi</h3>
                <form @submit.prevent="updateTransaction" class="space-y-5">
                    <!-- Tipe & Tanggal -->
                    <div class="grid gap-4 grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Tipe</label>
                            <select
                                v-model="editForm.type"
                                required
                                class="w-full rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:text-white"
                            >
                                <option value="income">💰 Pendapatan</option>
                                <option value="expense">💸 Pengeluaran</option>
                                <option value="debit_internal">🔄 Debit Antar Dompet</option>
                                <option value="kredit_internal">🔄 Kredit Antar Dompet</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal</label>
                            <input
                                v-model="editForm.transaction_date"
                                type="date"
                                required
                                class="w-full rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:text-white"
                            />
                        </div>
                    </div>
                    
                    <!-- Jumlah -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Jumlah (Rp)</label>
                        <input
                            v-model.number="editForm.amount"
                            type="number"
                            min="0"
                            required
                            placeholder="50000"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:text-white"
                        />
                    </div>
                    
                    <!-- Deskripsi -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Deskripsi</label>
                        <input
                            v-model="editForm.description"
                            type="text"
                            required
                            placeholder="Contoh: Makan siang"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:text-white"
                        />
                    </div>
                    
                    <!-- Kategori -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Kategori</label>
                        <select
                            v-model="editForm.category_id"
                            required
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:text-white"
                        >
                            <option value="">Pilih Kategori</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="flex justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="showEditModal = false; editForm.reset(); selectedTransaction = null"
                            class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-5 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="rounded-xl px-5 py-2.5 text-sm font-medium text-white shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/30 hover:opacity-90 transition-all disabled:opacity-50"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            {{ editForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

