<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
        metadata?: {
            attachment_path?: string | null;
            attachment_name?: string | null;
            attachment_uploaded_at?: string | null;
            [key: string]: any;
        } | null;
        created_at: string;
        updated_at: string;
    };
}

const props = defineProps<Props>();

const isDragActive = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

const uploadForm = useForm({
    attachment: null as File | null,
});

const onDragOver = (e: DragEvent) => {
    e.preventDefault();
    isDragActive.value = true;
};

const onDragLeave = () => {
    isDragActive.value = false;
};

const onDrop = (e: DragEvent) => {
    e.preventDefault();
    isDragActive.value = false;
    const files = e.dataTransfer?.files;
    if (files && files.length > 0) {
        handleFileSelect(files[0]);
    }
};

const triggerFileInput = () => {
    fileInput.value?.click();
};

const onFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    const files = target.files;
    if (files && files.length > 0) {
        handleFileSelect(files[0]);
    }
};

const handleFileSelect = (file: File) => {
    // Validate type and size (5MB max)
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    if (!allowedTypes.includes(file.type)) {
        alert('File harus berupa gambar (JPG, PNG, WebP) atau PDF.');
        return;
    }
    
    if (file.size > 5 * 1024 * 1024) {
        alert('Ukuran file maksimal 5MB.');
        return;
    }
    
    uploadForm.attachment = file;
    submitUpload();
};

const submitUpload = () => {
    uploadForm.post(`/transactions/${props.transaction.id}/upload-attachment`, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
        },
        onError: (errors) => {
            alert(Object.values(errors).flat().join(', ') || 'Gagal mengunggah lampiran.');
        }
    });
};

const deleteAttachment = () => {
    if (confirm('Apakah Anda yakin ingin menghapus lampiran ini?')) {
        router.delete(`/transactions/${props.transaction.id}/delete-attachment`, {
            preserveState: true,
            preserveScroll: true,
        });
    }
};

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

const showImageModal = ref(false);
const selectedImageUrl = ref('');

const openImageModal = (url: string) => {
    selectedImageUrl.value = url;
    showImageModal.value = true;
};

// Get receipt image URL or attachment URL
const receiptImageUrl = (() => {
    const filePath = props.transaction.message?.ocr_job?.file_path;
    if (filePath) {
        return '/files?path=' + encodeURIComponent(filePath);
    }
    
    const attachmentPath = props.transaction.metadata?.attachment_path;
    if (attachmentPath && !attachmentPath.toLowerCase().endsWith('.pdf')) {
        return '/files?path=' + encodeURIComponent(attachmentPath);
    }
    
    return null;
})();

const attachmentPdfUrl = (() => {
    const attachmentPath = props.transaction.metadata?.attachment_path;
    if (attachmentPath && attachmentPath.toLowerCase().endsWith('.pdf')) {
        return '/files?path=' + encodeURIComponent(attachmentPath);
    }
    return null;
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
            </div>

            <!-- Status Badge -->
            <div class="flex items-center gap-4">
                <span
                    class="rounded-full px-3 py-1 text-sm font-medium"
                    :class="{
                        'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400': transaction.status === 'confirmed',
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

                <!-- Status & Waktu Info -->
                <div class="rounded-[13px] border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h3 class="mb-4 text-lg font-semibold">Informasi Status</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-medium text-muted-foreground">Status</label>
                            <p class="mt-1 text-base font-medium capitalize">{{ transaction.status }}</p>
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

            <!-- Lampiran Transaksi (Nota / Struk) -->
            <div class="rounded-[13px] border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                        </svg>
                        Lampiran Nota / Struk
                    </h3>
                    <button
                        v-if="receiptImageUrl || attachmentPdfUrl"
                        @click="deleteAttachment"
                        class="text-xs text-red-600 dark:text-red-400 hover:underline flex items-center gap-1"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Hapus Lampiran
                    </button>
                </div>

                <!-- Preview State (Image) -->
                <div v-if="receiptImageUrl" class="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-4 border border-gray-100 dark:border-gray-800">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs text-muted-foreground">Klik gambar untuk memperbesar struk belanja.</p>
                            <button
                                @click="openImageModal(receiptImageUrl)"
                                class="group relative overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 hover:border-emerald-500 dark:hover:border-emerald-500 transition-all cursor-pointer shadow-sm bg-white dark:bg-gray-850"
                            >
                                <img
                                    :src="receiptImageUrl"
                                    alt="Struk Belanja"
                                    class="h-40 w-auto max-w-[200px] object-cover rounded-lg"
                                />
                                <div class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/35 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                    </svg>
                                </div>
                            </button>
                            <p v-if="transaction.metadata?.attachment_name" class="text-xs text-gray-500 mt-1 flex items-center gap-1 font-mono">
                                📂 {{ transaction.metadata.attachment_name }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-2">
                            <button
                                @click="triggerFileInput"
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200/50 dark:border-gray-700/30 bg-white dark:bg-gray-800 px-3.5 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-all"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                Ganti Lampiran
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Preview State (PDF) -->
                <div v-else-if="attachmentPdfUrl" class="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-4 border border-gray-100 dark:border-gray-800">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="p-3 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-xl">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Lampiran PDF Dokumen</h4>
                                <p class="text-xs text-gray-500 font-mono mt-0.5 truncate max-w-[250px] sm:max-w-xs">
                                    {{ transaction.metadata?.attachment_name || 'lampiran.pdf' }}
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <a
                                :href="attachmentPdfUrl"
                                target="_blank"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white shadow-md hover:bg-emerald-700 transition-all"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                Buka Dokumen
                            </a>
                            <button
                                @click="triggerFileInput"
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200/50 dark:border-gray-700/30 bg-white dark:bg-gray-800 px-3.5 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-all"
                            >
                                Ganti Lampiran
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty State / Upload Area -->
                <div
                    v-else
                    @dragover="onDragOver"
                    @dragleave="onDragLeave"
                    @drop="onDrop"
                    :class="[
                        'border-2 border-dashed rounded-xl p-8 text-center transition-all cursor-pointer flex flex-col items-center justify-center gap-3',
                        isDragActive 
                            ? 'border-emerald-500 bg-emerald-50/30 dark:bg-emerald-950/10' 
                            : 'border-gray-300 dark:border-gray-700 hover:border-emerald-500 dark:hover:border-emerald-500/50 bg-gray-50/50 dark:bg-gray-900/10'
                    ]"
                    @click="triggerFileInput"
                >
                    <div class="p-3 bg-emerald-100/60 dark:bg-emerald-950/30 text-emerald-600 dark:text-emerald-400 rounded-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            Tarik & lepaskan file Anda di sini, atau <span class="text-emerald-600 dark:text-emerald-450 underline font-medium">pilih file</span>
                        </p>
                        <p class="text-xs text-gray-500 mt-1">
                            Mendukung JPG, PNG, WebP, atau PDF (Maks. 5MB)
                        </p>
                    </div>
                </div>

                <!-- Hidden File Input -->
                <input
                    type="file"
                    ref="fileInput"
                    class="hidden"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    @change="onFileChange"
                />

                <!-- Uploading State Indicator -->
                <div v-if="uploadForm.processing" class="mt-3 flex items-center justify-center gap-2 text-xs text-emerald-600 dark:text-emerald-400">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Mengunggah berkas lampiran...</span>
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

