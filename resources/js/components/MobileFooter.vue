<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { toUrl } from '@/lib/utils';

interface MobileNavItem {
    title: string;
    subtitle: string;
    href: string;
    materialIcon: string;
}

const page = usePage();
const auth = computed(() => page.props.auth as any);
const isSuperAdmin = computed(() => (auth.value?.user as any)?.is_super_admin ?? false);

// Menu utama untuk user biasa (Profil dihapus)
const userMenuItems: MobileNavItem[] = [
    {
        title: 'Dashboard',
        subtitle: 'Beranda',
        href: dashboard(),
        materialIcon: 'grid_view',
    },
    {
        title: 'Transaksi',
        subtitle: 'Transaksi',
        href: '/transactions',
        materialIcon: 'receipt_long',
    },
    {
        title: 'Saldo',
        subtitle: 'Saldo',
        href: '/balances',
        materialIcon: 'account_balance_wallet',
    },
    {
        title: 'WhatsApp',
        subtitle: 'Chat WA',
        href: '/whatsapp',
        materialIcon: 'forum',
    },
];

const currentUrl = computed(() => page.url);

const isActive = (href: string) => {
    const hrefUrl = toUrl(href);
    // Untuk dashboard, kita check exact match
    if (hrefUrl === '/dashboard') {
        return currentUrl.value === '/dashboard';
    }
    // Untuk yang lain, check starts with
    return currentUrl.value.startsWith(hrefUrl);
};
</script>

<template>
    <div
        id="mobile-footer"
        v-if="!isSuperAdmin"
        class="fixed bottom-0 left-0 right-0 z-50 md:hidden font-['Plus_Jakarta_Sans',sans-serif]"
    >
        <div class="relative rounded-t-[24px] border-t border-x border-[#eae8e2] bg-white/95 backdrop-blur-sm dark:border-white/10 dark:bg-[#1b1c19]/95 overflow-hidden">
            <nav class="bottom-nav flex items-center justify-around px-2">
                <Link
                    v-for="(item) in userMenuItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="nav-item group"
                    :class="isActive(item.href) ? 'active' : ''"
                    :id="`mobile-menu-${item.title.toLowerCase()}`"
                >
                    <span
                        class="material-symbols-outlined nav-icon select-none transition-all duration-300"
                    >
                        {{ item.materialIcon }}
                    </span>
                    <Transition name="expand-label">
                        <span v-if="isActive(item.href)" class="nav-label text-[11px] leading-none font-bold ml-1.5 whitespace-nowrap overflow-hidden">
                            {{ item.subtitle }}
                        </span>
                    </Transition>
                </Link>
            </nav>
            <div class="pb-safe" />
        </div>
    </div>
</template>

<style scoped>
.pb-safe {
    padding-bottom: env(safe-area-inset-bottom, 0);
}

.bottom-nav {
    display: flex;
    align-items: center;
    justify-content: space-around;
    gap: 4px;
    padding: 8px 12px 10px;
}

/* ── Base nav item ── */
.nav-item {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    padding: 8px 12px;
    height: 44px;
    min-width: 44px;
    border-radius: 9999px;
    transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    color: #4d4634;
    text-decoration: none;
    position: relative;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    overflow: hidden;
}

:global(.dark) .nav-item {
    color: #a29f90;
}

/* ── Hover (non-active) ── */
.nav-item:not(.active):hover {
    background-color: #fdf8e7;
    color: #574500;
}

:global(.dark) .nav-item:not(.active):hover {
    background-color: rgba(255, 210, 63, 0.1);
    color: #ffe089;
}

.nav-item:not(.active):active {
    background-color: #fdf8e7;
    transform: scale(0.92);
}

:global(.dark) .nav-item:not(.active):active {
    background-color: rgba(255, 210, 63, 0.08);
}

/* ── Active state — tema kuning dashboard ── */
.nav-item.active {
    background-color: #ffd23f;
    color: #574500;
    padding: 8px 16px;
    box-shadow: 0 4px 14px rgba(255, 210, 63, 0.25);
    transform: scale(1.02);
}

:global(.dark) .nav-item.active {
    background-color: #ffd23f;
    color: #241a00;
    box-shadow: 0 4px 14px rgba(255, 210, 63, 0.15);
}

.nav-item .nav-icon {
    font-size: 22px;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.15s ease;
}

.nav-item.active .nav-icon {
    color: #574500;
    transform: scale(1.08);
}

:global(.dark) .nav-item.active .nav-icon {
    color: #241a00;
}

.nav-item.active .nav-label {
    color: #725a00;
    font-weight: 800;
}

:global(.dark) .nav-item.active .nav-label {
    color: #3d2f00;
}

/* ── Label smooth expand transition saat aktif ── */
.expand-label-enter-active {
    transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.expand-label-leave-active {
    transition: all 0.2s ease-out;
}

.expand-label-enter-from,
.expand-label-leave-to {
    max-width: 0;
    opacity: 0;
    transform: translateX(-6px) scale(0.9);
    margin-left: 0;
}

.expand-label-enter-to,
.expand-label-leave-from {
    max-width: 80px;
    opacity: 1;
    transform: translateX(0) scale(1);
}

/* ── Press feedback ── */
.nav-item.active:active {
    transform: scale(0.96);
}
</style>
