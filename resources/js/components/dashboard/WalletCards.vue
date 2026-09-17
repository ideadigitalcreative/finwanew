<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

interface Wallet {
    account_name: string;
    balance: number;
    currency: string;
    balance_date: string;
}

interface Props {
    wallets: Wallet[];
    period?: string;
}

const props = withDefaults(defineProps<Props>(), {
    wallets: () => [],
    period: 'Bulan ini'
});

const currentIndex = ref(0);

// Initialize currentIndex to last wallet when wallets change
const walletsRef = ref<Wallet[]>([]);
watch(() => props.wallets, (newWallets) => {
    walletsRef.value = newWallets;
    if (newWallets.length > 0) {
        currentIndex.value = newWallets.length - 1;
    }
}, { immediate: true });
const touchStartY = ref(0);
const touchMoveY = ref(0);
const isDragging = ref(false);

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
};

const balanceHidden = ref(localStorage.getItem('finwa_hide_balance') === 'true');
const toggleBalance = () => {
    balanceHidden.value = !balanceHidden.value;
    localStorage.setItem('finwa_hide_balance', balanceHidden.value ? 'true' : 'false');
    window.dispatchEvent(new CustomEvent('balance-visibility-changed', { detail: balanceHidden.value }));
};
const handleVisibilityChange = (e: Event) => {
    balanceHidden.value = (e as CustomEvent).detail;
};
window.addEventListener('balance-visibility-changed', handleVisibilityChange);
const maskedBalance = 'Rp ••••••••';

interface BrandColor {
    from: string;
    via: string;
    accent: string;
    text: string;
    textLight: string;
}

const walletBrandColors: Record<string, BrandColor> = {
    'bca':          { from: '#bfdbfe', via: '#dbeafe', accent: '#3b82f6', text: '#1e3a5f', textLight: '#3b6fa0' },
    'mandiri':      { from: '#bfdbfe', via: '#dbeafe', accent: '#3b82f6', text: '#1e3a5f', textLight: '#3b6fa0' },
    'bri':          { from: '#bfdbfe', via: '#dbeafe', accent: '#3b82f6', text: '#1e3a5f', textLight: '#3b6fa0' },
    'bni':          { from: '#fed7aa', via: '#ffedd5', accent: '#f97316', text: '#7c2d12', textLight: '#b45309' },
    'btn':          { from: '#bbf7d0', via: '#dcfce7', accent: '#22c55e', text: '#14532d', textLight: '#166534' },
    'bsi':          { from: '#bbf7d0', via: '#dcfce7', accent: '#22c55e', text: '#14532d', textLight: '#166534' },
    'cimb':         { from: '#fecdd3', via: '#ffe4e6', accent: '#f43f5e', text: '#881337', textLight: '#be123c' },
    'danamon':      { from: '#fecdd3', via: '#ffe4e6', accent: '#f43f5e', text: '#881337', textLight: '#be123c' },
    'permata':      { from: '#bbf7d0', via: '#dcfce7', accent: '#22c55e', text: '#14532d', textLight: '#166534' },
    'muamalat':     { from: '#bbf7d0', via: '#dcfce7', accent: '#22c55e', text: '#14532d', textLight: '#166534' },
    'gopay':        { from: '#bae6fd', via: '#e0f2fe', accent: '#0ea5e9', text: '#0c4a6e', textLight: '#0369a1' },
    'ovo':          { from: '#ddd6fe', via: '#ede9fe', accent: '#8b5cf6', text: '#4c1d95', textLight: '#6d28d9' },
    'dana':         { from: '#bae6fd', via: '#e0f2fe', accent: '#0ea5e9', text: '#0c4a6e', textLight: '#0369a1' },
    'shopeepay':    { from: '#fecdd3', via: '#ffe4e6', accent: '#f43f5e', text: '#881337', textLight: '#be123c' },
    'linkaja':      { from: '#fecdd3', via: '#ffe4e6', accent: '#f43f5e', text: '#881337', textLight: '#be123c' },
    'jenius':       { from: '#a7f3d0', via: '#d1fae5', accent: '#10b981', text: '#064e3b', textLight: '#047857' },
    'flip':         { from: '#c7d2fe', via: '#e0e7ff', accent: '#6366f1', text: '#312e81', textLight: '#4338ca' },
    'kartu kredit': { from: '#c7d2fe', via: '#e0e7ff', accent: '#6366f1', text: '#312e81', textLight: '#4338ca' },
    'tabungan':     { from: '#a7f3d0', via: '#d1fae5', accent: '#10b981', text: '#064e3b', textLight: '#047857' },
    'investasi':    { from: '#e9d5ff', via: '#f3e8ff', accent: '#a855f7', text: '#581c87', textLight: '#7e22ce' },
    'cash':         { from: '#bbf7d0', via: '#dcfce7', accent: '#22c55e', text: '#14532d', textLight: '#166534' },
    'tunai':        { from: '#bbf7d0', via: '#dcfce7', accent: '#22c55e', text: '#14532d', textLight: '#166534' },
    'dompet utama': { from: '#a7f3d0', via: '#d1fae5', accent: '#10b981', text: '#064e3b', textLight: '#047857' },
};

