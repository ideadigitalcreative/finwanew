<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useSweetAlert } from '@/composables/useSweetAlert';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'WhatsApp',
        href: '/whatsapp',
    },
];

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

// Preset nama alias untuk memudahkan pengguna menamai nomor (Suami, Istri, dll.)
const namePresets = ['Suami', 'Istri', 'Anak', 'Orang Tua', 'Usaha', 'Pribadi'];

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
            return 'check_circle';
        case 'connecting':
            return 'sync';
        case 'disconnected':
            return 'cancel';
        case 'error':
            return 'error';
        default:
            return 'sync';
    }
};

const getStatusColor = (status: string | null) => {
    switch (status) {
        case 'connected':
            return 'text-[#006c4f]';
        case 'connecting':
            return 'text-[#745c00]';
        case 'disconnected':
            return 'text-[#ad2c4f]';
        case 'error':
            return 'text-[#ad2c4f]';
        default:
            return 'text-[#7f7661]';
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

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="bg-white dark:bg-[#23231f] flex h-full flex-1 flex-col gap-4 md:gap-6 overflow-x-auto p-4 md:p-6 font-['Plus_Jakarta_Sans',sans-serif]">
            <!-- Header -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <h2 class="text-xl md:text-2xl font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                        <span class="material-symbols-outlined text-[#ffd23f] text-2xl md:text-3xl">forum</span>
                        WhatsApp Channels
                    </h2>
                    <p class="text-xs md:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1">
                        Kelola koneksi WhatsApp untuk chatbot
                    </p>
                </div>

                <a
                    href="https://wa.me/6285159205506"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-all w-full md:w-auto"
                >
                    <span class="material-symbols-outlined text-lg">chat</span>
                    Chat Finwa Bot
                </a>
            </div>

            <!-- User WhatsApp Numbers Section -->
            <div class="rounded-2xl bg-white dark:bg-[#23231f] p-5 md:p-6 border border-[#eae8e2] dark:border-white/10">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
                    <div>
                        <h3 class="text-lg font-bold text-[#1b1c19] dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#006c4f] text-xl">contact_phone</span>
                            Nomor WhatsApp Saya
                        </h3>
                        <p class="text-xs md:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1">
                            Daftarkan nomor WhatsApp Anda untuk routing pesan
                        </p>
                    </div>
                    <div v-if="props.limitInfo" class="text-sm text-[#4d4634]/60 dark:text-white/50">
                        <span class="font-bold text-[#1b1c19] dark:text-white">{{ props.limitInfo.current }}</span> /
                        <span v-if="props.limitInfo.is_unlimited">∞</span>
                        <span v-else>{{ props.limitInfo.limit }}</span>
                        <span class="ml-1">(Paket {{ props.limitInfo.plan_name }})</span>
                    </div>
                </div>

                <!-- Numbers List -->
                <div v-if="props.userWhatsAppNumbers && props.userWhatsAppNumbers.length > 0" class="space-y-3 mb-5">
                    <div
                        v-for="number in props.userWhatsAppNumbers"
                        :key="number.id"
                        class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl border border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee]/50 dark:bg-white/5"
                    >
                        <div class="flex-1 min-w-0 pr-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="`/whatsapp-numbers/${number.id}/transactions`"
                                    class="font-bold text-sm sm:text-base text-[#1b1c19] dark:text-white hover:text-[#006c4f] dark:hover:text-[#51fac1] transition-colors inline-flex items-center gap-1.5"
                                    title="Lihat riwayat transaksi nomor ini"
                                >
                                    <span>{{ number.whatsapp_number }}</span>
                                    <span class="material-symbols-outlined text-[16px] text-[#4d4634]/40 shrink-0">arrow_forward</span>
                                </Link>
                                <span v-if="number.is_primary" class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-[#51fac1] text-[#007152] shrink-0 whitespace-nowrap">
                                    <span class="material-symbols-outlined text-[13px]">star</span>
                                    Utama
                                </span>
                            </div>
                            <p v-if="number.name" class="text-xs sm:text-sm text-[#4d4634]/60 dark:text-white/50 mt-1 truncate">{{ number.name }}</p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0 self-end sm:self-center border-t sm:border-t-0 pt-2 sm:pt-0 border-[#eae8e2] dark:border-white/10 w-full sm:w-auto justify-end">
                            <button
                                v-if="!number.is_primary"
                                @click="setPrimaryNumber(number.id)"
                                class="p-2 rounded-lg text-[#4d4634]/50 hover:text-[#006c4f] hover:bg-[#51fac1]/20 transition-colors"
                                title="Set Utama"
                            >
                                <span class="material-symbols-outlined text-lg">star</span>
                            </button>
                            <button
                                @click="editNumber(number)"
                                class="p-2 rounded-lg text-[#4d4634]/50 hover:text-[#725a00] hover:bg-[#f5f3ee] dark:hover:bg-white/10 transition-colors"
                                title="Edit"
                            >
                                <span class="material-symbols-outlined text-lg">edit</span>
                            </button>
                            <button
                                @click="deleteNumber(number.id)"
                                :disabled="props.userWhatsAppNumbers.length <= 1"
                                class="p-2 rounded-lg text-[#4d4634]/50 hover:text-[#ad2c4f] hover:bg-[#ffc9d0]/30 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                                title="Hapus"
                            >
                                <span class="material-symbols-outlined text-lg">delete</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="text-center py-8 text-[#4d4634]/40 dark:text-white/30 text-sm">
                    <span class="material-symbols-outlined text-3xl mb-2 block">phone_disabled</span>
                    Belum ada nomor WhatsApp terdaftar
                </div>

                <!-- Add Number Button -->
                <div class="flex justify-end">
                    <Dialog v-model:open="showAddNumberDialog">
                        <DialogTrigger as-child>
                            <button
                                :disabled="!props.limitInfo?.can_add"
                                class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            >
                                <span class="material-symbols-outlined text-lg">person_add</span>
                                Tambah Nomor
                            </button>
                        </DialogTrigger>
                        <DialogContent class="!max-w-[95vw] sm:!max-w-md rounded-2xl p-0 overflow-hidden bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10">
                            <DialogHeader class="p-6 pb-0">
                                <DialogTitle class="text-xl font-bold text-[#1b1c19] dark:text-white">{{ editingNumber ? 'Edit Nomor WhatsApp' : 'Tambah Nomor WhatsApp' }}</DialogTitle>
                                <DialogDescription class="text-sm text-[#4d4634]/60 dark:text-white/50">
                                    {{ editingNumber ? 'Ubah informasi nomor WhatsApp' : 'Daftarkan nomor WhatsApp baru untuk routing pesan' }}
                                </DialogDescription>
                            </DialogHeader>
                            <div class="p-6">
                                <form @submit.prevent="saveNumber" class="space-y-4">
                                    <div>
                                        <Label for="whatsapp_number" class="text-sm font-medium text-[#4d4634] dark:text-white/70">Nomor WhatsApp</Label>
                                        <Input
                                            id="whatsapp_number"
                                            v-model="numberForm.whatsapp_number"
                                            type="tel"
                                            placeholder="6281234567890"
                                            required
                                            @input="numberForm.whatsapp_number = numberForm.whatsapp_number.replace(/\D/g, '')"
                                            class="mt-1.5 rounded-xl border-[#eae8e2] dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2 text-sm focus:border-[#ffd23f] focus:ring-[#ffd23f] text-[#1b1c19] dark:text-white"
                                        />
                                        <p class="mt-1 text-xs text-[#4d4634]/50">
                                            Format: 6281234567890 (dengan kode negara 62)
                                        </p>
                                    </div>
                                    <div>
                                        <Label for="number_name" class="text-sm font-medium text-[#4d4634] dark:text-white/70">Nama/Alias (Opsional)</Label>
                                        <Input
                                            id="number_name"
                                            v-model="numberForm.name"
                                            type="text"
                                            placeholder="Nomor Pribadi"
                                            class="mt-1.5 rounded-xl border-[#eae8e2] dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2 text-sm focus:border-[#ffd23f] focus:ring-[#ffd23f] text-[#1b1c19] dark:text-white"
                                        />
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <button
                                                v-for="preset in namePresets"
                                                :key="preset"
                                                type="button"
                                                @click="numberForm.name = preset"
                                                :class="[
                                                    'rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                                    numberForm.name === preset
                                                        ? 'border-[#ffd23f] bg-[#ffd23f] text-[#574500]'
                                                        : 'border-[#eae8e2] dark:border-white/10 bg-[#f5f3ee] dark:bg-white/5 text-[#4d4634]/60 dark:text-white/50 hover:bg-[#eae8e2] dark:hover:bg-white/10'
                                                ]"
                                            >
                                                {{ preset }}
                                            </button>
                                        </div>
                                        <p class="mt-1 text-xs text-[#4d4634]/50">
                                            Pilih preset atau ketik nama sendiri. Nama ini dipakai untuk filter "Oleh" di transaksi.
                                        </p>
                                    </div>
                                    <div class="flex items-center space-x-2 pt-2">
                                        <input
                                            id="is_primary"
                                            v-model="numberForm.is_primary"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-[#eae8e2] text-[#ffd23f] focus:ring-[#ffd23f]"
                                        />
                                        <Label for="is_primary" class="text-sm font-normal cursor-pointer text-[#4d4634] dark:text-white/70">
                                            Set sebagai nomor utama
                                        </Label>
                                    </div>
                                    <div v-if="props.limitInfo && !props.limitInfo.can_add" class="rounded-xl border border-[#ffd9dd] bg-[#ffc9d0]/30 p-3 dark:bg-[#ffc9d0]/10">
                                        <p class="text-xs text-[#ad2c4f]">
                                            Limit nomor WhatsApp sudah tercapai. Paket {{ props.limitInfo.plan_name }} hanya dapat menambahkan maksimal {{ props.limitInfo.limit }} nomor.
                                        </p>
                                    </div>
                                    <div class="flex justify-end gap-3 pt-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            @click="showAddNumberDialog = false; resetNumberForm()"
                                            class="rounded-xl border-[#eae8e2] dark:border-white/10"
                                        >
                                            Batal
                                        </Button>
                                        <button
                                            type="submit"
                                            :disabled="numberForm.processing || !props.limitInfo?.can_add"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-[#725a00] bg-[#ffd23f] hover:bg-[#ffe089] transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            {{ editingNumber ? 'Update' : 'Simpan' }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- Account Status Warning -->
            <div v-if="!props.tenantIsActive" class="rounded-2xl border border-[#ffd9dd] bg-[#ffc9d0]/30 p-5 dark:bg-[#ffc9d0]/10">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 mt-0.5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#ffc9d0] text-[#ad2c4f]">
                            <span class="material-symbols-outlined text-xl">warning</span>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-lg font-bold text-[#ad2c4f] mb-1">
                            Akun Belum Aktif
                        </h3>
                        <p class="text-sm text-[#ad2c4f]/80 leading-relaxed">
                            Akun belum aktif. Silakan lakukan pembayaran atau hubungi admin.
                            Fitur WhatsApp akan tersedia setelah akun Anda diaktifkan oleh admin.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Channels List -->
            <div class="space-y-4 md:space-y-6">
                <!-- Disabled Overlay if Account Not Active -->
                <div
                    v-if="!props.tenantIsActive && props.channels.length > 0"
                    class="rounded-2xl border border-[#ffd9dd] bg-[#ffc9d0]/30 p-5 dark:bg-[#ffc9d0]/10"
                >
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 mt-0.5">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#ffc9d0] text-[#ad2c4f]">
                                <span class="material-symbols-outlined text-xl">block</span>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-bold text-[#ad2c4f] mb-1">
                                Fitur WhatsApp Nonaktif
                            </h3>
                            <p class="text-sm text-[#ad2c4f]/80 leading-relaxed">
                                Akun belum aktif. Silakan lakukan pembayaran atau hubungi admin untuk mengaktifkan akun Anda.
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-for="channel in props.channels"
                    :key="channel.id"
                    class="rounded-2xl bg-white dark:bg-[#23231f] p-5 md:p-6 border border-[#eae8e2] dark:border-white/10"
                    :class="{ 'opacity-50 pointer-events-none': !props.tenantIsActive }"
                >
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 md:gap-6">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-4 mb-5">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#51fac1]/30 text-[#006c4f]">
                                    <span class="material-symbols-outlined text-2xl">forum</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-bold text-[#1b1c19] dark:text-white truncate">{{ channel.name }}</h3>
                                    <p class="text-sm text-[#4d4634]/60 dark:text-white/50 break-words">
                                        {{ channel.channel_account }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/50 dark:text-white/40 mb-2">Status</p>
                                    <div class="flex items-center gap-2">
                                        <span
                                            v-if="loadingChannels.has(channel.id) || loadingQr.has(channel.id)"
                                            class="material-symbols-outlined text-lg animate-spin text-[#745c00]"
                                        >sync</span>
                                        <span
                                            v-else
                                            class="material-symbols-outlined text-lg"
                                            :class="getStatusColor(channel.session_status)"
                                        >{{ getStatusIcon(channel.session_status) }}</span>
                                        <span
                                            class="text-sm font-medium"
                                            :class="channel.session_status === 'connected' ? 'text-[#006c4f] font-bold' : 'text-[#4d4634] dark:text-white/70'"
                                        >
                                            {{ loadingChannels.has(channel.id) ? 'Memuat session...' : loadingQr.has(channel.id) ? 'Memuat QR...' : getStatusLabel(channel.session_status) }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="channel.session_status === 'connected'"
                                        class="mt-1"
                                    >
                                        <p class="text-xs text-[#006c4f]">
                                            WhatsApp siap menerima pesan
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/50 dark:text-white/40 mb-2">Pesan</p>
                                    <p class="text-sm font-bold text-[#1b1c19] dark:text-white">
                                        {{ channel.messages_count }} pesan
                                    </p>
                                    <p v-if="channel.recent_messages && channel.recent_messages.length > 0" class="mt-1 text-xs text-[#4d4634]/50">
                                        Terbaru: {{ channel.recent_messages[0].created_at_human }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/50 dark:text-white/40 mb-2">Aktivitas Terakhir</p>
                                    <p class="text-sm font-bold text-[#1b1c19] dark:text-white">
                                        {{ channel.last_activity_at || '-' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#4d4634]/50 dark:text-white/40 mb-2">Dibuat</p>
                                    <p class="text-sm font-bold text-[#1b1c19] dark:text-white">
                                        {{ new Date(channel.created_at).toLocaleDateString('id-ID') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 sm:ml-4 sm:flex-nowrap sm:flex-shrink-0">
                            <!-- Loading indicator for channel -->
                            <div v-if="loadingChannels.has(channel.id)" class="flex items-center gap-2 px-3 py-2 w-full sm:w-auto">
                                <span class="material-symbols-outlined text-lg animate-spin text-[#745c00]">sync</span>
                                <span class="text-xs text-[#4d4634]/60">Memuat session...</span>
                            </div>

                            <button
                                v-if="channel.session_id && !loadingChannels.has(channel.id)"
                                @click="loadQrCode(channel)"
                                :disabled="channel.session_status === 'connected' || loadingQr.has(channel.id)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium border border-[#eae8e2] dark:border-white/10 text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5 transition-colors flex-1 sm:flex-initial disabled:opacity-40 disabled:cursor-not-allowed"
                                title="QR Code"
                            >
                                <span v-if="loadingQr.has(channel.id)" class="material-symbols-outlined text-lg animate-spin">sync</span>
                                <span v-else class="material-symbols-outlined text-lg">qr_code</span>
                                <span class="sm:hidden text-xs">QR</span>
                            </button>
                            <button
                                v-if="channel.session_id && !loadingChannels.has(channel.id)"
                                @click="refreshStatus(channel)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium border border-[#eae8e2] dark:border-white/10 text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5 transition-colors flex-1 sm:flex-initial"
                                title="Refresh Status"
                            >
                                <span class="material-symbols-outlined text-lg">refresh</span>
                                <span class="sm:hidden text-xs">Refresh</span>
                            </button>
                            <button
                                v-if="channel.session_id && channel.session_status !== 'connected' && !loadingChannels.has(channel.id)"
                                @click="reconnectSession(channel.id)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium border border-[#eae8e2] dark:border-white/10 text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5 transition-colors flex-1 sm:flex-initial"
                                title="Reconnect"
                            >
                                <span class="material-symbols-outlined text-lg">cached</span>
                                <span class="sm:hidden text-xs">Reconnect</span>
                            </button>
                            <button
                                v-if="!loadingChannels.has(channel.id)"
                                @click="deleteChannel(channel.id)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium border border-[#ffc9d0] text-[#ad2c4f] hover:bg-[#ffc9d0]/30 transition-colors flex-1 sm:flex-initial"
                                title="Hapus"
                            >
                                <span class="material-symbols-outlined text-lg">delete</span>
                                <span class="sm:hidden text-xs">Hapus</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QR Code Dialog -->
            <Dialog v-model:open="showQrDialog" @update:open="(open) => { if (!open) { stopQrStatusCheck(); qrCodeUrl = null; } }">
                <DialogContent class="!max-w-[95vw] sm:!max-w-xs !max-h-[90vh] overflow-y-auto rounded-2xl p-0 overflow-hidden bg-white dark:bg-[#23231f] border border-[#eae8e2] dark:border-white/10">
                    <DialogHeader class="p-6 pb-2">
                        <DialogTitle class="text-lg font-bold text-[#1b1c19] dark:text-white text-center">Scan QR Code</DialogTitle>
                        <DialogDescription class="text-sm text-[#4d4634]/60 dark:text-white/50 text-center">
                            Scan QR code ini dengan WhatsApp di ponsel Anda
                        </DialogDescription>
                    </DialogHeader>
                    <div class="p-6 pt-2 space-y-4">
                        <div class="flex justify-center">
                            <div v-if="qrCodeUrl" class="rounded-xl border-2 border-[#ffd23f] p-2 bg-white shadow-lg">
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
                                <p class="text-sm text-[#4d4634]/60 dark:text-white/50 mb-3">
                                    Memuat QR code...
                                </p>
                                <span class="material-symbols-outlined text-3xl animate-spin text-[#ffd23f]">progress_activity</span>
                            </div>
                        </div>
                        <div class="rounded-xl bg-[#51fac1]/20 p-3 dark:bg-[#51fac1]/10">
                            <p class="text-center text-xs font-medium text-[#006c4f] flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">phone_iphone</span>
                                Buka WhatsApp di ponsel Anda
                            </p>
                            <p class="text-center text-xs text-[#006c4f]/80 mt-1">
                                Lalu scan QR code ini untuk menghubungkan
                            </p>
                            <p class="text-center text-xs text-[#006c4f] mt-2 font-bold flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">timer</span>
                                Dialog akan tertutup otomatis setelah terhubung
                            </p>
                        </div>
                        <div v-if="selectedChannel" class="text-center text-xs text-[#4d4634]/40 dark:text-white/30">
                            {{ selectedChannel.name }} ({{ selectedChannel.channel_account }})
                        </div>
                        <div class="flex justify-center">
                            <button @click="showQrDialog = false; stopQrStatusCheck(); qrCodeUrl = null;" class="inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-medium border border-[#eae8e2] dark:border-white/10 text-[#4d4634] dark:text-white/70 hover:bg-[#f5f3ee] dark:hover:bg-white/5 transition-colors w-full">
                                Tutup
                            </button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
