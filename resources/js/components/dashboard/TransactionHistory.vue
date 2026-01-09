<script setup lang="ts">
import { 
    ChevronDown, SlidersHorizontal, MoreHorizontal,
    Youtube, Music, PenTool, CreditCard, ShoppingBag, Coffee,
    Utensils, Car, Signal, Film, Receipt, Zap, Smartphone,
    TrendingUp, TrendingDown, ArrowLeftRight, Pencil, Trash2
} from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import { useSweetAlert } from '@/composables/useSweetAlert';

const { showDeleteConfirm, showSuccess, showError, Swal, showLoading, close } = useSweetAlert();

interface Transaction {
    id: number;
    description: string;
    transaction_date: string;
    type: string;
    amount: number;
    status: string;
    category?: { name: string } | null;
}

interface Props {
    transactions: Transaction[];
}

const props = defineProps<Props>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
};

const formatDate = (date: string) => {
    const d = new Date(date);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return `${d.getDate().toString().padStart(2, '0')} ${months[d.getMonth()]} ${d.getFullYear()}`;
};

const formatTime = (date: string) => {
    const d = new Date(date);
    return `${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
};

const getIcon = (transaction: Transaction) => {
    const cat = transaction.category?.name?.toLowerCase() || '';
    const desc = transaction.description.toLowerCase();
    
    // Category checks
    if (cat.includes('makan') || cat.includes('food') || cat.includes('minum')) return Utensils;
    if (cat.includes('transport') || cat.includes('bensin') || cat.includes('ojek')) return Car;
    if (cat.includes('belanja') || cat.includes('shop')) return ShoppingBag;
    if (cat.includes('hiburan') || cat.includes('entertainment')) return Film;
    if (cat.includes('tagihan') || cat.includes('listrik')) return Zap;
    if (cat.includes('pulsa') || cat.includes('data')) return Smartphone;

    // Description fallback checks
    if (desc.includes('youtube')) return Youtube;
    if (desc.includes('spotify') || desc.includes('music')) return Music;
    if (desc.includes('figma') || desc.includes('design')) return PenTool;
    if (desc.includes('wifi') || desc.includes('internet') || desc.includes('biznet') || desc.includes('indihome')) return Signal;
    if (desc.includes('netflix') || desc.includes('bioskop') || desc.includes('cgv')) return Film;
    if (desc.includes('kopi') || desc.includes('coffee') || desc.includes('starbucks')) return Coffee;
    
    return Receipt;
};

const deleteTransaction = async (id: number) => {
    const confirmed = await showDeleteConfirm('Hapus Transaksi?', 'Data ini akan dihapus secara permanen.');
    
    if (confirmed) {
        showLoading('Menghapus...');
        router.delete(`/transactions/${id}`, {
            preserveScroll: true,
            onSuccess: () => {
                close();
                showSuccess('Berhasil', 'Transaksi telah dihapus');
            },
            onError: () => {
                close();
                showError('Gagal', 'Tidak dapat menghapus transaksi');
            }
        });
    }
};

const editTransaction = async (transaction: Transaction) => {
    const { value: formValues } = await Swal.fire({
        title: 'Edit Transaksi',
        html: `
            <div class="space-y-4 pt-4">
                <div class="text-left px-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wider">Keterangan</label>
                    <input id="swal-input-desc" class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all outline-none" value="${transaction.description}" placeholder="Keterangan transaksi">
                </div>
                <div class="text-left px-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wider">Jumlah (Rp)</label>
                    <input id="swal-input-amount" type="number" class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all outline-none" value="${transaction.amount}" placeholder="Contoh: 50000">
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Simpan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#6b7280',
        customClass: {
            popup: 'rounded-3xl bg-white/90 backdrop-blur-xl border border-white/20 shadow-2xl',
            title: 'text-xl font-bold text-gray-800',
            confirmButton: 'px-8 py-3 rounded-xl font-bold text-white',
            cancelButton: 'px-6 py-3 rounded-xl font-medium'
        },
        focusConfirm: false,
        preConfirm: () => {
            const description = (document.getElementById('swal-input-desc') as HTMLInputElement).value;
            const amount = (document.getElementById('swal-input-amount') as HTMLInputElement).value;
            
            if (!description || !amount) {
                Swal.showValidationMessage('Keterangan dan jumlah harus diisi');
                return false;
            }
            
            return { description, amount };
        }
    });

    if (formValues) {
        showLoading('Menyimpan...');
        router.put(`/transactions/${transaction.id}`, {
            description: formValues.description,
            amount: parseFloat(formValues.amount),
            type: transaction.type, // Maintain existing type
            transaction_date: transaction.transaction_date // Maintain existing date
        }, {
            preserveScroll: true,
            onSuccess: () => {
                close();
                showSuccess('Berhasil', 'Transaksi telah diperbarui');
            },
            onError: () => {
                close();
                showError('Gagal', 'Tidak dapat memperbarui transaksi');
            }
        });
    }
};
</script>

