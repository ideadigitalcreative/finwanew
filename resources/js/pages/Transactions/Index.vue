<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { format, parseISO, isToday, isYesterday, startOfMonth, endOfMonth } from 'date-fns';
import { id } from 'date-fns/locale';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Receipt, Upload, Download, Pencil, Trash2, Check, X, Paperclip } from 'lucide-vue-next';

interface Props {
    transactions: {
        data: Array<{
            id: number;
            type: string;
            amount: number;
            transaction_date: string | null;
            created_at?: string | null;
            description: string;
            source: string | null;
            status: string;
            confidence_score: number;
            category: {
                id: number;
                name: string;
                type: string;
            };
            message?: any;
            metadata?: any;
            whatsappNumber?: {
                id: number;
                whatsapp_number: string;
                name: string | null;
                is_primary: boolean;
                is_lid: boolean;
            } | null;
        }>;
        links: any;
        meta: any;
    };
    categories: Array<{
        id: number;
        name: string;
        type: string;
    }>;
    whatsappNumbers?: Array<{
        id: number;
        whatsapp_number: string;
        name: string | null;
        is_primary: boolean;
        is_lid: boolean;
    }>;
    filters: {
        type?: string;
        category_id?: string;
        status?: string;
        start_date?: string;
        end_date?: string;
        search?: string;
        number_id?: string;
    };
    transactionLimit?: {
        can_create: boolean;
        current: number;
        limit: number;
        remaining: number;
        plan: string;
        is_unlimited: boolean;
    };
    latestTransactions?: Array<{
        id: number;
        type: string;
        amount: number;
        transaction_date: string;
        description: string;
        category: { id: number; name: string; type: string } | null;
        whatsappNumber: { id: number; name: string | null } | null;
    }>;
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
    number_id: props.filters.number_id || '',
});

const memberOptions = computed(() => props.whatsappNumbers ?? []);

const memberLabel = (item: { whatsapp_number: string; name: string | null; is_primary: boolean }) =>
    item.name?.trim() || formatMemberNumber(item.whatsapp_number);

const formatMemberNumber = (num: string | null | undefined) => {
    if (!num) return 'Tanpa Nomor';
    if (num.startsWith('62') && num.length >= 11) {
        const body = num.slice(2);
        return `+62 ${body.slice(0, 3)}-${body.slice(3, 7)}-${body.slice(7)}`;
    }
    return num;
};

const exportForm = useForm({
    format: 'excel',
    start_date: '',
    end_date: '',
    type: '',
    status: '',
    number_id: '',
});

const importForm = useForm({
    file: null as File | null,
    skip_header: true,
});

const showExportModal = ref(false);
const showImportModal = ref(false);
const showEditModal = ref(false);
const showMobileMonthPicker = ref(false);
const showMobileWalletPicker = ref(false);
const showMobileCategoryPicker = ref(false);
const selectedTransaction = ref<any>(null);

const editForm = useForm({
    type: 'expense',
    amount: 0,
    transaction_date: '',
    description: '',
    source: '',
    category_id: '',
    status: 'confirmed',
    attachment: null as File | null,
    delete_attachment: false,
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
    if (exportForm.number_id) formData.append('number_id', exportForm.number_id);
    
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
        editForm.attachment = null;
        editForm.delete_attachment = false;
        
        console.log('Edit form data:', editForm.data());
        showEditModal.value = true;
        console.log('Modal should be visible now, showEditModal:', showEditModal.value);
    } catch (error) {
        console.error('Error opening edit modal:', error);
        showError('Error', 'Gagal membuka form edit. Silakan coba lagi.');
    }
};

const toggleDeleteCurrentAttachment = () => {
    editForm.delete_attachment = !editForm.delete_attachment;
    if (editForm.delete_attachment) {
        editForm.attachment = null;
    }
};

const onEditAttachmentChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    const files = target.files;
    if (files && files.length > 0) {
        const file = files[0];
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            alert('File harus berupa gambar (JPG, PNG, WebP) atau PDF.');
            target.value = '';
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) {
            alert('Ukuran file maksimal 5MB.');
            target.value = '';
            return;
        }
        
        editForm.attachment = file;
        editForm.delete_attachment = false;
    }
};
const getAttachmentUrl = (transaction: any) => {
    if (!transaction) return '';
    const filePath = transaction.message?.ocr_job?.file_path;
    if (filePath) {
        return '/files?path=' + encodeURIComponent(filePath);
    }
    const attachmentPath = transaction.metadata?.attachment_path;
    if (attachmentPath) {
        return '/files?path=' + encodeURIComponent(attachmentPath);
    }
    return '';
};

const isAttachmentPdf = (transaction: any) => {
    if (!transaction) return false;
    const attachmentPath = transaction.metadata?.attachment_path || '';
    return attachmentPath.toLowerCase().endsWith('.pdf');
};

const updateTransaction = () => {
    if (!selectedTransaction.value) return;
    
    // PHP doesn't support file upload via PUT natively, so we spoof it with POST and _method: 'PUT'
    editForm.transform((data) => ({
        ...data,
        _method: 'PUT',
    })).post(`/transactions/${selectedTransaction.value.id}`, {
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
watch(() => form.number_id, applyFilters);

// --- CuanCeria computed properties for mobile ---

const isIncome = (type: string) => type === 'income' || type === 'kredit_internal';

const totalIncome = computed(() =>
    props.transactions.data
        .filter((t) => isIncome(t.type))
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0)
);

const totalExpense = computed(() =>
    props.transactions.data
        .filter((t) => !isIncome(t.type))
        .reduce((sum, t) => sum + (Number(t.amount) || 0), 0)
);

const currentMonthLabel = computed(() => {
    const now = new Date();
    return format(now, 'MMM yyyy', { locale: id });
});

const dateKey = (dateStr: string) => {
    if (!dateStr) return '0000-00-00';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d.getTime())) return '0000-00-00';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
};

const parseTxDate = (dateStr: string) => {
    if (!dateStr) return new Date(0);
    const d = new Date(dateStr + 'T00:00:00');
    return isNaN(d.getTime()) ? new Date(0) : d;
};

