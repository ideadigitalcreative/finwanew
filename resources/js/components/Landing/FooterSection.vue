<template>
    <footer class="border-t border-neutral-200/60">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center gap-3">
                        <img src="/logo.png" alt="FinWa - Catat Keuangan via WhatsApp" class="h-8 w-auto" />
                        
                    </div>
                    <p class="mt-3 text-sm text-neutral-500">Catat keuangan via WhatsApp dengan mudah. Aplikasi pencatatan keuangan dari WA yang simpel, cepat, dan akurat.</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-neutral-900">Produk</p>
                    <ul class="mt-3 space-y-2 text-sm text-neutral-500">
                        <li><a class="hover:text-neutral-900 transition-colors" href="#fitur">Fitur</a></li>
                        <li><a class="hover:text-neutral-900 transition-colors" href="#cara-pakai">Cara Pakai</a></li>
                        <li><a class="hover:text-neutral-900 transition-colors" href="#harga">Harga</a></li>
                    </ul>
                </div>
                <div>
                    <p class="text-sm font-medium text-neutral-900">Bantuan</p>
                    <ul class="mt-3 space-y-2 text-sm text-neutral-500">
                        <li><a class="hover:text-neutral-900 transition-colors" href="/bantuan">Pusat Bantuan</a></li>
                        <li><a class="hover:text-neutral-900 transition-colors" href="/panduan-umkm">Panduan UMKM</a></li>
                        <li><a class="hover:text-neutral-900 transition-colors" href="/changelog">Changelog</a></li>
                        <li><a class="hover:text-neutral-900 transition-colors" href="/privasi">Kebijakan Privasi</a></li>
                        <li><a class="hover:text-neutral-900 transition-colors" href="/syarat-ketentuan">Syarat & Ketentuan</a></li>
                    </ul>
                </div>
                <div>
                    <p class="text-sm font-medium text-neutral-900">Kontak</p>
                    <ul class="mt-3 space-y-2 text-sm text-neutral-500">
                        <li class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"></path><rect x="2" y="4" width="20" height="16" rx="2"></rect></svg>
                            finwa.help@gmail.com
                        </li>
                        <li class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"></path></svg>
                            +62-8524-2766-676
                        </li>
                        <li class="flex items-center gap-2">
                            <Instagram class="w-4 h-4" />
                            <a href="https://instagram.com/finwa.web.id" target="_blank" class="hover:text-neutral-900 transition-colors">finwa.web.id</a>
                        </li>
                        <li class="flex items-center gap-2">
                            <Facebook class="w-4 h-4" />
                            <a href="https://web.facebook.com/profile.php?id=61584259491079" target="_blank" class="hover:text-neutral-900 transition-colors">FinWa</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="mt-8 pt-6 border-t border-neutral-200/60 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-xs text-neutral-500">© 2025 FinWa. Semua hak cipta dilindungi.</p>
                <div class="flex items-center gap-4 text-xs text-neutral-500">
                    <a href="#" class="hover:text-neutral-900 transition-colors flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path></svg>
                        Keamanan
                    </a>
                    <a href="#" class="hover:text-neutral-900 transition-colors flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg>
                        Bahasa
                    </a>
                </div>
            </div>
        </div>
    </footer>
</template>

<script setup lang="ts">
import { Instagram, Facebook } from 'lucide-vue-next';
</script>

