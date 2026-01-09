<script setup lang="ts">
import { Head, Link, router, useForm, Form } from '@inertiajs/vue3';
import { computed, ref, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { useFacebookPixel } from '@/composables/useFacebookPixel';
import { login } from '@/routes';
import BackgroundAccents from '@/components/Landing/BackgroundAccents.vue';
import FooterSection from '@/components/Landing/FooterSection.vue';

interface Duration {
    months: number;
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
    planDetails: PlanDetails;
    durations: Duration[];
    banks: Bank[];
    isFreePlan?: boolean;
}

const props = defineProps<Props>();
const { showError, showSuccess } = useSweetAlert();
const { trackInitiateCheckout, trackCompleteRegistration, trackStartTrial, trackLead } = useFacebookPixel();

// Track InitiateCheckout when page loads
onMounted(() => {
    trackInitiateCheckout({
        content_name: props.planDetails.name,
        currency: 'IDR',
        value: props.isFreePlan ? 0 : props.planDetails.monthly_price,
    });
});

const selectedDuration = ref<Duration>(props.durations[0]); // Default: 1 bulan
const selectedPaymentMethod = ref<string>('qris'); // Default: QRIS
const showQrZoom = ref(false); // QR Code zoom modal

const subtotal = computed(() => {
    return props.planDetails.monthly_price * selectedDuration.value.months;
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
        maximumFractionDigits: 0,
    }).format(amount);
};

// Registration form
const registerForm = useForm({
    name: '',
    email: '',
    whatsapp_number: '',
    password: '',
    password_confirmation: '',
    plan: props.isFreePlan ? 'free' : 'growth',
    duration_months: selectedDuration.value.months,
    price: total.value,
});

// Update form when duration changes
const updateDuration = (duration: Duration) => {
    selectedDuration.value = duration;
    registerForm.duration_months = duration.months;
    registerForm.price = total.value;
};

const handleRegister = () => {
    // Update price and plan before submit
    registerForm.price = total.value;
    registerForm.duration_months = selectedDuration.value.months;
    registerForm.plan = props.isFreePlan ? 'free' : 'growth';
    
    // Submit registration form to Fortify
    registerForm.post('/register', {
        onSuccess: () => {
            // Track Facebook Pixel events
            if (props.isFreePlan) {
                // Track as Lead/StartTrial for free plan
                trackStartTrial({
                    currency: 'IDR',
                    value: 0,
                });
                trackLead({
                    content_name: props.planDetails.name,
                    currency: 'IDR',
                    value: 0,
                });
                showSuccess('Berhasil', 'Pendaftaran berhasil! Ujicoba gratis Anda telah dimulai.');
            } else {
                // Track as CompleteRegistration for paid plan
                trackCompleteRegistration({
                    content_name: props.planDetails.name,
                    currency: 'IDR',
                    value: total.value,
                    status: true,
                });
                showSuccess('Berhasil', 'Pendaftaran berhasil! Silakan lanjutkan pembayaran.');
            }
            // After registration, user will be redirected to dashboard
        },
        onError: (errors) => {
            if (errors.email) {
                showError('Error', errors.email);
            } else if (errors.whatsapp_number) {
                showError('Error', errors.whatsapp_number);
            } else if (errors.password) {
                showError('Error', errors.password);
            } else {
                showError('Error', 'Terjadi kesalahan saat mendaftar. Silakan coba lagi.');
            }
        },
    });
};

// Watch total to update form price
computed(() => {
    registerForm.price = total.value;
    return total.value;
});

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

// WhatsApp number validation
const whatsappValidation = ref<{
    checking: boolean;
    valid: boolean | null;
    message: string;
}>({
    checking: false,
    valid: null,
    message: ''
});

let validateTimeout: ReturnType<typeof setTimeout> | null = null;

