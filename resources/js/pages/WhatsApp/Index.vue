<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { MessageSquare, QrCode, RefreshCw, Trash2, Plus, CheckCircle2, XCircle, Clock, AlertCircle, Phone, Edit, Star } from 'lucide-vue-next';
import { useSweetAlert } from '@/composables/useSweetAlert';

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

interface Channel {
    id: number;
    name: string;
    channel_account: string;
    is_active: boolean;
    session_id: string | null;
    session_status: string | null;
    last_activity_at: string | null;
    messages_count: number;
    recent_messages: Message[];
    created_at: string;
}

interface UserWhatsAppNumber {
    id: number;
    whatsapp_number: string;
    name: string | null;
    is_primary: boolean;
    created_at: string;
}

interface LimitInfo {
    current: number;
    limit: number;
    remaining: number;
    plan: string;
    can_add: boolean;
    is_unlimited: boolean;
    plan_name: string;
}

interface Props {
    channels: Channel[];
    newChannelId?: number;
    tenantIsActive: boolean;
    hasActiveSubscription: boolean;
    userWhatsAppNumbers?: UserWhatsAppNumber[];
    limitInfo?: LimitInfo;
    tenant_id?: number;
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
        console.log('[WhatsApp] New channel created, ID:', newChannelId);
        // Wait a bit for Vue to fully render and data to be available
        setTimeout(() => {
            const newChannel = props.channels.find((ch: Channel) => ch.id === newChannelId);
            
            if (newChannel) {
                console.log('[WhatsApp] Found channel:', { id: newChannel.id, session_id: newChannel.session_id, status: newChannel.session_status });
                // Mark channel as loading
                loadingChannels.value.add(newChannel.id);
                
                if (newChannel.session_id) {
                    console.log('[WhatsApp] Channel has session_id, loading QR code in 2 seconds...');
                    // Wait a bit more for QR to be ready (session might still be initializing)
                    setTimeout(async () => {
                        await autoLoadQrCode(newChannel);
                        loadingChannels.value.delete(newChannel.id);
                    }, 2000);
                } else {
                    console.log('[WhatsApp] Channel does not have session_id yet, waiting...');
                    // Wait for session_id to be available
                    waitForSessionId(newChannel);
                }
            } else {
                console.warn('[WhatsApp] Channel not found in channels array, channels count:', props.channels.length);
                // Channel might not be loaded yet, retry after a delay
                setTimeout(() => {
                    router.reload({ only: ['channels', 'newChannelId'] });
                }, 1000);
            }
        }, 1000); // Increased delay to ensure data is loaded
    }
}, { immediate: true });

const showCreateDialog = ref(false);
const showQrDialog = ref(false);
const selectedChannel = ref<Channel | null>(null);
const qrCodeUrl = ref<string | null>(null);
const statusInterval = ref<number | null>(null);
const qrCheckInterval = ref<number | null>(null);
const pendingChannelPhone = ref<string | null>(null);
const loadingChannels = ref<Set<number>>(new Set()); // Track channels that are loading
const loadingQr = ref<Set<number>>(new Set()); // Track channels that are loading QR
const deletingAllSessions = ref(false); // Track if deleting all sessions

// User WhatsApp Numbers
const showAddNumberDialog = ref(false);
const editingNumber = ref<UserWhatsAppNumber | null>(null);
const numberForm = useForm({
    whatsapp_number: '',
    name: '',
    is_primary: false,
});

// Function to wait for session_id to be available
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
});

