<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { ref } from 'vue';

interface Props {
    transaction: {
        id: number;
        type: string;
        amount: number;
        transaction_date: string;
        description: string;
        source: string | null;
        reference_number: string | null;
        status: string;
        confidence_score: number;
        category: {
            id: number;
            name: string;
            type: string;
        };
        message: {
            id: number;
            content: string;
            type: string;
            channel: string;
            ocr_job?: {
                id: number;
                file_path: string | null;
                status: string;
            } | null;
        } | null;
        reviewer: {
            id: number;
            name: string;
            email: string;
        } | null;
        reviewed_at: string | null;
        created_at: string;
        updated_at: string;
    };
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount);
};

const formatDate = (date: string) => {
    return format(new Date(date), 'dd MMMM yyyy', { locale: id });
};

const formatDateTime = (date: string) => {
    return format(new Date(date), 'dd MMMM yyyy HH:mm', { locale: id });
};

const updateStatus = (status: string) => {
    router.patch(`/transactions/${props.transaction.id}/status`, {
        status,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const showImageModal = ref(false);
const selectedImageUrl = ref('');

const openImageModal = (url: string) => {
    selectedImageUrl.value = url;
    showImageModal.value = true;
};

// Get receipt image URL from OcrJob file_path
const receiptImageUrl = (() => {
    const filePath = props.transaction.message?.ocr_job?.file_path;
    if (!filePath) return null;
    // Build URL via authenticated /files endpoint
    return '/files?path=' + encodeURIComponent(filePath);
})();

const parsedMessage = (text: string) => {
    if (!text) return [];
    
    // Match http/https URLs, /api/files paths, /files? paths, and raw whatsapp/ file paths
    const urlRegex = /(?:https?:\/\/[^\s]+|\/api\/files[^\s]+|\/files\?[^\s]+|whatsapp\/[a-zA-Z0-9_\-\/\.]+)/g;
    const parts = [];
    let lastIndex = 0;
    
    let match;
    while ((match = urlRegex.exec(text)) !== null) {
        if (match.index > lastIndex) {
            parts.push({
                type: 'text',
                content: text.substring(lastIndex, match.index)
            });
        }
        
        let url = match[0];
        // Clean trailing punctuations
        url = url.replace(/[)"'\]]+$/, '');
        
        let href = url;
        if (href.includes('/api/files')) {
            href = href.replace('/api/files', '/files');
        } else if (href.startsWith('whatsapp/')) {
            href = '/files?path=' + encodeURIComponent(href);
        }
        
        parts.push({
            type: 'image_link',
            url: url,
            href: href
        });
        
        lastIndex = match.index + url.length;
    }
    
    if (lastIndex < text.length) {
        parts.push({
            type: 'text',
            content: text.substring(lastIndex)
        });
    }
    
    return parts;
};
</script>

<template>
    <Head :title="`Transaksi #${transaction.id}`" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-[13px] p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <Link
                        href="/transactions"
                        class="mb-2 text-sm text-muted-foreground hover:underline"
                    >
                        ← Kembali ke Daftar Transaksi
                    </Link>
                    <h2 class="text-2xl font-bold">Detail Transaksi</h2>
                    <p class="text-sm text-muted-foreground">ID: #{{ transaction.id }}</p>
                </div>
                <div v-if="transaction.status === 'review'" class="flex gap-2">
                    <button
                        @click="updateStatus('rejected')"
                        class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 dark:border-red-700 dark:bg-red-900/20 dark:text-red-400"
                    >
                        Tolak
                    </button>
                    <button
                        @click="updateStatus('confirmed')"
                        class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
                    >
                        Setujui
                    </button>
                </div>
            </div>

            <!-- Status Badge -->
            <div class="flex items-center gap-4">
                <span
                    class="rounded-full px-3 py-1 text-sm font-medium"
                    :class="{
                        'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400': transaction.status === 'confirmed',
                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400': transaction.status === 'review',
                        'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400': transaction.status === 'rejected',
                    }"
                >
                    {{ transaction.status }}
                </span>
                <span class="text-sm text-muted-foreground">
                    Confidence: {{ (transaction.confidence_score * 100).toFixed(1) }}%
                </span>
            </div>

            <!-- Main Info Cards -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Transaction Info -->
                <div class="rounded-[13px] border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h3 class="mb-4 text-lg font-semibold">Informasi Transaksi</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Tanggal</label>
                            <p class="mt-1 text-base font-medium">{{ formatDate(transaction.transaction_date) }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Tipe</label>
                            <p class="mt-1 text-base font-medium capitalize">
                                <span :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'">
                                    {{ transaction.type === 'income' ? 'Pendapatan' : 'Pengeluaran' }}
                                </span>
                            </p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Jumlah</label>
                            <p
                                class="mt-1 text-2xl font-bold"
                                :class="transaction.type === 'income' ? 'text-green-600' : 'text-red-600'"
                            >
                                {{ transaction.type === 'income' ? '+' : '-' }}{{ formatCurrency(transaction.amount) }}
                            </p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Kategori</label>
                            <p class="mt-1 text-base font-medium">{{ transaction.category.name }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Deskripsi</label>
                            <p class="mt-1 text-base">{{ transaction.description }}</p>
                        </div>
                        <div v-if="transaction.source">
                            <label class="text-sm font-medium text-muted-foreground">Sumber/Tujuan</label>
                            <p class="mt-1 text-base">{{ transaction.source }}</p>
                        </div>
                        <div v-if="transaction.reference_number">
                            <label class="text-sm font-medium text-muted-foreground">No. Referensi</label>
                            <p class="mt-1 text-base font-mono">{{ transaction.reference_number }}</p>
                        </div>
                    </div>
                </div>

                <!-- Review Info -->
                <div class="rounded-[13px] border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h3 class="mb-4 text-lg font-semibold">Informasi Review</h3>
                    <div class="space-y-4">
                        <div v-if="transaction.reviewer">
                            <label class="text-sm font-medium text-muted-foreground">Ditinjau Oleh</label>
                            <p class="mt-1 text-base">{{ transaction.reviewer.name }}</p>
                            <p class="text-sm text-muted-foreground">{{ transaction.reviewer.email }}</p>
                        </div>
                        <div v-if="transaction.reviewed_at">
                            <label class="text-sm font-medium text-muted-foreground">Tanggal Review</label>
                            <p class="mt-1 text-base">{{ formatDateTime(transaction.reviewed_at) }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Dibuat</label>
                            <p class="mt-1 text-base">{{ formatDateTime(transaction.created_at) }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Diperbarui</label>
                            <p class="mt-1 text-base">{{ formatDateTime(transaction.updated_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Receipt Image from OCR -->
            <div v-if="receiptImageUrl" class="rounded-[13px] border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <h3 class="mb-4 text-lg font-semibold">Gambar Struk</h3>
                <div class="rounded-lg bg-muted p-4">
                    <div class="flex flex-col gap-3">
                        <p class="text-sm text-muted-foreground">Klik gambar untuk memperbesar</p>
                        <button
                            @click="openImageModal(receiptImageUrl)"
                            class="group relative overflow-hidden rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 hover:border-blue-400 dark:hover:border-blue-500 transition-colors cursor-pointer"
                        >
                            <img
                                :src="receiptImageUrl"
                                alt="Struk Belanja"
                                class="h-48 w-auto max-w-[200px] object-cover rounded-lg"
                                @error="($event.target as HTMLImageElement).style.display = 'none'"
                            />
                            <div class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/30 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                </svg>
                            </div>
                        </button>
                        <p class="text-sm text-muted-foreground">Klik gambar untuk memperbesar</p>
                    </div>
                </div>
            </div>

            <!-- Source Message -->
            <div v-if="transaction.message" class="rounded-[13px] border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <h3 class="mb-4 text-lg font-semibold">Pesan Asli</h3>
                <div class="rounded-lg bg-muted p-4">
                    <div class="mb-2 flex items-center gap-2 text-sm text-muted-foreground">
                        <span class="capitalize">{{ transaction.message.channel }}</span>
                        <span>•</span>
                        <span class="capitalize">{{ transaction.message.type }}</span>
                    </div>
                    <div class="whitespace-pre-wrap">
                        <template v-for="(part, index) in parsedMessage(transaction.message?.content || '')" :key="index">
                            <span v-if="part.type === 'text'" class="break-words">{{ part.content }}</span>
                            <button
                                v-else-if="part.type === 'image_link'"
                                @click="openImageModal(part.href || '')"
                                class="inline-flex items-center gap-1.5 rounded-md bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50 mt-1 mb-1 transition-colors border border-blue-200 dark:border-blue-800"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                Lihat Gambar
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Image Modal Popup -->
        <div
            v-if="showImageModal"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm p-4"
            @click.self="showImageModal = false"
        >
            <div class="relative w-full max-w-4xl max-h-[90vh] flex flex-col bg-transparent items-center">
                <div class="absolute -top-12 right-0 flex gap-4 bg-black/50 p-2 rounded-lg">
                    <a
                        :href="selectedImageUrl"
                        target="_blank"
                        class="text-white hover:text-gray-300 transition-colors"
                        title="Buka di tab baru"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </a>
                    <button
                        @click="showImageModal = false"
                        class="text-white hover:text-gray-300 transition-colors"
                        title="Tutup"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <!-- Prevent image drag & nice shadow -->
                <img
                    :src="selectedImageUrl"
                    alt="Struk Transaksi"
                    class="w-auto h-auto max-w-full max-h-[85vh] object-contain rounded-lg shadow-2xl bg-white/5"
                    @click.stop
                />
            </div>
        </div>
    </AppLayout>
</template>