<template>
    <div class="bg-card/60 backdrop-blur-2xl rounded-[13px] p-4 md:p-5 border border-gray-200/50 dark:border-gray-700/30 shadow-xl shadow-primary/5 transition-all duration-500 hover:shadow-2xl hover:shadow-primary/10 animate-fade-in-up" style="animation-delay: 0.5s">
        <div class="flex items-center justify-between mb-4 md:mb-5">
            <h3 class="text-base md:text-lg font-semibold text-foreground">Riwayat Transaksi</h3>
            
            <div class="flex items-center gap-2 md:gap-3">
                <button class="hidden sm:flex items-center gap-1.5 text-sm md:text-base text-foreground font-medium hover:text-primary transition-colors px-4 py-2 rounded-full bg-muted/30 backdrop-blur-sm border border-border/30 hover:border-primary/30 hover:bg-primary/10">
                    Semua Transaksi
                    <ChevronDown class="w-4 h-4" />
                </button>
                <button class="w-9 h-9 md:w-10 md:h-10 rounded-full bg-muted/30 backdrop-blur-sm border border-border/30 flex items-center justify-center hover:bg-primary/10 hover:border-primary/30 transition-all">
                    <SlidersHorizontal class="w-4 h-4 text-muted-foreground" />
                </button>
            </div>
        </div>
        
        <!-- Mobile card view -->
        <div class="md:hidden space-y-3">
            <div 
                v-for="(transaction, index) in transactions" 
                :key="transaction.id"
                class="flex flex-col p-3 bg-muted/20 backdrop-blur-md rounded-2xl border border-border/20 hover:bg-muted/30 hover:border-primary/20 transition-all gap-2"
                :style="{ animationDelay: `${0.6 + index * 0.1}s` }"
            >
                <div class="flex items-center justify-between w-full">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="w-10 h-10 rounded-xl bg-primary/10 backdrop-blur-sm flex items-center justify-center flex-shrink-0 border border-border/20">
                            <component :is="getIcon(transaction)" class="w-5 h-5 text-primary" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground truncate">{{ transaction.description }}</p>
                            <p class="text-xs text-muted-foreground">{{ formatDate(transaction.transaction_date) }}</p>
                        </div>
                    </div>
                    <div class="text-right flex-shrink-0 ml-3">
                        <p class="text-sm font-semibold text-foreground tabular-nums">{{ formatCurrency(transaction.amount) }}</p>
                    </div>
                </div>
                <!-- Mobile Actions -->
                <div class="flex justify-end gap-2 border-t border-border/10 pt-2">
                    <button @click="editTransaction(transaction)" class="p-1.5 rounded-lg hover:bg-primary/10 text-muted-foreground hover:text-primary transition-colors bg-muted/40 backdrop-blur-sm border border-border/20">
                        <Pencil class="w-3.5 h-3.5" />
                    </button>
                    <button @click="deleteTransaction(transaction.id)" class="p-1.5 rounded-lg hover:bg-red-500/10 text-muted-foreground hover:text-red-500 transition-colors bg-muted/40 backdrop-blur-sm border border-border/20">
                        <Trash2 class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Desktop table view -->
        <div class="hidden md:block overflow-x-auto bg-muted/10 backdrop-blur-sm rounded-2xl border border-border/20">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-border/30">
                        <th class="text-left py-4 px-4 text-sm font-medium text-muted-foreground">Nama</th>
                        <th class="text-left py-4 px-4 text-sm font-medium text-muted-foreground">Tanggal</th>
                        <th class="text-left py-4 px-4 text-sm font-medium text-muted-foreground">Jenis</th>
                        <th class="text-left py-4 px-4 text-sm font-medium text-muted-foreground">Jumlah</th>
                        <th class="text-left py-4 px-4 text-sm font-medium text-muted-foreground">Status</th>
                        <th class="text-right py-4 px-4 text-sm font-medium text-muted-foreground">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr 
                        v-for="(transaction, index) in transactions" 
                        :key="transaction.id" 
                        class="border-b border-border/20 last:border-b-0 hover:bg-muted/20 transition-colors"
                        :style="{ animationDelay: `${0.6 + index * 0.1}s` }"
                    >
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-primary/10 backdrop-blur-sm flex items-center justify-center border border-border/20">
                                    <component :is="getIcon(transaction)" class="w-5 h-5 text-primary" />
                                </div>
                                <span class="text-base font-medium text-foreground">{{ transaction.description }}</span>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <span class="text-sm md:text-base text-muted-foreground">{{ formatDate(transaction.transaction_date) }} - {{ formatTime(transaction.transaction_date) }}</span>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-2">
                                <div 
                                    class="w-8 h-8 rounded-xl backdrop-blur-sm flex items-center justify-center border"
                                    :class="transaction.type === 'income' 
                                        ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-500' 
                                        : 'bg-rose-500/10 border-rose-500/20 text-rose-500'"
                                >
                                    <component :is="transaction.type === 'income' ? TrendingUp : TrendingDown" class="w-4 h-4" />
                                </div>
                                <span class="text-sm md:text-base text-foreground capitalize">
                                    {{ transaction.type === 'income' ? 'Pemasukan' : (transaction.type === 'expense' ? 'Pengeluaran' : transaction.type) }}
                                </span>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <span class="text-base font-semibold text-foreground tabular-nums">{{ formatCurrency(transaction.amount) }}</span>
                        </td>
                        <td class="py-4 px-4">
                            <span :class="transaction.status === 'completed' || transaction.status === 'Selesai' ? 'bg-primary/20 text-primary border-primary/20' : 'bg-warning/20 text-warning-foreground border-warning/20'" class="inline-flex px-3 py-1.5 rounded-full text-sm font-medium backdrop-blur-sm border">
                                {{ transaction.status === 'completed' ? 'Selesai' : transaction.status }}
                            </span>
                        </td>
                        <td class="py-4 px-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button @click="editTransaction(transaction)" class="w-8 h-8 rounded-lg bg-muted/30 backdrop-blur-sm border border-border/30 flex items-center justify-center hover:bg-primary/10 hover:border-primary/30 hover:text-primary transition-all text-muted-foreground" title="Edit Transaksi">
                                    <Pencil class="w-4 h-4" />
                                </button>
                                <button @click="deleteTransaction(transaction.id)" class="w-8 h-8 rounded-lg bg-muted/30 backdrop-blur-sm border border-border/30 flex items-center justify-center hover:bg-red-500/10 hover:border-red-500/30 hover:text-red-500 transition-all text-muted-foreground" title="Hapus Transaksi">
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <p v-if="transactions.length === 0" class="text-center text-base text-muted-foreground py-8">
            Belum ada transaksi
        </p>
    </div>
</template>

<style scoped>
.animate-fade-in-up {
    animation: fade-in-up 0.5s ease-out forwards;
    opacity: 0;
}

@keyframes fade-in-up {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
