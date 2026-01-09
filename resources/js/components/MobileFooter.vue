<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Home, Plus, Wallet, MessageSquare, User } from 'lucide-vue-next';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { toUrl } from '@/lib/utils';

const page = usePage();
const auth = computed(() => page.props.auth as any);
const isSuperAdmin = computed(() => (auth.value?.user as any)?.is_super_admin ?? false);

// Menu utama untuk user biasa - Transaksi di tengah
const userMenuItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: Home,
    },
    {
        title: 'Saldo',
        href: '/balances',
        icon: Wallet,
    },
    {
        title: 'Transaksi',
        href: '/transactions',
        icon: Plus,
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
    <!-- Mobile Footer - Hanya tampil di mobile untuk user biasa -->
    <div
        id="mobile-footer"
        v-if="!isSuperAdmin"
        class="fixed bottom-0 left-0 right-0 z-50 md:hidden"
    >
        <!-- Background dengan cut-out effect -->
        <div class="relative">
            <!-- Main footer background -->
            <div class="footer-with-cutout border-t border-gray-200/80 bg-white/80 backdrop-blur-xl shadow-[0_-4px_20px_-2px_rgba(0,0,0,0.08)] dark:border-gray-800/80 dark:bg-gray-900/80 dark:shadow-[0_-4px_20px_-2px_rgba(0,0,0,0.4)]">
                <nav class="flex items-end justify-between px-4 py-2.5 relative">
                    <!-- Left Group (Dashboard, Saldo) -->
                    <div class="flex flex-1 justify-around">
                        <Link
                            v-for="item in userMenuItems.slice(0, 2)"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            :class="[
                                'mobile-footer-item group relative flex flex-col items-center justify-center gap-1 px-3 py-2 transition-all duration-200 ease-out active:scale-95',
                                isActive(item.href)
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'
                            ]"
                            :id="`mobile-menu-${item.title.toLowerCase()}`"
                        >
                            <div
                                class="relative transition-all duration-200 ease-out"
                                :class="isActive(item.href) ? 'scale-110' : 'scale-95 group-hover:scale-100'"
                            >
                                <component
                                    :is="item.icon"
                                    class="relative z-10 h-5 w-5 transition-all duration-200"
                                    :stroke-width="isActive(item.href) ? 2.5 : 2"
                                />
                            </div>
                            <span
                                class="relative z-10 text-[10px] leading-tight transition-all duration-200"
                                :class="isActive(item.href) ? 'font-bold' : 'font-medium'"
                            >
                                {{ item.title }}
                            </span>
                        </Link>
                    </div>

                    <!-- Center Spacer for Featured Button -->
                    <div class="flex-none w-20"></div>

                    <!-- Right Group (WhatsApp, Profile) -->
                    <div class="flex flex-1 justify-around">
                        <Link
                            v-for="item in userMenuItems.slice(3, 5)"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            :class="[
                                'mobile-footer-item group relative flex flex-col items-center justify-center gap-1 px-3 py-2 transition-all duration-200 ease-out active:scale-95',
                                isActive(item.href)
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'
                            ]"
                            :id="`mobile-menu-${item.title.toLowerCase()}`"
                        >
                            <div
                                class="relative transition-all duration-200 ease-out"
                                :class="isActive(item.href) ? 'scale-110' : 'scale-95 group-hover:scale-100'"
                            >
                                <component
                                    :is="item.icon"
                                    class="relative z-10 h-5 w-5 transition-all duration-200"
                                    :stroke-width="isActive(item.href) ? 2.5 : 2"
                                />
                            </div>
                            <span
                                class="relative z-10 text-[10px] leading-tight transition-all duration-200"
                                :class="isActive(item.href) ? 'font-bold' : 'font-medium'"
                            >
                                {{ item.title }}
                            </span>
                        </Link>
                    </div>
                </nav>
                
                <!-- Safe area untuk iPhone dengan notch -->
                <div class="pb-safe-area-inset-bottom bg-white/80 dark:bg-gray-900/80" />
            </div>

            <!-- Featured Center Button (Transaksi) - Floating Above -->
            <Link
                :href="userMenuItems[2].href"
                class="absolute left-1/2 -translate-x-1/2 -top-5 z-10 transition-all duration-200 active:scale-95"
                :id="`mobile-menu-transaksi`"
            >
                <!-- Main button -->
                <div 
                    class="relative flex h-16 w-16 items-center justify-center rounded-full transition-all duration-300"
                    :class="isActive(userMenuItems[2].href) 
                        ? 'bg-gradient-to-br from-orange-500 to-orange-600 scale-105' 
                        : 'bg-gradient-to-br from-orange-400 to-orange-500 hover:scale-105'"
                >
                    <!-- Inner shine effect -->
                    <div class="absolute inset-0 rounded-full bg-gradient-to-tr from-white/20 to-transparent"></div>
                    
                    <component
                        :is="userMenuItems[2].icon"
                        class="relative z-10 h-7 w-7 text-white drop-shadow-lg"
                        :stroke-width="2.5"
                    />
                </div>
            </Link>
        </div>
    </div>
</template>

<style scoped>
/* Safe area support untuk iPhone dengan notch */
.pb-safe-area-inset-bottom {
    padding-bottom: env(safe-area-inset-bottom, 0);
}

/* Footer dengan cutout untuk FAB */
.footer-with-cutout {
    position: relative;
    overflow: visible;
}
</style>