const groupedTransactions = computed(() => {
    const groups: Record<string, { label: string; transactions: typeof props.transactions.data; net: number }> = {};
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const todayKey = dateKey(format(today, 'yyyy-MM-dd'));
    const yesterdayKey = dateKey(format(yesterday, 'yyyy-MM-dd'));

    for (const tx of props.transactions.data) {
        const rawDate = tx.transaction_date || tx.created_at;
        const key = rawDate ? dateKey(rawDate) : 'no-date';
        const resolvedKey = (!rawDate || key === '0000-00-00') ? 'no-date' : key;
        if (!groups[resolvedKey]) {
            let label = '';
            if (resolvedKey === 'no-date') {
                label = 'Tanpa Tanggal';
            } else {
                const txDate = parseTxDate(rawDate!);
                if (resolvedKey === todayKey) label = `Hari Ini • ${format(txDate, 'dd MMM yyyy', { locale: id })}`;
                else if (resolvedKey === yesterdayKey) label = `Kemarin • ${format(txDate, 'dd MMM yyyy', { locale: id })}`;
                else label = format(txDate, 'dd MMM yyyy', { locale: id });
            }
            groups[resolvedKey] = { label, transactions: [], net: 0 };
        }
        groups[resolvedKey].transactions.push(tx);
        groups[resolvedKey].net += isIncome(tx.type) ? tx.amount : -tx.amount;
    }

    return Object.entries(groups)
        .sort(([a], [b]) => {
            if (a === 'no-date') return 1;
            if (b === 'no-date') return -1;
            return b.localeCompare(a);
        })
        .map(([key, g]) => ({ key, ...g }));
});

const categoryIconMap: Record<string, string> = {
    'Makanan': 'restaurant',
    'Kopi': 'local_cafe',
    'Kopi & Jajan': 'local_cafe',
    'Transport': 'local_gas_station',
    'Transportasi': 'local_gas_station',
    'Gaji': 'payments',
    'Pendapatan': 'payments',
    'Bonus': 'featured_seasonal_and_gifts',
    'Langganan': 'headphones',
    'Belanja': 'shopping_cart',
    'Tagihan': 'receipt',
    'Investasi': 'trending_up',
    'Transfer': 'swap_horiz',
    'Hiburan': 'sports_esports',
    'Kesehatan': 'favorite',
    'Pendidikan': 'school',
};

const categoryBgMap: Record<string, { bg: string; text: string }> = {
    'Makanan': { bg: 'bg-[#ffc9d0]/50', text: 'text-[#ad2c4f]' },
    'Kopi': { bg: 'bg-[#ffd23f]/40', text: 'text-[#725a00]' },
    'Kopi & Jajan': { bg: 'bg-[#ffd23f]/40', text: 'text-[#725a00]' },
    'Transport': { bg: 'bg-[#ffd23f]/30', text: 'text-[#745c00]' },
    'Transportasi': { bg: 'bg-[#ffd23f]/30', text: 'text-[#745c00]' },
    'Gaji': { bg: 'bg-[#51fac1]/40', text: 'text-[#006c4f]' },
    'Pendapatan': { bg: 'bg-[#51fac1]/40', text: 'text-[#006c4f]' },
    'Bonus': { bg: 'bg-[#51fac1]/40', text: 'text-[#006c4f]' },
    'Langganan': { bg: 'bg-[#eae8e2]', text: 'text-[#4d4634]' },
};

const getCategoryIcon = (catName: string) => categoryIconMap[catName] || 'receipt_long';
const getCategoryStyles = (catName: string) => categoryBgMap[catName] || { bg: 'bg-[#eae8e2]', text: 'text-[#4d4634]' };
const getCategoryBadgeStyles = (catType: string) => catType === 'income'
    ? 'bg-[#51fac1]/30 text-[#006c4f]'
    : 'bg-[#ffc9d0]/30 text-[#ad2c4f]';

const formatTime = (dateStr: string) => {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr);
        return format(d, 'HH:mm', { locale: id }) + ' WIB';
    } catch { return ''; }
};

