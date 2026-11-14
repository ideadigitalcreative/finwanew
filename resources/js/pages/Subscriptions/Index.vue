<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Subscription', href: '/subscriptions' },
];

interface Duration {
    value: number;
    label: string;
    discount: number;
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
    plans: Record<string, {
        slug: string;
        name: string;
        monthly_price: number;
        description: string;
        features: string[];
    }>;
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

type Plan = Props['plans'][string];

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

const durationOptions = computed<Duration[]>(() => props.durationOptions ?? []);
const planList = computed<Plan[]>(() => Object.values(props.plans ?? {}));

const fallbackPlan: Plan = {
    slug: 'growth',
    name: 'Paket Lite',
    monthly_price: 0,
    description: '',
    features: [],
};
const fallbackDuration: Duration = { value: 1, label: '1 Bulan', discount: 0 };

const selectedPlan = ref<Plan>(props.plans?.lite ?? planList.value[0] ?? fallbackPlan);
const selectedDuration = ref<Duration>(durationOptions.value[0] ?? fallbackDuration);
const upgradeNotes = ref('');

const extensionDuration = ref<Duration>(durationOptions.value[0] ?? fallbackDuration);
const extensionNotes = ref('');

const upgradeRequestForm = useForm({
    request_type: 'upgrade',
    plan: selectedPlan.value.slug,
    duration_months: selectedDuration.value.value,
    notes: '',
});

const extensionRequestForm = useForm({
    request_type: 'extend',
    plan: props.subscription?.package === 'pro' ? 'pro' : 'growth',
    duration_months: extensionDuration.value.value,
    notes: '',
});

watchEffect(() => {
    const preferredPlan = props.plans?.lite ?? planList.value[0];
    if (preferredPlan && selectedPlan.value === fallbackPlan) {
        selectedPlan.value = preferredPlan;
    }
    const preferredDuration = durationOptions.value[0];
    if (preferredDuration && selectedDuration.value === fallbackDuration) {
        selectedDuration.value = preferredDuration;
        extensionDuration.value = preferredDuration;
    }
    upgradeRequestForm.plan = selectedPlan.value.slug;
    upgradeRequestForm.duration_months = selectedDuration.value.value;
    extensionRequestForm.duration_months = extensionDuration.value.value;
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
    if (pkg === 'growth') return 'Paket Lite';
    if (pkg === 'pro') return 'Paket PRO';
    if (pkg === 'free') return 'Free Trial';
    if (pkg === 'paid') return 'Paket Premium';
    
    for (const key in props.plans) {
        if (props.plans[key].slug === pkg) return props.plans[key].name;
    }
    
    return pkg || '-';
};

const upgradeSubtotal = computed(() => {
    return selectedPlan.value.monthly_price * selectedDuration.value.value;
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
    const pkg = props.subscription.package;
    const planKey = pkg === 'pro' ? 'pro' : 'lite';
    const monthlyPrice = props.plans[planKey]?.monthly_price || 20000;
    return monthlyPrice * extensionDuration.value.value;
});

const extensionDiscount = computed(() => {
    if (extensionDuration.value.discount === 0) return 0;
    return (extensionSubtotal.value * extensionDuration.value.discount) / 100;
});

const extensionTotal = computed(() => {
    return extensionSubtotal.value - extensionDiscount.value;
});

const hasPendingRequest = computed(() => !!props.pendingRequest);

const updatePlan = (plan: typeof selectedPlan.value) => {
    selectedPlan.value = plan;
    upgradeRequestForm.plan = plan.slug;
};

const updateDuration = (duration: Duration) => {
    selectedDuration.value = duration;
    upgradeRequestForm.duration_months = duration.value;
};

const updateExtensionDuration = (duration: Duration) => {
    extensionDuration.value = duration;
    extensionRequestForm.duration_months = duration.value;
};

const submitUpgradeRequest = () => {
    upgradeRequestForm.notes = upgradeNotes.value;
    upgradeRequestForm.post('/subscriptions/request', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Permintaan upgrade berhasil diajukan. Silakan upload bukti pembayaran jika sudah transfer.');
            showUpgradeDialog.value = false;
        },
        onError: () => {
            showError('Gagal', 'Terjadi kesalahan saat mengajukan upgrade.');
        },
    });
};

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        const file = target.files[0];
        
        if (!file.type.startsWith('image/')) {
            showError('Error', 'File harus berupa gambar');
            return;
        }
        
        if (file.size > 5 * 1024 * 1024) {
            showError('Error', 'Ukuran file maksimal 5MB');
            return;
        }
        
        selectedFile.value = file;
        uploadForm.payment_proof = file;
        
        const reader = new FileReader();
        reader.onload = (e) => {
            previewUrl.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);
    }
};