const createChannel = async () => {
    // Validate phone number format
    const phoneNumber = form.channel_account.replace(/\D/g, '');
    if (phoneNumber.length < 10) {
        showWarning('Validasi Gagal', 'Nomor WhatsApp harus minimal 10 digit');
        return;
    }
    
    form.post('/whatsapp', {
        preserveScroll: true,
        onSuccess: (page: any) => {
            // Check if there's an error message from server
            const flash = page?.props?.flash;
            if (flash && typeof flash === 'object' && flash.error) {
                showError('Gagal Membuat Channel', flash.error);
                // Don't close dialog if there's an error
                return;
            }
            
            // Only close dialog and reset form if success
            form.reset();
            showCreateDialog.value = false;
            
            // Show success message if available
            if (flash && typeof flash === 'object' && flash.success) {
                showSuccess('Channel Berhasil Dibuat', flash.success);
            }
            
            // newChannelId will be set by server after redirect
            // The watch handler will automatically load QR code
        },
        onError: (errors: any) => {
            // Keep dialog open so user can see error and try again
            let errorMessage = 'Terjadi kesalahan saat membuat channel. Silakan coba lagi.';
            
            if (typeof errors === 'string') {
                errorMessage = errors;
            } else if (errors && typeof errors === 'object') {
                // Handle validation errors from Laravel
                if (errors.message) {
                    errorMessage = errors.message;
                } else if (errors.channel_account) {
                    errorMessage = Array.isArray(errors.channel_account) 
                        ? errors.channel_account[0] 
                        : errors.channel_account;
                } else if (errors.session) {
                    errorMessage = Array.isArray(errors.session) 
                        ? errors.session[0] 
                        : errors.session;
                } else if (errors.channel) {
                    errorMessage = Array.isArray(errors.channel) 
                        ? errors.channel[0] 
                        : errors.channel;
                } else {
                    // Get first error message from object
                    const errorKeys = Object.keys(errors);
                    if (errorKeys.length > 0) {
                        const firstError = errors[errorKeys[0]];
                        errorMessage = Array.isArray(firstError) 
                            ? firstError[0] 
                            : String(firstError);
                    }
                }
            }
            
            showError('Gagal Membuat Channel', errorMessage);
            // DON'T close dialog - keep it open so user can see the error
        },
        onFinish: () => {
        },
    });
};

