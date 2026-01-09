<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { MessageSquare, QrCode, RefreshCw, Trash2, Plus, CheckCircle2, XCircle, Clock, AlertCircle, Building2 } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';
import superadminRoutes from '@/routes/superadmin/index';

const superadmin = {
    whatsapp: {
        index: () => '/superadmin/whatsapp',
        store: () => '/superadmin/whatsapp',
        qr: (channelId: number) => `/superadmin/whatsapp/${channelId}/qr`,
        status: (channelId: number) => `/superadmin/whatsapp/${channelId}/status`,
        reconnect: (channelId: number) => `/superadmin/whatsapp/${channelId}/reconnect`,
        destroy: (channelId: number) => `/superadmin/whatsapp/${channelId}`,
        deleteAllSessions: () => '/superadmin/whatsapp/sessions',
    },
};

interface Message {
    id: number;
    content: string;
    type: string;
    sender_id: string;
    status: string;
    has_transaction: boolean;
    created_at: string;
    created_at_human: string;
}

interface Tenant {
    id: number;
    name: string;
}

interface Channel {
    id: number;
    name: string;
    channel_account: string;
    is_active: boolean;
    is_shared_channel?: boolean;
    session_id: string | null;
    session_status: string | null;
    last_activity_at: string | null;
    messages_count: number;
    recent_messages: Message[];
    created_at: string;
    tenant: Tenant | null;
}

interface Props {
    channels: Channel[];
    engineStatus: {
        success: boolean;
        status: string;
        error?: string;
    };
    newChannelId?: number;
    tenants: Tenant[];
    selectedTenantId?: number | null;
}

const props = defineProps<Props>();
const { showError, showWarning, showInfo, showSuccess, showConfirm, showDeleteConfirm } = useSweetAlert();

// Watch for flash messages from server
const page = usePage();

watch(() => page.props.flash, (flash: any) => {
    if (flash && typeof flash === 'object') {
        if (flash.error) {
            showError('Error', flash.error);
        }
        if (flash.success) {
            showSuccess('Berhasil', flash.success);
        }
    }
}, { deep: true, immediate: true });

// Watch for newChannelId changes to auto-load QR code
watch(() => props.newChannelId, (newChannelId) => {
    if (newChannelId) {
        setTimeout(() => {
            const newChannel = props.channels.find((ch: Channel) => ch.id === newChannelId);
            
            if (newChannel) {
                loadingChannels.value.add(newChannel.id);
                
                if (newChannel.session_id) {
                    setTimeout(async () => {
                        await autoLoadQrCode(newChannel);
                        loadingChannels.value.delete(newChannel.id);
                    }, 2000);
                } else {
                    waitForSessionId(newChannel);
                }
            }
        }, 800);
    }
}, { immediate: true });

const showCreateDialog = ref(false);
const showQrDialog = ref(false);
const selectedChannel = ref<Channel | null>(null);
const qrCodeUrl = ref<string | null>(null);
const statusInterval = ref<number | null>(null);
const qrCheckInterval = ref<number | null>(null);
const loadingChannels = ref<Set<number>>(new Set());
const loadingQr = ref<Set<number>>(new Set());
const deletingAllSessions = ref(false);
const selectedTenantFilter = ref<number | null>(props.selectedTenantId || null);

// Filter channels by selected tenant
const filteredChannels = computed(() => {
    if (!selectedTenantFilter.value) {
        return props.channels;
    }
    return props.channels.filter(ch => ch.tenant?.id === selectedTenantFilter.value);
});

