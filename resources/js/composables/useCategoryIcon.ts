/**
 * useCategoryIcon
 *
 * Maps category type strings OR legacy emoji icons to Lucide Vue icons.
 * Single source of truth — import this wherever category icons are rendered.
 */

import {
    Utensils,
    Car,
    Home,
    Zap,
    HeartPulse,
    GraduationCap,
    ShoppingBag,
    Tv2,
    Smartphone,
    FileText,
    TrendingUp,
    CreditCard,
    ArrowDownLeft,
    Handshake,
    Landmark,
    Shield,
    Receipt,
    Heart,
    HardHat,
    Users,
    Baby,
    RefreshCw,
    Shirt,
    Sparkles,
    PartyPopper,
    Wrench,
    Gift,
    PawPrint,
    Cpu,
    Package,
    Settings2,
    ArrowUpRight,
    ArrowDownRight,
    MoreHorizontal,
    // Pendapatan
    Wallet,
    Star,
    BarChart2,
    Building2,
    Key,
    RotateCcw,
    CheckCircle2,
    DollarSign,
    type LucideIcon,
} from 'lucide-vue-next';

/** Map dari category type (snake_case) ke Lucide icon */
const TYPE_MAP: Record<string, LucideIcon> = {
    // ── Pendapatan ──────────────────────────────────────────────
    pendapatan_gaji:           Wallet,
    pendapatan_bonus:          Star,
    pendapatan_investasi:      BarChart2,
    pendapatan_transfer:       ArrowDownLeft,
    pendapatan_usaha:          Building2,
    pendapatan_sewa:           Key,
    pendapatan_refund:         RotateCcw,
    pendapatan_hutang:         ArrowDownLeft,
    pendapatan_terima_piutang: CheckCircle2,
    pendapatan_lainnya:        DollarSign,

    // ── Pengeluaran ─────────────────────────────────────────────
    pengeluaran_makanan:       Utensils,
    pengeluaran_transport:     Car,
    pengeluaran_hunian:        Home,
    pengeluaran_utilitas:      Zap,
    pengeluaran_kesehatan:     HeartPulse,
    pengeluaran_pendidikan:    GraduationCap,
    pengeluaran_belanja:       ShoppingBag,
    pengeluaran_hiburan:       Tv2,
    pengeluaran_pulsa_token:   Smartphone,
    pengeluaran_tagihan:       FileText,
    pengeluaran_investasi:     TrendingUp,
    pengeluaran_pinjaman:      CreditCard,
    pengeluaran_bayar_hutang:  ArrowUpRight,
    pengeluaran_piutang:       Handshake,
    pengeluaran_cicilan:       Landmark,
    pengeluaran_asuransi:      Shield,
    pengeluaran_pajak:         Receipt,
    pengeluaran_donasi:        Heart,
    pengeluaran_gaji:          HardHat,
    pengeluaran_keluarga:      Users,
    pengeluaran_baby:          Baby,
    pengeluaran_langganan:     RefreshCw,
    pengeluaran_pakaian:       Shirt,
    pengeluaran_perawatan_diri:Sparkles,
    pengeluaran_acara:         PartyPopper,
    pengeluaran_otomotif:      Wrench,
    pengeluaran_sosial:        Users,
    pengeluaran_hadiah:        Gift,
    pengeluaran_hewan:         PawPrint,
    pengeluaran_gadget:        Cpu,
    pengeluaran_modal:         Package,
    pengeluaran_operasional:   Settings2,
    pengeluaran_transfer:      ArrowUpRight,
    pengeluaran_lainnya:       MoreHorizontal,

    // ── Internal ────────────────────────────────────────────────
    debit_internal:            ArrowUpRight,
    kredit_internal:           ArrowDownRight,
};

/** Map dari emoji lama ke Lucide icon (backward compat) */
const EMOJI_MAP: Record<string, LucideIcon> = {
    '💰': Wallet,
    '🎁': Gift,
    '📈': BarChart2,
    '📥': ArrowDownLeft,
    '🏪': Building2,
    '🏘️': Key,
    '💸': ArrowUpRight,
    '✅': CheckCircle2,
    '💵': DollarSign,
    '🍽️': Utensils,
    '🚗': Car,
    '🏠': Home,
    '⚡': Zap,
    '🏥': HeartPulse,
    '📚': GraduationCap,
    '🛒': ShoppingBag,
    '🎬': Tv2,
    '📱': Smartphone,
    '📄': FileText,
    '💼': TrendingUp,
    '💳': CreditCard,
    '🤝': Handshake,
    '🏦': Landmark,
    '🛡️': Shield,
    '📊': Receipt,
    '❤️': Heart,
    '👷': HardHat,
    '👨‍👩‍👧‍👦': Users,
    '👶': Baby,
    '🔄': RefreshCw,
    '👕': Shirt,
    '💇': Sparkles,
    '🎊': PartyPopper,
    '🔧': Wrench,
    '🎁': Gift,
    '🐾': PawPrint,
    '📱': Cpu,
    '📦': Package,
    '⚙️': Settings2,
    '📤': ArrowUpRight,
    '📝': MoreHorizontal,
    // Budgets/Index.vue extras
    '🍔': Utensils,
    '🍕': Utensils,
    '🍜': Utensils,
    '🏡': Home,
    '🚙': Car,
    '🚕': Car,
    '🎓': GraduationCap,
    '🛍️': ShoppingBag,
    '💊': HeartPulse,
    '🎮': Tv2,
    '🎯': Tv2,
    '👔': Shirt,
    '✨': Sparkles,
    '☕': Utensils,
    '🎵': Tv2,
    '🏋️': HeartPulse,
    '✈️': Car,
};

