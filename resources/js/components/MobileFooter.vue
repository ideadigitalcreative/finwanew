<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { toUrl } from '@/lib/utils';
import { useElasticIndicator } from '@/composables/useElasticIndicator';

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

const {
    container: navRef,
    indicator: indicatorRef,
    visible: indicatorVisible,
} = useElasticIndicator({
    orientation: 'horizontal',
    persistKey: 'mobile-footer',
});
</script>

<template>
    <div
        id="mobile-footer"
        v-if="!isSuperAdmin"
        class="fixed bottom-0 left-0 right-0 z-50 md:hidden font-['Plus_Jakarta_Sans',sans-serif]"
    >
        <div class="relative rounded-t-[24px] border-t border-x border-[#eae8e2] bg-white/95 backdrop-blur-sm dark:border-white/10 dark:bg-[#1b1c19]/95 overflow-hidden">
            <nav ref="navRef" class="bottom-nav flex items-center justify-around px-2">
                <!-- Pil elastis yang meluncur ke menu aktif -->
                <span
                    ref="indicatorRef"
                    class="nav-indicator"
                    :class="indicatorVisible ? 'opacity-100' : 'opacity-0'"
                    aria-hidden="true"
                />
                <Link
                    v-for="(item) in userMenuItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="nav-item group"
                    :class="isActive(item.href) ? 'active' : ''"
                    :data-elastic-active="isActive(item.href) ? 'true' : 'false'"
                    :id="`mobile-menu-${item.title.toLowerCase()}`"
                >
                    <span
                        class="material-symbols-outlined nav-icon select-none transition-all duration-300"
                    >
                        {{ item.materialIcon }}
                    </span>
                    <Transition name="expand-label">
                        <span v-if="isActive(item.href)" class="nav-label text-xs leading-none font-bold ml-1.5 whitespace-nowrap overflow-hidden">
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
    position: relative;
}

/* ── Pil indikator elastis ──
   Posisi & lebarnya diatur useElasticIndicator lewat transform + width. */
.nav-indicator {
    position: absolute;
    top: 8px;
    bottom: 10px;
    left: 0;
    width: 0;
    border-radius: 9999px;
    background-image: linear-gradient(to bottom right, #59c323, #4da818, #3d9014);
    box-shadow: 0 4px 14px rgba(77, 168, 24, 0.3);
    transition: opacity 0.2s ease;
    pointer-events: none;
    will-change: transform, width;
}

/* Catatan: seluruh selector harus berada DI DALAM :global(...).
   Pola `:global(.dark) .foo` membuat Vue membuang bagian `.foo`,
   sehingga aturannya malah menempel ke <html class="dark">. */
:global(.dark #mobile-footer .nav-indicator) {
    box-shadow: 0 4px 14px rgba(77, 168, 24, 0.2);
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
    z-index: 1;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    overflow: hidden;
}

:global(.dark #mobile-footer .nav-item) {
    color: #a29f90;
}

/* ── Hover (non-active) ── */
.nav-item:not(.active):hover {
    background-color: #eef8e7;
    color: #3d9014;
}

:global(.dark #mobile-footer .nav-item:not(.active):hover) {
    background-color: rgba(77, 168, 24, 0.15);
    color: #8fd65f;
}

.nav-item:not(.active):active {
    background-color: #eef8e7;
    transform: scale(0.92);
}

:global(.dark #mobile-footer .nav-item:not(.active):active) {
    background-color: rgba(77, 168, 24, 0.12);
}

/* ── Active state — latarnya dipegang .nav-indicator di belakang ── */
.nav-item.active {
    color: #ffffff;
    padding: 8px 16px;
}

:global(.dark #mobile-footer .nav-item.active) {
    color: #ffffff;
}

.nav-item .nav-icon {
    font-size: 22px;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.15s ease;
}

.nav-item.active .nav-icon {
    color: #ffffff;
    transform: scale(1.08);
}

.nav-item.active .nav-label {
    color: #ffffff;
    font-weight: 800;
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