const autoLoadQrCode = async (channel: Channel, retries = 5) => {
    if (!channel.session_id) {
        if (retries > 0) {
            loadingChannels.value.add(channel.id);
            await new Promise(resolve => setTimeout(resolve, 2000));
            // Reload channels to get updated session_id
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
        const response = await fetch(`/whatsapp/${channel.id}/qr`);
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            
            // Handle 202 Accepted (QR not ready yet)
            if (response.status === 202) {
                if (retries > 0) {
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    await autoLoadQrCode(channel, retries - 1);
                } else {
                    showInfo('QR Code Sedang Dipersiapkan', 'Silakan klik tombol QR Code secara manual atau tunggu beberapa detik.');
                }
                return;
            }
            
            // Handle 404 (Session not found or QR not available)
            if (response.status === 404) {
                if (retries > 0) {
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    await autoLoadQrCode(channel, retries - 1);
                } else {
                    showWarning('QR Code Belum Tersedia', 'Pastikan gateway berjalan dan session sudah dibuat.');
                }
                return;
            }
            
            if (retries > 0) {
                await new Promise(resolve => setTimeout(resolve, 2000));
                await autoLoadQrCode(channel, retries - 1);
            } else {
                showError('Gagal Memuat QR Code', errorData.error || `HTTP ${response.status}`);
            }
            return;
        }
        
        const data = await response.json();
        
        if (data.success && data.data) {
            // QR code bisa berupa data URL atau base64 string
            let qrCode = null;
            if (data.data.qr) {
                qrCode = data.data.qr;
            } else if (data.data.qrCode) {
                qrCode = data.data.qrCode;
            } else if (typeof data.data === 'string') {
                qrCode = data.data;
            }
            
            if (qrCode) {
                console.log('[WhatsApp] QR code loaded successfully, showing dialog');
                qrCodeUrl.value = qrCode;
                selectedChannel.value = channel;
                showQrDialog.value = true;
                
                // Start checking connection status while QR dialog is open
                startQrStatusCheck(channel);
                
                // Remove loading states
                loadingQr.value.delete(channel.id);
                loadingChannels.value.delete(channel.id);
                return;
            } else {
                console.warn('[WhatsApp] QR code data is empty');
                loadingQr.value.delete(channel.id);
            }
        }
        
        // If QR not ready yet, retry
        if (retries > 0) {
            await new Promise(resolve => setTimeout(resolve, 3000));
            await autoLoadQrCode(channel, retries - 1);
        } else {
            loadingQr.value.delete(channel.id);
            loadingChannels.value.delete(channel.id);
            // Show manual QR button message
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
    
    // Check if already connected - don't load QR if connected
    if (channel.session_status === 'connected' || channel.session_status === 'CONNECTED' || channel.session_status === 'authenticated') {
        showInfo('WhatsApp Sudah Terhubung', 'Tidak perlu scan QR code lagi.');
        return;
    }
    
    // Add loading state
    loadingQr.value.add(channel.id);
    
    try {
        const response = await fetch(`/whatsapp/${channel.id}/qr`);
        const data = await response.json().catch(() => ({}));
        
        // Handle different response statuses
        if (response.status === 202) {
            // QR not ready yet (202 Accepted)
            const errorMsg = data.error || 'QR code sedang dipersiapkan';
            const shouldReconnect = data.should_reconnect || false;
            const existsOnDisk = data.exists_on_disk || false;
            
            if (shouldReconnect || existsOnDisk) {
                // Session needs to be reconnected
                showInfo('Menyambungkan Ulang', 'Session perlu di-reconnect. Silakan tunggu...');
                
                // Try to reconnect
                try {
                    const reconnectResponse = await fetch(`/whatsapp/${channel.id}/reconnect`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Inertia': 'true'
                        }
                    });
                    
                    if (reconnectResponse.ok) {
                        // Wait a bit then retry loading QR
                        if (retries > 0) {
                            setTimeout(async () => {
                                await loadQrCode(channel, retries - 1);
                            }, 3000);
                        } else {
                            showInfo('QR Code Sedang Dipersiapkan', 'Session sudah di-reconnect. QR code akan muncul sebentar lagi.');
                        }
                    } else {
                        showError('Gagal Reconnect', 'Tidak bisa reconnect session. Silakan coba lagi.');
                    }
                } catch (reconnectError) {
                    showError('Error Reconnect', 'Terjadi error saat reconnect. Silakan coba lagi.');
                }
            } else if (retries > 0) {
                // QR not ready yet, retry after delay
                const retryIn = data.retry_in || 3;
                showInfo('QR Code Sedang Dipersiapkan', errorMsg + `. Mengulang dalam ${retryIn} detik...`);
                setTimeout(async () => {
                    await loadQrCode(channel, retries - 1);
                }, retryIn * 1000);
            } else {
                showInfo('QR Code Belum Siap', errorMsg + ' Silakan klik tombol QR Code lagi nanti.');
            }
            return;
        }
        
        if (!response.ok) {
            const errorMsg = data.error || `HTTP ${response.status}`;
            
            // Check if error is because already connected
            if (errorMsg.includes('terhubung') || errorMsg.includes('connected') || errorMsg.includes('already connected')) {
                showInfo('WhatsApp Sudah Terhubung', 'Tidak perlu scan QR code.');
                // Reload to update status
                router.reload({ only: ['channels'] });
            } else {
                showError('Gagal Memuat QR Code', errorMsg);
            }
            return;
        }
        
        if (data.success && data.data) {
            // QR code bisa berupa data URL atau base64 string
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
                
                // Start checking connection status while QR dialog is open
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

// Check connection status while QR dialog is open
const startQrStatusCheck = (channel: Channel) => {
    // Clear existing interval
    if (qrCheckInterval.value) {
        clearInterval(qrCheckInterval.value);
    }
    
    // Check status every 2 seconds
    qrCheckInterval.value = setInterval(async () => {
        if (!channel.session_id || !showQrDialog.value) {
            stopQrStatusCheck();
            return;
        }
        
        try {
            const response = await fetch(`/whatsapp/${channel.id}/status`);
            const data = await response.json();
            
            if (data.success && data.data) {
                const status = data.data.status || data.data.data?.status || data.data.data;
                
                // If connected, close QR dialog and show success message
                if (status === 'connected' || status === 'CONNECTED') {
                    stopQrStatusCheck();
                    showQrDialog.value = false;
                    qrCodeUrl.value = null;
                    
                    // Reload channels to update status
                    router.reload({ 
                        only: ['channels'],
                        onFinish: () => {
                            // Show success notification
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
    }, 2000); // Check every 2 seconds
};

// Stop QR status checking
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
        router.post(`/whatsapp/${channelId}/reconnect`);
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
        const response = await fetch('/whatsapp/sessions/all', {
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
            // Reload channels to reflect changes
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
        router.delete(`/whatsapp/${channelId}`);
    }
};

const refreshStatus = async (channel: Channel) => {
    if (!channel.session_id) return;
    
    try {
        const response = await fetch(`/whatsapp/${channel.id}/status`);
        const data = await response.json();
        
        if (data.success) {
            // Reload page to update status
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

// User WhatsApp Numbers Management
const resetNumberForm = () => {
    numberForm.reset();
    editingNumber.value = null;
};

const editNumber = (number: UserWhatsAppNumber) => {
    editingNumber.value = number;
    numberForm.whatsapp_number = number.whatsapp_number;
    numberForm.name = number.name || '';
    numberForm.is_primary = number.is_primary;
    showAddNumberDialog.value = true;
};

const saveNumber = () => {
    const tenantId = (page.props as any).tenant_id || (page.props.auth?.user as any)?.tenant_id;
    
    if (!tenantId) {
        showError('Error', 'Tenant ID tidak ditemukan. Silakan refresh halaman.');
        return;
    }
    
    if (editingNumber.value) {
        // Update
        numberForm.put(`/whatsapp/numbers/${editingNumber.value.id}?tenant_id=${tenantId}`, {
            preserveScroll: true,
            onSuccess: () => {
                showAddNumberDialog.value = false;
                resetNumberForm();
            },
            onError: (errors) => {
                let errorMessage = 'Gagal mengupdate nomor WhatsApp';
                if (errors.whatsapp_number) {
                    errorMessage = Array.isArray(errors.whatsapp_number) 
                        ? errors.whatsapp_number[0] 
                        : errors.whatsapp_number;
                }
                showError('Gagal Update', errorMessage);
            },
        });
    } else {
        // Create
        numberForm.post(`/whatsapp/numbers?tenant_id=${tenantId}`, {
            preserveScroll: true,
            onSuccess: () => {
                showAddNumberDialog.value = false;
                resetNumberForm();
            },
            onError: (errors) => {
                let errorMessage = 'Gagal menambahkan nomor WhatsApp';
                if (errors.whatsapp_number) {
                    errorMessage = Array.isArray(errors.whatsapp_number) 
                        ? errors.whatsapp_number[0] 
                        : errors.whatsapp_number;
                }
                showError('Gagal Tambah', errorMessage);
            },
        });
    }
};

const deleteNumber = async (numberId: number) => {
    const confirmed = await showDeleteConfirm(
        'Hapus Nomor WhatsApp',
        'Apakah Anda yakin ingin menghapus nomor WhatsApp ini?'
    );
    
    if (!confirmed) {
        return;
    }
    
    const tenantId = (page.props as any).tenant_id || (page.props.auth?.user as any)?.tenant_id;
    if (!tenantId) {
        showError('Error', 'Tenant ID tidak ditemukan. Silakan refresh halaman.');
        return;
    }
    
    router.delete(`/whatsapp/numbers/${numberId}?tenant_id=${tenantId}`, {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Nomor WhatsApp berhasil dihapus');
        },
        onError: () => {
            showError('Gagal', 'Gagal menghapus nomor WhatsApp');
        },
    });
};

const setPrimaryNumber = (numberId: number) => {
    const tenantId = (page.props as any).tenant_id || (page.props.auth?.user as any)?.tenant_id;
    if (!tenantId) {
        showError('Error', 'Tenant ID tidak ditemukan. Silakan refresh halaman.');
        return;
    }
    router.post(`/whatsapp/numbers/${numberId}/primary?tenant_id=${tenantId}`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Berhasil', 'Nomor utama berhasil diubah');
        },
        onError: () => {
            showError('Gagal', 'Gagal mengubah nomor utama');
        },
    });
};

// Auto refresh messages and status every 5 seconds
onMounted(() => {
    // Refresh messages and status every 5 seconds
    statusInterval.value = window.setInterval(() => {
        // Reload channels to get latest messages
        router.reload({
            only: ['channels']
        });
    }, 5000);
});

onUnmounted(() => {
    if (statusInterval.value) {
        clearInterval(statusInterval.value);
        // Also clear quick status check if it exists
        if ((statusInterval.value as any).quickCheck) {
            clearInterval((statusInterval.value as any).quickCheck);
        }
    }
    if (qrCheckInterval.value) {
        clearInterval(qrCheckInterval.value);
    }
});
</script>

<template>
    <Head title="WhatsApp Channels" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-8 overflow-x-auto p-6 bg-gray-50/50 dark:bg-black/10">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">WhatsApp Channels</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        Kelola koneksi WhatsApp untuk chatbot
                    </p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-2">
                    <Button
                        as="a"
                        :href="'https://wa.me/6285762000079'"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="w-full sm:w-auto text-white hover:opacity-90 transition-opacity rounded-xl shadow-sm"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        <MessageSquare class="mr-2 h-4 w-4" />
                        Chat Finwa Bot
                    </Button>
                </div>
            </div>

            <!-- User WhatsApp Numbers Section -->
            <div class="rounded-2xl bg-white p-8 border border-gray-200/50 dark:bg-gray-800 dark:border-gray-700/30">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Nomor WhatsApp Saya</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Daftarkan nomor WhatsApp Anda untuk routing pesan
                        </p>
                    </div>
                    <div v-if="props.limitInfo" class="text-sm text-gray-500">
                        <span class="font-bold text-gray-900 dark:text-white">{{ props.limitInfo.current }}</span> / 
                        <span v-if="props.limitInfo.is_unlimited">∞</span>
                        <span v-else>{{ props.limitInfo.limit }}</span>
                        <span class="ml-1">(Paket {{ props.limitInfo.plan_name }})</span>
                    </div>
                </div>

                <!-- Numbers List -->
                <div v-if="props.userWhatsAppNumbers && props.userWhatsAppNumbers.length > 0" class="space-y-3 mb-6">
                    <div 
                        v-for="number in props.userWhatsAppNumbers" 
                        :key="number.id"
                        class="flex items-center justify-between p-4 rounded-xl border border-gray-100 bg-gray-50/50 dark:border-gray-700 dark:bg-gray-900/50"
                    >
                        <div class="flex-1">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-gray-900 dark:text-white">{{ number.whatsapp_number }}</span>
                                <span v-if="number.is_primary" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
                                    Utama
                                </span>
                            </div>
                            <p v-if="number.name" class="text-sm text-gray-500 mt-1">{{ number.name }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                v-if="!number.is_primary"
                                variant="ghost"
                                size="icon"
                                @click="setPrimaryNumber(number.id)"
                                class="h-8 w-8 text-gray-500 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20"
                                title="Set Utama"
                            >
                                <Star class="h-4 w-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                @click="editNumber(number)"
                                class="h-8 w-8 text-gray-500 hover:text-gray-900 hover:bg-gray-100 dark:hover:bg-gray-800"
                                title="Edit"
                            >
                                <Edit class="h-4 w-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                @click="deleteNumber(number.id)"
                                :disabled="props.userWhatsAppNumbers.length <= 1"
                                class="h-8 w-8 text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                title="Hapus"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="text-center py-8 text-gray-500 text-sm">
                    Belum ada nomor WhatsApp terdaftar
                </div>

                <!-- Add Number Button -->
                <div class="flex justify-end">
                    <Dialog v-model:open="showAddNumberDialog">
                        <DialogTrigger as-child>
                            <Button 
                                :disabled="!props.limitInfo?.can_add"
                                class="rounded-xl text-white shadow-sm hover:opacity-90"
                                style="background-color: oklch(0.65 0.19 137.46);"
                            >
                                <Plus class="mr-2 h-4 w-4" />
                                Tambah Nomor
                            </Button>
                        </DialogTrigger>
                        <DialogContent class="!max-w-[95vw] sm:!max-w-md rounded-2xl p-0 overflow-hidden bg-white dark:bg-gray-800">
                            <DialogHeader class="p-6 pb-0">
                                <DialogTitle class="text-xl font-bold text-gray-900 dark:text-white">{{ editingNumber ? 'Edit Nomor WhatsApp' : 'Tambah Nomor WhatsApp' }}</DialogTitle>
                                <DialogDescription class="text-sm text-gray-500">
                                    {{ editingNumber ? 'Ubah informasi nomor WhatsApp' : 'Daftarkan nomor WhatsApp baru untuk routing pesan' }}
                                </DialogDescription>
                            </DialogHeader>
                            <div class="p-6">
                                <form @submit.prevent="saveNumber" class="space-y-4">
                                    <div>
                                        <Label for="whatsapp_number" class="text-sm font-medium text-gray-700 dark:text-gray-300">Nomor WhatsApp</Label>
                                        <Input
                                            id="whatsapp_number"
                                            v-model="numberForm.whatsapp_number"
                                            type="tel"
                                            placeholder="6281234567890"
                                            required
                                            @input="numberForm.whatsapp_number = numberForm.whatsapp_number.replace(/\D/g, '')"
                                            class="mt-1.5 rounded-xl border-gray-200 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">
                                            Format: 6281234567890 (dengan kode negara 62)
                                        </p>
                                    </div>
                                    <div>
                                        <Label for="number_name" class="text-sm font-medium text-gray-700 dark:text-gray-300">Nama/Alias (Opsional)</Label>
                                        <Input
                                            id="number_name"
                                            v-model="numberForm.name"
                                            type="text"
                                            placeholder="Nomor Pribadi"
                                            class="mt-1.5 rounded-xl border-gray-200 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500 dark:border-gray-700 dark:bg-gray-900"
                                        />
                                    </div>
                                    <div class="flex items-center space-x-2 pt-2">
                                        <input
                                            id="is_primary"
                                            v-model="numberForm.is_primary"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
                                        />
                                        <Label for="is_primary" class="text-sm font-normal cursor-pointer text-gray-700 dark:text-gray-300">
                                            Set sebagai nomor utama
                                        </Label>
                                    </div>
                                    <div v-if="props.limitInfo && !props.limitInfo.can_add" class="rounded-xl border border-yellow-200 bg-yellow-50 p-3 dark:border-yellow-900/30 dark:bg-yellow-900/20">
                                        <p class="text-xs text-yellow-800 dark:text-yellow-200">
                                            Limit nomor WhatsApp sudah tercapai. Paket {{ props.limitInfo.plan_name }} hanya dapat menambahkan maksimal {{ props.limitInfo.limit }} nomor.
                                        </p>
                                    </div>
                                    <div class="flex justify-end gap-3 pt-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            @click="showAddNumberDialog = false; resetNumberForm()"
                                            class="rounded-xl"
                                        >
                                            Batal
                                        </Button>
                                        <Button 
                                            type="submit" 
                                            :disabled="numberForm.processing || !props.limitInfo?.can_add"
                                            class="rounded-xl text-white shadow-sm hover:opacity-90"
                                            style="background-color: oklch(0.65 0.19 137.46);"
                                        >
                                            {{ editingNumber ? 'Update' : 'Simpan' }}
                                        </Button>
                                    </div>
                                </form>
                            </div>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Account Status Warning -->
            <div v-if="!props.tenantIsActive" class="rounded-2xl border border-yellow-200 bg-yellow-50 p-6 dark:border-yellow-900/30 dark:bg-yellow-900/20">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 mt-0.5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/50">
                            <AlertCircle class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-lg font-bold text-yellow-900 dark:text-yellow-100 mb-1">
                            Akun Belum Aktif
                        </h3>
                        <p class="text-sm text-yellow-800 dark:text-yellow-200 leading-relaxed">
                            Akun belum aktif. Silakan lakukan pembayaran atau hubungi admin.
                            Fitur WhatsApp akan tersedia setelah akun Anda diaktifkan oleh admin.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Channels List -->
            <div class="space-y-6">
                <!-- Disabled Overlay if Account Not Active -->
                <div
                    v-if="!props.tenantIsActive && props.channels.length > 0"
                    class="rounded-2xl border border-yellow-200 bg-yellow-50 p-6 dark:border-yellow-900/30 dark:bg-yellow-900/20"
                >
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 mt-0.5">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-yellow-100 dark:bg-yellow-900/50">
                                <AlertCircle class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-bold text-yellow-900 dark:text-yellow-100 mb-1">
                                Fitur WhatsApp Nonaktif
                            </h3>
                            <p class="text-sm text-yellow-800 dark:text-yellow-200 leading-relaxed">
                                Akun belum aktif. Silakan lakukan pembayaran atau hubungi admin untuk mengaktifkan akun Anda.
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-for="channel in props.channels"
                    :key="channel.id"
                    class="rounded-2xl bg-white p-8 border border-gray-200/50 dark:bg-gray-800 dark:border-gray-700/30"
                    :class="{ 'opacity-50 pointer-events-none': !props.tenantIsActive }"
                >
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-4 mb-6">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-green-50 dark:bg-green-900/20">
                                    <MessageSquare class="h-6 w-6 text-green-600 dark:text-green-400" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate">{{ channel.name }}</h3>
                                    <p class="text-sm text-gray-500 break-words">
                                        {{ channel.channel_account }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Status</p>
                                    <div class="flex items-center gap-2">
                                        <RefreshCw 
                                            v-if="loadingChannels.has(channel.id) || loadingQr.has(channel.id)" 
                                            class="h-4 w-4 animate-spin text-green-600" 
                                        />
                                        <component
                                            v-else
                                            :is="getStatusIcon(channel.session_status)"
                                            :class="getStatusColor(channel.session_status)"
                                            class="h-4 w-4"
                                        />
                                        <span 
                                            class="text-sm font-medium"
                                            :class="channel.session_status === 'connected' ? 'text-green-600 font-bold' : 'text-gray-700 dark:text-gray-300'"
                                        >
                                            {{ loadingChannels.has(channel.id) ? 'Memuat session...' : loadingQr.has(channel.id) ? 'Memuat QR...' : getStatusLabel(channel.session_status) }}
                                        </span>
                                    </div>
                                    <div 
                                        v-if="channel.session_status === 'connected'"
                                        class="mt-1"
                                    >
                                        <p class="text-xs text-green-600 dark:text-green-400">
                                            WhatsApp siap menerima pesan
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Pesan</p>
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">
                                        {{ channel.messages_count }} pesan
                                    </p>
                                    <p v-if="channel.recent_messages && channel.recent_messages.length > 0" class="mt-1 text-xs text-gray-500">
                                        Terbaru: {{ channel.recent_messages[0].created_at_human }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Aktivitas Terakhir</p>
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">
                                        {{ channel.last_activity_at || '-' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Dibuat</p>
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">
                                        {{ new Date(channel.created_at).toLocaleDateString('id-ID') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 sm:ml-4 sm:flex-nowrap sm:flex-shrink-0">
                            <!-- Loading indicator for channel -->
                            <div v-if="loadingChannels.has(channel.id)" class="flex items-center gap-2 px-3 py-2 w-full sm:w-auto">
                                <RefreshCw class="h-4 w-4 animate-spin text-green-600" />
                                <span class="text-xs text-gray-500">Memuat session...</span>
                            </div>
                            
                            <Button
                                v-if="channel.session_id && !loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="loadQrCode(channel)"
                                :disabled="channel.session_status === 'connected' || loadingQr.has(channel.id)"
                                class="flex-1 sm:flex-initial rounded-xl"
                                title="QR Code"
                            >
                                <RefreshCw v-if="loadingQr.has(channel.id)" class="h-4 w-4 animate-spin" />
                                <QrCode v-else class="h-4 w-4" />
                                <span class="ml-2 sm:hidden text-xs">QR</span>
                            </Button>
                            <Button
                                v-if="channel.session_id && !loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="refreshStatus(channel)"
                                class="flex-1 sm:flex-initial rounded-xl"
                                title="Refresh Status"
                            >
                                <RefreshCw class="h-4 w-4" />
                                <span class="ml-2 sm:hidden text-xs">Refresh</span>
                            </Button>
                            <Button
                                v-if="channel.session_id && channel.session_status !== 'connected' && !loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="reconnectSession(channel.id)"
                                class="flex-1 sm:flex-initial rounded-xl"
                                title="Reconnect"
                            >
                                <RefreshCw class="h-4 w-4" />
                                <span class="ml-2 sm:hidden text-xs">Reconnect</span>
                            </Button>
                            <Button
                                v-if="!loadingChannels.has(channel.id)"
                                variant="outline"
                                size="sm"
                                @click="deleteChannel(channel.id)"
                                class="flex-1 sm:flex-initial rounded-xl text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20"
                                title="Hapus"
                            >
                                <Trash2 class="h-4 w-4" />
                                <span class="ml-2 sm:hidden text-xs">Hapus</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QR Code Dialog -->
            <Dialog v-model:open="showQrDialog" @update:open="(open) => { if (!open) { stopQrStatusCheck(); qrCodeUrl = null; } }">
                <DialogContent class="!max-w-[95vw] sm:!max-w-xs !max-h-[90vh] overflow-y-auto rounded-2xl p-0 overflow-hidden bg-white dark:bg-gray-800">
                    <DialogHeader class="p-6 pb-2">
                        <DialogTitle class="text-lg font-bold text-gray-900 dark:text-white text-center">Scan QR Code</DialogTitle>
                        <DialogDescription class="text-sm text-gray-500 text-center">
                            Scan QR code ini dengan WhatsApp di ponsel Anda
                        </DialogDescription>
                    </DialogHeader>
                    <div class="p-6 pt-2 space-y-4">
                        <div class="flex justify-center">
                            <div v-if="qrCodeUrl" class="rounded-xl border-2 border-green-500 p-2 bg-white shadow-lg">
                                <img 
                                    v-if="qrCodeUrl.startsWith('data:image') || qrCodeUrl.startsWith('http')"
                                    :src="qrCodeUrl" 
                                    alt="QR Code"
                                    class="w-48 h-48 object-contain"
                                />
                                <div 
                                    v-else 
                                    class="qr-code-container"
                                    v-html="qrCodeUrl"
                                />
                            </div>
                            <div v-else class="text-center py-8">
                                <p class="text-sm text-gray-500 mb-3">
                                    Memuat QR code...
                                </p>
                                <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-green-600"></div>
                            </div>
                        </div>
                        <div class="rounded-xl bg-blue-50 p-3 dark:bg-blue-900/20">
                            <p class="text-center text-xs font-medium text-blue-900 dark:text-blue-300">
                                📱 Buka WhatsApp di ponsel Anda
                            </p>
                            <p class="text-center text-xs text-blue-700 dark:text-blue-400 mt-1">
                                Lalu scan QR code ini untuk menghubungkan
                            </p>
                            <p class="text-center text-xs text-blue-600 dark:text-blue-400 mt-2 font-bold">
                                ⏳ Dialog akan tertutup otomatis setelah terhubung
                            </p>
                        </div>
                        <div v-if="selectedChannel" class="text-center text-xs text-gray-400">
                            {{ selectedChannel.name }} ({{ selectedChannel.channel_account }})
                        </div>
                        <div class="flex justify-center">
                            <Button variant="outline" size="sm" @click="showQrDialog = false; stopQrStatusCheck(); qrCodeUrl = null;" class="rounded-xl w-full">
                                Tutup
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
