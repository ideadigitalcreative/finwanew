<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Swal from 'sweetalert2';

interface WhatsAppNumber {
    id: number;
    phone_number: string;
    mapping_name: string;
    user_name: string;
    user_email: string;
    tenant_name: string;
    is_primary: boolean;
    created_at: string;
}

interface SessionStatus {
    session_id: string;
    is_connected: boolean;
    status: string;
}

interface Template {
    id: string;
    name: string;
    message: string;
}

const props = defineProps<{
    whatsappNumbers: WhatsAppNumber[];
    sessionStatus: SessionStatus | null;
    stats: {
        total_numbers: number;
        primary_numbers: number;
    };
}>();

const message = ref('');
const selectedNumbers = ref<string[]>([]);
const selectAll = ref(false);
const isSending = ref(false);
const sendResults = ref<{ success: string[]; failed: { number: string; error: string }[] } | null>(null);
const templates = ref<Template[]>([]);
const showTemplates = ref(false);
const searchQuery = ref('');

// Single message mode
const singleMode = ref(false);
const singleNumber = ref('');

// Filtered numbers based on search
const filteredNumbers = computed(() => {
    if (!searchQuery.value) return props.whatsappNumbers;
    const query = searchQuery.value.toLowerCase();
    return props.whatsappNumbers.filter(n => 
        n.phone_number.includes(query) ||
        n.user_name.toLowerCase().includes(query) ||
        n.user_email.toLowerCase().includes(query) ||
        n.tenant_name.toLowerCase().includes(query)
    );
});

// Toggle select all
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedNumbers.value = filteredNumbers.value.map(n => n.phone_number);
    } else {
        selectedNumbers.value = [];
    }
};

// Load templates
const loadTemplates = async () => {
    try {
        const response = await fetch('/superadmin/broadcast/templates');
        const data = await response.json();
        if (data.success) {
            templates.value = data.templates;
            showTemplates.value = true;
        }
    } catch (error) {
        console.error('Failed to load templates:', error);
    }
};

// Apply template
const applyTemplate = (template: Template) => {
    message.value = template.message;
    showTemplates.value = false;
};

// Send broadcast
const sendBroadcast = async () => {
    if (!message.value.trim()) {
        Swal.fire({
            icon: 'warning',
            title: 'Pesan Kosong',
            text: 'Pesan tidak boleh kosong!',
        });
        return;
    }

    if (singleMode.value) {
        await sendSingleMessage();
        return;
    }

    if (selectedNumbers.value.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Pilih Penerima',
            text: 'Pilih minimal satu nomor penerima!',
        });
        return;
    }

    const result = await Swal.fire({
        icon: 'question',
        title: 'Kirim Broadcast?',
        text: `Kirim pesan ke ${selectedNumbers.value.length} nomor?`,
        showCancelButton: true,
        confirmButtonText: 'Ya, Kirim!',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#3b82f6',
    });

    if (!result.isConfirmed) return;

    isSending.value = true;
    sendResults.value = null;

    try {
        const response = await fetch('/superadmin/broadcast/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                message: message.value,
                recipients: selectedNumbers.value,
            }),
        });

        const data = await response.json();
        
        if (data.success) {
            sendResults.value = data.results;
            Swal.fire({
                icon: 'success',
                title: 'Broadcast Berhasil!',
                html: `<div class="text-left">
                    <p>✅ Berhasil: <strong>${data.results.success.length}</strong> nomor</p>
                    ${data.results.failed.length > 0 ? `<p>❌ Gagal: <strong>${data.results.failed.length}</strong> nomor</p>` : ''}
                </div>`,
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Gagal!',
                text: data.error || 'Gagal mengirim broadcast',
            });
        }
    } catch (error) {
        console.error('Broadcast error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Terjadi kesalahan saat mengirim broadcast',
        });
    } finally {
        isSending.value = false;
    }
};

