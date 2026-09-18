<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    X, Send, ArrowUpCircle, ArrowDownCircle, Check, Loader2, Pencil, ChevronDown, Calendar, Wallet, Camera,
    PiggyBank, Utensils, Home, Car, GraduationCap, ShoppingBag,
    Heart, Gamepad2, Shirt, Sparkles, Coffee, Film, Music,
    Dumbbell, Plane, Gift, Zap, Smartphone,
} from 'lucide-vue-next';
interface ParsedResult {
    success: boolean;
    type: string;
    amount: number;
    description: string;
    category_id: number | null;
    category_type: string;
    category_name: string;
    category_icon: string;
    date: string;
    confidence: number;
    default_balance_id: number | null;
    balances: Array<{
        id: number;
        name: string;
        type: string;
        balance: number;
        currency: string;
    }>;
    alternatives: Array<{
        id: number;
        type: string;
        name: string;
        icon: string;
    }>;
    merchant?: string;
    items?: Array<{ name: string; price: number }>;
    source?: string;
    error?: string;
}

interface Props {
    show: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'saved'): void;
}>();

const inputText = ref('');
const inputRef = ref<HTMLInputElement | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);
const parsed = ref<ParsedResult | null>(null);
const isParsing = ref(false);
const isSaving = ref(false);
const isUploadingReceipt = ref(false);
const parseError = ref('');
const showSuccessAnim = ref(false);
const savedTransactionInfo = ref<{ amount: number; type: string; description: string } | null>(null);
const receiptItems = ref<Array<{ name: string; price: number }>>([]);
const receiptMerchant = ref<string | null>(null);
let parseTimeout: ReturnType<typeof setTimeout> | null = null;

const page = usePage();
const isPremium = computed(() => {
    return (page.props as any).auth?.user?.is_premium ?? false;
});

const editableType = ref('');
const editableCategoryId = ref<number | null>(null);
const editableDate = ref('');
const editableBalanceId = ref<number | null>(null);

function getCsrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) return meta;
    const cookie = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='));
    if (cookie) {
        try { return decodeURIComponent(cookie.split('=')[1]); } catch { }
    }
    return '';
}

function getRequestHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const csrf = getCsrfToken();
    if (csrf) {
        headers['X-CSRF-TOKEN'] = csrf;
        headers['X-XSRF-TOKEN'] = csrf;
    }
    return headers;
}

const chatMessages = ref<Array<{
    role: 'user' | 'system';
    type: 'text' | 'preview' | 'success' | 'error';
    content: string;
    data?: ParsedResult;
}>>([]);

