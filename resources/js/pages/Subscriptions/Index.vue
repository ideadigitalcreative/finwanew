```
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Upload, X, Eye } from 'lucide-vue-next';

interface Duration {
    value: number;
    label: string;
    discount: number;
}

interface PlanDetails {
    name: string;
    monthly_price: number;
}

interface Bank {
    id: number;
    name: string;
    account_number: string;
    account_name: string;
    description: string | null;
}

interface Props {
    subscription: {
        id: number;
        package: string;
        status: string;
        starts_at: string;
        ends_at: string | null;
        payment_provider: string | null;
        payment_reference: string | null;
        payment_proof: string | null;
        price: string | number | null;
    } | null;
    activeSubscription: {
        id: number;
        package: string;
        status: string;
        starts_at: string;
        ends_at: string | null;
    } | null;
    pendingSubscription: {
        id: number;
        package: string;
        status: string;
        starts_at: string;
        ends_at: string | null;
        payment_provider: string | null;
        payment_reference: string | null;
        payment_proof: string | null;
        price: string | number | null;
    } | null;
    subscriptions: Array<{
        id: number;
        package: string;
        status: string;
        starts_at: string;
        ends_at: string | null;
        created_at: string;
    }>;
    tenant: {
        id: number;
        name: string;
        is_active: boolean;
        trial_ends_at: string | null;
    };
    planDetails: PlanDetails;
    durationOptions: Duration[];
    banks: Bank[];
    pendingRequest: {
        id: number;
        plan: string;
        duration_months: number;
        price: number;
        status: string;
        created_at: string;
        request_type: string;
        notes: string | null;
    } | null;
}

const props = defineProps<Props>();
const { showError, showSuccess } = useSweetAlert();

const showPaymentProofDialog = ref(false);
const showPaymentProofViewDialog = ref(false);
const showUpgradeDialog = ref(false);
const showExtensionDialog = ref(false);
const showQrZoom = ref(false);
const selectedFile = ref<File | null>(null);
const previewUrl = ref<string | null>(null);
const viewingPaymentProofUrl = ref<string | null>(null);
const selectedPaymentMethod = ref<string>('qris');

const uploadForm = useForm({
    payment_proof: null as File | null,
});

const durationOptions = computed<Duration[]>(() => props.durationOptions);

const selectedDuration = ref<Duration>(durationOptions.value[0] || { value: 1, label: '1 Bulan', discount: 0 });
const upgradeNotes = ref('');

const extensionDuration = ref<Duration>(durationOptions.value[0] || { value: 1, label: '1 Bulan', discount: 0 });
const extensionNotes = ref('');

const upgradeRequestForm = useForm({
    request_type: 'upgrade',
    plan: 'growth', // Fixed to growth (Paket Lengkap)
    duration_months: selectedDuration.value.value,
    notes: '',
});

const extensionRequestForm = useForm({
    request_type: 'extend',
    plan: 'growth', // Fixed to growth (Paket Lengkap)
    duration_months: extensionDuration.value.value,
    notes: '',
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return format(new Date(date), 'dd MMMM yyyy', { locale: id });
};

const formatPackage = (pkg: string) => {
    const fallback: Record<string, string> = {
        starter: 'Starter',
        growth: 'Paket Lengkap',
        pro: 'Pro',
        enterprise: 'Enterprise',
        free: 'Free Trial',
    };
    return fallback[pkg] || pkg || '-';
};

const getPackageFeatures = (pkg: string) => {
    return [
        '5 WhatsApp Numbers',
        'All Basic Features',
        'Priority Support',
    ];
};

// Calculate totals like checkout page
const upgradeSubtotal = computed(() => {
    return props.planDetails.monthly_price * selectedDuration.value.value;
});

const upgradeDiscount = computed(() => {
    if (selectedDuration.value.discount === 0) return 0;
    return (upgradeSubtotal.value * selectedDuration.value.discount) / 100;
});

const upgradeTotal = computed(() => {
    return upgradeSubtotal.value - upgradeDiscount.value;
});

const extensionSubtotal = computed(() => {
    if (!props.subscription) return 0;
    return props.planDetails.monthly_price * extensionDuration.value.value;
});

const extensionDiscount = computed(() => {
    if (extensionDuration.value.discount === 0) return 0;
    return (extensionSubtotal.value * extensionDuration.value.discount) / 100;
});

const extensionTotal = computed(() => {
    return extensionSubtotal.value - extensionDiscount.value;
});

const hasPendingRequest = computed(() => !!props.pendingRequest);

const updateDuration = (duration: Duration) => {
    selectedDuration.value = duration;
    upgradeRequestForm.duration_months = duration.value;
};

const updateExtensionDuration = (duration: Duration) => {
    extensionDuration.value = duration;
    extensionRequestForm.duration_months = duration.value;
};

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        const file = target.files[0];
        
        // Validate file type
        if (!file.type.startsWith('image/')) {
            showError('Error', 'File harus berupa gambar');
            return;
        }
        
        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            showError('Error', 'Ukuran file maksimal 5MB');
            return;
        }
        
        selectedFile.value = file;
        uploadForm.payment_proof = file;
        
        // Create preview
        const reader = new FileReader();
        reader.onload = (e) => {
            previewUrl.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);
    }
};

// Get subscription for upload (prioritize pending)
const subscriptionForUpload = computed(() => {
    // First, try pendingSubscription from props (full data)
    if (props.pendingSubscription) {
        return props.pendingSubscription;
    }
    // Then, try subscription if it's pending
    if (props.subscription?.status === 'pending') {
        return props.subscription;
    }
    // Finally, check subscriptions array for pending subscription (has ID but limited data)
    const pendingFromHistory = props.subscriptions.find(s => s.status === 'pending');
    if (pendingFromHistory) {
        // Return minimal data with ID for upload
        return {
            id: pendingFromHistory.id,
            package: pendingFromHistory.package,
            status: pendingFromHistory.status,
            payment_proof: null,
        };
    }
    return null;
});

const handleUpload = () => {
    const targetSubscription = subscriptionForUpload.value;
    if (!targetSubscription || !selectedFile.value) {
        showError('Error', 'Tidak ada subscription yang dapat diupload bukti pembayaran');
        return;
    }
    
    uploadForm.post(`/subscriptions/${targetSubscription.id}/upload-payment-proof`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Bukti pembayaran berhasil diupload');
            showPaymentProofDialog.value = false;
            selectedFile.value = null;
            previewUrl.value = null;
            uploadForm.reset();
        },
        onError: (errors) => {
            if (errors.payment_proof) {
                showError('Error', errors.payment_proof);
            } else {
                showError('Error', 'Gagal mengupload bukti pembayaran');
            }
        },
    });
};

const openPaymentProofDialog = () => {
    showPaymentProofDialog.value = true;
    selectedFile.value = null;
    previewUrl.value = null;
    uploadForm.reset();
};

const closePaymentProofDialog = () => {
    showPaymentProofDialog.value = false;
    selectedFile.value = null;
    previewUrl.value = null;
    uploadForm.reset();
};

const viewPaymentProof = (url: string) => {
    viewingPaymentProofUrl.value = url;
    showPaymentProofViewDialog.value = true;
};

const openUpgradeDialog = () => {
    selectedDuration.value = durationOptions.value[0] || { value: 1, label: '1 Bulan', discount: 0 };
    upgradeNotes.value = '';
    upgradeRequestForm.clearErrors();
    showUpgradeDialog.value = true;
};

const openExtensionDialog = () => {
    extensionDuration.value = durationOptions.value[0] || { value: 1, label: '1 Bulan', discount: 0 };
    extensionNotes.value = '';
    extensionRequestForm.clearErrors();
    showExtensionDialog.value = true;
};

const submitUpgradeRequest = () => {
    upgradeRequestForm.request_type = 'upgrade';
    upgradeRequestForm.plan = 'growth';
    upgradeRequestForm.duration_months = selectedDuration.value.value;
    upgradeRequestForm.notes = upgradeNotes.value;
    upgradeRequestForm.post('/subscriptions', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Permintaan Dikirim', 'Pengajuan upgrade berhasil dikirim. Admin akan segera menghubungi Anda.');
            upgradeNotes.value = '';
            showUpgradeDialog.value = false;
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0] as string | undefined;
            showError('Gagal', firstError || 'Gagal mengirim permintaan upgrade');
        },
    });
};

const submitExtensionRequest = () => {
    if (!props.subscription) return;
    extensionRequestForm.request_type = 'extend';
    extensionRequestForm.plan = 'growth';
    extensionRequestForm.duration_months = extensionDuration.value.value;
    extensionRequestForm.notes = extensionNotes.value;
    extensionRequestForm.post('/subscriptions', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Permintaan Dikirim', 'Pengajuan perpanjangan berhasil dikirim. Admin akan segera memprosesnya.');
            extensionNotes.value = '';
            showExtensionDialog.value = false;
        },
        onError: (errors) => {
            const firstError = Object.values(errors)[0] as string | undefined;
            showError('Gagal', firstError || 'Gagal mengirim permintaan perpanjangan');
        },
    });
};

// Copy to clipboard function
const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        showSuccess('Berhasil', 'Teks berhasil disalin ke clipboard');
    } catch (err) {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showSuccess('Berhasil', 'Teks berhasil disalin ke clipboard');
        } catch (err) {
            showError('Error', 'Gagal menyalin teks');
        }
        document.body.removeChild(textArea);
    }
};
</script>

<template>
    <Head title="Subscription" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-8 overflow-x-auto p-6 bg-gray-50/50 dark:bg-black/10">
            <!-- Header -->
            <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Subscription Management</h2>
                <p class="text-sm text-gray-500 mt-1">Kelola paket langganan Anda</p>
            </div>

            <!-- Pending Request Alert -->
            <div v-if="pendingRequest" class="rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm dark:border-amber-900/50 dark:bg-amber-950/30">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 mt-0.5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/50">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600 dark:text-amber-400">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-lg font-bold text-amber-900 dark:text-amber-50 mb-2">Pengajuan Sedang Diproses</h3>
                        <p class="text-base text-amber-800 dark:text-amber-200 leading-relaxed mb-3">
                            Anda sudah mengajukan <span class="font-bold text-amber-900 dark:text-amber-50">{{ pendingRequest.request_type === 'extend' ? 'perpanjangan' : 'upgrade' }}</span> paket
                            <span class="font-bold text-amber-900 dark:text-amber-50">{{ formatPackage(pendingRequest.plan) }}</span> selama
                            <span class="font-bold text-amber-900 dark:text-amber-50">{{ pendingRequest.duration_months }} bulan</span>.
                            Admin akan menghubungi Anda segera untuk proses pembayaran.
                        </p>
                        <div class="flex flex-wrap gap-4 pt-4 border-t border-amber-200 dark:border-amber-800/50">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-700 dark:text-amber-400">
                                    <rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect>
                                    <line x1="16" x2="16" y1="2" y2="6"></line>
                                    <line x1="8" x2="8" y1="2" y2="6"></line>
                                    <line x1="3" x2="21" y1="10" y2="10"></line>
                                </svg>
                                <span class="text-sm font-semibold text-amber-900 dark:text-amber-100">Diajukan: {{ formatDate(pendingRequest.created_at) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-700 dark:text-amber-400">
                                    <line x1="12" x2="12" y1="2" y2="22"></line>
                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                </svg>
                                <span class="text-sm font-semibold text-amber-900 dark:text-amber-100">Total: <span class="font-bold text-lg">{{ formatCurrency(Number(pendingRequest.price)) }}</span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Subscription Card -->
            <div v-if="subscription" class="rounded-2xl bg-white p-8 shadow-[0_2px_10px_rgba(0,0,0,0.04)] dark:bg-gray-800">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatPackage(subscription.package) }}</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Berakhir: {{ formatDate(subscription.ends_at) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span
                            class="rounded-full px-3 py-1 text-sm font-medium"
                            :class="{
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400': subscription.status === 'active',
                                'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400': subscription.status === 'pending',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400': subscription.status === 'expired',
                            }"
                        >
                            {{ subscription.status === 'pending' ? 'Menunggu Pembayaran' : subscription.status === 'active' ? 'Aktif' : 'Kedaluwarsa' }}
                        </span>
                        <Link
                            v-if="subscription.status === 'active' && !hasPendingRequest"
                            href="/subscriptions/new"
                            class="inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            Perpanjang
                        </Link>
                    </div>
                </div>

                <!-- Payment Proof Section (for pending status) -->
                <div v-if="subscription.status === 'pending' || pendingSubscription" class="rounded-xl bg-gray-50 p-6 border border-gray-100 dark:bg-gray-700/30 dark:border-gray-700">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-1">Bukti Pembayaran</h4>
                            <p v-if="subscriptionForUpload?.payment_proof" class="text-sm text-gray-500">
                                Bukti pembayaran sudah diupload. Menunggu verifikasi admin.
                            </p>
                            <p v-else class="text-sm text-gray-500">
                                Silakan upload bukti transfer untuk mempercepat proses verifikasi.
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <Button
                                v-if="subscriptionForUpload?.payment_proof"
                                @click="viewPaymentProof(subscriptionForUpload.payment_proof!)"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="rounded-xl"
                                title="Lihat Bukti Pembayaran"
                            >
                                <Eye class="mr-2 h-4 w-4" />
                                Lihat
                            </Button>
                            <Button
                                @click="openPaymentProofDialog"
                                type="button"
                                :variant="subscriptionForUpload?.payment_proof ? 'outline' : 'default'"
                                size="sm"
                                class="rounded-xl"
                                :class="subscriptionForUpload?.payment_proof ? '' : 'text-white'"
                                :style="subscriptionForUpload?.payment_proof ? '' : 'background-color: oklch(0.65 0.19 137.46);'"
                            >
                                <Upload class="mr-2 h-4 w-4" />
                                {{ subscriptionForUpload?.payment_proof ? 'Ganti Bukti' : 'Upload Bukti' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Active Subscription Info (if pending is displayed) -->
                <div v-if="subscription.status === 'pending' && activeSubscription" class="mt-6 rounded-xl bg-blue-50 p-4 border border-blue-100 dark:bg-blue-900/20 dark:border-blue-800/30">
                    <p class="text-xs font-semibold text-blue-600 dark:text-blue-400 mb-1">PAKET AKTIF SAAT INI</p>
                    <div class="flex justify-between items-center">
                        <p class="text-base font-bold text-blue-900 dark:text-blue-100">{{ formatPackage(activeSubscription.package) }}</p>
                        <p class="text-sm text-blue-700 dark:text-blue-300">Berakhir: {{ formatDate(activeSubscription.ends_at) }}</p>
                    </div>
                </div>
            </div>

            <!-- Free Trial / No Subscription Card -->
            <div v-else class="rounded-2xl bg-white p-8 shadow-[0_2px_10px_rgba(0,0,0,0.04)] dark:bg-gray-800">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-6">
                    <div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">Free Trial</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            <span v-if="tenant.trial_ends_at">
                                Berakhir: {{ formatDate(tenant.trial_ends_at) }}
                            </span>
                            <span v-else>
                                Upgrade untuk mengakses semua fitur
                            </span>
                        </p>
                    </div>
                    <Link
                        v-if="!hasPendingRequest"
                        href="/subscriptions/new"
                        class="inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-90"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        Upgrade Sekarang
                    </Link>
                </div>

                <!-- Payment Proof Section (if there's a pending subscription in history) -->
                <div v-if="pendingSubscription || (subscriptions.find(s => s.status === 'pending'))" class="rounded-xl bg-gray-50 p-6 border border-gray-100 dark:bg-gray-700/30 dark:border-gray-700">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-1">Upload Bukti Pembayaran</h4>
                            <p class="text-sm text-gray-500">
                                Anda memiliki subscription yang menunggu pembayaran. Silakan upload bukti transfer.
                            </p>
                        </div>
                        <Button
                            @click="openPaymentProofDialog"
                            type="button"
                            size="sm"
                            class="rounded-xl text-white"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            <Upload class="mr-2 h-4 w-4" />
                            Upload Bukti
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Subscription History -->
            <div class="rounded-2xl bg-white shadow-[0_2px_10px_rgba(0,0,0,0.04)] dark:bg-gray-800 overflow-hidden">
                <div class="border-b border-gray-100 p-6 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Riwayat Subscription</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Paket</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Mulai</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Berakhir</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Dibuat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr
                                v-for="sub in subscriptions"
                                :key="sub.id"
                                class="hover:bg-gray-50/50 dark:hover:bg-gray-700/50 transition-colors"
                            >
                                <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white">{{ formatPackage(sub.package) }}</td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400': sub.status === 'active',
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400': sub.status === 'pending' || sub.status === 'cancelled',
                                            'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400': sub.status === 'expired',
                                        }"
                                    >
                                        {{ sub.status === 'pending' ? 'Menunggu Pembayaran' : sub.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ formatDate(sub.starts_at) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ formatDate(sub.ends_at) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ formatDate(sub.created_at) }}</td>
                            </tr>
                            <tr v-if="subscriptions.length === 0">
                                <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada riwayat subscription
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Upgrade Dialog -->
        <Dialog :open="showUpgradeDialog" @update:open="showUpgradeDialog = $event">
            <DialogContent class="!max-w-[95vw] sm:!max-w-2xl !max-h-[90vh] rounded-2xl p-0 gap-0 bg-white dark:bg-gray-800 flex flex-col">
                <DialogHeader class="p-6 pb-4 border-b border-gray-100 dark:border-gray-700 flex-shrink-0">
                    <DialogTitle class="text-xl font-bold text-gray-900 dark:text-white">Upgrade ke Paket Lengkap</DialogTitle>
                    <DialogDescription class="text-sm text-gray-500">Pilih durasi langganan dan metode pembayaran</DialogDescription>
                </DialogHeader>
                <div class="p-6 space-y-6 overflow-y-auto flex-1 min-h-0">
                    <!-- Plan Info -->
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-base font-bold text-gray-900 dark:text-white">{{ planDetails.name }}</h4>
                                <p class="text-sm text-gray-500">
                                    {{ formatCurrency(planDetails.monthly_price) }}/bulan
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Duration Selection -->
                    <div>
                        <Label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-300">Durasi Langganan</Label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                v-for="duration in durationOptions"
                                :key="duration.value"
                                type="button"
                                @click="updateDuration(duration)"
                                class="p-4 rounded-xl border transition-all text-left relative"
                                :class="selectedDuration.value === duration.value
                                    ? 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20 dark:border-green-500'
                                    : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600'"
                            >
                                <p class="text-sm font-bold text-gray-900 dark:text-white">{{ duration.label }}</p>
                                <p v-if="duration.discount > 0" class="text-xs font-medium text-green-600 dark:text-green-400 mt-1">
                                    Hemat {{ duration.discount }}%
                                </p>
                            </button>
                        </div>
                    </div>

                    <!-- Price Summary -->
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-900/50">
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(upgradeSubtotal) }}</span>
                            </div>
                            <div v-if="upgradeDiscount > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                <span>Diskon ({{ selectedDuration.discount }}%)</span>
                                <span>-{{ formatCurrency(upgradeDiscount) }}</span>
                            </div>
                            <div class="border-t border-gray-200 pt-2 flex justify-between items-center dark:border-gray-700">
                                <span class="font-bold text-gray-900 dark:text-white">Total</span>
                                <span class="text-lg font-bold text-gray-900 dark:text-white">{{ formatCurrency(upgradeTotal) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <Label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-300">Metode Pembayaran</Label>
                        <div class="space-y-3">
                            <!-- QRIS Payment Dropdown -->
                            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden dark:border-gray-700 dark:bg-gray-800">
                                <button 
                                    @click="selectedPaymentMethod = 'qris'"
                                    class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    :class="selectedPaymentMethod === 'qris' ? 'bg-gray-50 dark:bg-gray-700/50' : ''"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 dark:border-gray-600 flex items-center justify-center p-1">
                                            <img src="/qris.png" alt="QRIS" class="w-full h-full object-contain" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900 dark:text-white">QRIS</p>
                                            <p class="text-xs text-gray-500">Scan QR code untuk pembayaran instan</p>
                                        </div>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'qris' ? 'border-green-500 bg-green-500' : 'border-gray-300 dark:border-gray-600'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'qris'" class="w-2 h-2 rounded-full bg-white"></div>
                                    </div>
                                </button>
                                
                                <div v-show="selectedPaymentMethod === 'qris'" class="p-4 border-t border-gray-200 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/30">
                                    <div class="flex flex-col items-center text-center">
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Scan QRIS di bawah ini menggunakan aplikasi e-wallet atau mobile banking Anda.</p>
                                        
                                        <div 
                                            class="bg-white p-2 rounded-xl border border-gray-200 inline-block shadow-sm mb-3 cursor-pointer hover:shadow-md transition-shadow dark:bg-gray-800 dark:border-gray-700"
                                            @click="showQrZoom = true"
                                            title="Klik untuk memperbesar"
                                        >
                                            <img src="/qriss.png" alt="Scan QRIS" class="w-40 h-40 object-contain mx-auto" />
                                        </div>
                                        <p class="text-xs text-gray-400">Klik gambar untuk memperbesar</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Manual Transfer Dropdown -->
                            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden dark:border-gray-700 dark:bg-gray-800">
                                <button 
                                    @click="selectedPaymentMethod = 'manual'"
                                    class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    :class="selectedPaymentMethod === 'manual' ? 'bg-gray-50 dark:bg-gray-700/50' : ''"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 dark:border-gray-600 flex items-center justify-center text-green-600">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900 dark:text-white">Transfer Bank Manual</p>
                                            <p class="text-xs text-gray-500">Transfer ke rekening bank admin</p>
                                        </div>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'manual' ? 'border-green-500 bg-green-500' : 'border-gray-300 dark:border-gray-600'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'manual'" class="w-2 h-2 rounded-full bg-white"></div>
                                    </div>
                                </button>

                                <div v-show="selectedPaymentMethod === 'manual'" class="p-4 border-t border-gray-200 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/30">
                                    <!-- Bank Accounts -->
                                    <div v-if="banks && banks.length > 0" class="space-y-3">
                                        <div
                                            v-for="bank in banks"
                                            :key="bank.id"
                                            class="p-3 rounded-xl bg-white border border-gray-200 shadow-sm dark:bg-gray-800 dark:border-gray-700"
                                        >
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="flex-1">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ bank.name }}</p>
                                                    <div class="space-y-1.5">
                                                        <div class="flex items-center gap-2 flex-wrap">
                                                            <span class="text-xs text-gray-500 min-w-[90px]">No. Rekening:</span>
                                                            <span class="text-sm font-mono text-gray-900 dark:text-white font-medium">{{ bank.account_number }}</span>
                                                            <button
                                                                @click="copyToClipboard(bank.account_number)"
                                                                type="button"
                                                                class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                                                title="Salin nomor rekening"
                                                            >
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2h8c1.1 0 2 .9 2 2"></path></svg>
                                                            </button>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-xs text-gray-500 min-w-[90px]">Atas Nama:</span>
                                                            <span class="text-sm text-gray-900 dark:text-white">{{ bank.account_name }}</span>
                                                        </div>
                                                        <div v-if="bank.description" class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                                            <p class="text-xs text-gray-500">{{ bank.description }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="p-4 rounded-lg bg-yellow-50 border border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-800">
                                        <p class="text-sm text-yellow-700 dark:text-yellow-300">Belum ada rekening bank yang tersedia. Silakan hubungi admin untuk informasi pembayaran.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <Label for="upgrade_notes" class="text-sm font-medium text-gray-700 dark:text-gray-300">Catatan (Opsional)</Label>
                        <textarea
                            id="upgrade_notes"
                            v-model="upgradeNotes"
                            rows="2"
                            class="mt-1.5 w-full rounded-xl border-gray-200 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            placeholder="Sertakan catatan tambahan untuk admin"
                        ></textarea>
                        <InputError :message="upgradeRequestForm.errors.notes" />
                    </div>
                </div>

                <!-- Footer with buttons -->
                <div class="p-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex-shrink-0 bg-white dark:bg-gray-800">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showUpgradeDialog = false"
                            class="w-full sm:w-auto rounded-xl order-2 sm:order-1"
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            @click="submitUpgradeRequest"
                            :disabled="upgradeRequestForm.processing"
                            class="w-full sm:flex-1 text-white hover:opacity-90 rounded-xl order-1 sm:order-2"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            {{ upgradeRequestForm.processing ? 'Mengirim...' : 'Ajukan Upgrade' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Extension Dialog -->
        <Dialog :open="showExtensionDialog" @update:open="showExtensionDialog = $event">
            <DialogContent class="!max-w-[95vw] sm:!max-w-2xl !max-h-[90vh] rounded-2xl p-0 gap-0 bg-white dark:bg-gray-800 flex flex-col">
                <DialogHeader class="p-6 pb-4 border-b border-gray-100 dark:border-gray-700 flex-shrink-0">
                    <DialogTitle class="text-xl font-bold text-gray-900 dark:text-white">Perpanjang Paket</DialogTitle>
                    <DialogDescription class="text-sm text-gray-500">Pilih durasi perpanjangan dan metode pembayaran</DialogDescription>
                </DialogHeader>
                <div class="p-6 space-y-6 overflow-y-auto flex-1 min-h-0">
                    <!-- Plan Info -->
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-base font-bold text-gray-900 dark:text-white">{{ subscription ? formatPackage(subscription.package) : 'Paket Lengkap' }}</h4>
                                <p class="text-sm text-gray-500">
                                    {{ formatCurrency(planDetails.monthly_price) }}/bulan
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Duration Selection -->
                    <div>
                        <Label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-300">Durasi Perpanjangan</Label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                v-for="duration in durationOptions"
                                :key="duration.value"
                                type="button"
                                @click="updateExtensionDuration(duration)"
                                class="p-4 rounded-xl border transition-all text-left relative"
                                :class="extensionDuration.value === duration.value
                                    ? 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20 dark:border-green-500'
                                    : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600'"
                            >
                                <p class="text-sm font-bold text-gray-900 dark:text-white">{{ duration.label }}</p>
                                <p v-if="duration.discount > 0" class="text-xs font-medium text-green-600 dark:text-green-400 mt-1">
                                    Hemat {{ duration.discount }}%
                                </p>
                            </button>
                        </div>
                    </div>

                    <!-- Price Summary -->
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-900/50">
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(extensionSubtotal) }}</span>
                            </div>
                            <div v-if="extensionDiscount > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                <span>Diskon ({{ extensionDuration.discount }}%)</span>
                                <span>-{{ formatCurrency(extensionDiscount) }}</span>
                            </div>
                            <div class="border-t border-gray-200 pt-2 flex justify-between items-center dark:border-gray-700">
                                <span class="font-bold text-gray-900 dark:text-white">Total</span>
                                <span class="text-lg font-bold text-gray-900 dark:text-white">{{ formatCurrency(extensionTotal) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div>
                        <Label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-300">Metode Pembayaran</Label>
                        <div class="space-y-3">
                            <!-- QRIS Payment Dropdown -->
                            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden dark:border-gray-700 dark:bg-gray-800">
                                <button 
                                    @click="selectedPaymentMethod = 'qris'"
                                    class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    :class="selectedPaymentMethod === 'qris' ? 'bg-gray-50 dark:bg-gray-700/50' : ''"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 dark:border-gray-600 flex items-center justify-center p-1">
                                            <img src="/qris.png" alt="QRIS" class="w-full h-full object-contain" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900 dark:text-white">QRIS</p>
                                            <p class="text-xs text-gray-500">Scan QR code untuk pembayaran instan</p>
                                        </div>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'qris' ? 'border-green-500 bg-green-500' : 'border-gray-300 dark:border-gray-600'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'qris'" class="w-2 h-2 rounded-full bg-white"></div>
                                    </div>
                                </button>
                                
                                <div v-show="selectedPaymentMethod === 'qris'" class="p-4 border-t border-gray-200 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/30">
                                    <div class="flex flex-col items-center text-center">
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Scan QRIS di bawah ini menggunakan aplikasi e-wallet atau mobile banking Anda.</p>
                                        
                                        <div 
                                            class="bg-white p-2 rounded-xl border border-gray-200 inline-block shadow-sm mb-3 cursor-pointer hover:shadow-md transition-shadow dark:bg-gray-800 dark:border-gray-700"
                                            @click="showQrZoom = true"
                                            title="Klik untuk memperbesar"
                                        >
                                            <img src="/qriss.png" alt="Scan QRIS" class="w-40 h-40 object-contain mx-auto" />
                                        </div>
                                        <p class="text-xs text-gray-400">Klik gambar untuk memperbesar</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Manual Transfer Dropdown -->
                            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden dark:border-gray-700 dark:bg-gray-800">
                                <button 
                                    @click="selectedPaymentMethod = 'manual'"
                                    class="w-full flex items-center justify-between p-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    :class="selectedPaymentMethod === 'manual' ? 'bg-gray-50 dark:bg-gray-700/50' : ''"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-white border border-gray-200 dark:border-gray-600 flex items-center justify-center text-green-600">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900 dark:text-white">Transfer Bank Manual</p>
                                            <p class="text-xs text-gray-500">Transfer ke rekening bank admin</p>
                                        </div>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'manual' ? 'border-green-500 bg-green-500' : 'border-gray-300 dark:border-gray-600'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'manual'" class="w-2 h-2 rounded-full bg-white"></div>
                                    </div>
                                </button>

                                <div v-show="selectedPaymentMethod === 'manual'" class="p-4 border-t border-gray-200 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/30">
                                    <!-- Bank Accounts -->
                                    <div v-if="banks && banks.length > 0" class="space-y-3">
                                        <div
                                            v-for="bank in banks"
                                            :key="bank.id"
                                            class="p-3 rounded-xl bg-white border border-gray-200 shadow-sm dark:bg-gray-800 dark:border-gray-700"
                                        >
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="flex-1">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ bank.name }}</p>
                                                    <div class="space-y-1.5">
                                                        <div class="flex items-center gap-2 flex-wrap">
                                                            <span class="text-xs text-gray-500 min-w-[90px]">No. Rekening:</span>
                                                            <span class="text-sm font-mono text-gray-900 dark:text-white font-medium">{{ bank.account_number }}</span>
                                                            <button
                                                                @click="copyToClipboard(bank.account_number)"
                                                                type="button"
                                                                class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                                                title="Salin nomor rekening"
                                                            >
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2h8c1.1 0 2 .9 2 2"></path></svg>
                                                            </button>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-xs text-gray-500 min-w-[90px]">Atas Nama:</span>
                                                            <span class="text-sm text-gray-900 dark:text-white">{{ bank.account_name }}</span>
                                                        </div>
                                                        <div v-if="bank.description" class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                                            <p class="text-xs text-gray-500">{{ bank.description }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="p-4 rounded-lg bg-yellow-50 border border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-800">
                                        <p class="text-sm text-yellow-700 dark:text-yellow-300">Belum ada rekening bank yang tersedia. Silakan hubungi admin untuk informasi pembayaran.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <Label for="extension_notes" class="text-sm font-medium text-gray-700 dark:text-gray-300">Catatan (Opsional)</Label>
                        <textarea
                            id="extension_notes"
                            v-model="extensionNotes"
                            rows="2"
                            class="mt-1.5 w-full rounded-xl border-gray-200 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            placeholder="Sertakan catatan tambahan untuk admin"
                        ></textarea>
                        <InputError :message="extensionRequestForm.errors.notes" />
                    </div>
                </div>

                <!-- Footer with buttons -->
                <div class="p-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex-shrink-0 bg-white dark:bg-gray-800">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showExtensionDialog = false"
                            class="w-full sm:w-auto rounded-xl order-2 sm:order-1"
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            @click="submitExtensionRequest"
                            :disabled="extensionRequestForm.processing"
                            class="w-full sm:flex-1 text-white hover:opacity-90 rounded-xl order-1 sm:order-2"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            {{ extensionRequestForm.processing ? 'Mengirim...' : 'Ajukan Perpanjangan' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- QR Code Zoom Modal -->
        <Dialog v-model:open="showQrZoom">
            <DialogContent class="max-w-md p-6 rounded-2xl">
                <div class="flex flex-col items-center text-center">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Scan QRIS</h3>
                    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-lg dark:bg-gray-800 dark:border-gray-700">
                        <img src="/qriss.png" alt="Scan QRIS" class="w-72 h-72 object-contain mx-auto" />
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">Scan menggunakan aplikasi e-wallet atau mobile banking Anda</p>
                    <Button 
                        @click="showQrZoom = false" 
                        class="mt-4 text-white"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        Tutup
                    </Button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Payment Proof View Dialog -->
        <Dialog :open="showPaymentProofViewDialog" @update:open="showPaymentProofViewDialog = $event">
            <DialogContent class="max-w-4xl rounded-2xl p-0 overflow-hidden bg-white dark:bg-gray-800">
                <DialogHeader class="p-6 pb-4">
                    <DialogTitle class="text-xl font-bold text-gray-900 dark:text-white">Bukti Pembayaran</DialogTitle>
                    <DialogDescription class="text-sm text-gray-500">Bukti transfer pembayaran</DialogDescription>
                </DialogHeader>
                <div v-if="viewingPaymentProofUrl" class="p-6 pt-0 space-y-6">
                    <div class="relative rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                        <img 
                            :src="viewingPaymentProofUrl" 
                            alt="Bukti Pembayaran" 
                            class="w-full h-auto rounded-lg max-h-[60vh] object-contain mx-auto" 
                        />
                    </div>
                    <div class="flex justify-between items-center">
                        <a
                            :href="viewingPaymentProofUrl"
                            target="_blank"
                            class="text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline inline-flex items-center gap-2"
                        >
                            <Eye class="w-4 h-4" />
                            Buka di tab baru
                        </a>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showPaymentProofViewDialog = false"
                            class="rounded-xl"
                        >
                            Tutup
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Payment Proof Upload Dialog -->
        <Dialog :open="showPaymentProofDialog" @update:open="showPaymentProofDialog = $event">
            <DialogContent class="max-w-lg rounded-2xl p-0 overflow-hidden bg-white dark:bg-gray-800">
                <DialogHeader class="p-6 pb-0">
                    <DialogTitle class="text-xl font-bold text-gray-900 dark:text-white">Upload Bukti Pembayaran</DialogTitle>
                    <DialogDescription class="text-sm text-gray-500">
                        Upload bukti transfer pembayaran Anda. Format: JPG, PNG, GIF, WebP (maks. 5MB)
                    </DialogDescription>
                </DialogHeader>
                <div class="p-6 space-y-6">
                    <!-- Current Payment Proof (if exists) -->
                    <div v-if="subscriptionForUpload?.payment_proof && !previewUrl" class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                        <Label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Bukti Pembayaran Saat Ini</Label>
                        <div class="relative">
                            <img :src="subscriptionForUpload.payment_proof" alt="Bukti Pembayaran" class="w-full h-auto rounded-lg border border-gray-200 max-h-48 object-contain bg-white" />
                            <a
                                :href="subscriptionForUpload.payment_proof"
                                target="_blank"
                                class="mt-2 inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline"
                            >
                                <Eye class="w-4 h-4" />
                                Lihat di tab baru
                            </a>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">
                            Anda dapat mengganti bukti pembayaran dengan mengupload file baru di bawah.
                        </p>
                    </div>

                    <!-- File Input -->
                    <div>
                        <Label for="payment_proof" class="text-sm font-medium text-gray-700 dark:text-gray-300">Pilih File Baru</Label>
                        <Input
                            id="payment_proof"
                            type="file"
                            accept="image/*"
                            @change="handleFileSelect"
                            class="mt-1.5 rounded-xl cursor-pointer file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100"
                        />
                        <InputError :message="uploadForm.errors.payment_proof" />
                    </div>

                    <!-- Preview New File -->
                    <div v-if="previewUrl" class="relative">
                        <Label class="text-sm font-medium text-gray-700 dark:text-gray-300">Preview File Baru</Label>
                        <div class="mt-2 relative">
                            <img :src="previewUrl" alt="Preview" class="w-full h-auto rounded-lg border border-gray-200 max-h-48 object-contain bg-white" />
                            <button
                                @click="previewUrl = null; selectedFile = null; uploadForm.payment_proof = null"
                                type="button"
                                class="absolute top-2 right-2 p-1.5 bg-red-500 text-white rounded-full hover:bg-red-600 transition-colors shadow-sm"
                                title="Hapus preview"
                            >
                                <X class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="closePaymentProofDialog"
                            class="rounded-xl"
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            @click="handleUpload"
                            :disabled="!selectedFile || uploadForm.processing || !subscriptionForUpload"
                            style="background-color: oklch(0.65 0.19 137.46);"
                            class="text-white disabled:opacity-50 rounded-xl"
                        >
                            {{ uploadForm.processing ? 'Mengupload...' : subscriptionForUpload?.payment_proof ? 'Ganti Bukti' : 'Upload Bukti' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
