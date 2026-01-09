<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Check, X } from 'lucide-vue-next';
import { Dialog, DialogContent } from '@/components/ui/dialog';

// Props
interface Props {
    planDetails: {
        name: string;
        monthly_price: number;
    };
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

// State
const currentStep = ref(1);
const selectedDuration = ref(props.durationOptions[0]);
const selectedPaymentMethod = ref<'qris' | 'bank'>('qris');
const selectedFile = ref<File | null>(null);
const previewUrl = ref<string | null>(null);
const showQrZoom = ref(false);

// Form
const subscriptionForm = useForm({
    plan: 'growth',
    duration_months: selectedDuration.value.value,
    payment_method: 'qris',
    payment_proof: null as File | null,
    notes: '',
});

// Computed
const subtotal = computed(() => {
    return props.planDetails.monthly_price * selectedDuration.value.value;
});

const discount = computed(() => {
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
    }).format(amount);
};

// Methods
const selectDuration = (duration: typeof selectedDuration.value) => {
    selectedDuration.value = duration;
    subscriptionForm.duration_months = duration.value;
};

const goToStep = (step: number) => {
    if (step === 2 && currentStep.value === 1) {
        // Validate step 1
        if (!selectedDuration.value) return;
    }
    if (step === 3 && currentStep.value === 2) {
        // Validate step 2
        if (!selectedPaymentMethod.value) return;
        subscriptionForm.payment_method = selectedPaymentMethod.value;
    }
    currentStep.value = step;
};

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        const file = target.files[0];
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
            // Success handled by backend redirect
        },
    });
};

const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        alert('Nomor rekening berhasil disalin!');
    } catch (err) {
        console.error('Failed to copy:', err);
    }
};
</script>

