<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { useSweetAlert } from '@/composables/useSweetAlert';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Subscription', href: '/subscriptions' },
    { title: 'Pilih Paket', href: '/subscriptions/new' },
];

const { showSuccess, showError } = useSweetAlert();

// Props
interface Props {
    plans: Record<string, {
        slug: string;
        name: string;
        monthly_price: number;
        description: string;
        features: string[];
    }>;
    durationOptions: Array<{
        value: number;
        label: string;
        discount: number;
    }>;
    banks: Array<{
        id: number;
        name: string;
        account_number: string;
        account_name: string;
    }>;
}

const props = defineProps<Props>();

type Plan = Props['plans'][string];
type DurationOption = Props['durationOptions'][number];

// State
const currentStep = ref(1);
const planList = computed<Plan[]>(() => Object.values(props.plans ?? {}));
const durationList = computed<DurationOption[]>(() => props.durationOptions ?? []);
const bankList = computed<Props['banks']>(() => props.banks ?? []);

const selectedPlan = ref<Plan | null>(props.plans?.lite ?? planList.value[0] ?? null);
const selectedDuration = ref<DurationOption | null>(durationList.value[0] ?? null);
const selectedPaymentMethod = ref<'qris' | 'bank'>('qris');
const selectedFile = ref<File | null>(null);
const previewUrl = ref<string | null>(null);
const showQrZoom = ref(false);

// Form
const subscriptionForm = useForm({
    plan: '',
    duration_months: 1,
    payment_method: 'qris',
    payment_proof: null as File | null,
    notes: '',
});

watchEffect(() => {
    if (!selectedPlan.value && planList.value.length > 0) {
        selectedPlan.value = props.plans?.lite ?? planList.value[0];
    }
    if (!selectedDuration.value && durationList.value.length > 0) {
        selectedDuration.value = durationList.value[0];
    }
    if (selectedPlan.value) {
        subscriptionForm.plan = selectedPlan.value.slug;
    }
    if (selectedDuration.value) {
        subscriptionForm.duration_months = selectedDuration.value.value;
    }
});

// Computed
const subtotal = computed(() => {
    if (!selectedPlan.value || !selectedDuration.value) return 0;
    return selectedPlan.value.monthly_price * selectedDuration.value.value;
});

const discount = computed(() => {
    if (!selectedDuration.value) return 0;
    if (selectedDuration.value.discount === 0) return 0;
    return (subtotal.value * selectedDuration.value.discount) / 100;
});

const total = computed(() => {
    return subtotal.value - discount.value;
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

// Methods
const selectPlan = (plan: Plan) => {
    selectedPlan.value = plan;
    subscriptionForm.plan = plan.slug;
};

const selectDuration = (duration: DurationOption) => {
    selectedDuration.value = duration;
    subscriptionForm.duration_months = duration.value;
};

const goToStep = (step: number) => {
    if (step === 2 && currentStep.value === 1) {
        if (!selectedDuration.value || !selectedPlan.value) {
            showError('Perhatian', 'Silakan pilih paket dan durasi terlebih dahulu');
            return;
        }
    }
    if (step === 3 && currentStep.value === 2) {
        if (!selectedPaymentMethod.value) {
            showError('Perhatian', 'Silakan tentukan metode pembayaran');
            return;
        }
        subscriptionForm.payment_method = selectedPaymentMethod.value;
    }
    currentStep.value = step;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        const file = target.files[0];
        
        if (!file.type.startsWith('image/')) {
            showError('Format Tidak Valid', 'File bukti harus berupa gambar (JPG, PNG, WebP)');
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            showError('Ukuran Terlalu Besar', 'Maksimal ukuran berkas adalah 5MB');
            return;
        }

        selectedFile.value = file;
        subscriptionForm.payment_proof = file;
        
        const reader = new FileReader();
        reader.onload = (e) => {
            previewUrl.value = e.target?.result as string;
        };
        reader.readAsDataURL(file);
    }
};

const submitSubscription = () => {
    subscriptionForm.post('/subscriptions', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Permintaan langganan berhasil dikirimkan');
        },
        onError: () => {
            showError('Gagal', 'Terjadi kendala saat mengirim data langganan');
        }
    });
};