const validateWhatsAppNumber = async (phoneNumber: string) => {
    // Clear previous timeout
    if (validateTimeout) {
        clearTimeout(validateTimeout);
    }
    
    // Reset validation state
    whatsappValidation.value = {
        checking: false,
        valid: null,
        message: ''
    };
    
    // Don't validate if number is too short
    if (!phoneNumber || phoneNumber.length < 10) {
        return;
    }
    
    // Debounce: wait 800ms before validating
    validateTimeout = setTimeout(async () => {
        whatsappValidation.value.checking = true;
        
        try {
            const response = await fetch('/api/validate-whatsapp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ phone_number: phoneNumber })
            });
            
            const data = await response.json();
            
            // Check if number is already registered in our system
            if (data.already_registered) {
                whatsappValidation.value = {
                    checking: false,
                    valid: false,
                    message: '⚠️ Nomor ini sudah terdaftar. Silakan login.'
                };
            } else if (data.success && data.exists) {
                whatsappValidation.value = {
                    checking: false,
                    valid: true,
                    message: '✓ Nomor terdaftar di WhatsApp'
                };
            } else if (data.success && !data.exists) {
                whatsappValidation.value = {
                    checking: false,
                    valid: false,
                    message: '✗ Nomor tidak terdaftar di WhatsApp'
                };
            } else {
                // API error, assume valid as fallback
                whatsappValidation.value = {
                    checking: false,
                    valid: null,
                    message: ''
                };
            }
        } catch (error) {
            // Network error, don't show error to user
            whatsappValidation.value = {
                checking: false,
                valid: null,
                message: ''
            };
        }
    }, 800);
};

// Watch whatsapp_number changes
import { watch } from 'vue';
watch(() => registerForm.whatsapp_number, (newValue) => {
    validateWhatsAppNumber(newValue);
});
</script>

