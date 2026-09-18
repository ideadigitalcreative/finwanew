<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <div class="font-['Plus_Jakarta_Sans',sans-serif] p-1">
        <DropdownMenuLabel class="p-0 font-normal">
            <div class="flex items-center gap-2 px-2 py-2 text-left text-sm">
                <UserInfo :user="user" :show-email="true" />
            </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator class="bg-[#eae8e2] dark:bg-white/10 my-1" />
        <DropdownMenuGroup>
            <DropdownMenuItem :as-child="true" class="rounded-xl cursor-pointer hover:bg-[#f5f3ee] dark:hover:bg-white/10">
                <Link class="flex items-center gap-2 w-full px-2.5 py-1.5 text-xs font-semibold text-[#1b1c19] dark:text-foreground" :href="edit()" prefetch as="button">
                    <Settings class="h-4 w-4 text-[#745c00] dark:text-[#ffd23f]" />
                    <span>Pengaturan</span>
                </Link>
            </DropdownMenuItem>
        </DropdownMenuGroup>
        <DropdownMenuSeparator class="bg-[#eae8e2] dark:bg-white/10 my-1" />
        <DropdownMenuItem :as-child="true" class="rounded-xl cursor-pointer text-[#ad2c4f] hover:bg-[#ffc9d0]/30">
            <Link
                class="flex items-center gap-2 w-full px-2.5 py-1.5 text-xs font-semibold text-[#ad2c4f]"
                :href="logout()"
                @click="handleLogout"
                as="button"
                data-test="logout-button"
            >
                <LogOut class="h-4 w-4" />
                <span>Keluar</span>
            </Link>
        </DropdownMenuItem>
    </div>
</template>
