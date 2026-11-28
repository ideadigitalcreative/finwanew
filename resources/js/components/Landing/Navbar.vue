<template>
    <div class="sticky top-0 z-50">
        <div class="relative mx-auto w-[calc(100%-40px)] md:w-fit">
            <!-- Sudut cekung kiri/kanan agar bar menyatu dengan tepi atas halaman -->
            <!-- Warna sudut = warna tepi gradasi header; digeser 1px agar menumpuk tanpa celah -->
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" class="pointer-events-none absolute top-0 left-[-19px] rotate-90"><path d="M0 0H20C8.954 0 0 8.954 0 20V0Z" fill="#59c323" /></svg>
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" class="pointer-events-none absolute top-0 right-[-19px]"><path d="M0 0H20C8.954 0 0 8.954 0 20V0Z" fill="#3d9014" /></svg>

            <header class="rounded-b-[18px] bg-gradient-to-r from-[#59c323] via-[#4da818] to-[#3d9014] p-[10px] shadow-[0_8px_24px_-12px_rgba(61,144,20,0.55)]">
                <div class="flex max-w-[1200px] items-center justify-between gap-10 md:justify-center">
                    <!-- Brand -->
                    <a href="/" class="flex shrink-0 items-center gap-[10px] overflow-hidden">
                        <span class="flex size-[30px] shrink-0 items-center justify-center overflow-hidden rounded-[8px] bg-white">
                            <img
                                src="/favicon.svg"
                                alt="Logo FinWa Aplikasi Keuangan"
                                class="size-[30px]"
                                width="30"
                                height="30"
                                loading="eager"
                                decoding="async"
                                fetchpriority="high"
                            />
                        </span>
                        <span class="text-[15.4px] leading-[19.2px] font-semibold tracking-[-0.32px] whitespace-nowrap text-white">FinWa</span>
                    </a>

                    <!-- Desktop Navigation -->
                    <nav class="hidden items-center justify-end gap-5 md:flex">
                        <a
                            v-for="item in navItems"
                            :key="item.href"
                            :href="item.href"
                            class="text-[14px] leading-[16.8px] font-medium tracking-[-0.28px] whitespace-nowrap text-white/85 transition-colors duration-200 hover:text-white"
                        >
                            {{ item.label }}
                        </a>
                    </nav>

                    <div class="flex shrink-0 items-center gap-2">
                        <!-- Desktop Login/Dashboard Button -->
                        <Link
                            v-if="!isAuthenticated"
                            :href="login()"
                            class="hidden items-center justify-center gap-1.5 rounded-[8px] bg-white px-4 py-2 text-[13px] leading-[16.8px] font-semibold whitespace-nowrap text-black transition-colors duration-200 hover:bg-white/90 md:inline-flex"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-[15px]"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line></svg>
                            Login
                        </Link>
                        <Link
                            v-else
                            :href="dashboard()"
                            class="hidden items-center justify-center gap-1.5 rounded-[8px] bg-white px-4 py-2 text-[13px] leading-[16.8px] font-semibold whitespace-nowrap text-black transition-colors duration-200 hover:bg-white/90 md:inline-flex"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-[15px]"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
                            Dashboard
                        </Link>

                        <!-- Mobile Hamburger Button -->
                        <button
                            @click="isMobileMenuOpen = !isMobileMenuOpen"
                            class="inline-flex size-[30px] items-center justify-center rounded-[8px] text-white transition-colors duration-200 hover:bg-white/10 md:hidden"
                            :aria-expanded="isMobileMenuOpen"
                            aria-controls="landing-mobile-menu"
                            :aria-label="isMobileMenuOpen ? 'Tutup menu' : 'Buka menu'"
                        >
                            <svg v-if="!isMobileMenuOpen" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5">
                                <line x1="4" x2="20" y1="12" y2="12"></line>
                                <line x1="4" x2="20" y1="6" y2="6"></line>
                                <line x1="4" x2="20" y1="18" y2="18"></line>
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5">
                                <path d="M18 6 6 18"></path>
                                <path d="m6 6 12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Mobile Menu -->
                <Transition name="menu">
                    <div v-if="isMobileMenuOpen" id="landing-mobile-menu" class="mt-2 border-t border-white/20 pt-2 md:hidden">
                        <nav class="flex flex-col">
                            <a
                                v-for="item in navItems"
                                :key="item.href"
                                :href="item.href"
                                @click="isMobileMenuOpen = false"
                                class="rounded-[8px] px-2 py-3 text-[14px] leading-[16.8px] font-medium tracking-[-0.28px] text-white/85 transition-colors duration-200 hover:bg-white/10 hover:text-white"
                            >
                                {{ item.label }}
                            </a>
                        </nav>

                        <Link
                            v-if="!isAuthenticated"
                            :href="login()"
                            class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-[8px] bg-white px-4 py-2.5 text-[13px] leading-[16.8px] font-semibold text-black transition-colors duration-200 hover:bg-white/90"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-[15px]"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line></svg>
                            Login
                        </Link>
                        <Link
                            v-else
                            :href="dashboard()"
                            class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-[8px] bg-white px-4 py-2.5 text-[13px] leading-[16.8px] font-semibold text-black transition-colors duration-200 hover:bg-white/90"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-[15px]"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
                            Buka Dashboard
                        </Link>
                    </div>
                </Transition>
            </header>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { login, dashboard } from '@/routes';

const page = usePage();
const auth = computed(() => page.props.auth);
const isAuthenticated = computed(() => !!auth.value?.user);

const navItems = [
    { label: 'Fitur', href: '#fitur' },
    { label: 'Cara Pakai', href: '#cara-pakai' },
    { label: 'Harga', href: '#harga' },
    { label: 'Panduan UMKM', href: '/panduan-umkm' },
    { label: 'Changelog', href: '/changelog' },
];

const isMobileMenuOpen = ref(false);

// Tutup menu saat layar melebar ke breakpoint desktop
let desktopQuery: MediaQueryList | null = null;
function closeOnDesktop(e: MediaQueryListEvent | MediaQueryList) {
    if (e.matches) isMobileMenuOpen.value = false;
}

onMounted(() => {
    desktopQuery = window.matchMedia('(min-width: 768px)');
    desktopQuery.addEventListener('change', closeOnDesktop);
});

onUnmounted(() => {
    desktopQuery?.removeEventListener('change', closeOnDesktop);
});
</script>

<style scoped>
.menu-enter-active,
.menu-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s cubic-bezier(0.25, 0.82, 0.2, 1);
}
.menu-enter-from,
.menu-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
    .menu-enter-active,
    .menu-leave-active {
        transition: none;
    }
}
</style>
