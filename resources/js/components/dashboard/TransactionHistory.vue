<script setup lang="ts">
import { computed } from 'vue';
import { 
    Youtube, Music, PenTool, ShoppingBag, Coffee,
    Utensils, Car, Signal, Film, Receipt, Zap, Smartphone,
    Pencil, Trash2
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

const recentTransactions = computed(() => {
    return props.transactions.slice(0, 5);
});

const defaultTransactions: Transaction[] = [
    {
        id: 101,
        description: 'Kopi Kenangan Mantan',
        transaction_date: new Date().toISOString(),
        type: 'expense',
        amount: 28000,
        status: 'completed',
        category: { name: 'Jajan' }
    },
    {
        id: 102,
        description: 'Honor Desain Icon Pack',
        transaction_date: new Date().toISOString(),
        type: 'income',
        amount: 1500000,
        status: 'completed',
        category: { name: 'Freelance' }
    },
    {
        id: 103,
        description: 'Belanja Superindo',
        transaction_date: new Date().toISOString(),
        type: 'expense',
        amount: 342500,
        status: 'completed',
        category: { name: 'Kebutuhan' }
    }
];

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0
    }).format(amount);
};

const formatDate = (date: string) => {
    if (!date) return '-';
    // Using string parts instead of new Date().getDate() directly to avoid timezone shifts
    // But since we want to show localized date, the simplest safe way is:
    const d = new Date(date);
    if (isNaN(d.getTime())) return date;
    
    // Check if it's a simple YYYY-MM-DD string
    if (date.length === 10 && /^\d{4}-\d{2}-\d{2}$/.test(date)) {
        const [y, m, day] = date.split('-').map(Number);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return `${day.toString().padStart(2, '0')} ${months[m - 1]} ${y}`;
    }

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
    const escapeHtml = (str: string) => {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    };

    const { value: formValues } = await Swal.fire({
        title: 'Edit Transaksi',
        html: `
            <div class="space-y-4 pt-4">
                <div class="text-left px-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wider">Tipe</label>
                    <select id="swal-input-type" class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all outline-none bg-white">
                        <option value="income" ${transaction.type === 'income' ? 'selected' : ''}>Pemasukan</option>
                        <option value="expense" ${transaction.type === 'expense' ? 'selected' : ''}>Pengeluaran</option>
                        <option value="debit_internal" ${transaction.type === 'debit_internal' ? 'selected' : ''}>Debit Antar Dompet</option>
                        <option value="kredit_internal" ${transaction.type === 'kredit_internal' ? 'selected' : ''}>Kredit Antar Dompet</option>
                    </select>
                </div>
                <div class="text-left px-4">
                    <label class="block text-xs font-semibold text-gray-500 mb-1 uppercase tracking-wider">Keterangan</label>
                    <input id="swal-input-desc" class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all outline-none" value="${escapeHtml(transaction.description)}" placeholder="Keterangan transaksi">
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
            const type = (document.getElementById('swal-input-type') as HTMLSelectElement).value;
            const description = (document.getElementById('swal-input-desc') as HTMLInputElement).value;
            const amount = (document.getElementById('swal-input-amount') as HTMLInputElement).value;
            
            if (!description || !amount) {
                Swal.showValidationMessage('Keterangan dan jumlah harus diisi');
                return false;
            }
            
            return { type, description, amount };
        }
    });

    if (formValues) {
        showLoading('Menyimpan...');
        const d = new Date(transaction.transaction_date);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const formattedDate = `${y}-${m}-${day}`;

        router.put(`/transactions/${transaction.id}`, {
            description: formValues.description,
            amount: parseFloat(formValues.amount),
            type: formValues.type, 
            transaction_date: formattedDate // Consistent with list display
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
    <!-- 9. Transaksi Terakhir / Recent Activity (1:1 CuanCeria Blueprint) -->
    <section class="flex flex-col gap-2 font-['Plus_Jakarta_Sans',sans-serif]">
        <div class="flex items-center justify-between">
            <span class="text-sm md:text-base font-bold text-[#1b1c19] dark:text-foreground">Catatan Teranyar</span>
            <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground font-semibold">Update Live</span>
        </div>

        <div class="flex flex-col gap-2">
            <div
                v-for="transaction in (recentTransactions.length > 0 ? recentTransactions : defaultTransactions)"
                :key="transaction.id"
                class="p-3.5 rounded-2xl bg-white dark:bg-card border border-[#eae8e2] dark:border-border/60 flex items-center justify-between gap-3 active:bg-[#f5f3ee] dark:active:bg-muted transition-colors group"
            >
                <div class="flex items-center gap-3 min-w-0">
                    <div
                        class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-xs"
                        :class="{
                            'bg-[#51fac1] text-[#007152]': transaction.type === 'income' || transaction.type === 'kredit_internal',
                            'bg-[#ffc9d0] text-[#ad2c4f]': transaction.type === 'expense' || transaction.type === 'debit_internal'
                        }"
                    >
                        <span class="material-symbols-outlined text-[20px]">
                            {{ 
                                transaction.type === 'income' ? 'payments' : 
                                transaction.description.toLowerCase().includes('kopi') || transaction.description.toLowerCase().includes('cafe') ? 'local_cafe' : 
                                transaction.description.toLowerCase().includes('belanja') || transaction.description.toLowerCase().includes('super') ? 'shopping_cart' : 
                                'local_cafe' 
                            }}
                        </span>
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-xs md:text-sm font-bold text-[#1b1c19] dark:text-foreground truncate">{{ transaction.description }}</span>
                        <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground flex items-center gap-1 mt-0.5 truncate">
                            <span>{{ transaction.type === 'income' ? 'Bank Utama' : 'GoPay Ceria' }}</span> • <span>{{ formatDate(transaction.transaction_date) }}</span>
                        </span>
                    </div>
                </div>

                <div class="text-right flex-shrink-0">
                    <span
                        class="text-xs md:text-sm font-bold block"
                        :class="{
                            'text-[#006c4f] dark:text-emerald-400': transaction.type === 'income' || transaction.type === 'kredit_internal',
                            'text-[#ad2c4f] dark:text-rose-400': transaction.type === 'expense' || transaction.type === 'debit_internal'
                        }"
                    >
                        {{ transaction.type === 'income' || transaction.type === 'kredit_internal' ? '+' : '-' }}{{ formatCurrency(transaction.amount) }}
                    </span>
                    <span 
                        class="text-[10px] block font-semibold"
                        :class="transaction.type === 'income' ? 'text-[#006c4f] dark:text-emerald-400' : 'text-[#4d4634] dark:text-muted-foreground'"
                    >
                        {{ transaction.category?.name || (transaction.type === 'income' ? 'Freelance' : 'Jajan') }}
                    </span>
                </div>
            </div>
        </div>

        <!-- View All Button -->
        <a href="/transactions" class="w-full py-3 rounded-2xl bg-[#f5f3ee] hover:bg-[#eae8e2] dark:bg-muted/40 dark:hover:bg-muted text-[#1b1c19] dark:text-foreground text-xs font-bold flex items-center justify-center gap-1.5 active:bg-[#eae8e2] transition-colors shadow-xs mt-1 text-center">
            <span>Lihat Riwayat Lengkap</span>
            <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
        </a>
    </section>
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