const formatShortDate = (dateStr: string) => {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr + 'T00:00:00');
        return format(d, 'dd MMM', { locale: id });
    } catch { return ''; }
};

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
        <div class="bg-background flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-y-auto p-4 md:p-6">
            <!-- Free Plan Transaction Limit Banner -->
            <div v-if="transactionLimit && !transactionLimit.is_unlimited" class="rounded-xl border p-4" :class="transactionLimit.can_create ? 'bg-cyan-50 border-cyan-200 dark:bg-cyan-950/30 dark:border-cyan-800' : 'bg-red-50 border-red-200 dark:bg-red-950/30 dark:border-red-800'">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-2xl" :class="transactionLimit.can_create ? 'text-cyan-600' : 'text-red-600'">{{ transactionLimit.can_create ? 'analytics' : 'warning' }}</span>
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
                    <Link href="/subscriptions" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 transition-colors">
                        Upgrade Paket
                    </Link>
                </div>
                <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                    <div class="h-1.5 rounded-full transition-all duration-500" :class="transactionLimit.can_create ? 'bg-cyan-500' : 'bg-red-500'" :style="{ width: Math.min((transactionLimit.current / transactionLimit.limit) * 100, 100) + '%' }"></div>
                </div>
            </div>

            <!-- ========== MOBILE VIEW (CuanCeria Theme) ========== -->
            <div class="lg:hidden flex flex-col gap-4 font-['Plus_Jakarta_Sans',sans-serif]">

                <!-- 1. Header Section -->
                <div class="flex items-center justify-between gap-3 pt-1">
                    <div class="flex flex-col min-w-0">
                        <div class="flex items-center gap-1.5">
                            <h1 class="text-[26px] leading-[34px] font-[700] tracking-tight text-[#1b1c19] truncate">
                                Riwayat Transaksi
                            </h1>
                            <span class="material-symbols-outlined text-xl select-none">receipt_long</span>
                        </div>
                        <p class="text-xs font-[500] text-[#4d4634] line-clamp-1 mt-0.5">
                            Pantau arus cuan &amp; kebiasaan jajanmu!
                        </p>
                    </div>
                    <img
                        src="/transaksi.png"
                        alt="Maskot Transaksi"
                        class="flex-shrink-0 relative z-20 -mb-7 md:-mb-8 w-20 h-20 md:w-24 md:h-24 object-contain self-end"
                    />
                </div>

                <!-- 2. Monthly Cashflow Mini Summary Bar -->
                <div class="w-full bg-white dark:bg-card rounded-2xl p-3.5 border border-[#eae8e2] dark:border-border/60 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-[#006c4f]"></span>
                            <span class="text-[10px] font-[800] uppercase tracking-wider text-[#4d4634]">Arus Kas • {{ currentMonthLabel }}</span>
                        </div>
                        <button
                            @click="showExportModal = true"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-[#f5f3ee] text-[#4d4634] text-[10px] font-[700] active:bg-[#eae8e2] transition-colors"
                            title="Unduh Rekap Laporan Bulanan"
                        >
                            <span class="material-symbols-outlined text-[15px]">file_download</span>
                            <span>Unduh Laporan</span>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-2.5">
                        <div class="flex flex-col p-2.5 rounded-xl bg-[#51fac1]/25">
                            <div class="flex items-center gap-1 text-[#006c4f] text-[10px] font-[800] uppercase tracking-wider">
                                <span class="material-symbols-outlined text-[16px]">arrow_downward_alt</span>
                                <span>Pemasukan</span>
                            </div>
                            <span class="text-[18px] font-[700] text-[#006c4f] font-extrabold mt-0.5 tracking-tight truncate">
                                +{{ formatCurrency(totalIncome) }}
                            </span>
                        </div>
                        <div class="flex flex-col p-2.5 rounded-xl bg-[#ffc9d0]/30">
                            <div class="flex items-center gap-1 text-[#ad2c4f] text-[10px] font-[800] uppercase tracking-wider">
                                <span class="material-symbols-outlined text-[16px]">arrow_upward_alt</span>
                                <span>Pengeluaran</span>
                            </div>
                            <span class="text-[18px] font-[700] text-[#ad2c4f] font-extrabold mt-0.5 tracking-tight truncate">
                                -{{ formatCurrency(totalExpense) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 3. Search & Filter Controls -->
                <div class="flex flex-col space-y-2.5">
                    <!-- Instant Search Bar -->
                    <div class="relative w-full">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[#4d4634]/60 text-[20px]">search</span>
                        <input
                            v-model="form.search"
                            type="text"
                            placeholder="Cari transaksi, merchant, dompet..."
                            class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-white dark:bg-card text-[#1b1c19] dark:text-foreground text-xs placeholder:text-[#4d4634]/50 dark:placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-[#ffd23f] border border-[#eae8e2] dark:border-border/60 transition-all"
                            @keyup.enter="applyFilters"
                        />
                    </div>

                    <!-- Filter Pills Row: Bulan + Dompet -->
                    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-nowrap scrollbar-none">
                        <button
                            @click="showMobileMonthPicker = !showMobileMonthPicker"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-white dark:bg-card text-[#1b1c19] dark:text-foreground text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 active:bg-[#f5f3ee] transition-colors"
                        >
                            <span class="material-symbols-outlined text-[16px]">calendar_today</span>
                            <span>{{ currentMonthLabel }}</span>
                            <span class="material-symbols-outlined text-[16px] text-[#4d4634]">expand_more</span>
                        </button>
                        <button
                            v-if="memberOptions.length"
                            @click="showMobileWalletPicker = !showMobileWalletPicker"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-white dark:bg-card text-[#1b1c19] dark:text-foreground text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 active:bg-[#f5f3ee] transition-colors"
                        >
                            <span class="material-symbols-outlined text-[16px]">wallet</span>
                            <span>{{ form.number_id ? (memberOptions.find(n => String(n.id) === form.number_id)?.name || 'Dompet') : 'Semua Dompet' }}</span>
                            <span class="material-symbols-outlined text-[16px] text-[#4d4634]">expand_more</span>
                        </button>
                        <button
                            @click="showMobileCategoryPicker = !showMobileCategoryPicker"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-white dark:bg-card text-[#1b1c19] dark:text-foreground text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 active:bg-[#f5f3ee] transition-colors"
                        >
                            <span class="material-symbols-outlined text-[16px]">label</span>
                            <span>{{ form.category_id ? (categories.find(c => String(c.id) === form.category_id)?.name || 'Kategori') : 'Kategori' }}</span>
                            <span class="material-symbols-outlined text-[16px] text-[#4d4634]">expand_more</span>
                        </button>
                    </div>

                    <!-- Mobile Date Range (hidden by default) -->
                    <div v-if="showMobileMonthPicker" class="flex items-center gap-2 px-1">
                        <input v-model="form.start_date" type="date" @change="applyFilters" class="flex-1 rounded-xl bg-white dark:bg-card px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground border border-[#eae8e2] dark:border-border/60 focus:outline-none focus:ring-2 focus:ring-[#ffd23f]" />
                        <span class="text-[10px] font-bold text-[#4d4634]">—</span>
                        <input v-model="form.end_date" type="date" @change="applyFilters" class="flex-1 rounded-xl bg-white dark:bg-card px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground border border-[#eae8e2] dark:border-border/60 focus:outline-none focus:ring-2 focus:ring-[#ffd23f]" />
                    </div>

                    <!-- Mobile Wallet Picker Dropdown -->
                    <div v-if="showMobileWalletPicker && memberOptions.length" class="flex flex-wrap gap-1.5 px-1">
                        <button @click="form.number_id = ''; applyFilters()" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.number_id === '' ? 'bg-[#ffd23f] text-[#725a00] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground']">Semua</button>
                        <button v-for="n in memberOptions" :key="n.id" @click="form.number_id = String(n.id); applyFilters()" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.number_id === String(n.id) ? 'bg-[#ffd23f] text-[#725a00] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground']">{{ memberLabel(n) }}</button>
                    </div>

                    <!-- Mobile Category Picker Dropdown -->
                    <div v-if="showMobileCategoryPicker" class="flex flex-wrap gap-1.5 px-1">
                        <button @click="form.category_id = ''; applyFilters()" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.category_id === '' ? 'bg-[#ffd23f] text-[#725a00] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground']">Semua</button>
                        <button v-for="c in categories" :key="c.id" @click="form.category_id = String(c.id); applyFilters()" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.category_id === String(c.id) ? 'bg-[#ffd23f] text-[#725a00] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground']">{{ c.name }}</button>
                    </div>

                    <!-- Filter Type Pills + Sort -->
                    <div class="flex items-center justify-between pt-1">
                        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-nowrap">
                            <button @click="form.type = ''" :class="['px-3.5 py-1 rounded-full text-xs font-bold border border-[#eae8e2] dark:border-border/60 transition-colors', form.type === '' ? 'bg-[#ffd23f] text-[#725a00] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground active:bg-[#f5f3ee]']">
                                Semua ({{ transactions.meta?.total || 0 }})
                            </button>
                            <button @click="form.type = 'income'" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.type === 'income' ? 'bg-[#51fac1] text-[#007152] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground active:bg-[#51fac1]/30']">
                                Pemasukan
                            </button>
                            <button @click="form.type = 'expense'" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.type === 'expense' ? 'bg-[#ffc9d0] text-[#ad2c4f] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground active:bg-[#ffc9d0]/30']">
                                Pengeluaran
                            </button>
                            <button @click="form.type = 'debit_internal'" :class="['px-3 py-1 rounded-full text-[10px] font-[700] border border-[#eae8e2] dark:border-border/60 transition-colors', form.type === 'debit_internal' ? 'bg-[#eae8e2] text-[#4d4634] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground active:bg-[#eae8e2]']">
                                Transfer
                            </button>
                        </div>
                        <button @click="resetFilters" class="flex-shrink-0 inline-flex items-center text-[#4d4634] text-[10px] font-[700] pl-2">
                            <span>Reset</span>
                            <span class="material-symbols-outlined text-[16px]">swap_vert</span>
                        </button>
                    </div>
                </div>

                <!-- 4. Grouped Transaction List -->
                <div class="flex flex-col space-y-4 pt-1">
                    <template v-if="groupedTransactions.length > 0">
                        <div v-for="group in groupedTransactions" :key="group.key" class="flex flex-col space-y-2">
                            <!-- Group Header -->
                            <div class="flex items-center justify-between px-1">
                                <span class="text-[10px] font-[800] uppercase tracking-wider text-[#4d4634]">
                                    {{ group.label }}
                                </span>
                                <span class="text-[10px] font-bold" :class="group.net >= 0 ? 'text-[#006c4f]' : 'text-[#ad2c4f]'">
                                    {{ group.net >= 0 ? '+' : '' }}{{ formatCurrency(Math.abs(group.net)) }}
                                </span>
                            </div>
                            <!-- Transaction Items -->
                            <div
                                v-for="tx in group.transactions"
                                :key="tx.id"
                                class="flex items-center justify-between p-3 rounded-2xl bg-white dark:bg-card border border-[#eae8e2] dark:border-border/60 active:bg-[#f5f3ee] dark:active:bg-muted transition-colors"
                            >
                                <div class="flex items-center gap-3 min-w-0">
                                    <div :class="['w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0', getCategoryStyles(tx.category?.name || '').bg, getCategoryStyles(tx.category?.name || '').text]">
                                        <span class="material-symbols-outlined text-[22px]">{{ getCategoryIcon(tx.category?.name || '') }}</span>
                                    </div>
                                    <div class="flex flex-col min-w-0">
                                        <Link
                                            :href="`/transactions/${tx.id}`"
                                            class="text-sm font-bold text-[#1b1c19] truncate max-w-[180px]"
                                        >
                                            {{ tx.description }}
                                        </Link>
                                        <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                            <span v-if="tx.whatsappNumber" class="px-2 py-0.5 rounded-full bg-[#f5f3ee] text-[10px] font-[700] text-[#4d4634]">{{ tx.whatsappNumber.name || 'WA' }}</span>
                                            <span v-if="tx.source" class="text-[10px] text-[#4d4634] truncate max-w-[120px]">"{{ tx.source }}"</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end flex-shrink-0 pl-2">
                                    <span class="text-sm font-bold tracking-tight" :class="isIncome(tx.type) ? 'text-[#006c4f]' : 'text-[#ad2c4f]'">
                                        {{ isIncome(tx.type) ? '+' : '-' }}{{ formatCurrency(tx.amount) }}
                                    </span>
                                    <span v-if="tx.category?.name" :class="['px-1.5 py-0.5 rounded-md text-[10px] font-[700] mt-0.5', getCategoryBadgeStyles(tx.category.type)]">
                                        {{ tx.category.name }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Empty State -->
                    <div v-else class="flex flex-col items-center justify-center py-8">
                        <div class="mb-3 rounded-full bg-[#f5f3ee] p-4">
                            <span class="material-symbols-outlined text-[32px] text-[#b0ad9e]">receipt_long</span>
                        </div>
                        <p class="text-sm font-bold text-[#4d4634]">Belum ada transaksi</p>
                        <p class="text-[10px] text-[#b0ad9e] mt-1">Coba ubah filter atau tambah transaksi baru</p>

                        <!-- Transaksi Terakhir -->
                        <div v-if="props.latestTransactions && props.latestTransactions.length > 0" class="w-full mt-5">
                            <p class="text-[10px] font-[800] uppercase tracking-wider text-[#4d4634] mb-2 px-1">Transaksi Terakhir</p>
                            <div class="flex flex-col gap-2">
                                <Link
                                    v-for="tx in props.latestTransactions"
                                    :key="tx.id"
                                    :href="`/transactions/${tx.id}`"
                                    class="flex items-center justify-between p-3 rounded-2xl bg-white active:bg-[#f5f3ee] transition-colors"
                                >
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div :class="['w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0', getCategoryStyles(tx.category?.name || '').bg, getCategoryStyles(tx.category?.name || '').text]">
                                            <span class="material-symbols-outlined text-[20px]">{{ getCategoryIcon(tx.category?.name || '') }}</span>
                                        </div>
                                        <div class="flex flex-col min-w-0">
                                            <span class="text-sm font-bold text-[#1b1c19] truncate max-w-[160px]">{{ tx.description }}</span>
                                            <span class="text-[10px] text-[#b0ad9e]">{{ tx.transaction_date ? formatShortDate(tx.transaction_date) : '' }}</span>
                                        </div>
                                    </div>
                                    <span class="text-sm font-bold flex-shrink-0 pl-2" :class="isIncome(tx.type) ? 'text-[#006c4f]' : 'text-[#ad2c4f]'">
                                        {{ isIncome(tx.type) ? '+' : '-' }}{{ formatCurrency(tx.amount) }}
                                    </span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Pagination & End of List (Mobile) -->
                <div v-if="transactions.links && transactions.links.length > 3" class="flex flex-col items-center justify-center pt-3 pb-2 space-y-3">
                    <p class="text-[10px] text-[#b0ad9e] uppercase tracking-wider font-bold">
                        Menampilkan {{ transactions.meta?.from || 0 }} — {{ transactions.meta?.to || 0 }} dari {{ transactions.meta?.total || 0 }} transaksi
                    </p>
                    <div class="flex items-center gap-2 w-full">
                        <Link
                            :href="transactions.links[0]?.url || '#'"
                            :class="['flex-1 py-3 rounded-2xl bg-[#f5f3ee] dark:bg-card border border-[#eae8e2] dark:border-border/60 text-[#4d4634] dark:text-foreground text-xs font-bold flex items-center justify-center gap-1.5 active:bg-[#eae8e2] transition-colors', !transactions.links[0]?.url ? 'opacity-40 pointer-events-none' : '']"
                        >
                            <span class="material-symbols-outlined text-[16px]">chevron_left</span>
                            Prev
                        </Link>
                        <span class="text-[10px] font-bold text-[#b0ad9e]">{{ transactions.meta?.current_page }}/{{ transactions.meta?.last_page }}</span>
                        <Link
                            :href="transactions.links[transactions.links.length - 1]?.url || '#'"
                            :class="['flex-1 py-3 rounded-2xl bg-[#f5f3ee] dark:bg-card border border-[#eae8e2] dark:border-border/60 text-[#4d4634] dark:text-foreground text-xs font-bold flex items-center justify-center gap-1.5 active:bg-[#eae8e2] transition-colors', !transactions.links[transactions.links.length - 1]?.url ? 'opacity-40 pointer-events-none' : '']"
                        >
                            Next
                            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ========== DESKTOP VIEW ========== -->
            <div class="hidden lg:flex flex-col gap-4 font-['Plus_Jakarta_Sans',sans-serif]">

                <!-- Header -->
                <div class="flex items-center justify-between gap-4 pt-1">
                    <div class="flex flex-col min-w-0">
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl font-bold tracking-tight text-[#1b1c19]">Riwayat Transaksi</h1>
                            <span class="material-symbols-outlined text-xl select-none text-[#4d4634]">receipt_long</span>
                        </div>
                        <p class="text-xs font-medium text-[#4d4634] mt-0.5">Pantau arus keuangan & kelola semua transaksi</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showImportModal = true" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white dark:bg-card border border-[#eae8e2] dark:border-border/60 text-[#4d4634] dark:text-foreground text-sm font-semibold hover:bg-[#f5f3ee] transition-colors">
                            <Upload class="w-4 h-4" /> Import
                        </button>
                        <button @click="showExportModal = true" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#ffd23f] text-[#725a00] text-sm font-bold hover:bg-[#ffd23f]/90 transition-colors">
                            <Download class="w-4 h-4" /> Unduh Laporan
                        </button>
                    </div>
                </div>

                <!-- Summary Bar -->
                <div class="w-full bg-white dark:bg-card rounded-2xl p-4 border border-[#eae8e2] dark:border-border/60">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-[#006c4f]"></span>
                            <span class="text-[11px] font-extrabold uppercase tracking-wider text-[#4d4634]">Arus Kas • {{ currentMonthLabel }}</span>
                        </div>
                        <span class="text-xs text-[#b0ad9e]">{{ transactions.meta?.total || 0 }} transaksi</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col p-3 rounded-xl bg-[#51fac1]/25">
                            <div class="flex items-center gap-1 text-[#006c4f] text-[11px] font-extrabold uppercase tracking-wider">
                                <span class="material-symbols-outlined text-[16px]">arrow_downward_alt</span>
                                <span>Pemasukan</span>
                            </div>
                            <span class="text-xl font-extrabold text-[#006c4f] mt-0.5 tracking-tight">+{{ formatCurrency(totalIncome) }}</span>
                        </div>
                        <div class="flex flex-col p-3 rounded-xl bg-[#ffc9d0]/30">
                            <div class="flex items-center gap-1 text-[#ad2c4f] text-[11px] font-extrabold uppercase tracking-wider">
                                <span class="material-symbols-outlined text-[16px]">arrow_upward_alt</span>
                                <span>Pengeluaran</span>
                            </div>
                            <span class="text-xl font-extrabold text-[#ad2c4f] mt-0.5 tracking-tight">-{{ formatCurrency(totalExpense) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Filters Card -->
                <div class="bg-white dark:bg-card rounded-2xl p-5 border border-[#eae8e2] dark:border-border/60 space-y-4">
                    <!-- Row 1: Search + Date + Status -->
                    <div class="grid grid-cols-5 gap-3">
                        <div class="col-span-2 relative">
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#4d4634]/50 text-[18px]">search</span>
                            <input v-model="form.search" type="text" placeholder="Cari transaksi, merchant..." class="w-full pl-9 pr-3 py-2.5 rounded-xl bg-[#f5f3ee] text-[#1b1c19] text-sm placeholder:text-[#4d4634]/40 focus:outline-none focus:ring-2 focus:ring-[#ffd23f] transition-all" @keyup.enter="applyFilters" />
                        </div>
                        <div>
                            <input v-model="form.start_date" type="date" @change="applyFilters" class="w-full px-3 py-2.5 rounded-xl bg-[#f5f3ee] text-[#1b1c19] text-sm focus:outline-none focus:ring-2 focus:ring-[#ffd23f] transition-all" />
                        </div>
                        <div>
                            <input v-model="form.end_date" type="date" @change="applyFilters" class="w-full px-3 py-2.5 rounded-xl bg-[#f5f3ee] text-[#1b1c19] text-sm focus:outline-none focus:ring-2 focus:ring-[#ffd23f] transition-all" />
                        </div>
                        <div>
                            <select v-model="form.status" class="w-full px-3 py-2.5 rounded-xl bg-[#f5f3ee] text-[#1b1c19] text-sm focus:outline-none focus:ring-2 focus:ring-[#ffd23f] transition-all">
                                <option value="">Semua Status</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Tipe pills + Kategori + Anggota + Reset -->
                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Tipe pills -->
                        <div class="flex items-center gap-1.5">
                            <button @click="form.type = ''" :class="['px-3.5 py-1.5 rounded-full text-xs font-bold transition-colors', form.type === '' ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#f5f3ee] text-[#4d4634] hover:bg-[#eae8e2]']">Semua</button>
                            <button @click="form.type = 'income'" :class="['px-3.5 py-1.5 rounded-full text-xs font-bold transition-colors', form.type === 'income' ? 'bg-[#51fac1] text-[#006c4f]' : 'bg-[#f5f3ee] text-[#4d4634] hover:bg-[#eae8e2]']">Pemasukan</button>
                            <button @click="form.type = 'expense'" :class="['px-3.5 py-1.5 rounded-full text-xs font-bold transition-colors', form.type === 'expense' ? 'bg-[#ffc9d0] text-[#ad2c4f]' : 'bg-[#f5f3ee] text-[#4d4634] hover:bg-[#eae8e2]']">Pengeluaran</button>
                            <button @click="form.type = 'debit_internal'" :class="['px-3.5 py-1.5 rounded-full text-xs font-bold transition-colors', form.type === 'debit_internal' ? 'bg-[#eae8e2] text-[#4d4634]' : 'bg-[#f5f3ee] text-[#4d4634] hover:bg-[#eae8e2]']">Transfer</button>
                        </div>

                        <div class="w-px h-5 bg-[#eae8e2]"></div>

                        <!-- Kategori -->
                        <select v-model="form.category_id" class="px-3 py-1.5 rounded-xl bg-[#f5f3ee] text-[#1b1c19] text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[#ffd23f] transition-all">
                            <option value="">Semua Kategori</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>

                        <!-- Anggota pills -->
                        <template v-if="memberOptions.length">
                            <div class="w-px h-5 bg-[#eae8e2]"></div>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-[11px] font-extrabold uppercase tracking-wider text-[#4d4634]">Anggota:</span>
                                <button @click="form.number_id = ''" :class="['px-3 py-1.5 rounded-full text-xs font-bold transition-colors', form.number_id === '' ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#f5f3ee] text-[#4d4634] hover:bg-[#eae8e2]']">Semua</button>
                                <button v-for="number in memberOptions" :key="number.id" @click="form.number_id = String(number.id)" :title="number.whatsapp_number" :class="['px-3 py-1.5 rounded-full text-xs font-bold transition-colors', form.number_id === String(number.id) ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#f5f3ee] text-[#4d4634] hover:bg-[#eae8e2]']">{{ memberLabel(number) }}</button>
                            </div>
                        </template>

                        <button @click="resetFilters" class="ml-auto text-xs font-bold text-[#4d4634] hover:text-[#1b1c19] flex items-center gap-1 transition-colors">
                            <span class="material-symbols-outlined text-[16px]">restart_alt</span> Reset
                        </button>
                    </div>
                </div>

                <!-- Grouped Transaction List (Desktop) -->
                <div class="flex flex-col gap-4">
                    <template v-if="groupedTransactions.length > 0">
                        <div v-for="group in groupedTransactions" :key="group.key" class="flex flex-col gap-2">
                            <!-- Group Header -->
                            <div class="flex items-center justify-between px-1">
                                <span class="text-[11px] font-extrabold uppercase tracking-wider text-[#4d4634]">{{ group.label }}</span>
                                <span class="text-xs font-bold" :class="group.net >= 0 ? 'text-[#006c4f]' : 'text-[#ad2c4f]'">
                                    {{ group.net >= 0 ? '+' : '' }}{{ formatCurrency(Math.abs(group.net)) }}
                                </span>
                            </div>
                            <!-- Transaction Rows -->
                            <div class="bg-white dark:bg-card rounded-2xl border border-[#eae8e2] dark:border-border/60 overflow-hidden divide-y divide-[#f5f3ee] dark:divide-border/40">
                                <div v-for="tx in group.transactions" :key="tx.id" class="flex items-center gap-4 px-5 py-3.5 hover:bg-[#f5f3ee]/60 transition-colors group">
                                    <!-- Icon -->
                                    <div :class="['w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0', getCategoryStyles(tx.category?.name || '').bg, getCategoryStyles(tx.category?.name || '').text]">
                                        <span class="material-symbols-outlined text-[20px]">{{ getCategoryIcon(tx.category?.name || '') }}</span>
                                    </div>
                                    <!-- Description + meta -->
                                    <div class="flex flex-col min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5">
                                            <Link :href="`/transactions/${tx.id}`" class="text-sm font-bold text-[#1b1c19] truncate max-w-xs hover:text-[#006c4f] transition-colors">{{ tx.description }}</Link>
                                            <Paperclip v-if="tx.metadata?.attachment_path || tx.message?.ocr_job?.file_path" class="w-3 h-3 text-[#006c4f] shrink-0" title="Ada lampiran" />
                                        </div>
                                        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                            <span class="text-[11px] text-[#b0ad9e]">{{ tx.category?.name || '-' }}</span>
                                            <span v-if="tx.source" class="text-[11px] text-[#b0ad9e]">• {{ tx.source }}</span>
                                            <span v-if="tx.whatsappNumber" class="px-2 py-0.5 rounded-full bg-[#f5f3ee] text-[10px] font-bold text-[#4d4634]">{{ tx.whatsappNumber.name || tx.whatsappNumber.whatsapp_number }}</span>
                                        </div>
                                    </div>
                                    <!-- Status badge -->
                                    <span class="hidden xl:inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold flex-shrink-0"
                                        :class="tx.status === 'confirmed' ? 'bg-[#51fac1]/30 text-[#006c4f]' : 'bg-[#ffc9d0]/40 text-[#ad2c4f]'">
                                        {{ tx.status === 'confirmed' ? 'Confirmed' : 'Rejected' }}
                                    </span>
                                    <!-- Amount -->
                                    <span class="text-sm font-extrabold tracking-tight flex-shrink-0 w-36 text-right" :class="isIncome(tx.type) ? 'text-[#006c4f]' : 'text-[#ad2c4f]'">
                                        {{ isIncome(tx.type) ? '+' : '-' }}{{ formatCurrency(tx.amount) }}
                                    </span>
                                    <!-- Actions -->
                                    <div class="flex items-center gap-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="openEditModal(tx)" class="p-1.5 rounded-lg text-[#4d4634] hover:bg-[#eae8e2] hover:text-[#1b1c19] transition-colors" title="Edit"><Pencil class="w-3.5 h-3.5" /></button>
                                        <button @click="deleteTransaction(tx.id, tx.description)" class="p-1.5 rounded-lg text-[#4d4634] hover:bg-[#ffc9d0]/40 hover:text-[#ad2c4f] transition-colors" title="Hapus"><Trash2 class="w-3.5 h-3.5" /></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Empty State -->
                    <div v-else class="flex flex-col items-center justify-center py-16 bg-white dark:bg-card rounded-2xl border border-[#eae8e2] dark:border-border/60">
                        <div class="mb-3 rounded-full bg-[#f5f3ee] dark:bg-muted p-5">
                            <span class="material-symbols-outlined text-[36px] text-[#b0ad9e]">receipt_long</span>
                        </div>
                        <p class="text-sm font-bold text-[#4d4634] dark:text-foreground">Belum ada transaksi</p>
                        <p class="text-xs text-[#b0ad9e] mt-1">Coba ubah filter atau tambah transaksi baru</p>
                    </div>
                </div>

                <!-- Desktop Pagination -->
                <div v-if="transactions.links && transactions.links.length > 3" class="flex items-center justify-between px-1">
                    <p class="text-xs text-[#b0ad9e] font-bold uppercase tracking-wider">
                        Menampilkan {{ transactions.meta?.from || 0 }}–{{ transactions.meta?.to || 0 }} dari {{ transactions.meta?.total || 0 }} transaksi
                    </p>
                    <div class="flex items-center gap-1.5">
                        <Link v-for="link in (transactions.links || [])" :key="link.label" :href="link.url || '#'"
                            :class="['px-3 py-1.5 rounded-xl text-xs font-bold border border-[#eae8e2] dark:border-border/60 transition-colors',
                                link.active ? 'bg-[#ffd23f] text-[#725a00] border-transparent' : 'bg-white dark:bg-card text-[#4d4634] dark:text-foreground hover:bg-[#f5f3ee]',
                                !link.url ? 'opacity-40 pointer-events-none' : '']">
                            <span v-html="link.label" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Modal -->
        <Teleport to="body">
            <div
                v-if="showExportModal"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
                @click.self="showExportModal = false"
            >
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-[#1b1c19] p-6 border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif] space-y-4">
                    <div class="flex items-center justify-between border-b border-[#eae8e2] dark:border-white/10 pb-3">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-[#ffd23f]/30 flex items-center justify-center text-[#725a00] dark:text-[#ffd23f]">
                                <span class="material-symbols-outlined text-[20px]">file_download</span>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-[#1b1c19] dark:text-foreground">Export Transaksi</h3>
                                <p class="text-[11px] text-[#4d4634] dark:text-muted-foreground font-medium">Unduh rekap transaksi ke Excel atau PDF</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="showExportModal = false"
                            class="p-1.5 rounded-xl hover:bg-[#f5f3ee] dark:hover:bg-white/10 text-[#4d4634] dark:text-muted-foreground transition-colors"
                        >
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <form @submit.prevent="exportData" class="space-y-4">
                        <div class="space-y-3.5">
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Format File</label>
                                <select v-model="exportForm.format" class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3.5 py-2.5 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]">
                                    <option value="excel">Excel (.xlsx)</option>
                                    <option value="pdf">Dokumen PDF (.pdf)</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Tanggal Mulai</label>
                                    <input v-model="exportForm.start_date" type="date" class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Tanggal Akhir</label>
                                    <input v-model="exportForm.end_date" type="date" class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Tipe Transaksi</label>
                                    <select v-model="exportForm.type" class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]">
                                        <option value="">Semua Tipe</option>
                                        <option value="income">Pemasukan</option>
                                        <option value="expense">Pengeluaran</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Status</label>
                                    <select v-model="exportForm.status" class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]">
                                        <option value="">Semua Status</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                            </div>
                            <div v-if="memberOptions.length">
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Anggota / Kantong</label>
                                <select v-model="exportForm.number_id" class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3.5 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]">
                                    <option value="">Semua Anggota</option>
                                    <option v-for="number in memberOptions" :key="number.id" :value="String(number.id)">
                                        {{ memberLabel(number) }}
                                    </option>
                                    <option value="none">Tanpa Nomor</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#eae8e2] dark:border-white/10">
                            <button
                                type="button"
                                @click="showExportModal = false"
                                class="px-4 py-2.5 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634] dark:text-foreground text-xs font-bold hover:bg-[#eae8e2] dark:hover:bg-white/10 transition-colors"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-[#ffd23f] text-[#725a00] text-xs font-bold hover:brightness-95 active:scale-95 transition-all"
                            >
                                <span class="material-symbols-outlined text-[16px]">download</span>
                                <span>Unduh File</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Import Modal -->
        <Teleport to="body">
            <div
                v-if="showImportModal"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
                @click.self="showImportModal = false"
            >
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-[#1b1c19] p-6 border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif] space-y-4">
                    <div class="flex items-center justify-between border-b border-[#eae8e2] dark:border-white/10 pb-3">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-[#51fac1]/30 flex items-center justify-center text-[#006c4f] dark:text-[#51fac1]">
                                <span class="material-symbols-outlined text-[20px]">upload_file</span>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-[#1b1c19] dark:text-foreground">Import Transaksi</h3>
                                <p class="text-[11px] text-[#4d4634] dark:text-muted-foreground font-medium">Unggah berkas CSV atau Excel transaksi</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="showImportModal = false"
                            class="p-1.5 rounded-xl hover:bg-[#f5f3ee] dark:hover:bg-white/10 text-[#4d4634] dark:text-muted-foreground transition-colors"
                        >
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <form @submit.prevent="importData" class="space-y-4">
                        <div class="space-y-3.5">
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Pilih Berkas (CSV / XLSX)</label>
                                <input
                                    type="file"
                                    accept=".csv,.xlsx,.xls"
                                    @input="importForm.file = ($event.target as HTMLInputElement).files?.[0] || null"
                                    class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]"
                                    required
                                />
                            </div>
                            <div>
                                <label class="flex items-center gap-2 cursor-pointer select-none">
                                    <input
                                        v-model="importForm.skip_header"
                                        type="checkbox"
                                        class="rounded border-[#eae8e2] text-[#ffd23f] focus:ring-[#ffd23f]"
                                    />
                                    <span class="text-xs font-medium text-[#4d4634] dark:text-foreground">Lewati baris header pertama</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#eae8e2] dark:border-white/10">
                            <button
                                type="button"
                                @click="showImportModal = false"
                                class="px-4 py-2.5 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634] dark:text-foreground text-xs font-bold hover:bg-[#eae8e2] dark:hover:bg-white/10 transition-colors"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="!importForm.file"
                                class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-[#ffd23f] text-[#725a00] text-xs font-bold hover:brightness-95 active:scale-95 transition-all disabled:opacity-50"
                            >
                                <span class="material-symbols-outlined text-[16px]">upload</span>
                                <span>Import Data</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Edit Modal (Simplified) -->
        <Teleport to="body">
            <div
                v-if="showEditModal"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto"
                @click.self="showEditModal = false"
            >
                <div class="w-full max-w-md rounded-2xl bg-white dark:bg-[#1b1c19] p-6 border border-[#eae8e2] dark:border-white/10 font-['Plus_Jakarta_Sans',sans-serif] space-y-4 my-8">
                    <div class="flex items-center justify-between border-b border-[#eae8e2] dark:border-white/10 pb-3">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-[#ffd23f]/30 flex items-center justify-center text-[#725a00] dark:text-[#ffd23f]">
                                <span class="material-symbols-outlined text-[20px]">edit_note</span>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-[#1b1c19] dark:text-foreground">Edit Transaksi</h3>
                                <p class="text-[11px] text-[#4d4634] dark:text-muted-foreground font-medium">Perbarui rincian transaksi</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="showEditModal = false; editForm.reset(); selectedTransaction = null"
                            class="p-1.5 rounded-xl hover:bg-[#f5f3ee] dark:hover:bg-white/10 text-[#4d4634] dark:text-muted-foreground transition-colors"
                        >
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </button>
                    </div>

                    <form @submit.prevent="updateTransaction" class="space-y-4">
                        <div class="space-y-3.5">
                            <!-- Tipe & Tanggal -->
                            <div class="grid gap-3 grid-cols-2">
                                <div>
                                    <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Tipe</label>
                                    <select
                                        v-model="editForm.type"
                                        required
                                        class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]"
                                    >
                                        <option value="income">Pendapatan</option>
                                        <option value="expense">Pengeluaran</option>
                                        <option value="debit_internal">Debit Antar Dompet</option>
                                        <option value="kredit_internal">Kredit Antar Dompet</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Tanggal</label>
                                    <input
                                        v-model="editForm.transaction_date"
                                        type="date"
                                        required
                                        class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]"
                                    />
                                </div>
                            </div>
                            
                            <!-- Jumlah -->
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Jumlah (Rp)</label>
                                <input
                                    v-model.number="editForm.amount"
                                    type="number"
                                    min="0"
                                    required
                                    placeholder="50000"
                                    class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3.5 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]"
                                />
                            </div>
                            
                            <!-- Deskripsi -->
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Deskripsi</label>
                                <input
                                    v-model="editForm.description"
                                    type="text"
                                    required
                                    placeholder="Contoh: Makan siang"
                                    class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3.5 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]"
                                />
                            </div>
                            
                            <!-- Kategori -->
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Kategori</label>
                                <select
                                    v-model="editForm.category_id"
                                    required
                                    class="w-full rounded-xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-[#23231f] px-3.5 py-2 text-xs text-[#1b1c19] dark:text-foreground font-medium focus:outline-none focus:ring-2 focus:ring-[#ffd23f]"
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

                            <!-- Lampiran (Nota / Struk) -->
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-[#1b1c19] dark:text-foreground">Lampiran (Nota / Struk)</label>
                                
                                <div v-if="selectedTransaction?.metadata?.attachment_path || selectedTransaction?.message?.ocr_job?.file_path" class="mb-2 flex items-center justify-between p-2.5 bg-[#f5f3ee] dark:bg-white/5 rounded-xl border border-[#eae8e2] dark:border-white/10">
                                    <span class="text-xs font-mono text-[#4d4634] dark:text-muted-foreground truncate max-w-[200px]">
                                        📂 {{ selectedTransaction?.metadata?.attachment_name || 'Struk/Nota Terlampir' }}
                                    </span>
                                    <button 
                                        type="button"
                                        @click="toggleDeleteCurrentAttachment"
                                        :class="[
                                            'text-xs font-bold px-2.5 py-1 rounded-lg transition-colors border',
                                            editForm.delete_attachment 
                                                ? 'bg-[#ffc9d0] border-transparent text-[#ad2c4f]' 
                                                : 'bg-white dark:bg-card border-[#eae8e2] dark:border-white/10 text-[#4d4634] dark:text-foreground hover:bg-[#ffc9d0]/40'
                                        ]"
                                    >
                                        {{ editForm.delete_attachment ? 'Batal Hapus' : 'Hapus Lampiran' }}
                                    </button>
                                </div>

                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    @change="onEditAttachmentChange"
                                    class="w-full text-xs text-[#4d4634] dark:text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-[#ffd23f]/30 file:text-[#725a00] hover:file:bg-[#ffd23f]/50"
                                />
                                <p class="text-[10px] text-[#4d4634]/60 dark:text-muted-foreground mt-1">Mendukung JPG, PNG, WebP, PDF (Maks. 5MB)</p>
                            </div>
                        </div>
                        
                        <!-- Buttons -->
                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#eae8e2] dark:border-white/10">
                            <button
                                type="button"
                                @click="showEditModal = false; editForm.reset(); selectedTransaction = null"
                                class="px-4 py-2.5 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634] dark:text-foreground text-xs font-bold hover:bg-[#eae8e2] dark:hover:bg-white/10 transition-colors"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="editForm.processing"
                                class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl bg-[#ffd23f] text-[#725a00] text-xs font-bold hover:brightness-95 active:scale-95 transition-all disabled:opacity-50"
                            >
                                <span class="material-symbols-outlined text-[16px]">save</span>
                                <span>{{ editForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