<template>
    <Head title="Checkout" />

    <div class="antialiased bg-white text-neutral-900 selection:bg-emerald-500/30 selection:text-emerald-900 font-sans min-h-screen flex flex-col">
        <BackgroundAccents />

        <!-- Header -->
        <header class="sticky top-0 z-50 backdrop-blur-xl bg-white/80 border-b border-gray-200/60 transition-all duration-300">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <Link href="/" class="flex items-center group transition-transform duration-200 hover:scale-105">
                        <img src="/logo.png" alt="Logo FinWa Aplikasi Keuangan" class="h-9 w-auto" />
                    </Link>
                    <Link href="/" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors duration-200">
                        Kembali ke Beranda
                    </Link>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12 relative z-10">
            <div class="max-w-4xl mx-auto">
                <h1 class="text-3xl font-semibold text-neutral-900 mb-2">Checkout</h1>
                <p class="text-neutral-600 mb-8">Lengkapi informasi langganan Anda</p>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Order Summary -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Plan Information -->
                        <div class="rounded-2xl border border-neutral-200 bg-white/50 backdrop-blur-sm p-6 shadow-sm hover:shadow-md transition-shadow duration-300">
                            <h2 class="text-lg font-semibold text-neutral-900 mb-4">Paket yang Dipilih</h2>
                            <div class="flex items-center justify-between p-4 rounded-xl bg-neutral-50 ring-1 ring-neutral-200/60">
                                <div>
                                    <p class="text-base font-medium text-neutral-900">{{ planDetails.name }}</p>
                                    <p v-if="isFreePlan" class="text-sm text-neutral-500 mt-1">
                                        Gratis
                                    </p>
                                    <p v-else class="text-sm text-neutral-500 mt-1">
                                        {{ formatCurrency(planDetails.monthly_price) }}/bulan
                                    </p>
                                </div>
                                <div class="flex items-center gap-2 px-3 py-1 rounded-full" :class="isFreePlan ? 'bg-cyan-50 ring-1 ring-cyan-200' : 'bg-emerald-50 ring-1 ring-emerald-200'">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4" :class="isFreePlan ? 'text-cyan-600' : 'text-emerald-600'"><path d="M20 6 9 17l-5-5"></path></svg>
                                    <span class="text-xs font-medium" :class="isFreePlan ? 'text-cyan-600' : 'text-emerald-600'">Dipilih</span>
                                </div>
                            </div>
                        </div>

                        <!-- Duration Selection -->
                        <div v-if="!isFreePlan" class="rounded-2xl border border-neutral-200 bg-white/50 backdrop-blur-sm p-6 shadow-sm hover:shadow-md transition-shadow duration-300">
                            <h2 class="text-lg font-semibold text-neutral-900 mb-4">Durasi Langganan</h2>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <button
                                    v-for="duration in durations"
                                    :key="duration.months"
                                    @click="updateDuration(duration)"
                                    type="button"
                                    class="p-4 rounded-xl border transition-all duration-200 text-left relative overflow-hidden group"
                                    :class="
                                        selectedDuration.months === duration.months
                                            ? 'border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-500/20 shadow-sm'
                                            : 'border-neutral-200 bg-white hover:border-emerald-300 hover:shadow-sm'
                                    "
                                >
                                    <p class="text-sm font-medium text-neutral-900">{{ duration.label }}</p>
                                    <p v-if="duration.discount > 0" class="text-xs text-emerald-600 mt-1">
                                        Diskon {{ duration.discount }}%
                                    </p>
                                    <p v-else class="text-xs text-neutral-400 mt-1">&nbsp;</p>
                                </button>
                            </div>
                            <p class="text-xs text-neutral-600 mt-4">
                                Durasi yang dipilih: <span class="text-neutral-900 font-medium">{{ selectedDuration.label }}</span>
                            </p>
                        </div>
                        
                        <!-- Duration Info for Free Plan -->
                        <div v-else class="rounded-2xl border border-neutral-200 bg-white/50 backdrop-blur-sm p-6 shadow-sm hover:shadow-md transition-shadow duration-300">
                            <h2 class="text-lg font-semibold text-neutral-900 mb-4">Durasi Ujicoba</h2>
                            <div class="p-4 rounded-xl bg-cyan-50 ring-1 ring-cyan-200/60">
                                <p class="text-sm font-medium text-neutral-900">{{ selectedDuration.label }}</p>
                                <p class="text-xs text-cyan-600 mt-1">Ujicoba gratis penuh dengan semua fitur</p>
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div v-if="!isFreePlan" class="rounded-2xl border border-neutral-200 bg-white/50 backdrop-blur-sm p-6 shadow-sm hover:shadow-md transition-shadow duration-300">
                            <h2 class="text-lg font-semibold text-neutral-900 mb-4">Metode Pembayaran</h2>
                            <div class="space-y-3">
                                <!-- QRIS Payment Dropdown -->
                                <div class="rounded-xl border border-neutral-200 bg-white overflow-hidden">
                                    <button 
                                        @click="selectedPaymentMethod = 'qris'"
                                        class="w-full flex items-center justify-between p-4 text-left hover:bg-neutral-50 transition-colors"
                                        :class="selectedPaymentMethod === 'qris' ? 'bg-neutral-50' : ''"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-white border border-neutral-200 flex items-center justify-center p-1">
                                                <img src="/qris.png" alt="QRIS" class="w-full h-full object-contain" />
                                            </div>
                                            <div>
                                                <p class="text-sm font-semibold text-neutral-900">QRIS</p>
                                                <p class="text-xs text-neutral-500">Scan QR code untuk pembayaran instan</p>
                                            </div>
                                        </div>
                                        <div class="w-5 h-5 rounded-full border flex items-center justify-center"
                                            :class="selectedPaymentMethod === 'qris' ? 'border-emerald-500 bg-emerald-500' : 'border-neutral-300'"
                                        >
                                            <div v-if="selectedPaymentMethod === 'qris'" class="w-2 h-2 rounded-full bg-white"></div>
                                        </div>
                                    </button>
                                    
                                    <div v-show="selectedPaymentMethod === 'qris'" class="p-4 border-t border-neutral-200 bg-neutral-50/50">
                                        <div class="flex flex-col items-center text-center">
                                            <p class="text-sm text-neutral-600 mb-4">Scan QRIS di bawah ini menggunakan aplikasi e-wallet atau mobile banking Anda.</p>
                                            
                                            <div 
                                                class="bg-white p-2 rounded-xl border border-neutral-200 inline-block shadow-sm mb-3 cursor-pointer hover:shadow-md transition-shadow"
                                                @click="showQrZoom = true"
                                                title="Klik untuk memperbesar"
                                            >
                                                <img src="/qriss.png" alt="Scan QRIS" class="w-48 h-48 object-contain mx-auto" />
                                            </div>
                                            <p class="text-xs text-neutral-400">Klik gambar untuk memperbesar</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Manual Transfer Dropdown -->
                                <div class="rounded-xl border border-neutral-200 bg-white overflow-hidden">
                                    <button 
                                        @click="selectedPaymentMethod = 'manual'"
                                        class="w-full flex items-center justify-between p-4 text-left hover:bg-neutral-50 transition-colors"
                                        :class="selectedPaymentMethod === 'manual' ? 'bg-neutral-50' : ''"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-white border border-neutral-200 flex items-center justify-center text-emerald-600">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                                            </div>
                                            <div>
                                                <p class="text-sm font-semibold text-neutral-900">Transfer Bank Manual</p>
                                                <p class="text-xs text-neutral-500">Transfer ke rekening bank admin</p>
                                            </div>
                                        </div>
                                        <div class="w-5 h-5 rounded-full border flex items-center justify-center"
                                            :class="selectedPaymentMethod === 'manual' ? 'border-emerald-500 bg-emerald-500' : 'border-neutral-300'"
                                        >
                                            <div v-if="selectedPaymentMethod === 'manual'" class="w-2 h-2 rounded-full bg-white"></div>
                                        </div>
                                    </button>

                                    <div v-show="selectedPaymentMethod === 'manual'" class="p-4 border-t border-neutral-200 bg-neutral-50/50">
                                        <!-- Bank Accounts -->
                                        <div v-if="banks && banks.length > 0" class="space-y-3">
                                            <div
                                                v-for="bank in banks"
                                                :key="bank.id"
                                                class="p-2 rounded-xl bg-white border border-neutral-200 shadow-sm"
                                            >
                                                <div class="flex items-start justify-between gap-4">
                                                    <div class="flex-1">
                                                        <p class="text-sm font-semibold text-neutral-900 mb-2">{{ bank.name }}</p>
                                                        <div class="space-y-1.5">
                                                            <div class="flex items-center gap-2">
                                                                <span class="text-xs text-neutral-500 min-w-[100px]">No. Rekening:</span>
                                                                <span class="text-sm font-mono text-neutral-900 font-medium">{{ bank.account_number }}</span>
                                                                <button
                                                                    @click="copyToClipboard(bank.account_number)"
                                                                    type="button"
                                                                    class="ml-2 p-1 rounded hover:bg-neutral-100 transition-colors"
                                                                    title="Salin nomor rekening"
                                                                >
                                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-neutral-400 hover:text-neutral-600"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2h8c1.1 0 2 .9 2 2"></path></svg>
                                                                </button>
                                                            </div>
                                                            <div class="flex items-center gap-2">
                                                                <span class="text-xs text-neutral-500 min-w-[100px]">Atas Nama:</span>
                                                                <span class="text-sm text-neutral-900">{{ bank.account_name }}</span>
                                                            </div>
                                                            <div v-if="bank.description" class="mt-2 pt-2 border-t border-neutral-200">
                                                                <p class="text-xs text-neutral-500">{{ bank.description }}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="p-4 rounded-lg bg-yellow-50 border border-yellow-200">
                                            <p class="text-sm text-yellow-700">Belum ada rekening bank yang tersedia. Silakan hubungi admin untuk informasi pembayaran.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Registration Form -->
                        <div class="rounded-2xl border border-neutral-200 bg-white/50 backdrop-blur-sm p-6 shadow-sm hover:shadow-md transition-shadow duration-300">
                            <h2 class="text-lg font-semibold text-neutral-900 mb-4">Data Pengguna</h2>
                            
                            <Form @submit.prevent="handleRegister" class="space-y-4">
                                <!-- Nama Lengkap -->
                                <div class="grid gap-2">
                                    <Label for="name">Nama Lengkap</Label>
                                    <Input
                                        id="name"
                                        v-model="registerForm.name"
                                        type="text"
                                        required
                                        autofocus
                                        autocomplete="name"
                                        placeholder="Masukkan nama lengkap"
                                        class="bg-white border-neutral-200 text-neutral-900 placeholder:text-neutral-400 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all duration-200"
                                    />
                                    <InputError :message="registerForm.errors.name" />
                                </div>

                                <!-- Email -->
                                <div class="grid gap-2">
                                    <Label for="email">Email</Label>
                                    <Input
                                        id="email"
                                        v-model="registerForm.email"
                                        type="email"
                                        required
                                        autocomplete="email"
                                        placeholder="email@example.com"
                                        class="bg-white border-neutral-200 text-neutral-900 placeholder:text-neutral-400 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all duration-200"
                                    />
                                    <InputError :message="registerForm.errors.email" />
                                </div>

                                <!-- No WhatsApp -->
                                <div class="grid gap-2">
                                    <Label for="whatsapp_number">No Whatsapp <span class="text-red-500">*</span></Label>
                                    <Input
                                        id="whatsapp_number"
                                        v-model="registerForm.whatsapp_number"
                                        type="text"
                                        required
                                        placeholder="6281234567812"
                                        class="bg-white border-neutral-200 text-neutral-900 placeholder:text-neutral-400 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all duration-200"
                                        :class="{
                                            'border-emerald-500 ring-2 ring-emerald-500/20': whatsappValidation.valid === true,
                                            'border-red-500 ring-2 ring-red-500/20': whatsappValidation.valid === false
                                        }"
                                    />
                                    <p class="text-xs text-neutral-500">Contoh: 6281234567812 (format: 62 + nomor tanpa 0 di depan)</p>
                                    
                                    <!-- WhatsApp Validation Status -->
                                    <div v-if="whatsappValidation.checking" class="flex items-center gap-2 text-xs text-neutral-500">
                                        <svg class="animate-spin h-4 w-4 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span>Memeriksa nomor WhatsApp...</span>
                                    </div>
                                    <div v-else-if="whatsappValidation.valid === true" class="flex items-center gap-2 text-xs text-emerald-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                                        <span>{{ whatsappValidation.message }}</span>
                                    </div>
                                    <div v-else-if="whatsappValidation.valid === false" class="flex items-center gap-2 text-xs text-red-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                                        <span>{{ whatsappValidation.message }}</span>
                                    </div>
                                    
                                    <InputError :message="registerForm.errors.whatsapp_number" />
                                </div>

                                <!-- Password -->
                                <div class="grid gap-2">
                                    <Label for="password">Password</Label>
                                    <Input
                                        id="password"
                                        v-model="registerForm.password"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="Masukkan password"
                                        class="bg-white border-neutral-200 text-neutral-900 placeholder:text-neutral-400 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all duration-200"
                                    />
                                    <InputError :message="registerForm.errors.password" />
                                </div>

                                <!-- Konfirmasi Password -->
                                <div class="grid gap-2">
                                    <Label for="password_confirmation">Konfirmasi Password</Label>
                                    <Input
                                        id="password_confirmation"
                                        v-model="registerForm.password_confirmation"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="Konfirmasi password"
                                        class="bg-white border-neutral-200 text-neutral-900 placeholder:text-neutral-400 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all duration-200"
                                    />
                                    <InputError :message="registerForm.errors.password_confirmation" />
                                </div>
                            </Form>
                        </div>
                    </div>

                    <!-- Order Summary Card -->
                    <div class="lg:col-span-1">
                        <div class="rounded-2xl border border-neutral-200 bg-white/50 backdrop-blur-sm p-6 sticky top-24 shadow-lg shadow-gray-200/50 relative overflow-hidden">
                            <!-- Background accent -->
                            <div class="absolute -top-20 -right-20 h-40 w-40 rounded-full bg-emerald-500/5 blur-3xl pointer-events-none"></div>
                            <div class="absolute -bottom-20 -left-20 h-40 w-40 rounded-full bg-cyan-500/5 blur-3xl pointer-events-none"></div>

                            <h2 class="text-lg font-semibold text-neutral-900 mb-4 relative z-10">Ringkasan Pesanan</h2>
                            
                            <div class="space-y-4 mb-6 relative z-10">
                                <div class="flex justify-between text-sm">
                                    <span class="text-neutral-600">Paket</span>
                                    <span class="text-neutral-900 font-medium">{{ planDetails.name }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-neutral-600">Durasi</span>
                                    <span class="text-neutral-900 font-medium">{{ selectedDuration.label }}</span>
                                </div>
                                <div v-if="!isFreePlan" class="flex justify-between text-sm">
                                    <span class="text-neutral-600">Harga/bulan</span>
                                    <span class="text-neutral-900">{{ formatCurrency(planDetails.monthly_price) }}</span>
                                </div>
                                <div v-if="!isFreePlan" class="flex justify-between text-sm">
                                    <span class="text-neutral-600">Subtotal</span>
                                    <span class="text-neutral-900">{{ formatCurrency(subtotal) }}</span>
                                </div>
                                <div v-if="!isFreePlan && discount > 0" class="flex justify-between text-sm">
                                    <span class="text-emerald-600">Diskon ({{ selectedDuration.discount }}%)</span>
                                    <span class="text-emerald-600">-{{ formatCurrency(discount) }}</span>
                                </div>
                                <div class="border-t border-neutral-200 pt-4 flex justify-between items-center">
                                    <span class="text-neutral-900 font-semibold">Total</span>
                                    <span v-if="isFreePlan" class="text-cyan-600 text-2xl font-bold">Gratis</span>
                                    <span v-else class="text-neutral-900 text-3xl font-bold tracking-tight">{{ formatCurrency(total) }}</span>
                                </div>
                            </div>

                            <Button
                                @click="handleRegister"
                                :disabled="registerForm.processing"
                                class="w-full rounded-xl py-6 text-base font-bold text-white shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all duration-200"
                                :class="isFreePlan 
                                    ? 'bg-cyan-600 hover:bg-cyan-500 active:bg-cyan-700 shadow-cyan-500/20 hover:shadow-cyan-500/30'
                                    : 'shadow-emerald-500/20 hover:shadow-emerald-500/30'"
                                :style="!isFreePlan ? 'background-color: oklch(0.65 0.19 137.46);' : ''"
                            >
                                {{ registerForm.processing 
                                    ? 'Memproses...' 
                                    : isFreePlan 
                                        ? 'Mulai Ujicoba Gratis' 
                                        : 'Daftar dan Bayar Sekarang' }}
                            </Button>

                            <div class="mt-4 text-center">
                                <p class="text-sm text-neutral-600">
                                    Sudah pernah daftar?
                                    <Link :href="login()" class="text-emerald-600 hover:text-emerald-500 font-medium underline">
                                        Login dan Bayar Sekarang
                                    </Link>
                                </p>
                            </div>

                            <p class="text-xs text-neutral-400 mt-4 text-center">
                                Dengan melanjutkan, Anda menyetujui Syarat & Ketentuan kami
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <FooterSection />

        <!-- QR Code Zoom Modal -->
        <Dialog v-model:open="showQrZoom">
            <DialogContent class="max-w-md p-6">
                <div class="flex flex-col items-center text-center">
                    <h3 class="text-lg font-semibold text-neutral-900 mb-4">Scan QRIS</h3>
                    <div class="bg-white p-4 rounded-xl border border-neutral-200 shadow-lg">
                        <img src="/qriss.png" alt="Scan QRIS" class="w-80 h-80 object-contain mx-auto" />
                    </div>
                    <p class="text-sm text-neutral-600 mt-4">Scan menggunakan aplikasi e-wallet atau mobile banking Anda</p>
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
    </div>
</template>