const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        showSuccess('Tersalin', 'Nomor rekening disalin ke clipboard');
    } catch {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showSuccess('Tersalin', 'Nomor rekening disalin ke clipboard');
        } catch {
            showError('Error', 'Gagal menyalin nomor rekening');
        }
        document.body.removeChild(textArea);
    }
};
</script>

<template>
    <Head title="Pilih Paket Berlangganan" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="bg-[#fbf9f3] min-h-screen flex h-full flex-1 flex-col gap-6 p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-2.5">
                    <span class="w-10 h-10 rounded-xl bg-[#ffd23f] text-[#574500] flex items-center justify-center shadow-sm">
                        <span class="material-symbols-outlined text-[24px]">shopping_cart_checkout</span>
                    </span>
                    <div>
                        <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19]">Pilih Paket FinWa</h2>
                        <p class="text-xs md:text-sm text-[#4d4634]">Nikmati kemudahan pencatatan finansial dan integrasi WhatsApp tanpa batas</p>
                    </div>
                </div>

                <Link
                    href="/subscriptions"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs md:text-sm font-bold border border-[#eae8e2] bg-white text-[#1b1c19] hover:bg-[#f5f3ee] transition-all w-fit shadow-xs"
                >
                    <span class="material-symbols-outlined text-base text-[#725a00]">arrow_back</span>
                    Kembali ke Status Langganan
                </Link>
            </div>

            <!-- Stepper Indicator -->
            <div class="rounded-2xl bg-white border border-[#eae8e2] p-4 sm:p-5 shadow-sm">
                <div class="flex items-center justify-between max-w-2xl mx-auto">
                    <!-- Step 1 -->
                    <div class="flex items-center gap-2 sm:gap-3 cursor-pointer" @click="goToStep(1)">
                        <div 
                            class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-xl font-bold transition-all text-xs sm:text-sm shadow-xs"
                            :class="currentStep >= 1 ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#f5f3ee] text-[#4d4634]/60'"
                        >
                            <span v-if="currentStep > 1" class="material-symbols-outlined text-lg">check</span>
                            <span v-else>1</span>
                        </div>
                        <div class="text-left hidden sm:block">
                            <p class="text-xs font-bold uppercase tracking-wider" :class="currentStep >= 1 ? 'text-[#725a00]' : 'text-[#4d4634]/50'">Langkah 1</p>
                            <p class="text-sm font-bold" :class="currentStep >= 1 ? 'text-[#1b1c19]' : 'text-[#4d4634]/60'">Pilih Paket</p>
                        </div>
                    </div>

                    <div class="flex-1 h-0.5 mx-2 sm:mx-4" :class="currentStep >= 2 ? 'bg-[#ffd23f]' : 'bg-[#eae8e2]'"></div>

                    <!-- Step 2 -->
                    <div class="flex items-center gap-2 sm:gap-3 cursor-pointer" @click="goToStep(2)">
                        <div 
                            class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-xl font-bold transition-all text-xs sm:text-sm shadow-xs"
                            :class="currentStep >= 2 ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#f5f3ee] text-[#4d4634]/60'"
                        >
                            <span v-if="currentStep > 2" class="material-symbols-outlined text-lg">check</span>
                            <span v-else>2</span>
                        </div>
                        <div class="text-left hidden sm:block">
                            <p class="text-xs font-bold uppercase tracking-wider" :class="currentStep >= 2 ? 'text-[#725a00]' : 'text-[#4d4634]/50'">Langkah 2</p>
                            <p class="text-sm font-bold" :class="currentStep >= 2 ? 'text-[#1b1c19]' : 'text-[#4d4634]/60'">Metode Bayar</p>
                        </div>
                    </div>

                    <div class="flex-1 h-0.5 mx-2 sm:mx-4" :class="currentStep >= 3 ? 'bg-[#ffd23f]' : 'bg-[#eae8e2]'"></div>

                    <!-- Step 3 -->
                    <div class="flex items-center gap-2 sm:gap-3 cursor-pointer" @click="goToStep(3)">
                        <div 
                            class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-xl font-bold transition-all text-xs sm:text-sm shadow-xs"
                            :class="currentStep >= 3 ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#f5f3ee] text-[#4d4634]/60'"
                        >
                            <span>3</span>
                        </div>
                        <div class="text-left hidden sm:block">
                            <p class="text-xs font-bold uppercase tracking-wider" :class="currentStep >= 3 ? 'text-[#725a00]' : 'text-[#4d4634]/50'">Langkah 3</p>
                            <p class="text-sm font-bold" :class="currentStep >= 3 ? 'text-[#1b1c19]' : 'text-[#4d4634]/60'">Konfirmasi</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Wizard Card -->
            <div class="rounded-2xl bg-white p-6 md:p-8 border border-[#eae8e2] shadow-sm">
                <div v-if="!selectedPlan || !selectedDuration" class="text-center py-12 text-[#4d4634]">
                    <span class="material-symbols-outlined text-4xl text-[#725a00] mb-2">hourglass_top</span>
                    <p class="font-bold">Memuat konfigurasi paket...</p>
                </div>

                <template v-else>
                    <!-- Step 1: Pilih Paket & Durasi -->
                    <div v-show="currentStep === 1" class="space-y-8">
                        <div>
                            <h3 class="text-lg md:text-xl font-black text-[#1b1c19]">1. Tentukan Paket Layanan</h3>
                            <p class="text-xs md:text-sm text-[#4d4634] mt-0.5">Pilih paket yang paling sesuai dengan kebutuhan skala transaksi Anda</p>
                        </div>

                        <!-- Plan Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <button
                                v-for="plan in planList"
                                :key="plan.slug"
                                type="button"
                                @click="selectPlan(plan)"
                                class="p-6 rounded-2xl border-2 transition-all text-left relative flex flex-col justify-between"
                                :class="selectedPlan.slug === plan.slug
                                    ? 'border-[#ffd23f] bg-[#fff9e6] shadow-sm ring-1 ring-[#ffd23f]'
                                    : 'border-[#eae8e2] bg-white hover:border-[#ffd23f]/50 hover:bg-[#fbf9f3]'"
                            >
                                <div>
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <span class="inline-block px-2.5 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider mb-1"
                                                :class="plan.slug === 'pro' ? 'bg-[#ffd23f] text-[#725a00]' : 'bg-[#51fac1]/30 text-[#006c4f]'"
                                            >
                                                {{ plan.slug === 'pro' ? 'Rekomendasi Terbaik' : 'Hemat & Populer' }}
                                            </span>
                                            <h4 class="text-xl font-bold text-[#1b1c19]">{{ plan.name }}</h4>
                                            <p class="text-xs text-[#4d4634] mt-0.5">{{ plan.description }}</p>
                                        </div>
                                        <div 
                                            class="w-6 h-6 rounded-full border-2 flex items-center justify-center"
                                            :class="selectedPlan.slug === plan.slug ? 'border-[#725a00] bg-[#ffd23f]' : 'border-[#eae8e2]'"
                                        >
                                            <span v-if="selectedPlan.slug === plan.slug" class="material-symbols-outlined text-xs font-black text-[#725a00]">check</span>
                                        </div>
                                    </div>

                                    <!-- Features List -->
                                    <ul class="my-4 space-y-2 border-t border-[#eae8e2]/60 pt-3">
                                        <li v-for="(feat, idx) in plan.features" :key="idx" class="flex items-center gap-2 text-xs text-[#1b1c19]">
                                            <span class="material-symbols-outlined text-sm text-[#006c4f]">check_circle</span>
                                            <span>{{ feat }}</span>
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="pt-3 border-t border-[#eae8e2]">
                                    <p class="text-2xl font-black text-[#1b1c19]">
                                        {{ formatCurrency(plan.monthly_price) }}
                                        <span class="text-xs font-semibold text-[#4d4634]">/bulan</span>
                                    </p>
                                </div>
                            </button>
                        </div>

                        <!-- Duration Cards -->
                        <div class="pt-6 border-t border-[#eae8e2]">
                            <div class="mb-4">
                                <h3 class="text-lg md:text-xl font-black text-[#1b1c19]">2. Pilih Durasi Berlangganan</h3>
                                <p class="text-xs md:text-sm text-[#4d4634] mt-0.5">Dapatkan potongan harga khusus untuk langganan jangka panjang</p>
                            </div>

                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <button
                                    v-for="duration in durationList"
                                    :key="duration.value"
                                    type="button"
                                    @click="selectDuration(duration)"
                                    class="p-4 rounded-xl border-2 transition-all text-left relative"
                                    :class="selectedDuration.value === duration.value
                                        ? 'border-[#ffd23f] bg-[#fff9e6] ring-1 ring-[#ffd23f]'
                                        : 'border-[#eae8e2] bg-white hover:border-[#ffd23f]/50 hover:bg-[#fbf9f3]'"
                                >
                                    <div class="flex justify-between items-start mb-1">
                                        <p class="text-sm font-black text-[#1b1c19]">{{ duration.label }}</p>
                                        <span 
                                            v-if="duration.discount > 0"
                                            class="px-2 py-0.5 rounded-full bg-[#ffc9d0] text-[#ad2c4f] text-[10px] font-bold"
                                        >
                                            -{{ duration.discount }}%
                                        </span>
                                    </div>
                                    <p class="text-base font-bold text-[#006c4f] mt-2">
                                        {{ formatCurrency(selectedPlan.monthly_price * duration.value - (selectedPlan.monthly_price * duration.value * duration.discount / 100)) }}
                                    </p>
                                    <p class="text-[11px] text-[#4d4634]/50 mt-0.5">
                                        <span v-if="duration.discount > 0" class="line-through">{{ formatCurrency(selectedPlan.monthly_price * duration.value) }}</span>
                                        <span v-else>{{ formatCurrency(selectedPlan.monthly_price * duration.value) }}</span>
                                    </p>
                                </button>
                            </div>
                        </div>

                        <!-- Price Summary -->
                        <div class="rounded-2xl bg-[#f5f3ee] p-5 border border-[#eae8e2]">
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between text-[#4d4634]">
                                    <span>Subtotal ({{ selectedPlan.name }} x {{ selectedDuration.label }}):</span>
                                    <span class="font-medium text-[#1b1c19]">{{ formatCurrency(subtotal) }}</span>
                                </div>
                                <div v-if="discount > 0" class="flex justify-between text-[#006c4f] font-bold">
                                    <span>Hemat Diskon ({{ selectedDuration.discount }}%):</span>
                                    <span>-{{ formatCurrency(discount) }}</span>
                                </div>
                                <div class="border-t border-[#eae8e2] pt-3 flex justify-between items-center">
                                    <span class="font-bold text-[#1b1c19] text-base">Total yang Harus Dibayar:</span>
                                    <span class="text-2xl font-black text-[#725a00]">{{ formatCurrency(total) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Next Button -->
                        <div class="flex justify-end pt-2">
                            <Button
                                @click="goToStep(2)"
                                type="button"
                                class="px-8 py-3 rounded-xl bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold text-sm shadow-sm transition-all active:scale-95 flex items-center gap-2"
                            >
                                Lanjut ke Metode Pembayaran
                                <span class="material-symbols-outlined text-base">arrow_forward</span>
                            </Button>
                        </div>
                    </div>

                    <!-- Step 2: Metode Pembayaran -->
                    <div v-show="currentStep === 2" class="space-y-6">
                        <div>
                            <h3 class="text-lg md:text-xl font-black text-[#1b1c19]">Pilih Metode Pembayaran</h3>
                            <p class="text-xs md:text-sm text-[#4d4634] mt-0.5">Tersedia opsi QRIS instan dan transfer antar-rekening bank</p>
                        </div>

                        <div class="space-y-4">
                            <!-- QRIS Card -->
                            <div 
                                @click="selectedPaymentMethod = 'qris'"
                                class="rounded-2xl border-2 transition-all overflow-hidden cursor-pointer"
                                :class="selectedPaymentMethod === 'qris'
                                    ? 'border-[#ffd23f] bg-[#fff9e6] shadow-sm'
                                    : 'border-[#eae8e2] bg-white hover:border-[#ffd23f]/50'"
                            >
                                <div class="p-5 flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-xl bg-white border border-[#eae8e2] flex items-center justify-center p-1.5 shadow-xs">
                                            <img src="/qris.png" alt="QRIS" class="w-full h-full object-contain" />
                                        </div>
                                        <div>
                                            <p class="font-bold text-[#1b1c19] text-base">QRIS (GoPay, OVO, Dana, ShopeePay, m-Banking)</p>
                                            <p class="text-xs text-[#4d4634]">Scan cepat tanpa repot salin nomor rekening</p>
                                        </div>
                                    </div>
                                    <div 
                                        class="w-6 h-6 rounded-full border-2 flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'qris' ? 'border-[#725a00] bg-[#ffd23f]' : 'border-[#eae8e2]'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'qris'" class="w-2 h-2 rounded-full bg-[#725a00]"></div>
                                    </div>
                                </div>

                                <div v-if="selectedPaymentMethod === 'qris'" class="p-5 border-t border-[#ffd23f]/40 bg-[#fbf9f3]">
                                    <div class="flex flex-col items-center text-center">
                                        <p class="text-xs text-[#4d4634] mb-3">Scan kode QR di bawah ini menggunakan aplikasi pembayaran pilihan Anda:</p>
                                        <div 
                                            @click.stop="showQrZoom = true"
                                            class="bg-white p-3 rounded-2xl border border-[#eae8e2] shadow-sm cursor-pointer hover:shadow-md transition-all"
                                            title="Perbesar gambar QR"
                                        >
                                            <img src="/qriss.png" alt="QRIS Code" class="w-48 h-48 object-contain" />
                                        </div>
                                        <p class="text-[11px] text-[#725a00] font-bold mt-2 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">zoom_in</span>
                                            Klik gambar untuk memperbesar tampilan
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Transfer Bank Card -->
                            <div 
                                @click="selectedPaymentMethod = 'bank'"
                                class="rounded-2xl border-2 transition-all overflow-hidden cursor-pointer"
                                :class="selectedPaymentMethod === 'bank'
                                    ? 'border-[#ffd23f] bg-[#fff9e6] shadow-sm'
                                    : 'border-[#eae8e2] bg-white hover:border-[#ffd23f]/50'"
                            >
                                <div class="p-5 flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-xl bg-[#51fac1]/20 text-[#006c4f] border border-[#51fac1]/30 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-2xl">account_balance</span>
                                        </div>
                                        <div>
                                            <p class="font-bold text-[#1b1c19] text-base">Transfer Rekening Bank Manual</p>
                                            <p class="text-xs text-[#4d4634]">Transfer ke rekening resmi operasional FinWa</p>
                                        </div>
                                    </div>
                                    <div 
                                        class="w-6 h-6 rounded-full border-2 flex items-center justify-center"
                                        :class="selectedPaymentMethod === 'bank' ? 'border-[#725a00] bg-[#ffd23f]' : 'border-[#eae8e2]'"
                                    >
                                        <div v-if="selectedPaymentMethod === 'bank'" class="w-2 h-2 rounded-full bg-[#725a00]"></div>
                                    </div>
                                </div>

                                <div v-if="selectedPaymentMethod === 'bank'" class="p-5 border-t border-[#ffd23f]/40 bg-[#fbf9f3] space-y-3">
                                    <div
                                        v-for="bank in bankList"
                                        :key="bank.id"
                                        class="p-4 rounded-xl bg-white border border-[#eae8e2] shadow-xs"
                                    >
                                        <div class="flex items-center justify-between gap-4">
                                            <div>
                                                <p class="font-black text-sm text-[#1b1c19]">{{ bank.name }}</p>
                                                <div class="mt-1 flex items-center gap-2">
                                                    <span class="font-mono font-bold text-sm bg-[#f5f3ee] px-2 py-0.5 rounded text-[#1b1c19]">{{ bank.account_number }}</span>
                                                    <button
                                                        @click.stop="copyToClipboard(bank.account_number)"
                                                        type="button"
                                                        class="p-1 rounded hover:bg-[#eae8e2] text-[#725a00] transition-colors"
                                                        title="Salin nomor rekening"
                                                    >
                                                        <span class="material-symbols-outlined text-base">content_copy</span>
                                                    </button>
                                                </div>
                                                <p class="text-xs text-[#4d4634] mt-1">a.n. <strong class="text-[#1b1c19]">{{ bank.account_name }}</strong></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-between pt-4 border-t border-[#eae8e2]">
                            <Button
                                @click="goToStep(1)"
                                type="button"
                                variant="outline"
                                class="px-6 py-2.5 rounded-xl border-[#eae8e2] text-[#1b1c19] hover:bg-[#f5f3ee] font-bold text-sm"
                            >
                                <span class="material-symbols-outlined text-base mr-1">arrow_back</span>
                                Kembali
                            </Button>
                            <Button
                                @click="goToStep(3)"
                                type="button"
                                class="px-8 py-2.5 rounded-xl bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold text-sm shadow-sm transition-all active:scale-95 flex items-center gap-2"
                            >
                                Lanjut ke Konfirmasi
                                <span class="material-symbols-outlined text-base">arrow_forward</span>
                            </Button>
                        </div>
                    </div>

                    <!-- Step 3: Konfirmasi & Upload Bukti -->
                    <div v-show="currentStep === 3" class="space-y-6">
                        <div>
                            <h3 class="text-lg md:text-xl font-black text-[#1b1c19]">Konfirmasi & Unggah Bukti Bayar</h3>
                            <p class="text-xs md:text-sm text-[#4d4634] mt-0.5">Periksa rincian pemesanan Anda sebelum mengirimkan pengajuan</p>
                        </div>

                        <!-- Order Summary Card -->
                        <div class="rounded-2xl border border-[#eae8e2] bg-[#f5f3ee] p-5 md:p-6">
                            <h4 class="font-bold text-[#1b1c19] text-base mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-[#725a00]">receipt</span>
                                Ringkasan Tagihan Langganan
                            </h4>
                            <div class="space-y-2.5 text-sm">
                                <div class="flex justify-between text-[#4d4634]">
                                    <span>Paket Dipilih:</span>
                                    <span class="font-bold text-[#1b1c19]">{{ selectedPlan.name }}</span>
                                </div>
                                <div class="flex justify-between text-[#4d4634]">
                                    <span>Masa Durasi:</span>
                                    <span class="font-bold text-[#1b1c19]">{{ selectedDuration.label }}</span>
                                </div>
                                <div class="flex justify-between text-[#4d4634]">
                                    <span>Metode Pembayaran:</span>
                                    <span class="font-bold text-[#1b1c19]">{{ selectedPaymentMethod === 'qris' ? 'QRIS Otomatis' : 'Transfer Manual Bank' }}</span>
                                </div>
                                <div class="border-t border-[#eae8e2] pt-3 flex justify-between items-center">
                                    <span class="font-black text-[#1b1c19] text-base">Total Pembayaran:</span>
                                    <span class="text-2xl font-black text-[#725a00]">{{ formatCurrency(total) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Upload Proof Box -->
                        <div class="space-y-4">
                            <label class="block">
                                <span class="text-sm font-bold text-[#1b1c19]">Unggah Bukti Pembayaran (Opsional / Disarankan)</span>
                                <div class="mt-2 flex justify-center rounded-2xl border-2 border-dashed border-[#ffd23f] bg-[#fff9e6]/50 px-6 py-8 hover:bg-[#fff9e6] transition-all">
                                    <div class="text-center">
                                        <div v-if="!previewUrl" class="flex flex-col items-center">
                                            <span class="material-symbols-outlined text-4xl text-[#725a00] mb-2">cloud_upload</span>
                                            <label class="cursor-pointer font-bold text-sm text-[#725a00] hover:underline">
                                                <span>Pilih berkas bukti pembayaran</span>
                                                <input
                                                    type="file"
                                                    class="sr-only"
                                                    accept="image/*"
                                                    @change="handleFileSelect"
                                                />
                                            </label>
                                            <p class="text-xs text-[#4d4634] mt-1">Format gambar: PNG, JPG, WebP (maks. 5MB)</p>
                                        </div>
                                        <div v-else class="relative">
                                            <img :src="previewUrl" class="mx-auto max-h-52 rounded-xl border border-[#eae8e2] shadow-sm bg-white" />
                                            <button
                                                @click="previewUrl = null; selectedFile = null; subscriptionForm.payment_proof = null"
                                                type="button"
                                                class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-[#ad2c4f] hover:underline"
                                            >
                                                <span class="material-symbols-outlined text-sm">delete</span>
                                                Hapus berkas terpilih
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </label>

                            <!-- Notes -->
                            <div>
                                <label class="text-sm font-bold text-[#1b1c19]">Catatan Tambahan (Opsional)</label>
                                <textarea
                                    v-model="subscriptionForm.notes"
                                    rows="2"
                                    class="mt-1.5 w-full rounded-xl border-[#eae8e2] bg-white px-3 py-2 text-sm focus:border-[#ffd23f] focus:ring-[#ffd23f] text-[#1b1c19]"
                                    placeholder="Tulis pesan atau nomor referensi transfer jika ada..."
                                ></textarea>
                            </div>
                        </div>

                        <!-- Footer Actions -->
                        <div class="flex justify-between pt-4 border-t border-[#eae8e2]">
                            <Button
                                @click="goToStep(2)"
                                type="button"
                                variant="outline"
                                class="px-6 py-2.5 rounded-xl border-[#eae8e2] text-[#1b1c19] hover:bg-[#f5f3ee] font-bold text-sm"
                            >
                                <span class="material-symbols-outlined text-base mr-1">arrow_back</span>
                                Kembali
                            </Button>
                            <Button
                                @click="submitSubscription"
                                type="button"
                                :disabled="subscriptionForm.processing"
                                class="px-8 py-2.5 rounded-xl bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold text-sm shadow-sm transition-all active:scale-95 flex items-center gap-2"
                            >
                                <span class="material-symbols-outlined text-base">send</span>
                                {{ subscriptionForm.processing ? 'Mengirim Permintaan...' : 'Selesaikan Pemesanan' }}
                            </Button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- QRIS Zoom Dialog -->
        <Dialog :open="showQrZoom" @update:open="showQrZoom = $event">
            <DialogContent class="max-w-md p-6 rounded-2xl bg-white font-['Plus_Jakarta_Sans',sans-serif]">
                <div class="flex flex-col items-center text-center">
                    <h3 class="text-lg font-bold text-[#1b1c19] mb-3">Scan QRIS FinWa</h3>
                    <div class="bg-white p-4 rounded-2xl border border-[#eae8e2] shadow-sm">
                        <img src="/qriss.png" alt="QRIS Code" class="w-72 h-72 object-contain mx-auto" />
                    </div>
                    <p class="text-xs text-[#4d4634] mt-3">Scan menggunakan aplikasi mobile banking atau e-wallet Anda</p>
                    <Button 
                        @click="showQrZoom = false" 
                        class="mt-5 w-full bg-[#ffd23f] text-[#725a00] hover:bg-[#ffe089] font-bold rounded-xl shadow-sm"
                    >
                        Tutup
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