<template>
    <Head title="Berlangganan" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-8 p-6 bg-gray-50/50 dark:bg-black/10">
            <!-- Header -->
            <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Berlangganan</h2>
                <p class="text-sm text-gray-500 mt-1">Pilih paket dan metode pembayaran Anda</p>
            </div>

            <!-- Stepper -->
            <div class="flex items-center justify-center gap-2 sm:gap-4 mb-4 px-4">
                <div class="flex items-center">
                    <div 
                        class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-full font-bold transition-all text-sm sm:text-base"
                        :class="currentStep >= 1 ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'"
                    >
                        <Check v-if="currentStep > 1" class="w-4 h-4 sm:w-5 sm:h-5" />
                        <span v-else>1</span>
                    </div>
                    <span class="ml-1 sm:ml-2 text-xs sm:text-sm font-medium hidden md:inline" :class="currentStep >= 1 ? 'text-gray-900 dark:text-white' : 'text-gray-500'">
                        Pilih Paket
                    </span>
                </div>

                <div class="w-8 sm:w-12 md:w-16 h-0.5" :class="currentStep >= 2 ? 'bg-green-500' : 'bg-gray-200'"></div>

                <div class="flex items-center">
                    <div 
                        class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-full font-bold transition-all text-sm sm:text-base"
                        :class="currentStep >= 2 ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'"
                    >
                        <Check v-if="currentStep > 2" class="w-4 h-4 sm:w-5 sm:h-5" />
                        <span v-else>2</span>
                    </div>
                    <span class="ml-1 sm:ml-2 text-xs sm:text-sm font-medium hidden md:inline" :class="currentStep >= 2 ? 'text-gray-900 dark:text-white' : 'text-gray-500'">
                        Metode Pembayaran
                    </span>
                </div>

                <div class="w-8 sm:w-12 md:w-16 h-0.5" :class="currentStep >= 3 ? 'bg-green-500' : 'bg-gray-200'"></div>

                <div class="flex items-center">
                    <div 
                        class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 rounded-full font-bold transition-all text-sm sm:text-base"
                        :class="currentStep >= 3 ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'"
                    >
                        3
                    </div>
                    <span class="ml-1 sm:ml-2 text-xs sm:text-sm font-medium hidden md:inline" :class="currentStep >= 3 ? 'text-gray-900 dark:text-white' : 'text-gray-500'">
                        Konfirmasi
                    </span>
                </div>
            </div>

            <!-- Step Content -->
            <div class="rounded-2xl bg-white p-8 shadow-sm dark:bg-gray-800">
                <!-- Step 1: Pilih Paket -->
                <div v-show="currentStep === 1" class="space-y-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Pilih Durasi Langganan</h3>
                        <p class="text-sm text-gray-500">Semakin lama durasi, semakin hemat!</p>
                    </div>

                    <!-- Plan Info -->
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                        <h4 class="text-base font-bold text-gray-900 dark:text-white">{{ planDetails.name }}</h4>
                        <p class="text-sm text-gray-500">{{ formatCurrency(planDetails.monthly_price) }}/bulan</p>
                    </div>

                    <!-- Duration Options -->
                    <div class="grid grid-cols-2 gap-3 sm:gap-4">
                        <button
                            v-for="duration in durationOptions"
                            :key="duration.value"
                            type="button"
                            @click="selectDuration(duration)"
                            class="p-4 sm:p-6 rounded-lg border transition-all text-left relative hover:shadow-md"
                            :class="selectedDuration.value === duration.value
                                ? 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20'
                                : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                        >
                            <div class="flex justify-between items-start mb-2">
                                <p class="text-sm sm:text-lg font-bold text-gray-900 dark:text-white">{{ duration.label }}</p>
                                <div 
                                    v-if="duration.discount > 0"
                                    class="px-2 py-0.5 sm:py-1 rounded-full bg-red-500 text-white text-xs font-bold"
                                >
                                    -{{ duration.discount }}%
                                </div>
                            </div>
                            <p class="text-lg sm:text-2xl font-bold text-green-600 dark:text-green-400">
                                {{ formatCurrency(planDetails.monthly_price * duration.value - (planDetails.monthly_price * duration.value * duration.discount / 100)) }}
                            </p>
                            <p class="text-xs sm:text-sm text-gray-500 mt-1">
                                <span v-if="duration.discount > 0" class="line-through">{{ formatCurrency(planDetails.monthly_price * duration.value) }}</span>
                                <span v-else>{{ formatCurrency(planDetails.monthly_price * duration.value) }}</span>
                            </p>
                        </button>
                    </div>

                    <!-- Price Summary -->
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-900/50">
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Subtotal</span>
                                <span>{{ formatCurrency(subtotal) }}</span>
                            </div>
                            <div v-if="discount > 0" class="flex justify-between text-green-600 dark:text-green-400">
                                <span>Diskon ({{ selectedDuration.discount }}%)</span>
                                <span>-{{ formatCurrency(discount) }}</span>
                            </div>
                            <div class="border-t border-gray-200 pt-2 flex justify-between items-center dark:border-gray-700">
                                <span class="font-bold text-gray-900 dark:text-white">Total</span>
                                <span class="text-xl font-bold text-gray-900 dark:text-white">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            @click="goToStep(2)"
                            type="button"
                            class="px-6 py-3 rounded-xl text-white font-semibold"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            Lanjut ke Pembayaran
                        </Button>
                    </div>
                </div>

                <!-- Step 2: Metode Pembayaran -->
                <div v-show="currentStep === 2" class="space-y-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Pilih Metode Pembayaran</h3>
                        <p class="text-sm text-gray-500">Pilih cara pembayaran yang paling mudah untuk Anda</p>
                    </div>

                    <!-- Payment Methods -->
                    <div class="space-y-4">
                        <!-- QRIS -->
                        <button
                            @click="selectedPaymentMethod = 'qris'"
                            type="button"
                            class="w-full p-6 rounded-lg border transition-all text-left hover:shadow-md"
                            :class="selectedPaymentMethod === 'qris'
                                ? 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20'
                                : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-lg bg-white border border-gray-200 flex items-center justify-center p-2">
                                        <img src="/qris.png" alt="QRIS" class="w-full h-full object-contain" />
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 dark:text-white">QRIS</p>
                                        <p class="text-sm text-gray-500">Scan QR untuk pembayaran instan</p>
                                    </div>
                                </div>
                                <div 
                                    class="w-6 h-6 rounded-full border-2 flex items-center justify-center"
                                    :class="selectedPaymentMethod === 'qris' ? 'border-green-500 bg-green-500' : 'border-gray-300'"
                                >
                                    <div v-if="selectedPaymentMethod === 'qris'" class="w-3 h-3 rounded-full bg-white"></div>
                                </div>
                            </div>

                            <div v-if="selectedPaymentMethod === 'qris'" class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                                <div class="flex flex-col items-center">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 text-center">
                                        Scan QRIS menggunakan aplikasi e-wallet atau mobile banking Anda
                                    </p>
                                    <div 
                                        @click="showQrZoom = true"
                                        class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm cursor-pointer hover:shadow-md transition-shadow"
                                        title="Klik untuk memperbesar"
                                    >
                                        <img src="/qriss.png" alt="QRIS Code" class="w-48 h-48 object-contain" />
                                    </div>
                                    <p class="text-xs text-gray-400 mt-2">Klik gambar untuk memperbesar</p>
                                </div>
                            </div>
                        </button>

                        <!-- Transfer Bank -->
                        <button
                            @click="selectedPaymentMethod = 'bank'"
                            type="button"
                            class="w-full p-6 rounded-lg border transition-all text-left hover:shadow-md"
                            :class="selectedPaymentMethod === 'bank'
                                ? 'border-green-500 bg-green-50 ring-1 ring-green-500 dark:bg-green-900/20'
                                : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-lg bg-white border border-gray-200 flex items-center justify-center text-green-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect width="20" height="14" x="2" y="5" rx="2"></rect>
                                            <line x1="2" x2="22" y1="10" y2="10"></line>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 dark:text-white">Transfer Bank</p>
                                        <p class="text-sm text-gray-500">Transfer ke rekening bank</p>
                                    </div>
                                </div>
                                <div 
                                    class="w-6 h-6 rounded-full border-2 flex items-center justify-center"
                                    :class="selectedPaymentMethod === 'bank' ? 'border-green-500 bg-green-500' : 'border-gray-300'"
                                >
                                    <div v-if="selectedPaymentMethod === 'bank'" class="w-3 h-3 rounded-full bg-white"></div>
                                </div>
                            </div>

                            <div v-if="selectedPaymentMethod === 'bank'" class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700 space-y-3">
                                <div
                                    v-for="bank in banks"
                                    :key="bank.id"
                                    class="p-4 rounded-xl bg-white border border-gray-200 dark:bg-gray-900 dark:border-gray-700"
                                >
                                    <p class="font-bold text-gray-900 dark:text-white mb-3">{{ bank.name }}</p>
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-gray-500">No. Rekening:</span>
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono font-bold text-gray-900 dark:text-white">{{ bank.account_number }}</span>
                                                <button
                                                    @click.stop="copyToClipboard(bank.account_number)"
                                                    type="button"
                                                    class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                                >
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-gray-400">
                                                        <rect width="14" height="14" x="8" y="8" rx="2"></rect>
                                                        <path d="M4 16c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2h8c1.1 0 2 .9 2 2"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm text-gray-500">Atas Nama:</span>
                                            <span class="font-medium text-gray-900 dark:text-white">{{ bank.account_name }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </button>
                    </div>

                    <div class="flex justify-between">
                        <Button
                            @click="goToStep(1)"
                            type="button"
                            variant="outline"
                            class="px-6 py-3 rounded-xl"
                        >
                            Kembali
                        </Button>
                        <Button
                            @click="goToStep(3)"
                            type="button"
                            class="px-6 py-3 rounded-xl text-white font-semibold"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            Lanjut ke Konfirmasi
                        </Button>
                    </div>
                </div>

                <!-- Step 3: Upload Bukti / Konfirmasi -->
                <div v-show="currentStep === 3" class="space-y-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Upload Bukti Pembayaran</h3>
                        <p class="text-sm text-gray-500">Upload bukti transfer untuk mempercepat verifikasi</p>
                    </div>

                    <!-- Order Summary -->
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-6 dark:border-gray-700 dark:bg-gray-900/50">
                        <h4 class="font-bold text-gray-900 dark:text-white mb-4">Ringkasan Pesanan</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Paket:</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ planDetails.name }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Durasi:</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ selectedDuration.label }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Metode Pembayaran:</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ selectedPaymentMethod === 'qris' ? 'QRIS' : 'Transfer Bank' }}</span>
                            </div>
                            <div class="border-t border-gray-200 pt-2 flex justify-between dark:border-gray-700">
                                <span class="font-bold text-gray-900 dark:text-white">Total:</span>
                                <span class="text-xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Section -->
                    <div class="space-y-4">
                        <label class="block">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Bukti Pembayaran</span>
                            <div class="mt-2 flex justify-center rounded-xl border-2 border-dashed border-gray-300 px-6 py-10 dark:border-gray-700">
                                <div class="text-center">
                                    <svg v-if="!previewUrl" class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <img v-else :src="previewUrl" class="mx-auto max-h-64 rounded-lg" />
                                    <div class="mt-4 flex text-sm text-gray-600">
                                        <label class="relative cursor-pointer rounded-md font-medium text-green-600 hover:text-green-500">
                                            <span>{{ previewUrl ? 'Ganti file' : 'Upload file' }}</span>
                                            <input
                                                type="file"
                                                class="sr-only"
                                                accept="image/*"
                                                @change="handleFileSelect"
                                            />
                                        </label>
                                        <p class="pl-1">atau drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">PNG, JPG, GIF up to 5MB</p>
                                </div>
                            </div>
                        </label>

                        <!-- Notes -->
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Catatan (Opsional)</label>
                            <textarea
                                v-model="subscriptionForm.notes"
                                rows="3"
                                class="mt-2 w-full rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                placeholder="Tambahkan catatan jika diperlukan"
                            ></textarea>
                        </div>
                    </div>

                    <div class="flex justify-between">
                        <Button
                            @click="goToStep(2)"
                            type="button"
                            variant="outline"
                            class="px-6 py-3 rounded-xl"
                        >
                            Kembali
                        </Button>
                        <Button
                            @click="submitSubscription"
                            type="button"
                            :disabled="subscriptionForm.processing"
                            class="px-6 py-3 rounded-xl text-white font-semibold"
                            style="background-color: oklch(0.65 0.19 137.46);"
                        >
                            {{ subscriptionForm.processing ? 'Memproses...' : 'Kirim Permintaan' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- QRIS Zoom Dialog -->
        <Dialog :open="showQrZoom" @update:open="showQrZoom = $event">
            <DialogContent class="max-w-2xl p-0 overflow-hidden bg-transparent border-0">
                <div class="relative bg-white dark:bg-gray-900 rounded-xl p-6">
                    <button
                        @click="showQrZoom = false"
                        class="absolute top-4 right-4 p-2 rounded-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 transition-colors z-10"
                    >
                        <X class="w-5 h-5 text-gray-600 dark:text-gray-300" />
                    </button>
                    <div class="flex flex-col items-center">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Scan QRIS</h3>
                        <img src="/qriss.png" alt="QRIS Code" class="w-full max-w-md object-contain" />
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-4 text-center">
                            Scan menggunakan aplikasi e-wallet atau mobile banking Anda
                        </p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