const fallbackColors: BrandColor[] = [
    { from: '#a7f3d0', via: '#d1fae5', accent: '#10b981', text: '#064e3b', textLight: '#047857' },
    { from: '#fed7aa', via: '#ffedd5', accent: '#f97316', text: '#7c2d12', textLight: '#b45309' },
    { from: '#bae6fd', via: '#e0f2fe', accent: '#0ea5e9', text: '#0c4a6e', textLight: '#0369a1' },
    { from: '#e9d5ff', via: '#f3e8ff', accent: '#a855f7', text: '#581c87', textLight: '#7e22ce' },
    { from: '#fecdd3', via: '#ffe4e6', accent: '#f43f5e', text: '#881337', textLight: '#be123c' },
    { from: '#bae6fd', via: '#e0f2fe', accent: '#0ea5e9', text: '#0c4a6e', textLight: '#0369a1' },
    { from: '#fde68a', via: '#fef3c7', accent: '#f59e0b', text: '#78350f', textLight: '#b45309' }
];

const assignedColors = new Map<string, BrandColor>();
const usedFamilies = new Set<string>();

const colorFamily = (hex: string): string => {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    if (g > r && g > b && g > 100) return 'green';
    if (r > 180 && g < 120 && b < 120) return 'red';
    if (r > 180 && g > 100 && b < 100) return 'orange';
    if (b > r && b > g && b > 120) return 'blue';
    if (r > 120 && b > 120 && g < 100) return 'purple';
    if (r > 200 && g > 180 && b < 120) return 'yellow';
    return 'other';
};

watch(() => props.wallets, () => {
    assignedColors.clear();
    usedFamilies.clear();
}, { immediate: true });

const getBrandColors = (accountName: string, index: number): BrandColor => {
    const key = accountName.toLowerCase().trim();
    if (assignedColors.has(key)) return assignedColors.get(key)!;
    let matched: BrandColor | null = null;
    if (walletBrandColors[key]) {
        matched = walletBrandColors[key];
    } else {
        for (const [brandKey, colors] of Object.entries(walletBrandColors)) {
            if (key.includes(brandKey)) { matched = colors; break; }
        }
    }
    if (matched && !usedFamilies.has(colorFamily(matched.accent))) {
        assignedColors.set(key, matched);
        usedFamilies.add(colorFamily(matched.accent));
        return matched;
    }
    for (const color of fallbackColors) {
        if (!usedFamilies.has(colorFamily(color.accent))) {
            assignedColors.set(key, color);
            usedFamilies.add(colorFamily(color.accent));
            return color;
        }
    }
    const color = fallbackColors[index % fallbackColors.length];
    assignedColors.set(key, color);
    return color;
};

const getWalletGradient = (accountName: string, index: number) => {
    const c = getBrandColors(accountName, index);
    return `linear-gradient(to bottom, ${c.from} 10%, ${c.via} 60%, #ffffff 100%)`;
};

const handleTouchStart = (e: TouchEvent | MouseEvent) => {
    isDragging.value = true;
    if ('touches' in e) {
        touchStartY.value = e.touches[0].clientY;
    } else {
        touchStartY.value = e.clientY;
    }
    touchMoveY.value = touchStartY.value;
};

const handleTouchMove = (e: TouchEvent | MouseEvent) => {
    if (!isDragging.value) return;
    e.preventDefault();
    if ('touches' in e) {
        touchMoveY.value = e.touches[0].clientY;
    } else {
        touchMoveY.value = e.clientY;
    }
};

const handleTouchEnd = () => {
    if (!isDragging.value || props.wallets.length <= 1) {
        isDragging.value = false;
        return;
    }
    isDragging.value = false;
    const diff = touchStartY.value - touchMoveY.value;
    if (Math.abs(diff) > 50) {
        if (diff < 0) {
            nextCard();
        } else {
            prevCard();
        }
    }
};

const nextCard = () => {
    currentIndex.value = (currentIndex.value + 1) % props.wallets.length;
};

const prevCard = () => {
    currentIndex.value = (currentIndex.value - 1 + props.wallets.length) % props.wallets.length;
};

const getCardStyles = (index: number) => {
    const offset = (index - currentIndex.value + props.wallets.length) % props.wallets.length;
    let translateY: number;
    let scale: number;
    let zIndex: number;
    let opacity: number;

    if (offset === 0) {
        translateY = 0;
        scale = 1;
        zIndex = 10;
        opacity = 1;
    } else if (offset === 1) {
        translateY = -12;
        scale = 0.94;
        zIndex = 9;
        opacity = 0.85;
    } else if (offset === 2) {
        translateY = -22;
        scale = 0.88;
        zIndex = 8;
        opacity = 0.55;
    } else if (offset === 3) {
        translateY = -28;
        scale = 0.83;
        zIndex = 7;
        opacity = 0.25;
    } else {
        translateY = -34;
        scale = 0.79;
        zIndex = 6;
        opacity = 0;
    }

    if (isDragging.value && index === currentIndex.value) {
        const dragDiff = touchMoveY.value - touchStartY.value;
        // Geser ke bawah (dragDiff positif = ke bawah, negatif = ke atas)
        translateY = Math.max(-110, Math.min(110, dragDiff));
        scale = 1;
    }

    return {
        transform: `translateY(${translateY}px) scale(${scale})`,
        zIndex,
        opacity
    };
};
</script>