const subscriptionForUpload = computed(() => {
    if (props.pendingSubscription) {
        return props.pendingSubscription;
    }
    if (props.subscription?.status === 'pending') {
        return props.subscription;
    }
    const pendingFromHistory = props.subscriptions.find(s => s.status === 'pending');
    if (pendingFromHistory) {
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

const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        showSuccess('Berhasil', 'Nomor rekening disalin ke clipboard');
    } catch {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showSuccess('Berhasil', 'Nomor rekening disalin ke clipboard');
        } catch {
            showError('Error', 'Gagal menyalin teks');
        }
        document.body.removeChild(textArea);
    }
};
</script>

<template>
    <Head title="Subscription Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="bg-[#fbf9f3] min-h-screen flex h-full flex-1 flex-col gap-6 p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2.5">
                        <span class="w-10 h-10 rounded-xl bg-[#ffd23f] text-[#574500] flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-[24px]">verified</span>
                        </span>
                        <div>
                            <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19]">Langganan & Paket</h2>
                            <p class="text-xs md:text-sm text-[#4d4634]">Kelola status langganan dan akses fitur aplikasi FinWa Anda</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        v-if="!hasPendingRequest"
                        href="/subscriptions/new"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] rounded-xl font-bold text-sm shadow-sm transition-all active:scale-95"
                    >
                        <span class="material-symbols-outlined text-lg">add_circle</span>
                        {{ subscription ? 'Perpanjang / Ganti Paket' : 'Upgrade Paket' }}
                    </Link>
                </div>
            </div>

            <!-- Pending Request Alert Banner -->
            <div v-if="pendingRequest" class="rounded-2xl border border-[#ffd23f] bg-[#fff9e6] p-5 shadow-sm">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 mt-0.5">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#ffd23f] text-[#574500]">
                            <span class="material-symbols-outlined text-2xl">pending_actions</span>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                            <h3 class="text-base md:text-lg font-bold text-[#574500]">Pengajuan Sedang Diproses Admin</h3>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-[#ffd23f] text-[#574500]">
                                <span class="material-symbols-outlined text-sm">schedule</span>
                                Menunggu Verifikasi
                            </span>
                        </div>
                        <p class="text-sm text-[#4d4634] leading-relaxed mb-4">
                            Anda mengajukan permintaan <span class="font-bold text-[#1b1c19]">{{ pendingRequest.request_type === 'extend' ? 'perpanjangan' : 'upgrade' }}</span> untuk
                            <span class="font-bold text-[#1b1c19]">{{ formatPackage(pendingRequest.plan) }}</span> selama
                            <span class="font-bold text-[#1b1c19]">{{ pendingRequest.duration_months }} bulan</span>.
                            Tim admin sedang memeriksa pembayaran dan akan segera mengaktifkan status akun Anda.
                        </p>

                        <div class="flex flex-wrap gap-4 pt-3 border-t border-[#ffd23f]/40">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-base text-[#725a00]">calendar_today</span>
                                <span class="text-xs md:text-sm font-semibold text-[#574500]">Diajukan: {{ formatDate(pendingRequest.created_at) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-base text-[#725a00]">payments</span>
                                <span class="text-xs md:text-sm font-semibold text-[#574500]">Total Biaya: <strong class="text-[#1b1c19] text-base">{{ formatCurrency(Number(pendingRequest.price)) }}</strong></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Current Subscription Card -->
            <div v-if="subscription" class="rounded-2xl bg-white p-6 md:p-8 border border-[#eae8e2] shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 pb-6 border-b border-[#eae8e2]">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-sm"
                            :class="subscription.status === 'active' ? 'bg-[#51fac1]/30 text-[#006c4f]' : 'bg-[#ffd23f]/30 text-[#725a00]'"
                        >
                            <span class="material-symbols-outlined text-2xl">
                                {{ subscription.status === 'active' ? 'workspace_premium' : 'receipt_long' }}
                            </span>
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <h3 class="text-2xl font-black text-[#1b1c19] tracking-tight">{{ formatPackage(subscription.package) }}</h3>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold"
                                    :class="{
                                        'bg-[#51fac1]/30 text-[#006c4f]': subscription.status === 'active',
                                        'bg-[#ffd23f]/40 text-[#725a00]': subscription.status === 'pending',
                                        'bg-[#ffc9d0]/60 text-[#ad2c4f]': subscription.status === 'expired',
                                    }"
                                >
                                    <span class="material-symbols-outlined text-sm">
                                        {{ subscription.status === 'active' ? 'check_circle' : subscription.status === 'pending' ? 'timelapse' : 'cancel' }}
                                    </span>
                                    {{ subscription.status === 'pending' ? 'Menunggu Pembayaran' : subscription.status === 'active' ? 'Aktif' : 'Kedaluwarsa' }}
                                </span>
                            </div>
                            <p class="text-sm text-[#4d4634] mt-1 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base text-[#4d4634]/60">event</span>
                                Masa Berlaku: <span class="font-semibold text-[#1b1c19]">{{ formatDate(subscription.ends_at) }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <Link
                            v-if="subscription.status === 'active' && !hasPendingRequest"
                            href="/subscriptions/new"
                            class="inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] shadow-sm transition-all active:scale-95"
                        >
                            <span class="material-symbols-outlined text-lg">autorenew</span>
                            Perpanjang Sekarang
                        </Link>
                    </div>
                </div>

                <!-- Info Banner if Active exists while pending is shown -->
                <div v-if="subscription.status === 'pending' && activeSubscription" class="mt-6 rounded-xl bg-[#51fac1]/15 p-4 border border-[#51fac1]/40">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-2.5">
                            <span class="material-symbols-outlined text-[#006c4f] text-xl">verified</span>
                            <div>
                                <p class="text-xs font-bold text-[#006c4f] uppercase tracking-wider">Paket Anda yang Masih Aktif</p>
                                <p class="text-base font-black text-[#006c4f]">{{ formatPackage(activeSubscription.package) }}</p>
                            </div>
                        </div>
                        <p class="text-xs md:text-sm font-semibold text-[#006c4f]">
                            Berlaku hingga: <span class="underline">{{ formatDate(activeSubscription.ends_at) }}</span>
                        </p>
                    </div>
                </div>

                <!-- Payment Proof Upload Alert (When Pending) -->
                <div v-if="subscription.status === 'pending' || pendingSubscription" class="mt-6 rounded-xl bg-[#fff9e6] p-5 border border-[#ffd23f]/50">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-[#725a00] text-2xl mt-0.5">receipt</span>
                            <div>
                                <h4 class="text-sm font-bold text-[#1b1c19]">Bukti Pembayaran Tagihan</h4>
                                <p v-if="subscriptionForUpload?.payment_proof" class="text-xs md:text-sm text-[#4d4634] mt-0.5">
                                    Bukti pembayaran telah berhasil dikirimkan. Tim FinWa sedang memverifikasi dana Anda.
                                </p>
                                <p v-else class="text-xs md:text-sm text-[#4d4634] mt-0.5">
                                    Silakan unggah bukti transfer/QRIS untuk mengonfirmasi perpanjangan atau upgrade akun.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2.5 flex-shrink-0">
                            <Button
                                v-if="subscriptionForUpload?.payment_proof"
                                @click="viewPaymentProof(subscriptionForUpload.payment_proof!)"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="rounded-xl border-[#eae8e2] bg-white text-[#1b1c19] hover:bg-[#f5f3ee] font-semibold"
                            >
                                <span class="material-symbols-outlined text-lg mr-1.5 text-[#4d4634]">visibility</span>
                                Lihat Bukti
                            </Button>
                            <Button
                                @click="openPaymentProofDialog"
                                type="button"
                                size="sm"
                                class="rounded-xl bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold shadow-sm active:scale-95"
                            >
                                <span class="material-symbols-outlined text-lg mr-1.5">upload</span>
                                {{ subscriptionForUpload?.payment_proof ? 'Ganti Bukti' : 'Unggah Bukti' }}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Free Trial / No Subscription Card -->
            <div v-else class="rounded-2xl bg-white p-6 md:p-8 border border-[#eae8e2] shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-[#ffd23f]/25 text-[#725a00] flex items-center justify-center shadow-sm">
                            <span class="material-symbols-outlined text-2xl">hourglass_empty</span>
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <h3 class="text-2xl font-black text-[#1b1c19]">Masa Uji Coba Gratis (Free Trial)</h3>
                                <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold bg-[#ffd23f]/30 text-[#725a00]">
                                    <span class="material-symbols-outlined text-sm">schedule</span>
                                    Trial
                                </span>
                            </div>
                            <p class="text-sm text-[#4d4634] mt-1">
                                <span v-if="tenant.trial_ends_at">
                                    Berakhir pada: <strong class="text-[#1b1c19]">{{ formatDate(tenant.trial_ends_at) }}</strong>
                                </span>
                                <span v-else>
                                    Tingkatkan ke paket Pro atau Lite untuk membuka seluruh fitur otomatisasi keuangan FinWa.
                                </span>
                            </p>
                        </div>
                    </div>

                    <Link
                        v-if="!hasPendingRequest"
                        href="/subscriptions/new"
                        class="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] shadow-sm transition-all active:scale-95"
                    >
                        <span class="material-symbols-outlined text-lg">rocket_launch</span>
                        Upgrade Sekarang
                    </Link>
                </div>

                <!-- Proof upload prompt if pending subscription in history -->
                <div v-if="pendingSubscription || (subscriptions.find(s => s.status === 'pending'))" class="mt-6 rounded-xl bg-[#fff9e6] p-5 border border-[#ffd23f]/50">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h4 class="text-sm font-bold text-[#1b1c19] flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#725a00] text-lg">payment</span>
                                Ada Tagihan Menunggu Pembayaran
                            </h4>
                            <p class="text-xs md:text-sm text-[#4d4634] mt-1">
                                Anda telah memesan paket langganan. Silakan unggah bukti transfer agar akun langsung diaktifkan.
                            </p>
                        </div>
                        <Button
                            @click="openPaymentProofDialog"
                            type="button"
                            size="sm"
                            class="rounded-xl bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold shadow-sm active:scale-95"
                        >
                            <span class="material-symbols-outlined text-lg mr-1.5">upload</span>
                            Unggah Bukti Transfer
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Subscription History Table Card -->
            <div class="rounded-2xl bg-white border border-[#eae8e2] shadow-sm overflow-hidden">
                <div class="border-b border-[#eae8e2] p-5 md:p-6 bg-white flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="material-symbols-outlined text-[#725a00] text-xl">history</span>
                        <h3 class="text-base md:text-lg font-bold text-[#1b1c19]">Riwayat Langganan</h3>
                    </div>
                    <span class="text-xs font-semibold text-[#4d4634]">Total {{ subscriptions.length }} Transaksi</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-[#f5f3ee] border-b border-[#eae8e2]">
                            <tr>
                                <th class="px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-[#4d4634]">Paket</th>
                                <th class="px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-[#4d4634]">Status</th>
                                <th class="px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-[#4d4634]">Mulai</th>
                                <th class="px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-[#4d4634]">Berakhir</th>
                                <th class="px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-[#4d4634]">Dibuat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#eae8e2]">
                            <tr
                                v-for="sub in subscriptions"
                                :key="sub.id"
                                class="hover:bg-[#fbf9f3] transition-colors"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-7 h-7 rounded-lg bg-[#ffd23f]/25 text-[#725a00] flex items-center justify-center font-bold text-xs">
                                            {{ sub.package === 'pro' ? 'PRO' : 'L' }}
                                        </span>
                                        <span class="text-sm font-bold text-[#1b1c19]">{{ formatPackage(sub.package) }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                                        :class="{
                                            'bg-[#51fac1]/30 text-[#006c4f]': sub.status === 'active',
                                            'bg-[#ffd23f]/40 text-[#725a00]': sub.status === 'pending' || sub.status === 'cancelled',
                                            'bg-[#ffc9d0]/60 text-[#ad2c4f]': sub.status === 'expired',
                                        }"
                                    >
                                        <span class="material-symbols-outlined text-[14px]">
                                            {{ sub.status === 'active' ? 'check' : sub.status === 'pending' ? 'timelapse' : 'close' }}
                                        </span>
                                        {{ sub.status === 'pending' ? 'Menunggu Pembayaran' : sub.status === 'active' ? 'Aktif' : sub.status === 'expired' ? 'Kedaluwarsa' : sub.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-[#4d4634] font-medium">{{ formatDate(sub.starts_at) }}</td>
                                <td class="px-6 py-4 text-sm text-[#4d4634] font-medium">{{ formatDate(sub.ends_at) }}</td>
                                <td class="px-6 py-4 text-sm text-[#4d4634]/70">{{ formatDate(sub.created_at) }}</td>
                            </tr>
                            <tr v-if="subscriptions.length === 0">
                                <td colspan="5" class="px-6 py-12 text-center text-sm text-[#4d4634]">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <span class="material-symbols-outlined text-4xl text-[#4d4634]/40">receipt_long</span>
                                        <p class="font-medium text-[#4d4634]">Belum ada riwayat langganan yang tercatat</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Upgrade / Change Plan Dialog -->
        <Dialog :open="showUpgradeDialog" @update:open="showUpgradeDialog = $event">
            <DialogContent class="!max-w-[95vw] sm:!max-w-2xl !max-h-[90vh] rounded-2xl p-0 gap-0 bg-white flex flex-col font-['Plus_Jakarta_Sans',sans-serif]">
                <DialogHeader class="p-6 pb-4 border-b border-[#eae8e2] flex-shrink-0">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#725a00]">rocket_launch</span>
                        Upgrade ke Paket Lengkap
                    </DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]">Pilih durasi langganan dan metode pembayaran yang Anda inginkan</DialogDescription>
                </DialogHeader>

                <div class="p-6 space-y-6 overflow-y-auto flex-1 min-h-0">
                    <!-- Plan Selection -->
                    <div>
                        <Label class="mb-3 block text-sm font-bold text-[#1b1c19]">Pilih Paket</Label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                v-for="plan in plans"
                                :key="plan.slug"
                                type="button"
                                @click="updatePlan(plan)"
                                class="p-4 rounded-xl border transition-all text-left relative"
                                :class="selectedPlan.slug === plan.slug
                                    ? 'border-[#ffd23f] bg-[#fff9e6] ring-2 ring-[#ffd23f]'
                                    : 'border-[#eae8e2] bg-white hover:border-[#ffd23f]/50'"
                            >
                                <p class="text-base font-bold text-[#1b1c19]">{{ plan.name }}</p>
                                <p class="text-xs font-semibold text-[#725a00] mt-1">{{ formatCurrency(plan.monthly_price) }}/bulan</p>
                            </button>
                        </div>
                    </div>

                    <!-- Duration Selection -->
                    <div>
                        <Label class="mb-3 block text-sm font-bold text-[#1b1c19]">Durasi Langganan</Label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                v-for="duration in durationOptions"
                                :key="duration.value"
                                type="button"
                                @click="updateDuration(duration)"
                                class="p-4 rounded-xl border transition-all text-left relative"
                                :class="selectedDuration.value === duration.value
                                    ? 'border-[#ffd23f] bg-[#fff9e6] ring-2 ring-[#ffd23f]'
                                    : 'border-[#eae8e2] bg-white hover:border-[#ffd23f]/50'"
                            >
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-bold text-[#1b1c19]">{{ duration.label }}</p>
                                    <span v-if="duration.discount > 0" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-[#ffc9d0] text-[#ad2c4f]">
                                        -{{ duration.discount }}%
                                    </span>
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <p class="text-sm font-bold text-[#006c4f]">
                                        {{ formatCurrency(selectedPlan.monthly_price * duration.value * (1 - duration.discount / 100)) }}
                                    </p>
                                    <p v-if="duration.discount > 0" class="text-[11px] text-[#4d4634]/50 line-through">
                                        {{ formatCurrency(selectedPlan.monthly_price * duration.value) }}
                                    </p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Price Summary -->
                    <div class="rounded-xl bg-[#f5f3ee] p-4 border border-[#eae8e2]">
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-[#4d4634]">
                                <span>Subtotal:</span>
                                <span class="font-medium text-[#1b1c19]">{{ formatCurrency(upgradeSubtotal) }}</span>
                            </div>
                            <div v-if="upgradeDiscount > 0" class="flex justify-between text-[#006c4f] font-semibold">
                                <span>Diskon ({{ selectedDuration.discount }}%):</span>
                                <span>-{{ formatCurrency(upgradeDiscount) }}</span>
                            </div>
                            <div class="border-t border-[#eae8e2] pt-2 flex justify-between items-center">
                                <span class="font-bold text-[#1b1c19]">Total Pembayaran:</span>
                                <span class="text-lg font-black text-[#725a00]">{{ formatCurrency(upgradeTotal) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method Accordion -->
                    <div>
                        <Label class="mb-3 block text-sm font-bold text-[#1b1c19]">Pilih Metode Pembayaran</Label>
                        <div class="space-y-3">
                            <!-- QRIS Selection -->
                            <div class="rounded-xl border border-[#eae8e2] bg-white overflow-hidden">
                                <button 
                                    @click="selectedPaymentMethod = 'qris'"
                                    type="button"
                                    class="w-full flex items-center justify-between p-4 text-left transition-colors"
                                    :class="selectedPaymentMethod === 'qris' ? 'bg-[#fff9e6] border-b border-[#ffd23f]/40' : 'hover:bg-[#f5f3ee]'"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-11 h-11 rounded-lg bg-white border border-[#eae8e2] flex items-center justify-center p-1.5 shadow-xs">
                                            <img src="/qris.png" alt="QRIS" class="w-full h-full object-contain" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-[#1b1c19]">QRIS (Semua E-Wallet & Mobile Banking)</p>
                                            <p class="text-xs text-[#4d4634]">Scan kode QR untuk verifikasi transaksi cepat</p>
                                        </div>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'qris' ? 'border-[#ffd23f] bg-[#ffd23f]' : 'border-[#eae8e2]'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'qris'" class="w-2 h-2 rounded-full bg-[#725a00]"></div>
                                    </div>
                                </button>
                                
                                <div v-show="selectedPaymentMethod === 'qris'" class="p-5 bg-[#fbf9f3]">
                                    <div class="flex flex-col items-center text-center">
                                        <p class="text-xs text-[#4d4634] mb-3">Buka GoPay, OVO, Dana, BCA Mobile, atau aplikasi bank Anda lalu scan QRIS ini:</p>
                                        <div 
                                            class="bg-white p-3 rounded-2xl border border-[#eae8e2] shadow-sm cursor-pointer hover:shadow-md transition-all"
                                            @click="showQrZoom = true"
                                            title="Klik untuk memperbesar gambar QR"
                                        >
                                            <img src="/qriss.png" alt="Scan QRIS" class="w-44 h-44 object-contain mx-auto" />
                                        </div>
                                        <button type="button" @click="showQrZoom = true" class="mt-2 text-xs font-bold text-[#725a00] hover:underline flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">zoom_in</span>
                                            Klik untuk memperbesar tampilan QR
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank Transfer Selection -->
                            <div class="rounded-xl border border-[#eae8e2] bg-white overflow-hidden">
                                <button 
                                    @click="selectedPaymentMethod = 'manual'"
                                    type="button"
                                    class="w-full flex items-center justify-between p-4 text-left transition-colors"
                                    :class="selectedPaymentMethod === 'manual' ? 'bg-[#fff9e6] border-b border-[#ffd23f]/40' : 'hover:bg-[#f5f3ee]'"
                                >
                                    <div class="flex items-center gap-3">
                                        <div class="w-11 h-11 rounded-lg bg-[#51fac1]/20 text-[#006c4f] border border-[#51fac1]/30 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-2xl">account_balance</span>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-[#1b1c19]">Transfer Bank Manual</p>
                                            <p class="text-xs text-[#4d4634]">Transfer ke nomor rekening bank resmi FinWa</p>
                                        </div>
                                    </div>
                                    <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'manual' ? 'border-[#ffd23f] bg-[#ffd23f]' : 'border-[#eae8e2]'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'manual'" class="w-2 h-2 rounded-full bg-[#725a00]"></div>
                                    </div>
                                </button>

                                <div v-show="selectedPaymentMethod === 'manual'" class="p-5 bg-[#fbf9f3] space-y-3">
                                    <div v-if="banks && banks.length > 0" class="space-y-3">
                                        <div
                                            v-for="bank in banks"
                                            :key="bank.id"
                                            class="p-4 rounded-xl bg-white border border-[#eae8e2] shadow-xs"
                                        >
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="flex-1">
                                                    <p class="text-sm font-black text-[#1b1c19] mb-1.5">{{ bank.name }}</p>
                                                    <div class="space-y-1 text-xs">
                                                        <div class="flex items-center gap-2 flex-wrap">
                                                            <span class="text-[#4d4634]">No. Rekening:</span>
                                                            <span class="text-sm font-mono text-[#1b1c19] font-bold bg-[#f5f3ee] px-2 py-0.5 rounded">{{ bank.account_number }}</span>
                                                            <button
                                                                @click="copyToClipboard(bank.account_number)"
                                                                type="button"
                                                                class="p-1 rounded hover:bg-[#eae8e2] transition-colors text-[#725a00]"
                                                                title="Salin nomor rekening"
                                                            >
                                                                <span class="material-symbols-outlined text-base">content_copy</span>
                                                            </button>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-[#4d4634]">Atas Nama:</span>
                                                            <span class="text-xs font-semibold text-[#1b1c19]">{{ bank.account_name }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="p-4 rounded-xl bg-[#fff9e6] border border-[#ffd23f]/40 text-xs font-medium text-[#725a00]">
                                        Belum ada data rekening bank yang ditampilkan. Silakan gunakan QRIS atau hubungi dukungan FinWa.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <Label for="upgrade_notes" class="text-sm font-bold text-[#1b1c19]">Catatan Tambahan (Opsional)</Label>
                        <textarea
                            id="upgrade_notes"
                            v-model="upgradeNotes"
                            rows="2"
                            class="mt-1.5 w-full rounded-xl border-[#eae8e2] bg-white px-3 py-2 text-sm focus:border-[#ffd23f] focus:ring-[#ffd23f] text-[#1b1c19]"
                            placeholder="Tuliskan catatan untuk admin jika diperlukan"
                        ></textarea>
                        <InputError :message="upgradeRequestForm.errors.notes" />
                    </div>
                </div>

                <!-- Footer with buttons -->
                <div class="p-6 pt-4 border-t border-[#eae8e2] flex-shrink-0 bg-white">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            @click="showUpgradeDialog = false"
                            class="w-full sm:w-auto rounded-xl border-[#eae8e2] text-[#1b1c19] hover:bg-[#f5f3ee] order-2 sm:order-1"
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            @click="submitUpgradeRequest"
                            :disabled="upgradeRequestForm.processing"
                            class="w-full sm:flex-1 rounded-xl bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold shadow-sm active:scale-95 order-1 sm:order-2"
                        >
                            {{ upgradeRequestForm.processing ? 'Mengirim Permintaan...' : 'Kirim Pengajuan Upgrade' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- QR Code Zoom Modal -->
        <Dialog v-model:open="showQrZoom">
            <DialogContent class="max-w-md p-6 rounded-2xl font-['Plus_Jakarta_Sans',sans-serif] bg-white">
                <div class="flex flex-col items-center text-center">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-[#725a00] text-2xl">qr_code_scanner</span>
                        <h3 class="text-lg font-bold text-[#1b1c19]">Scan QRIS FinWa</h3>
                    </div>
                    <div class="bg-white p-4 rounded-2xl border border-[#eae8e2] shadow-sm">
                        <img src="/qriss.png" alt="Scan QRIS" class="w-72 h-72 object-contain mx-auto" />
                    </div>
                    <p class="text-xs text-[#4d4634] mt-3">Gunakan kamera pemindai di GoPay, OVO, Dana, ShopeePay, atau m-Banking Anda</p>
                    <Button 
                        @click="showQrZoom = false" 
                        class="mt-5 w-full bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold rounded-xl shadow-sm"
                    >
                        Tutup
                    </Button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Payment Proof View Dialog -->
        <Dialog :open="showPaymentProofViewDialog" @update:open="showPaymentProofViewDialog = $event">
            <DialogContent class="max-w-3xl rounded-2xl p-0 overflow-hidden bg-white font-['Plus_Jakarta_Sans',sans-serif]">
                <DialogHeader class="p-6 pb-4 border-b border-[#eae8e2]">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#725a00]">image</span>
                        Pratinjau Bukti Pembayaran
                    </DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]">Berkas bukti transfer yang telah Anda kirimkan</DialogDescription>
                </DialogHeader>
                <div v-if="viewingPaymentProofUrl" class="p-6 space-y-4">
                    <div class="relative rounded-2xl border border-[#eae8e2] bg-[#f5f3ee] p-3 flex items-center justify-center">
                        <img 
                            :src="viewingPaymentProofUrl" 
                            alt="Bukti Pembayaran" 
                            class="w-full h-auto rounded-xl max-h-[60vh] object-contain mx-auto" 
                        />
                    </div>
                    <div class="flex justify-between items-center pt-2">
                        <a
                            :href="viewingPaymentProofUrl"
                            target="_blank"
                            class="text-sm font-bold text-[#006c4f] hover:underline inline-flex items-center gap-1.5"
                        >
                            <span class="material-symbols-outlined text-lg">open_in_new</span>
                            Buka Berkas di Tab Baru
                        </a>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showPaymentProofViewDialog = false"
                            class="rounded-xl border-[#eae8e2] text-[#1b1c19]"
                        >
                            Tutup
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Payment Proof Upload Dialog -->
        <Dialog :open="showPaymentProofDialog" @update:open="showPaymentProofDialog = $event">
            <DialogContent class="max-w-lg rounded-2xl p-0 overflow-hidden bg-white font-['Plus_Jakarta_Sans',sans-serif]">
                <DialogHeader class="p-6 pb-4 border-b border-[#eae8e2]">
                    <DialogTitle class="text-xl font-bold text-[#1b1c19] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#725a00]">upload_file</span>
                        Unggah Bukti Pembayaran
                    </DialogTitle>
                    <DialogDescription class="text-sm text-[#4d4634]">
                        Format didukung: JPG, PNG, WebP (Ukuran maksimal 5MB)
                    </DialogDescription>
                </DialogHeader>
                <div class="p-6 space-y-5">
                    <!-- Current Payment Proof (if exists) -->
                    <div v-if="subscriptionForUpload?.payment_proof && !previewUrl" class="rounded-xl border border-[#eae8e2] bg-[#f5f3ee] p-4">
                        <Label class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#4d4634]">Bukti Pembayaran Tersimpan</Label>
                        <div class="relative">
                            <img :src="subscriptionForUpload.payment_proof" alt="Bukti Pembayaran" class="w-full h-auto rounded-lg border border-[#eae8e2] max-h-44 object-contain bg-white" />
                            <a
                                :href="subscriptionForUpload.payment_proof"
                                target="_blank"
                                class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-[#006c4f] hover:underline"
                            >
                                <span class="material-symbols-outlined text-sm">visibility</span>
                                Lihat gambar asli
                            </a>
                        </div>
                    </div>

                    <!-- File Input -->
                    <div>
                        <Label for="payment_proof" class="text-sm font-bold text-[#1b1c19]">Pilih Berkas Gambar</Label>
                        <Input
                            id="payment_proof"
                            type="file"
                            accept="image/*"
                            @change="handleFileSelect"
                            class="mt-1.5 rounded-xl cursor-pointer file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-[#ffd23f] file:text-[#725a00] hover:file:bg-[#ffe089]"
                        />
                        <InputError :message="uploadForm.errors.payment_proof" />
                    </div>

                    <!-- Preview New File -->
                    <div v-if="previewUrl" class="relative">
                        <Label class="text-xs font-bold uppercase tracking-wider text-[#4d4634]">Pratinjau Berkas Baru</Label>
                        <div class="mt-2 relative rounded-xl border border-[#eae8e2] p-2 bg-[#f5f3ee]">
                            <img :src="previewUrl" alt="Preview" class="w-full h-auto rounded-lg max-h-48 object-contain bg-white mx-auto" />
                            <button
                                @click="previewUrl = null; selectedFile = null; uploadForm.payment_proof = null"
                                type="button"
                                class="absolute top-3 right-3 p-1.5 bg-[#ffc9d0] text-[#ad2c4f] rounded-full hover:bg-[#ffc9d0]/80 transition-colors shadow-sm"
                                title="Hapus gambar"
                            >
                                <span class="material-symbols-outlined text-base">close</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-3 border-t border-[#eae8e2]">
                        <Button
                            type="button"
                            variant="outline"
                            @click="closePaymentProofDialog"
                            class="rounded-xl border-[#eae8e2] text-[#1b1c19] hover:bg-[#f5f3ee]"
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            @click="handleUpload"
                            :disabled="!selectedFile || uploadForm.processing || !subscriptionForUpload"
                            class="bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold disabled:opacity-50 rounded-xl shadow-sm"
                        >
                            <span class="material-symbols-outlined text-lg mr-1.5">upload</span>
                            {{ uploadForm.processing ? 'Mengunggah...' : subscriptionForUpload?.payment_proof ? 'Ganti Bukti' : 'Kirim Bukti' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
