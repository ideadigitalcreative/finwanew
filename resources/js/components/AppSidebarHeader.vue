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
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-[#eae8e2] dark:border-white/10 bg-white/90 dark:bg-[#1b1c19]/90 backdrop-blur-sm px-6 font-['Plus_Jakarta_Sans',sans-serif] transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1 text-[#4d4634] dark:text-[#a29f90] hover:bg-[#f5f3ee] dark:hover:bg-white/10 rounded-xl" />
            <!-- Logo: visible only on mobile, replaces breadcrumb -->
            <img src="/logo1.png" alt="Logo FinWa Aplikasi Keuangan" class="h-8 w-auto md:hidden">
            <!-- Breadcrumbs: visible only on desktop -->
            <div v-if="breadcrumbs && breadcrumbs.length > 0" class="hidden md:block">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>

        <!-- Right Side: Premium Badge, Dark Mode & Profile -->
        <div class="flex items-center gap-3">
            <!-- Premium Badge (Mobile Visible) -->
            <span
                v-if="isPremium"
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-extrabold bg-[#ffd23f] text-[#725a00]"
                title="Premium Member"
            >
                <Crown class="w-3 h-3 text-[#725a00]" />
                PRO
            </span>

            <!-- Dark Mode Toggle -->
            <button 
                @click="toggleTheme" 
                class="rounded-xl p-2 text-[#4d4634] hover:bg-[#f5f3ee] dark:text-[#a29f90] dark:hover:bg-white/10 transition-colors focus:outline-none"
                :title="appearance === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
            >
                <Sun v-if="appearance === 'dark'" class="h-5 w-5 text-[#ffd23f]" />
                <Moon v-else class="h-5 w-5 text-[#4d4634]" />
            </button>

            <!-- Profile Dropdown -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button class="h-9 w-9 rounded-xl overflow-hidden border border-[#eae8e2] dark:border-white/10 hover:ring-2 hover:ring-[#ffd23f] transition-all focus:outline-none">
                        <img 
                            :src="auth?.user?.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(auth?.user?.name || 'User')}&background=ffd23f&color=725a00`" 
                            alt="Profile" 
                            class="h-full w-full object-cover"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56 rounded-2xl border border-[#eae8e2] dark:border-white/10 bg-white dark:bg-card p-1.5 font-['Plus_Jakarta_Sans',sans-serif]">
                    <DropdownMenuLabel class="font-normal px-3 py-2">
                        <div class="flex flex-col space-y-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-bold text-[#1b1c19] dark:text-foreground leading-none">{{ auth?.user?.name || 'User' }}</p>
                                <span
                                    v-if="isPremium"
                                    class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[9px] font-extrabold bg-[#ffd23f] text-[#725a00]"
                                >
                                    <Crown class="w-2.5 h-2.5" />
                                    PRO
                                </span>
                            </div>
                            <p class="text-xs leading-none text-[#7f7661] dark:text-muted-foreground">{{ auth?.user?.email || '' }}</p>
                        </div>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator class="bg-[#eae8e2] dark:bg-white/10 my-1" />
                    <DropdownMenuItem as-child class="rounded-xl cursor-pointer hover:bg-[#f5f3ee] dark:hover:bg-white/10">
                        <Link href="/settings/profile" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-[#1b1c19] dark:text-foreground">
                            <User class="h-4 w-4 text-[#745c00] dark:text-[#ffd23f]" />
                            <span>Profil Saya</span>
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child class="rounded-xl cursor-pointer hover:bg-[#f5f3ee] dark:hover:bg-white/10">
                        <Link href="/settings/profile" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-[#1b1c19] dark:text-foreground">
                            <Settings class="h-4 w-4 text-[#745c00] dark:text-[#ffd23f]" />
                            <span>Pengaturan</span>
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator class="bg-[#eae8e2] dark:bg-white/10 my-1" />
                    <DropdownMenuItem 
                        @click="logout" 
                        class="flex items-center gap-2 px-3 py-2 rounded-xl cursor-pointer text-[#ad2c4f] hover:bg-[#ffc9d0]/30 font-semibold text-xs"
                    >
                        <LogOut class="h-4 w-4" />
                        <span>Keluar</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>