// Handle tenant filter change
const handleTenantFilterChange = (tenantId: string | number | null) => {
    const tenantIdStr = tenantId?.toString() || '';
    selectedTenantFilter.value = tenantIdStr ? parseInt(tenantIdStr) : null;
    router.get(superadmin.whatsapp.index(), { tenant_id: selectedTenantFilter.value }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const waitForSessionId = async (channel: Channel, attempt = 1, maxAttempts = 10) => {
    if (attempt >= maxAttempts) {
        showWarning('Session Belum Siap', 'Channel berhasil dibuat, tapi session belum siap. Silakan klik tombol QR Code secara manual.');
        return;
    }
    
    setTimeout(async () => {
        router.reload({ 
            only: ['channels', 'newChannelId'],
            onSuccess: () => {
                setTimeout(() => {
                    const updatedChannel = props.channels.find((ch: Channel) => ch.id === channel.id);
                    if (updatedChannel?.session_id) {
                        autoLoadQrCode(updatedChannel);
                        loadingChannels.value.delete(channel.id);
                    } else {
                        waitForSessionId(channel, attempt + 1, maxAttempts);
                    }
                }, 500);
            }
        });
    }, 2000);
};

const form = useForm({
    channel_account: '',
    name: '',
    tenant_id: props.selectedTenantId ? props.selectedTenantId.toString() : '',
    is_shared_channel: false,
});

const createChannel = async () => {
    const phoneNumber = form.channel_account.replace(/\D/g, '');
    if (phoneNumber.length < 10) {
        showWarning('Validasi Gagal', 'Nomor WhatsApp harus minimal 10 digit');
        return;
    }
    
    // Convert tenant_id to integer if it's a string, or null if empty
    const formData = {
        ...form.data(),
        tenant_id: form.tenant_id && form.tenant_id.trim() !== '' ? parseInt(form.tenant_id) : null,
    };
    
    // Get the store URL
    let storeUrl: string;
    try {
        storeUrl = superadmin.whatsapp.store();
        // Ensure URL is valid
        if (!storeUrl || storeUrl.trim() === '') {
            // Fallback to hardcoded URL if route generation fails
            storeUrl = '/superadmin/whatsapp';
            console.warn('Route URL is empty, using fallback URL:', storeUrl);
        }
        // Ensure URL starts with /
        if (!storeUrl.startsWith('/')) {
            storeUrl = '/' + storeUrl;
        }
    } catch (error) {
        console.error('Error getting store URL:', error);
        // Fallback to hardcoded URL
        storeUrl = '/superadmin/whatsapp';
        console.warn('Using fallback URL due to error:', storeUrl);
    }
    
    // Debug: log the URL to console
    console.log('Creating channel with URL:', storeUrl);
    console.log('Form data:', formData);
    console.log('Full URL will be:', window.location.origin + storeUrl);
    
    // Use router.post() instead of form.post() to ensure proper request handling
    router.post(storeUrl, formData, {
        preserveScroll: true,
        onSuccess: (page: any) => {
            const flash = page?.props?.flash;
            if (flash && typeof flash === 'object' && flash.error) {
                showError('Gagal Membuat Channel', flash.error);
                return;
            }
            
            form.reset();
            showCreateDialog.value = false;
            
            if (flash && typeof flash === 'object' && flash.success) {
                showSuccess('Channel Berhasil Dibuat', flash.success);
            }
        },
        onError: (errors: any) => {
            console.error('Error creating channel:', errors);
            console.error('Error type:', typeof errors);
            console.error('Error keys:', errors && typeof errors === 'object' ? Object.keys(errors) : 'N/A');
            console.error('Full error object:', JSON.stringify(errors, null, 2));
            
            let errorMessage = 'Terjadi kesalahan saat membuat channel. Silakan coba lagi.';
            
            // Check if it's a 404 error
            if (errors && typeof errors === 'object') {
                // Check for Inertia error response
                if (errors.status === 404 || errors.statusCode === 404) {
                    errorMessage = `Route tidak ditemukan (404). URL: ${storeUrl}. Silakan refresh halaman dan coba lagi.`;
                } else if (errors.message) {
                    errorMessage = errors.message;
                } else if (errors.channel_account) {
                    errorMessage = Array.isArray(errors.channel_account) 
                        ? errors.channel_account[0] 
                        : errors.channel_account;
                } else if (errors.session) {
                    errorMessage = Array.isArray(errors.session) 
                        ? errors.session[0] 
                        : errors.session;
                } else if (errors.error) {
                    errorMessage = typeof errors.error === 'string' ? errors.error : 'Terjadi kesalahan tidak diketahui';
                }
            } else if (typeof errors === 'string') {
                errorMessage = errors;
            }
            
            showError('Gagal Membuat Channel', errorMessage);
        },
        onFinish: () => {
            // Reset form processing state
        },
    });
};

const autoLoadQrCode = async (channel: Channel, retries = 5) => {
    if (!channel.session_id) {
        if (retries > 0) {
            loadingChannels.value.add(channel.id);
            await new Promise(resolve => setTimeout(resolve, 2000));
            router.reload({ only: ['channels'] });
            setTimeout(async () => {
                const updatedChannel = props.channels.find((ch: Channel) => ch.id === channel.id);
                if (updatedChannel) {
                    await autoLoadQrCode(updatedChannel, retries - 1);
                } else {
                    loadingChannels.value.delete(channel.id);
                }
            }, 1000);
        } else {
            loadingChannels.value.delete(channel.id);
        }
        return;
    }
    
    loadingQr.value.add(channel.id);
    
    try {
        const response = await fetch(superadmin.whatsapp.qr(channel.id));
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            
            if (response.status === 202) {
                if (retries > 0) {
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    await autoLoadQrCode(channel, retries - 1);
                } else {
                    showInfo('QR Code Sedang Dipersiapkan', 'Silakan klik tombol QR Code secara manual atau tunggu beberapa detik.');
                }
                return;
            }
            
            if (response.status === 404) {
                if (retries > 0) {
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    await autoLoadQrCode(channel, retries - 1);
                } else {
                    showWarning('QR Code Belum Tersedia', 'Pastikan gateway berjalan dan session sudah dibuat.');
                }
                return;
            }
            
            showError('Gagal Memuat QR Code', errorData.error || `HTTP ${response.status}`);
            return;
        }
        
        const data = await response.json();
        
        if (data.success && data.data) {
            let qrCode = null;
            if (data.data.qr) {
                qrCode = data.data.qr;
            } else if (data.data.qrCode) {
                qrCode = data.data.qrCode;
            } else if (typeof data.data === 'string') {
                qrCode = data.data;
            }
            
            if (qrCode) {
                qrCodeUrl.value = qrCode;
                selectedChannel.value = channel;
                showQrDialog.value = true;
                startQrStatusCheck(channel);
                loadingQr.value.delete(channel.id);
                loadingChannels.value.delete(channel.id);
                return;
            }
        }
        
        if (retries > 0) {
            await new Promise(resolve => setTimeout(resolve, 3000));
            await autoLoadQrCode(channel, retries - 1);
        } else {
            loadingQr.value.delete(channel.id);
            loadingChannels.value.delete(channel.id);
            showInfo('QR Code Belum Siap', 'Silakan klik tombol QR Code pada channel yang baru dibuat.');
        }
    } catch (error) {
        loadingQr.value.delete(channel.id);
        loadingChannels.value.delete(channel.id);
        if (retries > 0) {
            await new Promise(resolve => setTimeout(resolve, 2000));
            await autoLoadQrCode(channel, retries - 1);
        } else {
            showError('Gagal Memuat QR Code', error instanceof Error ? error.message : 'Pastikan server berjalan.');
        }
    }
};

const loadQrCode = async (channel: Channel, retries = 3) => {
    if (!channel.session_id) {
        showWarning('Session ID Tidak Tersedia', 'Silakan reconnect channel.');
        return;
    }
    
    if (channel.session_status === 'connected' || channel.session_status === 'CONNECTED' || channel.session_status === 'authenticated') {
        showInfo('WhatsApp Sudah Terhubung', 'Tidak perlu scan QR code lagi.');
        return;
    }
    
    loadingQr.value.add(channel.id);
    
    try {
        const response = await fetch(superadmin.whatsapp.qr(channel.id));
        const data = await response.json().catch(() => ({}));
        
        if (response.status === 202) {
            if (retries > 0) {
                const retryIn = data.retry_in || 3;
                showInfo('QR Code Sedang Dipersiapkan', data.error || `Mengulang dalam ${retryIn} detik...`);
                setTimeout(async () => {
                    await loadQrCode(channel, retries - 1);
                }, retryIn * 1000);
            } else {
                showInfo('QR Code Belum Siap', data.error || 'Silakan klik tombol QR Code lagi nanti.');
            }
            return;
        }
        
        if (!response.ok) {
            const errorMsg = data.error || `HTTP ${response.status}`;
            
            if (errorMsg.includes('terhubung') || errorMsg.includes('connected') || errorMsg.includes('already connected')) {
                showInfo('WhatsApp Sudah Terhubung', 'Tidak perlu scan QR code.');
                router.reload({ only: ['channels'] });
            } else {
                showError('Gagal Memuat QR Code', errorMsg);
            }
            return;
        }
        
        if (data.success && data.data) {
            let qrCode = null;
            if (data.data.qr) {
                qrCode = data.data.qr;
            } else if (data.data.qrCode) {
                qrCode = data.data.qrCode;
            } else if (typeof data.data === 'string') {
                qrCode = data.data;
            }
            
            if (qrCode) {
                qrCodeUrl.value = qrCode;
                selectedChannel.value = channel;
                showQrDialog.value = true;
                startQrStatusCheck(channel);
            } else {
                if (retries > 0) {
                    setTimeout(async () => {
                        await loadQrCode(channel, retries - 1);
                    }, 2000);
                } else {
                    showInfo('QR Code Belum Tersedia', 'Tunggu beberapa detik dan coba lagi.');
                }
            }
        } else {
            const errorMsg = data.error || 'Unknown error';
            if (errorMsg.includes('terhubung') || errorMsg.includes('connected')) {
                showInfo('WhatsApp Sudah Terhubung', 'Tidak perlu scan QR code.');
                router.reload({ only: ['channels'] });
            } else {
                showError('Gagal Memuat QR Code', errorMsg);
            }
        }
    } catch (error) {
        if (retries > 0) {
            setTimeout(async () => {
                await loadQrCode(channel, retries - 1);
            }, 2000);
        } else {
            showError('Error Memuat QR Code', error instanceof Error ? error.message : 'Pastikan server berjalan.');
        }
    }
};

const startQrStatusCheck = (channel: Channel) => {
    if (qrCheckInterval.value) {
        clearInterval(qrCheckInterval.value);
    }
    
    qrCheckInterval.value = setInterval(async () => {
        if (!channel.session_id || !showQrDialog.value) {
            stopQrStatusCheck();
            return;
        }
        
        try {
            const response = await fetch(superadmin.whatsapp.status(channel.id));
            const data = await response.json();
            
            if (data.success && data.data) {
                const status = data.data.status || data.data.data?.status || data.data.data;
                
                if (status === 'connected' || status === 'CONNECTED') {
                    stopQrStatusCheck();
                    showQrDialog.value = false;
                    qrCodeUrl.value = null;
                    
                    router.reload({ 
                        only: ['channels'],
                        onFinish: () => {
                            setTimeout(() => {
                                showSuccess('WhatsApp Berhasil Terhubung!', 'Sekarang Anda dapat menggunakan WhatsApp untuk mengirim dan menerima pesan.');
                            }, 300);
                        }
                    });
                }
            }
        } catch (error) {
            // Silent error handling
        }
    }, 2000);
};

const stopQrStatusCheck = () => {
    if (qrCheckInterval.value) {
        clearInterval(qrCheckInterval.value);
        qrCheckInterval.value = null;
    }
};

const reconnectSession = async (channelId: number) => {
    const confirmed = await showConfirm(
        'Reconnect Session?',
        'Apakah Anda yakin ingin reconnect session ini?'
    );
    if (confirmed) {
        router.post(superadmin.whatsapp.reconnect(channelId));
    }
};

const deleteAllSessions = async () => {
    const confirmed = await showDeleteConfirm(
        'Hapus Semua Session',
        'Apakah Anda yakin ingin menghapus semua session WhatsApp? Tindakan ini akan menghapus semua koneksi WhatsApp dan tidak dapat dibatalkan.'
    );
    
    if (!confirmed) {
        return;
    }
    
    deletingAllSessions.value = true;
    
    try {
        const response = await fetch(superadmin.whatsapp.deleteAllSessions(), {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-Inertia': 'true',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json().catch(() => ({}));
        
        if (response.ok && data.success) {
            showSuccess('Berhasil', data.message || 'Semua session berhasil dihapus.');
            router.reload({ only: ['channels'] });
        } else {
            showError('Gagal', data.error || 'Gagal menghapus semua session.');
        }
    } catch (error) {
        showError('Error', error instanceof Error ? error.message : 'Terjadi kesalahan saat menghapus session.');
    } finally {
        deletingAllSessions.value = false;
    }
};

const deleteChannel = async (channelId: number) => {
    const confirmed = await showDeleteConfirm(
        'Hapus Channel?',
        'Apakah Anda yakin ingin menghapus channel ini? Session akan dihapus dari engine.'
    );
    if (confirmed) {
        router.delete(superadmin.whatsapp.destroy(channelId));
    }
};

const refreshStatus = async (channel: Channel) => {
    if (!channel.session_id) return;
    
    try {
        const response = await fetch(superadmin.whatsapp.status(channel.id));
        const data = await response.json();
        
        if (data.success) {
            router.reload({ only: ['channels'] });
        }
    } catch (error) {
        // Silent error handling
    }
};

const getStatusIcon = (status: string | null) => {
    switch (status) {
        case 'connected':
            return CheckCircle2;
        case 'connecting':
            return Clock;
        case 'disconnected':
            return XCircle;
        case 'error':
            return AlertCircle;
        default:
            return Clock;
    }
};

const getStatusColor = (status: string | null) => {
    switch (status) {
        case 'connected':
            return 'text-green-600';
        case 'connecting':
            return 'text-yellow-600';
        case 'disconnected':
            return 'text-red-600';
        case 'error':
            return 'text-red-600';
        default:
            return 'text-gray-600';
    }
};

const getStatusLabel = (status: string | null) => {
    switch (status) {
        case 'connected':
            return 'Terhubung';
        case 'connecting':
            return 'Menghubungkan...';
        case 'disconnected':
            return 'Terputus';
        case 'error':
            return 'Error';
        default:
            return 'Tidak diketahui';
    }
};

onMounted(() => {
    // Check if there's a new channel created
    if (props.newChannelId) {
        const newChannel = props.channels.find(c => c.id === props.newChannelId);
        if (newChannel) {
            console.log('New channel detected, auto loading QR code:', newChannel.id);
            autoLoadQrCode(newChannel);
        }
    }

    statusInterval.value = window.setInterval(() => {
        router.reload({
            only: ['channels']
        });
    }, 5000);
});

onUnmounted(() => {
    if (statusInterval.value) {
        clearInterval(statusInterval.value);
    }
    if (qrCheckInterval.value) {
        clearInterval(qrCheckInterval.value);
    }
});
</script>

<template>
    <Head title="WhatsApp Channels - Super Admin" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold">WhatsApp Channels (Super Admin)</h2>
                    <p class="text-sm text-muted-foreground">
                        Kelola semua channel WhatsApp dari semua tenant
                    </p>
                </div>
                
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        @click="deleteAllSessions"
                        :disabled="deletingAllSessions"
                    >
                        <RefreshCw v-if="deletingAllSessions" class="mr-2 h-4 w-4 animate-spin" />
                        <Trash2 v-else class="mr-2 h-4 w-4" />
                        {{ deletingAllSessions ? 'Menghapus...' : 'Hapus Semua Session' }}
                    </Button>
                    
                    <Dialog v-model:open="showCreateDialog">
                        <DialogTrigger as-child>
                            <Button 
                                class="text-white hover:opacity-90 transition-opacity" 
                                style="background-color: oklch(0.65 0.19 137.46);"
                            >
                                <Plus class="mr-2 h-4 w-4" />
                                Tambah Channel
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Tambah Channel WhatsApp</DialogTitle>
                                <DialogDescription>
                                    Tambahkan nomor WhatsApp baru untuk chatbot
                                </DialogDescription>
                            </DialogHeader>
                            <form @submit.prevent="createChannel" class="space-y-4">
                                <div>
                                    <Label for="tenant_id">Tenant (Opsional)</Label>
                                    <Select v-model="form.tenant_id">
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih tenant (default: Sistem)">
                                                <template #default="{ value }">
                                                    <span v-if="!value">Pilih tenant (default: Sistem)</span>
                                                    <span v-else>
                                                        {{ props.tenants.find(t => t.id.toString() === value)?.name || 'Sistem (Default)' }}
                                                    </span>
                                                </template>
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="">Sistem (Default)</SelectItem>
                                            <SelectItem 
                                                v-for="tenant in props.tenants" 
                                                :key="tenant.id" 
                                                :value="tenant.id.toString()"
                                            >
                                                {{ tenant.name }}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Pilih tenant untuk channel ini. Kosongkan untuk menggunakan tenant sistem.
                                    </p>
                                </div>
                                <div>
                                    <Label for="channel_account">Nomor WhatsApp</Label>
                                    <Input
                                        id="channel_account"
                                        v-model="form.channel_account"
                                        type="tel"
                                        placeholder="6285242766676"
                                        required
                                        @input="form.channel_account = form.channel_account.replace(/\D/g, '')"
                                    />
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Masukkan nomor tanpa + atau spasi (contoh: 6281234567890)
                                    </p>
                                </div>
                                <div>
                                    <Label for="name">Nama Channel (Opsional)</Label>
                                    <Input
                                        id="name"
                                        v-model="form.name"
                                        type="text"
                                        placeholder="Channel Utama"
                                    />
                                </div>
                                <div class="flex items-center space-x-2">
                                    <input
                                        id="is_shared_channel"
                                        v-model="form.is_shared_channel"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                    />
                                    <Label for="is_shared_channel" class="text-sm font-normal cursor-pointer">
                                        Shared Channel (untuk routing berdasarkan nomor pengirim)
                                    </Label>
                                </div>
                                <p class="text-xs text-muted-foreground -mt-2">
                                    Jika dicentang, channel ini akan digunakan untuk semua user. Pesan akan di-routing berdasarkan nomor WhatsApp pengirim yang terdaftar di profil user.
                                </p>
                                <div class="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        @click="showCreateDialog = false"
                                    >
                                        Batal
                                    </Button>
                                    <Button type="submit" :disabled="form.processing" class="text-white hover:opacity-90 transition-opacity disabled:opacity-50" style="background-color: oklch(0.65 0.19 137.46);">
                                        <RefreshCw v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                                        {{ form.processing ? 'Membuat Channel...' : 'Buat Channel' }}
                                    </Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Filter by Tenant -->
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <Building2 class="h-4 w-4 text-muted-foreground" />
                    <Label for="tenant_filter">Filter by Tenant:</Label>
                    <Select :model-value="selectedTenantFilter?.toString() || ''" @update:model-value="handleTenantFilterChange">
                        <SelectTrigger class="w-[200px]">
                            <SelectValue placeholder="Semua Tenant">
                                <template #default="{ value }">
                                    <span v-if="!value">Semua Tenant</span>
                                    <span v-else>
                                        {{ props.tenants.find(t => t.id.toString() === value)?.name || 'Semua Tenant' }}
                                    </span>
                                </template>
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Semua Tenant</SelectItem>
                            <SelectItem 
                                v-for="tenant in props.tenants" 
                                :key="tenant.id" 
                                :value="tenant.id.toString()"
                            >
                                {{ tenant.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="text-sm text-muted-foreground">
                    Total: {{ filteredChannels.length }} channel
                </div>
            </div>

            <!-- Engine Status -->
            <div class="rounded-lg border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">WhatsApp Gateway Status</p>
                        <p class="text-xs text-muted-foreground">
                            Status koneksi ke WhatsApp Gateway
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <component
                            :is="props.engineStatus.success ? CheckCircle2 : XCircle"
                            :class="props.engineStatus.success ? 'text-green-600' : 'text-red-600'"
                            class="h-5 w-5"
                        />
                        <span class="text-sm font-medium">
                            {{ props.engineStatus.success ? 'Running' : 'Stopped' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Channels List -->
            <div class="space-y-4">
                <div
                    v-for="channel in filteredChannels"
                    :key="channel.id"
                    class="rounded-lg border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-3">
                                <MessageSquare class="h-5 w-5 text-primary" />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold">{{ channel.name }}</h3>
                                        <span v-if="channel.is_shared_channel" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            Shared Channel
                                        </span>
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ channel.channel_account }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                                <div>
                                    <p class="text-xs text-muted-foreground">Status</p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <RefreshCw 
                                            v-if="loadingChannels.has(channel.id) || loadingQr.has(channel.id)" 
                                            class="h-4 w-4 animate-spin text-primary" 
                                        />
                                        <component
                                            v-else
                                            :is="getStatusIcon(channel.session_status)"
                                            :class="getStatusColor(channel.session_status)"
                                            class="h-4 w-4"
                                        />
                                        <span 
                                            class="text-sm font-medium"
                                            :class="channel.session_status === 'connected' ? 'text-green-600 font-bold' : ''"
                                        >
                                            {{ loadingChannels.has(channel.id) ? 'Memuat session...' : loadingQr.has(channel.id) ? 'Memuat QR...' : getStatusLabel(channel.session_status) }}
                                        </span>
                                    </div>
                                    <div 
                                        v-if="channel.session_status === 'connected'"
                                        class="mt-2"
                                    >
                                        <p class="text-xs text-green-600 dark:text-green-400">
                                            WhatsApp siap menerima pesan
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">Pesan</p>
                                    <p class="mt-1 text-sm font-medium">
                                        {{ channel.messages_count }} pesan
                                    </p>
                                    <p v-if="channel.recent_messages && channel.recent_messages.length > 0" class="mt-1 text-xs text-muted-foreground">
                                        Terbaru: {{ channel.recent_messages[0].created_at_human }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">Aktivitas Terakhir</p>
                                    <p class="mt-1 text-sm font-medium">
                                        {{ channel.last_activity_at || '-' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">Dibuat</p>
                                    <p class="mt-1 text-sm font-medium">
                                        {{ new Date(channel.created_at).toLocaleDateString('id-ID') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="ml-4 flex gap-2">
                            <div v-if="loadingChannels.has(channel.id)" class="flex items-center gap-2 px-3 py-2">
                                <RefreshCw class="h-4 w-4 animate-spin text-primary" />
                                <span class="text-xs text-muted-foreground">Memuat session...</span>
                            </div>
                            
                            <Button
                                v-if="channel.session_id && !loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="loadQrCode(channel)"
                                :disabled="channel.session_status === 'connected' || loadingQr.has(channel.id)"
                            >
                                <RefreshCw v-if="loadingQr.has(channel.id)" class="h-4 w-4 animate-spin" />
                                <QrCode v-else class="h-4 w-4" />
                            </Button>
                            <Button
                                v-if="channel.session_id && !loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="refreshStatus(channel)"
                            >
                                <RefreshCw class="h-4 w-4" />
                            </Button>
                            <Button
                                v-if="channel.session_id && channel.session_status !== 'connected' && !loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="reconnectSession(channel.id)"
                            >
                                <RefreshCw class="h-4 w-4" />
                            </Button>
                            <Button
                                v-if="!loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="deleteChannel(channel.id)"
                            >
                                <Trash2 class="h-4 w-4 text-red-600" />
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="filteredChannels.length === 0"
                    class="rounded-lg border border-sidebar-border/70 bg-card p-12 text-center dark:border-sidebar-border"
                >
                    <MessageSquare class="mx-auto h-12 w-12 text-muted-foreground" />
                    <p class="mt-4 text-sm font-medium">Belum ada channel WhatsApp</p>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="selectedTenantFilter">Tidak ada channel untuk tenant yang dipilih.</span>
                        <span v-else>Buat channel baru untuk mulai menggunakan WhatsApp chatbot</span>
                    </p>
                </div>
            </div>

            <!-- QR Code Dialog -->
            <Dialog v-model:open="showQrDialog" @update:open="(open) => { if (!open) { stopQrStatusCheck(); qrCodeUrl = null; } }">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Scan QR Code</DialogTitle>
                        <DialogDescription>
                            Scan QR code ini dengan WhatsApp di ponsel Anda untuk menghubungkan channel
                        </DialogDescription>
                    </DialogHeader>
                    <div class="space-y-4">
                        <div class="flex justify-center">
                            <div v-if="qrCodeUrl" class="rounded-lg border-2 border-primary p-4 bg-white shadow-lg">
                                <img 
                                    v-if="qrCodeUrl.startsWith('data:image') || qrCodeUrl.startsWith('http')"
                                    :src="qrCodeUrl" 
                                    alt="QR Code"
                                    class="max-w-full h-auto"
                                />
                                <div 
                                    v-else 
                                    class="qr-code-container"
                                    v-html="qrCodeUrl"
                                />
                            </div>
                            <div v-else class="text-center py-8">
                                <p class="text-sm text-muted-foreground mb-2">
                                    Memuat QR code...
                                </p>
                                <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
                            </div>
                        </div>
                        <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3">
                            <p class="text-center text-sm font-medium text-blue-900 dark:text-blue-300">
                                📱 Buka WhatsApp di ponsel Anda
                            </p>
                            <p class="text-center text-xs text-blue-700 dark:text-blue-400 mt-1">
                                Lalu scan QR code ini untuk menghubungkan
                            </p>
                            <p class="text-center text-xs text-blue-600 dark:text-blue-400 mt-2 font-semibold">
                                ⏳ Dialog akan tertutup otomatis setelah terhubung
                            </p>
                        </div>
                        <div v-if="selectedChannel" class="text-center text-xs text-muted-foreground">
                            Channel: {{ selectedChannel.name }} ({{ selectedChannel.channel_account }})
                        </div>
                        <div class="flex justify-between items-center">
                            <p class="text-xs text-muted-foreground">
                                ⏳ Menunggu koneksi...
                            </p>
                            <Button variant="outline" @click="showQrDialog = false; stopQrStatusCheck(); qrCodeUrl = null;">
                                Tutup
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>