// Send single message
const sendSingleMessage = async () => {
    if (!singleNumber.value.trim()) {
        Swal.fire({
            icon: 'warning',
            title: 'Nomor Kosong',
            text: 'Masukkan nomor WhatsApp!',
        });
        return;
    }

    isSending.value = true;

    try {
        const response = await fetch('/superadmin/broadcast/send-single', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                message: message.value,
                phone_number: singleNumber.value,
            }),
        });

        const data = await response.json();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: data.message,
            });
            singleNumber.value = '';
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Gagal!',
                text: data.error || 'Gagal mengirim pesan',
            });
        }
    } catch (error) {
        console.error('Send error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Terjadi kesalahan saat mengirim pesan',
        });
    } finally {
        isSending.value = false;
    }
};

// Format phone number display
const formatPhone = (phone: string) => {
    if (phone.startsWith('62')) {
        return '+' + phone;
    }
    return phone;
};
</script>

<template>
    <Head title="Broadcast Messages" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">📢 Broadcast Messages</h1>
                    <p class="text-muted-foreground mt-1">Kirim pesan ke semua pengguna atau satu per satu</p>
                </div>
                <span class="text-sm text-muted-foreground">
                    {{ stats.total_numbers }} nomor terdaftar
                </span>
            </div>

            <div>
                <!-- Session Status Alert -->
                <div v-if="!sessionStatus?.is_connected" class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">⚠️</span>
                        <div>
                            <h3 class="font-semibold text-red-800 dark:text-red-200">WhatsApp Tidak Terkoneksi</h3>
                            <p class="text-sm text-red-600 dark:text-red-300">
                                Session WhatsApp tidak aktif. Silakan setup di menu WhatsApp terlebih dahulu.
                            </p>
                        </div>
                    </div>
                </div>

                <div v-else class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">✅</span>
                        <div>
                            <h3 class="font-semibold text-green-800 dark:text-green-200">WhatsApp Terkoneksi</h3>
                            <p class="text-sm text-green-600 dark:text-green-300">
                                Siap mengirim pesan broadcast ke semua pengguna
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Left: Compose Message -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
                            ✍️ Tulis Pesan
                        </h3>

                        <!-- Mode Toggle -->
                        <div class="flex gap-2 mb-4">
                            <button
                                @click="singleMode = false"
                                :class="[
                                    'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                                    !singleMode 
                                        ? 'bg-blue-600 text-white' 
                                        : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'
                                ]"
                            >
                                📢 Broadcast
                            </button>
                            <button
                                @click="singleMode = true"
                                :class="[
                                    'px-4 py-2 rounded-lg text-sm font-medium transition-colors',
                                    singleMode 
                                        ? 'bg-blue-600 text-white' 
                                        : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'
                                ]"
                            >
                                👤 Kirim ke Satu Nomor
                            </button>
                        </div>

                        <!-- Single Number Input -->
                        <div v-if="singleMode" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Nomor WhatsApp
                            </label>
                            <input
                                v-model="singleNumber"
                                type="text"
                                placeholder="contoh: 6281234567890"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>

                        <!-- Template Button -->
                        <div class="mb-4">
                            <button
                                @click="loadTemplates"
                                class="text-blue-600 hover:text-blue-700 dark:text-blue-400 text-sm font-medium flex items-center gap-1"
                            >
                                📝 Gunakan Template
                            </button>
                        </div>

                        <!-- Templates Modal -->
                        <div v-if="showTemplates" class="mb-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <div class="flex justify-between items-center mb-3">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Pilih Template</span>
                                <button @click="showTemplates = false" class="text-gray-500 hover:text-gray-700">✕</button>
                            </div>
                            <div class="space-y-2">
                                <button
                                    v-for="template in templates"
                                    :key="template.id"
                                    @click="applyTemplate(template)"
                                    class="w-full text-left px-3 py-2 rounded-lg bg-white dark:bg-gray-600 hover:bg-blue-50 dark:hover:bg-gray-500 border border-gray-200 dark:border-gray-500 transition-colors"
                                >
                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ template.name }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Message Textarea -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Pesan
                            </label>
                            <textarea
                                v-model="message"
                                rows="10"
                                placeholder="Tulis pesan broadcast disini...&#10;&#10;Gunakan *bold* untuk teks tebal&#10;Gunakan _italic_ untuk teks miring"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                            ></textarea>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ message.length }} / 4000 karakter
                            </p>
                        </div>

                        <!-- Send Button -->
                        <button
                            @click="sendBroadcast"
                            :disabled="isSending || !sessionStatus?.is_connected"
                            :class="[
                                'w-full py-3 rounded-lg font-semibold text-white transition-all',
                                isSending || !sessionStatus?.is_connected
                                    ? 'bg-gray-400 cursor-not-allowed'
                                    : 'bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 shadow-lg hover:shadow-xl'
                            ]"
                        >
                            <span v-if="isSending" class="flex items-center justify-center gap-2">
                                <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                Mengirim...
                            </span>
                            <span v-else>
                                {{ singleMode ? '📤 Kirim Pesan' : `📢 Kirim ke ${selectedNumbers.length} Nomor` }}
                            </span>
                        </button>

                        <!-- Results -->
                        <div v-if="sendResults" class="mt-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <h4 class="font-medium text-gray-800 dark:text-gray-200 mb-2">Hasil Broadcast</h4>
                            <p class="text-sm text-green-600 dark:text-green-400">
                                ✅ Berhasil: {{ sendResults.success.length }} nomor
                            </p>
                            <p v-if="sendResults.failed.length > 0" class="text-sm text-red-600 dark:text-red-400">
                                ❌ Gagal: {{ sendResults.failed.length }} nomor
                            </p>
                            <div v-if="sendResults.failed.length > 0" class="mt-2 text-xs text-gray-500">
                                <p v-for="fail in sendResults.failed" :key="fail.number">
                                    {{ fail.number }}: {{ fail.error }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Recipients List (for broadcast mode) -->
                    <div v-if="!singleMode" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
                            👥 Pilih Penerima
                        </h3>

                        <!-- Search -->
                        <div class="mb-4">
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="🔍 Cari nomor, nama, atau email..."
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>

                        <!-- Select All -->
                        <div class="flex items-center justify-between mb-4 pb-4 border-b border-gray-200 dark:border-gray-600">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="selectAll"
                                    @change="toggleSelectAll"
                                    class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
                                />
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Pilih Semua ({{ filteredNumbers.length }})
                                </span>
                            </label>
                            <span class="text-sm text-blue-600 font-medium">
                                {{ selectedNumbers.length }} dipilih
                            </span>
                        </div>

                        <!-- Numbers List -->
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            <label
                                v-for="number in filteredNumbers"
                                :key="number.id"
                                class="flex items-start gap-3 p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors"
                            >
                                <input
                                    type="checkbox"
                                    :value="number.phone_number"
                                    v-model="selectedNumbers"
                                    class="mt-1 w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
                                />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-800 dark:text-gray-200">
                                            {{ formatPhone(number.phone_number) }}
                                        </span>
                                        <span v-if="number.is_primary" class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">
                                            Primary
                                        </span>
                                        <span v-else-if="number.phone_number.length > 15" class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">
                                            WhatsApp ID
                                        </span>
                                        <span v-else class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                                            Secondary
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 truncate">
                                        <span v-if="number.mapping_name && number.mapping_name !== number.user_name" class="font-medium text-gray-700 dark:text-gray-300 mr-1">
                                            {{ number.mapping_name }} <span class="text-gray-400 font-normal">•</span>
                                        </span>
                                        {{ number.user_name }} <span class="text-gray-400 mx-1">•</span> {{ number.user_email }}
                                    </p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ number.tenant_name }}
                                    </p>
                                </div>
                            </label>

                            <p v-if="filteredNumbers.length === 0" class="text-center text-gray-500 dark:text-gray-400 py-8">
                                Tidak ada nomor ditemukan
                            </p>
                        </div>
                    </div>

                    <!-- Right: Info for single mode -->
                    <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">
                            💡 Tips
                        </h3>
                        <div class="space-y-4 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex gap-3">
                                <span class="text-xl">📱</span>
                                <p>Masukkan nomor dengan format internasional (62xxx) tanpa tanda + atau spasi.</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="text-xl">✍️</span>
                                <p>Gunakan *asterisk* untuk teks tebal dan _underscore_ untuk miring.</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="text-xl">📝</span>
                                <p>Klik "Gunakan Template" untuk memilih template pesan yang sudah disiapkan.</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="text-xl">⏱️</span>
                                <p>Broadcast ke banyak nomor akan dikirim dengan jeda 500ms antar pesan.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
