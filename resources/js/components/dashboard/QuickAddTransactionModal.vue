<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    X, Send, ArrowUpCircle, ArrowDownCircle, Check, Loader2, Pencil, ChevronDown, Calendar, Wallet, Camera
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
const receiptItems = ref<Array<{ name: string; price: number }>>([]);
const receiptMerchant = ref<string | null>(null);
let parseTimeout: ReturnType<typeof setTimeout> | null = null;

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
        const csrfToken = getCsrfToken();

        const response = await fetch('/transactions/parse', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ text }),
        });

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
        const csrfToken = getCsrfToken();
        const formData = new FormData();
        formData.append('image', file);

        const response = await fetch('/transactions/parse-receipt', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: formData,
        });

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
        const csrfToken = getCsrfToken();

        const response = await fetch('/transactions/store-json', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (response.ok && data.transaction) {
            showSuccessAnim.value = true;

            chatMessages.value.push(
                { role: 'user', type: 'text', content: inputText.value },
                { role: 'system', type: 'success', content: `✅ ${typeLabel(data.transaction.type)} ${formatCurrency(data.transaction.amount)} tersimpan!` },
            );

            inputText.value = '';
            parsed.value = null;

            setTimeout(() => {
                emit('saved');
                router.reload({ only: ['balanceData', 'recentTransactions', 'chartData', 'insights'] });
            }, 800);
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
                        class="relative w-full max-w-lg rounded-t-2xl bg-white shadow-2xl dark:bg-neutral-900 sm:rounded-2xl sm:mx-4 flex flex-col"
                        style="max-height: 85vh"
                    >
                        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-neutral-800">
                            <div class="flex items-center gap-2">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/40">
                                    <Pencil class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tambah Transaksi</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Ketik seperti di WhatsApp</p>
                                </div>
                            </div>
                            <button
                                @click="emit('close')"
                                class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-neutral-800 dark:hover:text-gray-300 transition-colors"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>

                        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
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
                                        'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm',
                                        msg.role === 'user'
                                            ? 'bg-emerald-600 text-white rounded-br-md'
                                            : msg.type === 'success'
                                                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200 rounded-bl-md'
                                                : msg.type === 'error'
                                                    ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200 rounded-bl-md'
                                                    : 'bg-gray-100 text-gray-700 dark:bg-neutral-800 dark:text-gray-300 rounded-bl-md',
                                    ]"
                                    v-html="msg.content.replace(/\*(.*?)\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>')"
                                />
                            </div>

                            <div
                                v-if="parsed?.success"
                                class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/50"
                            >
                                <div class="flex items-center justify-between mb-3">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                                            typeColor(editableType),
                                        ]"
                                    >
                                        <component
                                            :is="editableType === 'income' ? ArrowUpCircle : ArrowDownCircle"
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ typeLabel(editableType) }}
                                        <button @click="toggleType" class="ml-1 opacity-60 hover:opacity-100 transition-opacity">
                                            <ChevronDown class="h-3 w-3" />
                                        </button>
                                    </span>
                                    <span :class="['text-xs font-medium', confidenceColor]">
                                        {{ confidencePercent }}% yakin
                                    </span>
                                </div>

                                <div class="text-2xl font-bold text-gray-900 dark:text-white mb-2">
                                    {{ formatCurrency(parsed.amount) }}
                                </div>

                                <div class="space-y-2 text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500 dark:text-gray-400">Deskripsi</span>
                                        <span class="font-medium text-gray-900 dark:text-white">{{ parsed.description }}</span>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500 dark:text-gray-400">Kategori</span>
                                        <select
                                            v-if="parsed.alternatives.length > 0"
                                            v-model="editableCategoryId"
                                            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-900 dark:border-neutral-600 dark:bg-neutral-700 dark:text-white"
                                        >
                                            <option :value="parsed.category_id">
                                                {{ parsed.category_icon }} {{ parsed.category_name }}
                                            </option>
                                            <option
                                                v-for="alt in parsed.alternatives"
                                                :key="alt.id"
                                                :value="alt.id"
                                            >
                                                {{ alt.icon }} {{ alt.name }}
                                            </option>
                                        </select>
                                        <span v-else class="font-medium text-gray-900 dark:text-white">
                                            {{ parsed.category_icon }} {{ parsed.category_name }}
                                        </span>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                            <Calendar class="h-3.5 w-3.5" /> Tanggal
                                        </span>
                                        <input
                                            type="date"
                                            v-model="editableDate"
                                            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-900 dark:border-neutral-600 dark:bg-neutral-700 dark:text-white"
                                        />
                                    </div>

                                    <div
                                        v-if="parsed.balances.length > 0"
                                        class="flex items-center justify-between"
                                    >
                                        <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                            <Wallet class="h-3.5 w-3.5" /> Dompet
                                        </span>
                                        <select
                                            v-model="editableBalanceId"
                                            class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-900 dark:border-neutral-600 dark:bg-neutral-700 dark:text-white"
                                        >
                                            <option
                                                v-for="bal in parsed.balances"
                                                :key="bal.id"
                                                :value="bal.id"
                                            >
                                                {{ bal.name }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div
                                    v-if="receiptItems.length > 0"
                                    class="mt-3 border-t border-gray-200 dark:border-neutral-700 pt-3"
                                >
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">
                                        🧾 Item dari struk
                                    </p>
                                    <div class="space-y-1 max-h-32 overflow-y-auto">
                                        <div
                                            v-for="(item, i) in receiptItems"
                                            :key="i"
                                            class="flex items-center justify-between text-xs"
                                        >
                                            <span class="text-gray-700 dark:text-gray-300 truncate mr-2">{{ item.name }}</span>
                                            <span class="text-gray-500 dark:text-gray-400 font-mono whitespace-nowrap">
                                                {{ formatCurrency(item.price) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="isParsing"
                                class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 px-1"
                            >
                                <Loader2 class="h-4 w-4 animate-spin" />
                                <span>Menganalisis...</span>
                            </div>

                            <div
                                v-if="parseError && !isParsing"
                                class="rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-300"
                            >
                                {{ parseError }}
                            </div>
                        </div>

                        <div class="border-t border-gray-100 px-4 py-3 dark:border-neutral-800">
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
                                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-600 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-400 dark:hover:bg-amber-900/60 transition-all"
                                    title="Foto struk belanja"
                                >
                                    <Loader2 v-if="isUploadingReceipt" class="h-5 w-5 animate-spin" />
                                    <Camera v-else class="h-5 w-5" />
                                </button>
                                <input
                                    ref="inputRef"
                                    v-model="inputText"
                                    type="text"
                                    placeholder="Contoh: makan siang 25rb"
                                    class="flex-1 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:placeholder-gray-500 transition-all"
                                    :disabled="isSaving"
                                />
                                <button
                                    @click="parsed?.success ? saveTransaction() : handleSend()"
                                    :disabled="(!inputText.trim() && !parsed?.success) || isSaving || isParsing"
                                    :class="[
                                        'flex h-10 w-10 items-center justify-center rounded-xl transition-all',
                                        canSave || (inputText.trim().length >= 3 && !isParsing)
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-lg shadow-emerald-500/25'
                                            : 'bg-gray-100 text-gray-400 dark:bg-neutral-800 dark:text-gray-600',
                                    ]"
                                >
                                    <Loader2 v-if="isSaving" class="h-5 w-5 animate-spin" />
                                    <Check v-else-if="parsed?.success" class="h-5 w-5" />
                                    <Send v-else class="h-5 w-5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
