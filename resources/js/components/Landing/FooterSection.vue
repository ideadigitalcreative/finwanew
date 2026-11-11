<template>
    <footer class="bg-gradient-to-br from-[#59c323] via-[#4da818] to-[#3d9014]">
        <div class="mx-auto max-w-[1200px]">
            <!-- Penutup putih: bagian bawah halaman yang membulat -->
            <div class="relative mx-5 h-10 rounded-b-[18px] bg-white md:mx-10"></div>

            <div class="grid grid-cols-1 gap-10 px-5 pt-14 pb-10 sm:grid-cols-2 md:px-10 lg:grid-cols-[2fr_1fr_1fr_1fr] lg:pt-[100px] lg:pl-20">
                <!-- Kolom brand -->
                <div class="flex flex-col items-start gap-5">
                    <div class="flex items-center gap-[10px]">
                        <span class="flex size-[30px] shrink-0 items-center justify-center overflow-hidden rounded-[8px] bg-white">
                            <img src="/favicon.svg" alt="Logo FinWa" class="size-[30px]" width="30" height="30" loading="lazy" decoding="async" />
                        </span>
                        <span class="text-[15.4px] leading-[19.2px] font-semibold tracking-[-0.32px] text-white">FinWa</span>
                        <span class="text-[15.5px] leading-[19.2px] font-normal tracking-[-0.32px] text-white/75">bot WhatsApp</span>
                    </div>

                    <p class="text-[27.9px] leading-[30px] font-bold tracking-[-1.5px] text-white">
                        Catat sekali.
                        <br />
                        <span class="font-serif text-[30px] font-normal italic">Rapi selamanya.</span>
                    </p>

                    <p class="max-w-[420px] text-[12.9px] leading-[19.6px] tracking-[-0.14px] text-white/85">
                        Catat keuangan via WhatsApp dengan mudah. Aplikasi pencatatan keuangan dari WA yang simpel, cepat, dan akurat untuk UMKM dan
                        freelancer.
                    </p>

                    <a
                        href="https://wa.me/6285159205506?text=Halo%20kak%2C%20saya%20mau%20daftar%20FinWa.%20Ketik%20*Daftar*%20untuk%20mulai%20ya!"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-1.5 rounded-[8px] bg-white px-4 py-2 text-[11.4px] leading-[16.8px] font-semibold text-black transition-colors duration-200 hover:bg-white/90"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-[14px]"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12 12 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 0 1 6.988 2.896 9.83 9.83 0 0 1 2.892 6.994c-.003 5.45-4.437 9.886-9.884 9.886m8.413-18.297A11.8 11.8 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.9 11.9 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.82 11.82 0 0 0 20.465 3.49"></path></svg>
                        Mulai Gratis via WhatsApp
                    </a>

                    <p class="text-[12px] leading-[19.2px] text-white/75">© {{ year }} FinWa. Semua hak cipta dilindungi.</p>
                </div>

                <!-- Menu -->
                <div>
                    <h2 class="text-[16px] leading-[19.2px] font-semibold text-white/75">Menu</h2>
                    <nav class="mt-5 flex flex-col gap-[14px]">
                        <a
                            v-for="item in menuLinks"
                            :key="item.href"
                            :href="item.href"
                            class="text-[14px] leading-[16.8px] font-medium tracking-[-0.28px] text-white transition-colors duration-200 hover:text-white/70"
                        >
                            {{ item.label }}
                        </a>
                    </nav>
                </div>

                <!-- Navigasi -->
                <div>
                    <h2 class="text-[15.4px] leading-[19.2px] font-semibold text-white/75">Navigasi</h2>
                    <nav class="mt-5 flex flex-col gap-[15px]">
                        <a
                            v-for="item in navigationLinks"
                            :key="item.href"
                            :href="item.href"
                            class="text-[14px] leading-[22.4px] text-white transition-colors duration-200 hover:text-white/70"
                        >
                            {{ item.label }}
                        </a>
                    </nav>
                </div>

                <!-- Kontak -->
                <div>
                    <h2 class="text-[15.3px] leading-[19.2px] font-semibold text-white/75">Kontak</h2>
                    <ul class="mt-5 flex flex-col gap-[15px] text-[14px] leading-[22.4px] text-white">
                        <li class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"></path><rect x="2" y="4" width="20" height="16" rx="2"></rect></svg>
                            <a href="mailto:finwa.help@gmail.com" class="transition-colors duration-200 hover:text-white/70">finwa.help@gmail.com</a>
                        </li>
                        <li class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"></path></svg>
                            +62-8524-2766-676
                        </li>
                        <li class="flex items-center gap-2">
                            <Instagram class="size-4 shrink-0" />
                            <a href="https://instagram.com/finwa.web.id" target="_blank" rel="noopener noreferrer" class="transition-colors duration-200 hover:text-white/70">finwa.web.id</a>
                        </li>
                        <li class="flex items-center gap-2">
                            <Facebook class="size-4 shrink-0" />
                            <a href="https://web.facebook.com/profile.php?id=61584259491079" target="_blank" rel="noopener noreferrer" class="transition-colors duration-200 hover:text-white/70">FinWa</a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Wordmark raksasa -->
            <div class="overflow-hidden" aria-hidden="true">
                <p class="translate-y-[8%] text-center text-[clamp(5rem,32vw,390px)] leading-[1.035] font-bold tracking-[-0.032em] whitespace-nowrap text-black/10 select-none">
                    FinWa
                </p>
            </div>
        </div>
    </footer>
</template>

<script setup lang="ts">
import { Facebook, Instagram } from 'lucide-vue-next';

const year = new Date().getFullYear();

const menuLinks = [
    { label: 'Beranda', href: '/' },
    { label: 'Fitur', href: '#fitur' },
    { label: 'Cara Pakai', href: '#cara-pakai' },
    { label: 'Harga', href: '#harga' },
    { label: 'Changelog', href: '/changelog' },
];

const navigationLinks = [
    { label: 'Tentang Kami', href: '/tentang-kami' },
    { label: 'Pusat Bantuan', href: '/bantuan' },
    { label: 'Panduan UMKM', href: '/panduan-umkm' },
    { label: 'Kebijakan Privasi', href: '/privasi' },
    { label: 'Syarat & Ketentuan', href: '/syarat-ketentuan' },
];
</script>