<template>
    <div class="flex flex-col gap-3">
        <!-- Header -->
        <div class="flex items-center justify-between font-['Plus_Jakarta_Sans',sans-serif]">
            <div class="flex items-center gap-1.5">
                <span class="text-sm md:text-base font-bold text-[#1b1c19] dark:text-foreground">Kantong Aktif</span>
                <span class="w-5 h-5 rounded-full bg-[#eae8e2] dark:bg-muted text-[#4d4634] dark:text-muted-foreground text-[10px] font-extrabold flex items-center justify-center">
                    {{ wallets.length }}
                </span>
            </div>
            <span class="text-[10px] font-bold text-[#4d4634] dark:text-muted-foreground flex items-center gap-1">
                <span class="w-1.5 h-1.5 rounded-full bg-[#006c4f]"></span> Sinkron 2m lalu
            </span>
        </div>

        <!-- Snap Carousel / Horizontal Scroll -->
        <div class="flex gap-3 overflow-x-auto pb-2 pt-1 snap-x snap-mandatory scroll-smooth no-scrollbar -mx-4 px-4 md:-mx-0 md:px-0 font-['Plus_Jakarta_Sans',sans-serif]">
            <div
                v-for="(wallet, index) in wallets"
                :key="wallet.account_name"
                class="snap-start flex-shrink-0 w-44 p-3.5 rounded-2xl bg-white dark:bg-card border border-[#eae8e2] dark:border-border/60 flex flex-col justify-between gap-3 transition-all active:scale-[0.98]"
            >
                <div class="flex items-center justify-between">
                    <div 
                        class="w-8 h-8 rounded-xl flex items-center justify-center shadow-xs"
                        :class="[
                            index % 3 === 0 ? 'bg-[#ffe089] text-[#241a00]' : 
                            index % 3 === 1 ? 'bg-[#51fac1] text-[#007152]' : 
                            'bg-[#edc22e] text-[#574500]'
                        ]"
                    >
                        <span class="material-symbols-outlined text-[18px]">
                            {{ 
                                wallet.account_name.toLowerCase().includes('gopay') || wallet.account_name.toLowerCase().includes('ovo') || wallet.account_name.toLowerCase().includes('dana') 
                                    ? 'qr_code_scanner' 
                                    : wallet.account_name.toLowerCase().includes('emas') || wallet.account_name.toLowerCase().includes('invest') 
                                        ? 'stars' 
                                        : 'account_balance' 
                            }}
                        </span>
                    </div>
                    <span 
                        class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                        :class="[
                            index % 3 === 0 ? 'bg-[#f0eee8] text-[#4d4634]' : 
                            index % 3 === 1 ? 'bg-[#51fac1]/30 text-[#007152]' : 
                            'bg-[#ffe089]/40 text-[#574500]'
                        ]"
                    >
                        {{ 
                            wallet.account_name.toLowerCase().includes('gopay') || wallet.account_name.toLowerCase().includes('ovo') || wallet.account_name.toLowerCase().includes('dana') 
                                ? 'E-Wallet' 
                                : wallet.account_name.toLowerCase().includes('emas') || wallet.account_name.toLowerCase().includes('invest') 
                                    ? 'Investasi' 
                                    : 'Harian' 
                        }}
                    </span>
                </div>

                <div>
                    <span class="text-[10px] text-[#4d4634] dark:text-muted-foreground font-semibold block truncate">{{ wallet.account_name }}</span>
                    <span class="text-base font-extrabold text-[#1b1c19] dark:text-foreground block mt-0.5 truncate tracking-tight">
                        <span v-if="!balanceHidden">{{ formatCurrency(wallet.balance) }}</span>
                        <span v-else class="tracking-widest">Rp ••••••••</span>
                    </span>
                </div>
            </div>

            <!-- Empty State if no wallets -->
            <div v-if="wallets.length === 0" class="w-full p-4 rounded-2xl bg-white dark:bg-card border border-dashed border-[#eae8e2] text-center text-[#4d4634] text-xs">
                Belum ada data kantong aktif
            </div>
        </div>
    </div>
</template>

<style scoped>
.font-orbitron {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

.will-change-transform {
}

.transition-none {
    transition-property: none;
}

.transition-all {
    transition-property: transform, opacity;
}

.duration-380 {
    transition-duration: 380ms;
}

@keyframes float-up {
    0%, 100% { transform: translateY(0); opacity: 0.3; }
    50% { transform: translateY(-5px); opacity: 0.8; }
}

.animate-float-up {
    animation: float-up 1.8s ease-in-out infinite;
}
</style>