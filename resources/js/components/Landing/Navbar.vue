<template>
    <header class="sticky top-0 z-50 backdrop-blur-xl bg-white/80 border-b border-gray-200/60 dark:bg-gray-900/80 dark:border-gray-800/60 transition-all duration-300">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <a href="/" class="flex items-center group transition-transform duration-200 hover:scale-105">
                    <img src="/logo.png" alt="Logo FinWa Aplikasi Keuangan" class="h-9 w-auto" width="120" height="36" loading="eager" decoding="async" fetchpriority="high" />
                </a>
                
                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center gap-8">
                    <a href="#fitur" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors duration-200">Fitur</a>
                    <a href="#cara-pakai" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors duration-200">Cara Pakai</a>
                    <a href="#harga" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors duration-200">Harga</a>
                    <a href="/panduan-umkm" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors duration-200">Panduan UMKM</a>
                    <a href="/changelog" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors duration-200">Changelog</a>
                </nav>
                
                <div class="flex items-center gap-3">
                    <!-- Desktop Login/Dashboard Button -->
                    <Link 
                        v-if="!isAuthenticated"
                        :href="login()" 
                        class="hidden md:inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line></svg>
                        Login
                    </Link>
                    <Link 
                        v-else
                        :href="dashboard()" 
                        class="hidden md:inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
                        Dashboard
                    </Link>
                    
                    <!-- Mobile Hamburger Button -->
                    <button 
                        @click="isMobileMenuOpen = true"
                        class="md:hidden inline-flex items-center justify-center p-2 rounded-lg text-gray-600 hover:text-emerald-600 hover:bg-gray-100 transition-colors duration-200"
                        aria-label="Open menu"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6">
                            <line x1="4" x2="20" y1="12" y2="12"></line>
                            <line x1="4" x2="20" y1="6" y2="6"></line>
                            <line x1="4" x2="20" y1="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Mobile Sidebar Overlay -->
    <Teleport to="body">
        <Transition name="fade">
            <div 
                v-if="isMobileMenuOpen" 
                class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm md:hidden"
                @click="isMobileMenuOpen = false"
            ></div>
        </Transition>
        
        <!-- Mobile Sidebar -->
        <Transition name="slide">
            <div 
                v-if="isMobileMenuOpen"
                class="fixed top-0 right-0 z-50 h-full w-72 bg-white dark:bg-gray-900 shadow-2xl md:hidden"
            >
                <!-- Sidebar Header -->
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-800">
                    <a href="/" class="flex items-center">
                        <img src="/logo.png" alt="Logo FinWa Aplikasi Keuangan" class="h-8 w-auto" width="107" height="32" loading="lazy" decoding="async" />
                    </a>
                    <button 
                        @click="isMobileMenuOpen = false"
                        class="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        aria-label="Close menu"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Sidebar Navigation -->
                <nav class="p-4 space-y-2">
                    <a 
                        href="#fitur" 
                        @click="isMobileMenuOpen = false"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600 transition-colors duration-200"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                            <path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"></path>
                        </svg>
                        <span class="font-medium">Fitur</span>
                    </a>
                    <a 
                        href="#cara-pakai" 
                        @click="isMobileMenuOpen = false"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600 transition-colors duration-200"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                            <path d="M12 17h.01"></path>
                        </svg>
                        <span class="font-medium">Cara Pakai</span>
                    </a>
                    <a 
                        href="#harga" 
                        @click="isMobileMenuOpen = false"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600 transition-colors duration-200"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                        <span class="font-medium">Harga</span>
                    </a>
                    <a 
                        href="/panduan-umkm" 
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600 transition-colors duration-200"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                            <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"></path>
                        </svg>
                        <span class="font-medium">Panduan UMKM</span>
                    </a>
                    <a 
                        href="/changelog" 
                        class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 hover:text-emerald-600 transition-colors duration-200"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">
                            <path d="M12 8v4l3 3"></path>
                            <circle cx="12" cy="12" r="10"></circle>
                        </svg>
                        <span class="font-medium">Changelog</span>
                    </a>
                </nav>
                
                <!-- Sidebar Footer / CTA -->
                <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                    <Link 
                        v-if="!isAuthenticated"
                        :href="login()" 
                        class="flex items-center justify-center gap-2 w-full rounded-xl px-5 py-3 text-sm font-semibold text-white shadow-lg hover:shadow-xl transition-all duration-200"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" x2="3" y1="12" y2="12"></line></svg>
                        Login
                    </Link>
                    <Link 
                        v-else
                        :href="dashboard()" 
                        class="flex items-center justify-center gap-2 w-full rounded-xl px-5 py-3 text-sm font-semibold text-white shadow-lg hover:shadow-xl transition-all duration-200"
                        style="background-color: oklch(0.65 0.19 137.46);"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
                        Buka Dashboard
                    </Link>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { login, dashboard } from '@/routes';

const page = usePage();
const auth = computed(() => page.props.auth);
const isAuthenticated = computed(() => !!auth.value?.user);

const isMobileMenuOpen = ref(false);
</script>

<style scoped>
/* Fade transition for overlay */
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

/* Slide transition for sidebar */
.slide-enter-active,
.slide-leave-active {
    transition: transform 0.3s ease;
}
.slide-enter-from,
.slide-leave-to {
    transform: translateX(100%);
}
</style>