const canSave = computed(() => {
    return parsed.value?.success && !isSaving.value;
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const typeLabel = (type: string) => type === 'income' ? 'Pemasukan' : 'Pengeluaran';
const typeColor = (type: string) => type === 'income'
    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
    : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';

const confidencePercent = computed(() => {
    if (!parsed.value) return 0;
    return Math.round(parsed.value.confidence * 100);
});

const confidenceColor = computed(() => {
    if (confidencePercent.value >= 80) return 'text-emerald-600 dark:text-emerald-400';
    if (confidencePercent.value >= 60) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
});

watch(() => props.show, (val) => {
    if (val) {
        resetState();
        nextTick(() => inputRef.value?.focus());
    }
});

watch(inputText, (val) => {
    if (parseTimeout) clearTimeout(parseTimeout);
    if (val.trim().length < 3) {
        parsed.value = null;
        parseError.value = '';
        return;
    }
    parseTimeout = setTimeout(() => parseInput(), 600);
});

function resetState() {
    inputText.value = '';
    parsed.value = null;
    isParsing.value = false;
    isSaving.value = false;
    isUploadingReceipt.value = false;
    parseError.value = '';
    showSuccessAnim.value = false;
    receiptItems.value = [];
    receiptMerchant.value = null;
    chatMessages.value = [
        {
            role: 'system',
            type: 'text',
            content: 'Ketik transaksi seperti di WhatsApp.\nContoh: *makan siang 25rb* atau *gaji 5jt*\n\nAtau foto struk belanja Anda untuk input otomatis!',
        },
    ];
}

async function parseInput() {
    const text = inputText.value.trim();
    if (text.length < 3) return;

    isParsing.value = true;
    parseError.value = '';

    try {
        const response = await fetch('/transactions/parse', {
            method: 'POST',
            headers: {
                ...getRequestHeaders(),
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ text }),
        });

        if (response.status === 419) {
            parseError.value = 'Sesi telah kedaluwarsa. Silakan refresh halaman browser.';
            return;
        }

        const data = await response.json();

        if (data.success) {
            parsed.value = data;
            editableType.value = data.type;
            editableCategoryId.value = data.category_id;
            editableDate.value = data.date;
            editableBalanceId.value = data.default_balance_id;
        } else {
            parsed.value = null;
            parseError.value = data.error || 'Gagal parse transaksi';
        }
    } catch {
        parseError.value = 'Gagal menghubungi server';
    } finally {
        isParsing.value = false;
    }
}

async function handleReceiptUpload(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    isUploadingReceipt.value = true;
    parseError.value = '';
    parsed.value = null;
    receiptItems.value = [];
    receiptMerchant.value = null;

    chatMessages.value.push({
        role: 'user',
        type: 'text',
        content: '📸 Mengupload struk belanja...',
    });

    try {
        const formData = new FormData();
        formData.append('image', file);

        const response = await fetch('/transactions/parse-receipt', {
            method: 'POST',
            headers: {
                ...getRequestHeaders(),
            },
            credentials: 'same-origin',
            body: formData,
        });

        if (response.status === 419) {
            chatMessages.value.push({
                role: 'system',
                type: 'error',
                content: 'Sesi telah kedaluwarsa. Silakan refresh halaman browser Anda.',
            });
            return;
        }

        const data = await response.json();

        if (data.success) {
            parsed.value = data;
            editableType.value = data.type;
            editableCategoryId.value = data.category_id;
            editableDate.value = data.date;
            editableBalanceId.value = data.default_balance_id;
            receiptItems.value = data.items || [];
            receiptMerchant.value = data.merchant || null;

            chatMessages.value.push({
                role: 'system',
                type: 'success',
                content: `✅ Struk terbaca! ${data.merchant ? 'Toko: ' + data.merchant : ''}`,
            });
        } else {
            parseError.value = data.error || 'Gagal membaca struk';
            chatMessages.value.push({
                role: 'system',
                type: 'error',
                content: data.error || 'Gagal membaca struk',
            });
        }
    } catch {
        parseError.value = 'Gagal menghubungi server';
        chatMessages.value.push({
            role: 'system',
            type: 'error',
            content: 'Gagal menghubungi server',
        });
    } finally {
        isUploadingReceipt.value = false;
        if (fileInputRef.value) fileInputRef.value.value = '';
    }
}

function triggerReceiptUpload() {
    if (!isPremium.value) {
        chatMessages.value.push({
            role: 'system',
            type: 'error',
            content: '⚠️ <strong>Fitur Scan Struk Premium</strong><br>Fitur membaca struk belanja otomatis (OCR) hanya tersedia untuk paket Premium (Grow/Pro).<br><br><a href="/subscriptions" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700 transition-all mt-1">Upgrade Sekarang</a>',
        });
        return;
    }
    fileInputRef.value?.click();
}

async function handleSend() {
    if (!inputText.value.trim()) return;

    if (parsed.value?.success) {
        await saveTransaction();
        return;
    }

    await parseInput();
}

async function saveTransaction() {
    if (!parsed.value?.success) return;

    isSaving.value = true;

    const payload: Record<string, any> = {
        type: editableType.value,
        amount: parsed.value.amount,
        description: parsed.value.description,
        category_id: editableCategoryId.value,
        transaction_date: editableDate.value,
        status: 'confirmed',
    };

    if (editableBalanceId.value) {
        payload.balance_id = editableBalanceId.value;
    }

    if (parsed.value.source) {
        payload.source = parsed.value.source;
    }

    try {
        const response = await fetch('/transactions/store-json', {
            method: 'POST',
            headers: {
                ...getRequestHeaders(),
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        if (response.status === 419) {
            chatMessages.value.push({
                role: 'system',
                type: 'error',
                content: 'Sesi telah kedaluwarsa. Silakan refresh halaman browser Anda.',
            });
            return;
        }

        const data = await response.json();

        if (response.ok && data.transaction) {
            savedTransactionInfo.value = {
                amount: data.transaction.amount,
                type: data.transaction.type,
                description: data.transaction.description || 'Transaksi berhasil',
            };
            showSuccessAnim.value = true;

            chatMessages.value.push(
                { role: 'user', type: 'text', content: inputText.value || parsed.value.description },
                { role: 'system', type: 'success', content: `✅ ${typeLabel(data.transaction.type)} ${formatCurrency(data.transaction.amount)} berhasil dicatat!` },
            );

            inputText.value = '';
            parsed.value = null;

            setTimeout(() => {
                showSuccessAnim.value = false;
                emit('saved');
                router.reload({ only: ['balanceData', 'recentTransactions', 'chartData', 'insights'] });
            }, 1800);
        } else {
            chatMessages.value.push(
                { role: 'system', type: 'error', content: data.message || 'Gagal menyimpan transaksi' },
            );
        }
    } catch {
        chatMessages.value.push(
            { role: 'system', type: 'error', content: 'Gagal menghubungi server' },
        );
    } finally {
        isSaving.value = false;
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
    }
    if (e.key === 'Escape') {
        emit('close');
    }
}

function handleOverlayClick(e: MouseEvent) {
    if ((e.target as HTMLElement).classList.contains('modal-overlay')) {
        emit('close');
    }
}

function toggleType() {
    editableType.value = editableType.value === 'income' ? 'expense' : 'income';
    if (parsed.value) parsed.value.type = editableType.value;
}

function getCategoryIcon(iconEmoji: string) {
    const iconMap: Record<string, any> = {
        // Makanan
        '🍔': Utensils, '🍕': Utensils, '🍜': Utensils, '🍽️': Utensils,
        // Hunian
        '🏠': Home, '🏡': Home,
        // Transport
        '🚗': Car, '🚙': Car, '🚕': Car,
        // Pendidikan
        '📚': GraduationCap, '🎓': GraduationCap,
        // Belanja
        '🛍️': ShoppingBag, '🛒': ShoppingBag, '📦': ShoppingBag,
        // Kesehatan / Donasi
        '❤️': Heart, '💊': Heart, '🏥': Heart,
        // Hiburan
        '🎮': Gamepad2, '🎯': Gamepad2, '🎬': Film,
        // Pakaian
        '👕': Shirt, '👔': Shirt,
        // Perawatan diri
        '✨': Sparkles, '💇': Sparkles,
        // Kopi
        '☕': Coffee,
        // Musik
        '🎵': Music,
        // Olahraga
        '🏋️': Dumbbell,
        // Travel
        '✈️': Plane,
        // Hadiah / Bonus
        '🎁': Gift, '🎊': Gift,
        // Utilitas / Operasional
        '⚡': Zap, '⚙️': Zap,
        // Pulsa / Gadget
        '📱': Smartphone,
        // Keuangan / Dompet
        '💳': Wallet, '💰': Wallet, '💵': Wallet, '💸': Wallet, '💼': Wallet,
        // Tagihan / Dokumen
        '📄': Wallet, '📊': Wallet, '📝': Wallet,
        // Transfer
        '📤': Wallet, '📥': Wallet,
        // Investasi
        '📈': PiggyBank,
        // Sosial / Piutang
        '🤝': Heart,
        // Cicilan / Bank
        '🏦': Wallet,
        // Asuransi
        '🛡️': Heart,
        // Gaji karyawan
        '👷': Wallet,
        // Keluarga
        '👨‍👩‍👧‍👦': Home, '👶': Heart,
        // Langganan
        '🔄': Smartphone,
        // Hewan
        '🐾': Heart,
        // Otomotif
        '🔧': Car,
        // Usaha
        '🏪': ShoppingBag, '🏘️': Home,
        // Lainnya
        '✅': Wallet,
    };
    return iconMap[iconEmoji] ?? PiggyBank;
}

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown);
    if (parseTimeout) clearTimeout(parseTimeout);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="modal-overlay fixed inset-0 z-[100] flex items-end justify-center bg-black/40 backdrop-blur-sm sm:items-center"
                @click="handleOverlayClick"
            >
                <Transition
                    enter-active-class="transition-all duration-300 ease-out"
                    enter-from-class="translate-y-8 opacity-0 sm:scale-95"
                    enter-to-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-from-class="translate-y-0 opacity-100 sm:scale-100"
                    leave-to-class="translate-y-8 opacity-0 sm:scale-95"
                >
                    <div
                        v-if="show"
                        class="relative w-full max-w-lg rounded-t-3xl sm:rounded-3xl sm:mx-4 flex flex-col font-['Plus_Jakarta_Sans',sans-serif] overflow-hidden bg-[#efeae2] dark:bg-[#0c1317] border border-[#d1c7b7] dark:border-[#222e35] shadow-[0_20px_60px_-15px_rgba(0,0,0,0.4)]"
                        style="max-height: 85vh"
                    >
                        <!-- WhatsApp Pattern Wallpaper Background -->
                        <div 
                            class="pointer-events-none absolute inset-0 z-0 opacity-[0.06] dark:opacity-[0.04]"
                            style="background-image: radial-gradient(#000 1px, transparent 1px), radial-gradient(#000 1px, #efeae2 1px); background-size: 20px 20px; background-position: 0 0, 10px 10px;"
                        ></div>

                        <!-- Header Dialog Khas WhatsApp -->
                        <div class="relative z-10 flex items-center justify-between border-b border-[#e1d9cc] dark:border-[#202c33] px-4 py-3 bg-[#f0f2f5] dark:bg-[#202c33]">
                            <div class="flex items-center gap-3">
                                <div class="relative">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[#25D366] text-white shadow-sm">
                                        <span class="material-symbols-outlined text-[22px]">chat</span>
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-[#202c33] rounded-full"></span>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-[#111b21] dark:text-[#e9edef] flex items-center gap-1.5 leading-tight">
                                        Catat Cepat WhatsApp
                                    </h3>
                                    <p class="text-[11px] text-[#008069] dark:text-[#25d366] font-semibold flex items-center gap-1 mt-0.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#25d366] inline-block animate-pulse"></span>
                                        online • ketik seperti biasa
                                    </p>
                                </div>
                            </div>
                            <button
                                @click="emit('close')"
                                class="rounded-full p-2 text-[#54656f] hover:bg-black/5 hover:text-[#111b21] dark:text-[#aebac1] dark:hover:bg-white/5 dark:hover:text-white transition-colors"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>

                        <!-- Area Percakapan & Bubble Chat WhatsApp Style -->
                        <div class="relative z-10 flex-1 overflow-y-auto px-4 py-4 space-y-3">
                            <div
                                v-for="(msg, i) in chatMessages"
                                :key="i"
                                :class="[
                                    'flex',
                                    msg.role === 'user' ? 'justify-end' : 'justify-start',
                                ]"
                            >
                                <div
                                    :class="[
                                        'max-w-[85%] px-3.5 py-2 text-xs sm:text-sm leading-relaxed shadow-sm rounded-lg',
                                        msg.role === 'user'
                                            ? 'bg-[#d9fdd3] text-[#111b21] dark:bg-[#005c4b] dark:text-[#e9edef] rounded-tr-none font-medium'
                                            : msg.type === 'success'
                                                ? 'bg-white dark:bg-[#202c33] text-[#111b21] dark:text-[#e9edef] border-l-4 border-[#25D366] rounded-tl-none font-medium'
                                                : msg.type === 'error'
                                                    ? 'bg-[#ffebee] border-l-4 border-rose-500 text-rose-900 dark:bg-rose-950/50 dark:text-rose-200 rounded-tl-none'
                                                    : 'bg-white dark:bg-[#202c33] text-[#111b21] dark:text-[#e9edef] rounded-tl-none',
                                    ]"
                                    v-html="msg.content.replace(/\*(.*?)\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>')"
                                />
                            </div>

                            <!-- Card Preview Transaksi Khas Stiker WhatsApp -->
                            <div
                                v-if="parsed?.success"
                                class="rounded-xl border border-[#e1d9cc] dark:border-[#2a3942] bg-white dark:bg-[#202c33] p-3.5 shadow-sm space-y-2.5"
                            >
                                <div class="flex items-center justify-between">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold transition-all',
                                            typeColor(editableType),
                                        ]"
                                    >
                                        <component
                                            :is="editableType === 'income' ? ArrowUpCircle : ArrowDownCircle"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ typeLabel(editableType) }}
                                        <button @click="toggleType" class="ml-1 opacity-70 hover:opacity-100 transition-opacity" title="Klik untuk ubah jenis transaksi">
                                            ⇄
                                        </button>
                                    </span>
                                    <span class="text-[11px] font-bold text-[#008069] dark:text-[#25D366] bg-[#25D366]/10 px-2 py-0.5 rounded-full">
                                        {{ confidencePercent }}% akurat
                                    </span>
                                </div>

                                <div class="text-2xl font-extrabold text-[#111b21] dark:text-white tracking-tight">
                                    {{ formatCurrency(parsed.amount) }}
                                </div>

                                <div class="divide-y divide-[#f0f2f5] dark:divide-[#2a3942] text-xs">
                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-[#54656f] dark:text-[#8696a0]">Keterangan</span>
                                        <span class="font-semibold text-[#111b21] dark:text-[#e9edef]">{{ parsed.description }}</span>
                                    </div>

                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-[#54656f] dark:text-[#8696a0]">Kategori</span>
                                        <span class="font-bold text-[#111b21] dark:text-[#e9edef] flex items-center gap-1.5">
                                            <span class="w-5 h-5 rounded-full bg-[#25D366]/15 flex items-center justify-center text-[12px]">
                                                <component :is="getCategoryIcon(parsed.category_icon)" class="w-3 h-3 text-[#008069] dark:text-[#25D366]" />
                                            </span>
                                            {{ parsed.category_name }}
                                        </span>
                                    </div>

                                    <div class="flex items-center justify-between py-1.5">
                                        <span class="text-[#54656f] dark:text-[#8696a0]">Tanggal</span>
                                        <span class="font-semibold text-[#111b21] dark:text-[#e9edef]">{{ editableDate }}</span>
                                    </div>

                                    <div
                                        v-if="parsed.balances.length > 0"
                                        class="flex items-center justify-between py-1.5"
                                    >
                                        <span class="text-[#54656f] dark:text-[#8696a0]">Dompet</span>
                                        <span class="font-semibold text-[#111b21] dark:text-[#e9edef]">
                                            {{ parsed.balances.find(b => b.id === editableBalanceId)?.name || parsed.balances[0]?.name }}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-if="receiptItems.length > 0"
                                    class="mt-2 border-t border-[#f0f2f5] dark:border-[#2a3942] pt-2"
                                >
                                    <p class="text-[11px] font-bold text-[#54656f] dark:text-[#8696a0] mb-1.5">
                                        🧾 Rincian Item dari Struk
                                    </p>
                                    <div class="space-y-1 max-h-32 overflow-y-auto">
                                        <div
                                            v-for="(item, i) in receiptItems"
                                            :key="i"
                                            class="flex items-center justify-between text-xs"
                                        >
                                            <span class="text-[#111b21] dark:text-[#e9edef] truncate mr-2">{{ item.name }}</span>
                                            <span class="text-[#54656f] dark:text-[#8696a0] font-mono whitespace-nowrap">
                                                {{ formatCurrency(item.price) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="isParsing"
                                class="flex items-center gap-2 text-xs font-semibold text-[#54656f] dark:text-[#8696a0] px-1"
                            >
                                <Loader2 class="h-3.5 w-3.5 animate-spin text-[#008069] dark:text-[#25D366]" />
                                <span>sedang mengetik...</span>
                            </div>

                            <div
                                v-if="parseError && !isParsing"
                                class="rounded-lg bg-rose-50 border border-rose-200 px-3.5 py-2 text-xs font-medium text-rose-800 dark:bg-rose-950/40 dark:text-rose-200"
                            >
                                {{ parseError }}
                            </div>
                        </div>

                        <!-- Bar Input Bawah Khas WhatsApp (Pill Input & Green Send Button) -->
                        <div class="relative z-10 p-2.5 sm:px-4 sm:py-3 bg-[#f0f2f5] dark:bg-[#202c33] border-t border-[#e1d9cc] dark:border-[#202c33]">
                            <div class="flex items-center gap-2">
                                <input
                                    type="file"
                                    ref="fileInputRef"
                                    accept="image/jpeg,image/png,image/webp"
                                    class="hidden"
                                    @change="handleReceiptUpload"
                                />
                                <button
                                    @click="triggerReceiptUpload"
                                    :disabled="isUploadingReceipt || isSaving"
                                    class="flex h-10 w-10 items-center justify-center rounded-full text-[#54656f] hover:bg-black/5 dark:text-[#aebac1] dark:hover:bg-white/5 transition-colors shrink-0"
                                    :title="isPremium ? 'Foto struk belanja' : 'Foto struk belanja (Fitur Premium)'"
                                >
                                    <Loader2 v-if="isUploadingReceipt" class="h-5 w-5 animate-spin" />
                                    <Camera v-else class="h-5 w-5" />
                                </button>
                                <input
                                    ref="inputRef"
                                    v-model="inputText"
                                    type="text"
                                    placeholder="Ketik pesan..."
                                    class="flex-1 rounded-full border-0 bg-white dark:bg-[#2a3942] px-4 py-2.5 text-xs sm:text-sm font-normal text-[#111b21] dark:text-[#e9edef] placeholder:text-[#8696a0] focus:ring-0 focus:outline-none shadow-xs transition-all"
                                    :disabled="isSaving"
                                />
                                <button
                                    @click="parsed?.success ? saveTransaction() : handleSend()"
                                    :disabled="(!inputText.trim() && !parsed?.success) || isSaving || isParsing"
                                    :class="[
                                        'flex h-10 w-10 items-center justify-center rounded-full transition-all active:scale-95 shrink-0 shadow-sm',
                                        canSave || (inputText.trim().length >= 3 && !isParsing)
                                            ? 'bg-[#00a884] hover:bg-[#008f6f] text-white'
                                            : 'bg-[#00a884]/40 text-white/70 cursor-not-allowed',
                                    ]"
                                >
                                    <Loader2 v-if="isSaving" class="h-4 w-4 animate-spin" />
                                    <Check v-else-if="parsed?.success" class="h-5 w-5 stroke-[2.5]" />
                                    <Send v-else class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Overlay Animasi Sukses Menarik & Playful -->
                        <Transition
                            enter-active-class="transition-all duration-400 ease-out"
                            enter-from-class="opacity-0 scale-90"
                            enter-to-class="opacity-100 scale-100"
                            leave-active-class="transition-all duration-300 ease-in"
                            leave-from-class="opacity-100 scale-100"
                            leave-to-class="opacity-0 scale-95"
                        >
                            <div
                                v-if="showSuccessAnim"
                                class="absolute inset-0 z-30 flex flex-col items-center justify-center bg-white/95 dark:bg-[#111b21]/95 backdrop-blur-md p-6 text-center"
                            >
                                <!-- Efek Confetti Floating Rings -->
                                <div class="relative flex items-center justify-center mb-4">
                                    <div class="absolute w-24 h-24 rounded-full bg-[#25D366]/20 animate-ping duration-1000"></div>
                                    <div class="absolute w-20 h-20 rounded-full bg-[#ffd23f]/30 animate-pulse"></div>
                                    
                                    <!-- Lingkaran Checklist Sukses -->
                                    <div class="relative w-16 h-16 rounded-full bg-gradient-to-tr from-[#00a884] to-[#25D366] text-white flex items-center justify-center shadow-lg shadow-[#25D366]/40 transform scale-100 animate-bounce">
                                        <span class="material-symbols-outlined text-3xl font-black">check</span>
                                    </div>
                                </div>

                                <div class="space-y-1 transform transition-all">
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-black bg-[#d9fdd3] text-[#007152] dark:bg-[#005c4b] dark:text-[#51fac1] tracking-wide uppercase">
                                        <span class="material-symbols-outlined text-[14px]">verified</span>
                                        Transaksi Tersimpan!
                                    </span>
                                    <h4 class="text-2xl font-black text-[#111b21] dark:text-white pt-1">
                                        {{ savedTransactionInfo ? formatCurrency(savedTransactionInfo.amount) : '' }}
                                    </h4>
                                    <p class="text-xs text-[#54656f] dark:text-[#8696a0] font-medium max-w-xs truncate">
                                        {{ savedTransactionInfo?.description }}
                                    </p>
                                </div>

                                <!-- Floating Little Coins / Badge -->
                                <div class="mt-4 flex items-center gap-1.5 text-[11px] font-bold text-[#574500] bg-[#ffd23f]/25 px-3 py-1 rounded-full">
                                    <span class="material-symbols-outlined text-sm text-[#ffd23f]">monetization_on</span>
                                    <span>Saldo & riwayat otomatis terupdate!</span>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
