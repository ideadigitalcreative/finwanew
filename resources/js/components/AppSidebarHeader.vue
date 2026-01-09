<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';
import { useAppearance } from '@/composables/useAppearance';
import { Sun, Moon, User, Settings, LogOut, Crown } from 'lucide-vue-next';
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { appearance, updateAppearance } = useAppearance();
const page = usePage();
const auth = computed(() => page.props.auth as any);
const isPremium = computed(() => auth.value?.user?.is_premium ?? false);

const toggleTheme = () => {
    updateAppearance(appearance.value === 'dark' ? 'light' : 'dark');
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- Right Side: Premium Badge, Dark Mode & Profile -->
        <div class="flex items-center gap-3">
            <!-- Premium Badge (Mobile Visible) -->
            <span
                v-if="isPremium"
                class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-bold bg-gradient-to-r from-amber-400 to-yellow-500 text-amber-900 shadow-sm"
                title="Premium Member"
            >
                <Crown class="w-3 h-3" />
                PRO
            </span>

            <!-- Dark Mode Toggle -->
            <button 
                @click="toggleTheme" 
                class="rounded-full p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 transition-colors focus:outline-none"
                :title="appearance === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
            >
                <Sun v-if="appearance === 'dark'" class="h-5 w-5" />
                <Moon v-else class="h-5 w-5" />
            </button>

            <!-- Profile Dropdown -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button class="h-8 w-8 rounded-full overflow-hidden border border-gray-200 dark:border-gray-700 shadow-sm hover:ring-2 hover:ring-emerald-500/50 transition-all focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <img 
                            :src="auth?.user?.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(auth?.user?.name || 'User')}&background=random`" 
                            alt="Profile" 
                            class="h-full w-full object-cover"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <DropdownMenuLabel class="font-normal">
                        <div class="flex flex-col space-y-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium leading-none">{{ auth?.user?.name || 'User' }}</p>
                                <span
                                    v-if="isPremium"
                                    class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-gradient-to-r from-amber-400 to-yellow-500 text-amber-900"
                                >
                                    <Crown class="w-2.5 h-2.5" />
                                    PRO
                                </span>
                            </div>
                            <p class="text-xs leading-none text-muted-foreground">{{ auth?.user?.email || '' }}</p>
                        </div>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link href="/settings/profile" class="flex items-center gap-2 cursor-pointer">
                            <User class="h-4 w-4" />
                            <span>Profil Saya</span>
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link href="/settings/profile" class="flex items-center gap-2 cursor-pointer">
                            <Settings class="h-4 w-4" />
                            <span>Pengaturan</span>
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem 
                        @click="logout" 
                        class="flex items-center gap-2 cursor-pointer bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:!bg-red-100 dark:hover:!bg-red-900/50 hover:!text-red-700 dark:hover:!text-red-300 font-medium"
                    >
                        <LogOut class="h-4 w-4" />
                        <span>Keluar</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>

