<template>
    <section id="harga" class="relative border-t border-gray-200/60 bg-gray-50/30">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="max-w-2xl mx-auto text-center">
                <div
                    class="gradient-badge mx-auto flex w-fit items-center gap-[6px] rounded-full border border-[#f0c9a8] bg-[#fff3e6]/95 p-[8px_16px] backdrop-blur-sm"
                >
                    <Tag class="h-5 w-5 shrink-0 text-[#fa8605]" />
                    <p class="text-xs font-medium text-[#8a4608] md:text-sm">Harga</p>
                </div>
                <h3 class="mt-3 text-3xl md:text-4xl font-bold tracking-tight text-gray-900 leading-tight">Investasi kecil, manfaat besar</h3>
                <p class="mt-3 text-gray-600">Kelola keuangan keluarga dengan lebih mudah dan terorganisir.</p>
            </div>
            
            <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6 max-w-6xl mx-auto">
                <!-- Paket Gratis -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-slate-200/90 bg-gradient-to-br from-slate-50 via-white to-slate-100/60 p-6 transition-all duration-300 hover:border-slate-300/90 md:p-8"
                >
                    <div class="relative">
                        <!-- Header -->
                        <div class="mb-6 text-center">
                            <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-slate-100/90 px-3 py-1 ring-1 ring-slate-200/90">
                                <Gift class="h-3.5 w-3.5 text-slate-600" />
                                <span class="text-xs font-semibold text-slate-700">Gratis Selamanya</span>
                            </div>
                            <h4 class="mb-2 text-xl font-bold tracking-tight text-slate-900">Paket Gratis</h4>
                            <p class="text-sm text-slate-600">Catat keuangan tanpa biaya</p>
                        </div>

                        <!-- Pricing -->
                        <div class="mb-8 text-center">
                            <div class="flex items-baseline justify-center gap-1">
                                <span class="text-4xl font-bold tracking-tight text-slate-800">Rp0</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Selamanya</p>
                        </div>

                        <!-- Value Props -->
                        <div class="mb-8 space-y-3">
                            <div class="flex items-center gap-3">
                                <div class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-200/80">
                                    <Check class="h-3.5 w-3.5 text-slate-700" />
                                </div>
                                <span class="text-sm text-slate-700">50 transaksi / bulan</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-200/80">
                                    <Check class="h-3.5 w-3.5 text-slate-700" />
                                </div>
                                <span class="text-sm text-slate-700">Catat via teks WhatsApp</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-gray-100 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </div>
                                <span class="text-sm text-gray-400 line-through">Scan struk otomatis</span>
                            </div>
                        </div>
                        
                        <!-- CTA -->
                        <a
                            :href="waRegisterUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-900 transition-all duration-200 hover:bg-slate-50"
                        >
                            <MessageCircle class="w-4 h-4" />
                            Daftar via WhatsApp
                        </a>
                    </div>
                </div>

                <!-- Paket Lite (Growth) -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-emerald-200/70 bg-gradient-to-br from-[#f0fdf4]/95 via-white to-emerald-50/40 p-6 ring-2 ring-[var(--fw-500)] transition-all duration-300 hover:-translate-y-0.5 md:p-8"
                >
                    <div class="absolute -right-1 -top-1">
                        <div class="rounded-bl-xl rounded-tr-xl bg-emerald-500 px-3 py-1 text-[10px] font-bold text-white uppercase tracking-wider">
                            Terpopuler
                        </div>
                    </div>

                    <div class="relative">
                        <!-- Header -->
                        <div class="mb-6 text-center">
                            <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-[var(--fw-50)] px-3 py-1 ring-1 ring-[var(--fw-200)]">
                                <Users class="h-3.5 w-3.5 text-[var(--fw-600)]" />
                                <span class="text-xs font-semibold text-[var(--fw-700)]">Paling Pas</span>
                            </div>
                            <h4 class="mb-2 text-xl font-bold text-gray-900">Paket Lite</h4>
                            <p class="text-sm text-gray-600">Terbaik untuk individu</p>
                        </div>
                        
                        <!-- Pricing -->
                        <div class="text-center mb-8">
                            <div class="flex items-baseline justify-center gap-1">
                                <span class="text-4xl font-bold tracking-tight text-[var(--fw-600)]">Rp20rb</span>
                                <span class="text-sm text-gray-500">/bln</span>
                            </div>
                        </div>
                        
                        <!-- Value Props -->
                        <div class="space-y-3 mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-emerald-600" />
                                </div>
                                <span class="text-sm text-gray-700">Transaksi Unlimited</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-emerald-600" />
                                </div>
                                <span class="text-sm text-gray-700">100 Scan Struk/bulan</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-emerald-600" />
                                </div>
                                <span class="text-sm text-gray-700">Hingga 2 nomor WA</span>
                            </div>
                        </div>
                        
                        <!-- CTA -->
                        <a
                            :href="waRegisterUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--fw-600)] px-4 py-3 text-sm font-bold text-white transition-all duration-200 hover:bg-[var(--fw-700)]"
                        >
                            <MessageCircle class="w-4 h-4" />
                            Daftar via WhatsApp
                        </a>
                    </div>
                </div>

                <!-- Paket Pro -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-orange-200/70 bg-gradient-to-br from-orange-50/50 via-white to-orange-100/30 p-6 transition-all duration-300 hover:border-orange-300/90 md:p-8"
                >
                    <div class="relative">
                        <!-- Header -->
                        <div class="mb-6 text-center">
                            <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-orange-50 px-3 py-1 ring-1 ring-orange-200">
                                <Tag class="h-3.5 w-3.5 text-orange-600" />
                                <span class="text-xs font-semibold text-orange-700">Power User</span>
                            </div>
                            <h4 class="mb-2 text-xl font-bold text-gray-900">Paket Pro</h4>
                            <p class="text-sm text-gray-600">Untuk keluarga & UMKM</p>
                        </div>
                        
                        <!-- Pricing -->
                        <div class="text-center mb-8">
                            <div class="flex items-baseline justify-center gap-1">
                                <span class="text-4xl font-bold tracking-tight text-orange-600">Rp45rb</span>
                                <span class="text-sm text-gray-500">/bln</span>
                            </div>
                        </div>
                        
                        <!-- Value Props -->
                        <div class="space-y-3 mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-orange-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-orange-600" />
                                </div>
                                <span class="text-sm text-gray-700">Transaksi Unlimited</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-orange-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-orange-600" />
                                </div>
                                <span class="text-sm text-gray-700">300 Scan Struk/bulan</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-orange-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-orange-600" />
                                </div>
                                <span class="text-sm text-gray-700">Hingga 5 nomor WA</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 rounded-full bg-orange-100 flex items-center justify-center">
                                    <Check class="w-3.5 h-3.5 text-orange-600" />
                                </div>
                                <span class="text-sm text-gray-700 font-medium">Dukungan Prioritas</span>
                            </div>
                        </div>
                        
                        <!-- CTA -->
                        <a
                            :href="waRegisterUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 py-3 text-sm font-bold text-white transition-all duration-200 hover:bg-orange-700"
                        >
                            <MessageCircle class="w-4 h-4" />
                            Daftar via WhatsApp
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Bottom Trust Section -->
            <div class="mt-12 flex flex-wrap items-center justify-center gap-6 text-sm text-gray-500">
                <div class="flex items-center gap-2">
                    <Lock class="w-4 h-4 text-gray-400" />
                    <span>Pembayaran Aman</span>
                </div>
                <div class="flex items-center gap-2">
                    <CreditCard class="w-4 h-4 text-gray-400" />
                    <span>QRIS & Transfer Bank</span>
                </div>
                <div class="flex items-center gap-2">
                    <MessageCircle class="w-4 h-4 text-gray-400" />
                    <span>Support 24/7</span>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Gift, ArrowRight, Users, Check, Lock, CreditCard, MessageCircle, Tag } from 'lucide-vue-next';

const WA_BOT_NUMBER = '6285762000079';
const waRegisterUrl = computed(() => {
    const message = encodeURIComponent('Halo kak, saya mau daftar FinWa. Ketik Daftar untuk mulai ya!');
    return `https://wa.me/${WA_BOT_NUMBER}?text=${message}`;
});
</script>