/**
 * Resolve icon dari category type atau emoji.
 * @param typeOrEmoji  category type string (e.g. "pengeluaran_makanan") atau emoji (e.g. "🍽️")
 * @returns Lucide icon component
 */
export function getCategoryIcon(typeOrEmoji: string): LucideIcon {
    return TYPE_MAP[typeOrEmoji] ?? EMOJI_MAP[typeOrEmoji] ?? MoreHorizontal;
}

/**
 * Warna background + foreground per kategori (konsisten dengan tema emerald).
 * Returns Tailwind classes.
 */
export function getCategoryColor(type: string): { bg: string; text: string; border: string } {
    if (type.startsWith('pendapatan_')) {
        return { bg: 'bg-emerald-100 dark:bg-emerald-900/30', text: 'text-emerald-600 dark:text-emerald-400', border: 'border-emerald-200 dark:border-emerald-800' };
    }
    const colorMap: Record<string, { bg: string; text: string; border: string }> = {
        pengeluaran_makanan:        { bg: 'bg-orange-100 dark:bg-orange-900/30',  text: 'text-orange-600 dark:text-orange-400',  border: 'border-orange-200 dark:border-orange-800' },
        pengeluaran_transport:      { bg: 'bg-blue-100 dark:bg-blue-900/30',      text: 'text-blue-600 dark:text-blue-400',      border: 'border-blue-200 dark:border-blue-800' },
        pengeluaran_hunian:         { bg: 'bg-amber-100 dark:bg-amber-900/30',    text: 'text-amber-600 dark:text-amber-400',    border: 'border-amber-200 dark:border-amber-800' },
        pengeluaran_utilitas:       { bg: 'bg-yellow-100 dark:bg-yellow-900/30',  text: 'text-yellow-600 dark:text-yellow-400',  border: 'border-yellow-200 dark:border-yellow-800' },
        pengeluaran_kesehatan:      { bg: 'bg-red-100 dark:bg-red-900/30',        text: 'text-red-600 dark:text-red-400',        border: 'border-red-200 dark:border-red-800' },
        pengeluaran_pendidikan:     { bg: 'bg-indigo-100 dark:bg-indigo-900/30',  text: 'text-indigo-600 dark:text-indigo-400',  border: 'border-indigo-200 dark:border-indigo-800' },
        pengeluaran_belanja:        { bg: 'bg-pink-100 dark:bg-pink-900/30',      text: 'text-pink-600 dark:text-pink-400',      border: 'border-pink-200 dark:border-pink-800' },
        pengeluaran_hiburan:        { bg: 'bg-purple-100 dark:bg-purple-900/30',  text: 'text-purple-600 dark:text-purple-400',  border: 'border-purple-200 dark:border-purple-800' },
        pengeluaran_gadget:         { bg: 'bg-cyan-100 dark:bg-cyan-900/30',      text: 'text-cyan-600 dark:text-cyan-400',      border: 'border-cyan-200 dark:border-cyan-800' },
        pengeluaran_hewan:          { bg: 'bg-violet-100 dark:bg-violet-900/30',  text: 'text-violet-600 dark:text-violet-400',  border: 'border-violet-200 dark:border-violet-800' },
        pengeluaran_baby:           { bg: 'bg-fuchsia-100 dark:bg-fuchsia-900/30',text: 'text-fuchsia-600 dark:text-fuchsia-400',border: 'border-fuchsia-200 dark:border-fuchsia-800' },
        pengeluaran_langganan:      { bg: 'bg-violet-100 dark:bg-violet-900/30',  text: 'text-violet-600 dark:text-violet-400',  border: 'border-violet-200 dark:border-violet-800' },
        pengeluaran_bayar_hutang:   { bg: 'bg-amber-100 dark:bg-amber-900/30',    text: 'text-amber-700 dark:text-amber-400',    border: 'border-amber-200 dark:border-amber-800' },
        pengeluaran_piutang:        { bg: 'bg-blue-100 dark:bg-blue-900/30',      text: 'text-blue-700 dark:text-blue-400',      border: 'border-blue-200 dark:border-blue-800' },
        pengeluaran_modal:          { bg: 'bg-amber-100 dark:bg-amber-900/30',    text: 'text-amber-600 dark:text-amber-400',    border: 'border-amber-200 dark:border-amber-800' },
    };
    return colorMap[type] ?? { bg: 'bg-gray-100 dark:bg-gray-700/50', text: 'text-gray-500 dark:text-gray-400', border: 'border-gray-200 dark:border-gray-700' };
}
