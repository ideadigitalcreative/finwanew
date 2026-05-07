<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Home, ArrowDownUp, Wallet, MessageSquare, User } from 'lucide-vue-next';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { toUrl } from '@/lib/utils';

const page = usePage();
const auth = computed(() => page.props.auth as any);
const isSuperAdmin = computed(() => (auth.value?.user as any)?.is_super_admin ?? false);

// Menu utama untuk user biasa - Dashboard di tengah
const userMenuItems: NavItem[] = [
    {
        title: 'Transaksi',
        href: '/transactions',
        icon: ArrowDownUp,
    },
    {
        title: 'Saldo',
        href: '/balances',
        icon: Wallet,
    },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: Home,
    },
    {
        title: 'WhatsApp',
        href: '/whatsapp',
        icon: MessageSquare,
    },
    {
        title: 'Profile',
        href: '/settings/profile',
        icon: User,
    },
];

const currentUrl = computed(() => page.url);

const isActive = (href: NonNullable<NavItem['href']>) => {
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
        class="fixed bottom-0 left-0 right-0 z-50 md:hidden"
    >
        <div class="border-t border-gray-200/80 bg-white/80 backdrop-blur-xl shadow-[0_-4px_20px_-2px_rgba(0,0,0,0.08)] dark:border-gray-800/80 dark:bg-gray-900/80 dark:shadow-[0_-4px_20px_-2px_rgba(0,0,0,0.4)]">
            <nav class="flex items-center justify-around px-2 py-2">
                <Link
                    v-for="item in userMenuItems"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    :class="[
                        'group relative flex flex-col items-center justify-center gap-0.5 px-2 py-1.5 transition-all duration-200 ease-out active:scale-95',
                        isActive(item.href)
                            ? 'text-green-600 dark:text-green-400'
                            : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'
                    ]"
                    :id="`mobile-menu-${item.title.toLowerCase()}`"
                >
                    <component
                        :is="item.icon"
                        class="h-5 w-5 transition-all duration-200"
                        :stroke-width="isActive(item.href) ? 2.5 : 2"
                    />
                    <span
                        class="text-[10px] leading-tight transition-all duration-200"
                        :class="isActive(item.href) ? 'font-bold' : 'font-medium'"
                    >
                        {{ item.title }}
                    </span>
                </Link>
            </nav>
            <div class="pb-safe-area-inset-bottom" />
        </div>
    </div>
</template>

<style scoped>
.pb-safe-area-inset-bottom {
    padding-bottom: env(safe-area-inset-bottom, 0);
}
</style>
