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

const { isDark, toggleAppearance } = useAppearance();
const page = usePage();
const auth = computed(() => page.props.auth as any);
const isPremium = computed(() => auth.value?.user?.is_premium ?? false);

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <!-- Header bergaya navbar landing: satu elemen hijau gradasi + blur.
         Sudut bawah membulat & sudut cekung di tepi atas dibentuk dengan CSS mask
         (bukan potongan SVG terpisah) supaya tidak ada garis sambungan di tepinya. -->
    <div class="sticky top-0 z-40 shrink-0">
    <header
        class="finwa-nav-shape relative flex h-16 items-center justify-between gap-2 bg-gradient-to-r from-[#59c323]/[0.92] via-[#4da818]/[0.92] to-[#3d9014]/[0.92] backdrop-blur-md px-9 text-white font-['Plus_Jakarta_Sans',sans-serif] transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1 text-white hover:bg-white/15 hover:text-white rounded-xl" />
            <!-- Logo: visible only on mobile, replaces breadcrumb -->
            <span class="flex items-center gap-2 md:hidden">
                <span class="flex size-[30px] shrink-0 items-center justify-center overflow-hidden rounded-[8px] bg-white">
                    <img src="/favicon.svg" alt="" class="size-[30px]" width="30" height="30" />
                </span>
                <span class="text-[15px] font-semibold tracking-[-0.32px] text-white">FinWa</span>
            </span>
            <!-- Breadcrumbs: visible only on desktop -->
            <div v-if="breadcrumbs && breadcrumbs.length > 0" class="hidden md:block [&_*]:!text-white/80 [&_[aria-current=page]]:!text-white">
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
                type="button"
                @click="toggleAppearance($event)"
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-white hover:bg-white/15 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                :title="isDark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap'"
                :aria-label="isDark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap'"
            >
                <Sun v-if="isDark" class="h-5 w-5 text-[#ffd23f]" aria-hidden="true" />
                <Moon v-else class="h-5 w-5 text-white" aria-hidden="true" />
            </button>

            <!-- Profile Dropdown -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button" aria-label="Menu profil" class="h-10 w-10 rounded-xl overflow-hidden border-2 border-white/60 hover:ring-2 hover:ring-white transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#ffd23f]">
                        <img
                            :src="auth?.user?.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(auth?.user?.name || 'User')}&background=ffd23f&color=725a00`"
                            alt=""
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
                                    class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[11px] font-extrabold bg-[#ffd23f] text-[#725a00]"
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
    </div>
</template>

<style scoped>
/* ── Mode gelap: header menyatu dengan latar dashboard (abu-biru tua),
   bukan hijau cerah. Ditulis di sini, bukan sebagai utility `dark:`,
   karena CSS komponen tanpa @layer menang atas utility Tailwind. ── */
:global(.dark .finwa-nav-shape) {
    background-image: linear-gradient(
        to right,
        rgba(26, 32, 41, 0.95),
        rgba(21, 27, 34, 0.95),
        rgba(17, 22, 28, 0.95)
    );
}

/* Garis hijau tipis di tepi bawah: aksen merek yang tersisa di mode gelap.
   Ikut terpotong mask .finwa-nav-shape, jadi mengikuti lengkung sudutnya. */
:global(.dark .finwa-nav-shape::after) {
    content: '';
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    height: 2px;
    background-image: linear-gradient(to right, #59c323, #4da818, #3d9014);
    pointer-events: none;
}

/* Bentuk "notch" navbar landing: bar selebar (100% - 40px) dengan sudut bawah 18px,
   ditambah sudut cekung 20px di kiri/kanan atas yang menyatu dengan tepi halaman. */
.finwa-nav-shape {
    --ear: 20px;
    --r: 18px;
    /* Lapisan mask sengaja saling tumpang (±1–2px) supaya tidak ada celah subpiksel di sambungannya. */
    -webkit-mask:
        linear-gradient(#000 0 0) var(--ear) 0 / calc(100% - 2 * var(--ear)) calc(100% - var(--r) + 1px) no-repeat,
        linear-gradient(#000 0 0) calc(var(--ear) + var(--r) - 1px) 0 / calc(100% - 2 * var(--ear) - 2 * var(--r) + 2px) 100% no-repeat,
        radial-gradient(circle var(--r), #000 calc(var(--r) - 0.5px), transparent var(--r)) var(--ear) 100% / calc(2 * var(--r)) calc(2 * var(--r)) no-repeat,
        radial-gradient(circle var(--r), #000 calc(var(--r) - 0.5px), transparent var(--r)) calc(100% - var(--ear)) 100% / calc(2 * var(--r)) calc(2 * var(--r)) no-repeat,
        radial-gradient(circle var(--ear) at 0 var(--ear), transparent calc(var(--ear) - 0.5px), #000 var(--ear)) 0 0 / calc(var(--ear) + 2px) var(--ear) no-repeat,
        radial-gradient(circle var(--ear) at 100% var(--ear), transparent calc(var(--ear) - 0.5px), #000 var(--ear)) 100% 0 / calc(var(--ear) + 2px) var(--ear) no-repeat;
    mask:
        linear-gradient(#000 0 0) var(--ear) 0 / calc(100% - 2 * var(--ear)) calc(100% - var(--r) + 1px) no-repeat,
        linear-gradient(#000 0 0) calc(var(--ear) + var(--r) - 1px) 0 / calc(100% - 2 * var(--ear) - 2 * var(--r) + 2px) 100% no-repeat,
        radial-gradient(circle var(--r), #000 calc(var(--r) - 0.5px), transparent var(--r)) var(--ear) 100% / calc(2 * var(--r)) calc(2 * var(--r)) no-repeat,
        radial-gradient(circle var(--r), #000 calc(var(--r) - 0.5px), transparent var(--r)) calc(100% - var(--ear)) 100% / calc(2 * var(--r)) calc(2 * var(--r)) no-repeat,
        radial-gradient(circle var(--ear) at 0 var(--ear), transparent calc(var(--ear) - 0.5px), #000 var(--ear)) 0 0 / calc(var(--ear) + 2px) var(--ear) no-repeat,
        radial-gradient(circle var(--ear) at 100% var(--ear), transparent calc(var(--ear) - 0.5px), #000 var(--ear)) 100% 0 / calc(var(--ear) + 2px) var(--ear) no-repeat;
}
</style>

