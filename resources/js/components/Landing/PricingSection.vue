<template>
    <section id="harga" class="relative border-t border-gray-200/60 bg-gray-50/30">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-20">
            <div class="max-w-2xl mx-auto text-center">
                <p class="text-sm uppercase tracking-wider text-emerald-600 font-bold">Harga</p>
                <h3 class="mt-3 text-3xl md:text-4xl font-bold tracking-tight text-gray-900 leading-tight">Investasi kecil, manfaat besar</h3>
                <p class="mt-3 text-gray-600">Kelola keuangan keluarga dengan lebih mudah dan terorganisir.</p>
            </div>
            
            <div class="mt-12 grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-4xl mx-auto">
                <!-- Paket Ujicoba 30 Hari Gratis -->
                <div class="rounded-2xl ring-1 ring-gray-200 bg-white p-6 md:p-8 hover:ring-cyan-500/30 hover:shadow-xl hover:shadow-cyan-500/5 transition-all duration-300 relative overflow-hidden group">
                    <!-- Background accent -->
                    <div class="absolute -top-20 -right-20 h-40 w-40 rounded-full bg-cyan-500/10 blur-3xl group-hover:bg-cyan-500/20 transition-colors duration-500"></div>
                    
                    <div class="relative">
                        <!-- Header -->
                        <div class="text-center mb-6">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-50 ring-1 ring-cyan-200 mb-4">
                                <Gift class="w-3.5 h-3.5 text-cyan-600" />
                                <span class="text-xs text-cyan-700 font-semibold">Tanpa Kartu Kredit</span>
                            </div>
                            <h4 class="text-2xl md:text-3xl font-bold tracking-tight text-gray-900 mb-2">Coba Dulu</h4>
                            <p class="text-gray-600">Rasakan semua fitur tanpa bayar</p>
                        </div>
                        
                        <!-- Pricing -->
                        <div class="text-center mb-8">
                            <div class="flex items-baseline justify-center gap-2">
                                <span class="text-5xl md:text-6xl font-bold tracking-tight text-cyan-600">Rp0</span>
                            </div>
                            <p class="mt-2 text-sm text-gray-500">Gratis 3 hari</p>
                        </div>
                        
                        <!-- Value Props -->
                        <div class="space-y-3 mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-cyan-100 flex items-center justify-center">
                                    <Check class="w-4 h-4 text-cyan-600" />
                                </div>
                                <span class="text-sm text-gray-700">Akses semua fitur premium</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-cyan-100 flex items-center justify-center">
                                    <Check class="w-4 h-4 text-cyan-600" />
                                </div>
                                <span class="text-sm text-gray-700">Tidak ada biaya tersembunyi</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-cyan-100 flex items-center justify-center">
                                    <Check class="w-4 h-4 text-cyan-600" />
                                </div>
                                <span class="text-sm text-gray-700">Berhenti kapan saja</span>
                            </div>
                        </div>
                        
                        <!-- CTA Button -->
                        <div class="flex flex-col items-center gap-4">
                            <Link href="/checkout?plan=free" class="inline-flex items-center justify-center gap-2 rounded-xl px-8 py-4 text-base font-bold text-white bg-cyan-600 hover:bg-cyan-500 active:bg-cyan-700 transition-all duration-200 shadow-lg shadow-cyan-500/20 hover:shadow-cyan-500/30 hover:-translate-y-0.5 w-full">
                                <ArrowRight class="w-5 h-5" />
                                Mulai Gratis Sekarang
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Paket Lengkap Berbayar -->
                <div class="rounded-2xl ring-2 ring-emerald-500 bg-white p-6 md:p-8 shadow-2xl shadow-emerald-500/10 hover:shadow-emerald-500/20 transition-all duration-300 relative overflow-hidden group transform hover:-translate-y-1">
                    <!-- Popular Badge -->
                    <div class="absolute -top-1 -right-1">
                        <div class="bg-gradient-to-r from-emerald-500 to-teal-500 text-white text-xs font-bold px-4 py-1.5 rounded-bl-xl rounded-tr-xl shadow-lg">
                            ⭐ TERLARIS
                        </div>
                    </div>
                    
                    <!-- Background accent -->
                    <div class="absolute -top-20 -right-20 h-40 w-40 rounded-full bg-emerald-500/10 blur-3xl group-hover:bg-emerald-500/20 transition-colors duration-500"></div>
                    
                    <div class="relative">
                        <!-- Header -->
                        <div class="text-center mb-6">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 ring-1 ring-emerald-200 mb-4">
                                <Users class="w-3.5 h-3.5 text-emerald-600" />
                                <span class="text-xs text-emerald-700 font-semibold">Untuk Semua</span>
                            </div>
                            <h4 class="text-2xl md:text-3xl font-bold tracking-tight text-gray-900 mb-2">Paket Lengkap</h4>
                            <p class="text-gray-600">Cocok untuk pribadi, keluarga, atau UMKM</p>
                        </div>
                        
                        <!-- Pricing with Anchor -->
                        <div class="text-center mb-6">
                            <div class="flex items-baseline justify-center gap-2">
                                <span class="text-5xl md:text-6xl font-bold tracking-tight text-emerald-600">Rp20rb</span>
                                <span class="text-base text-gray-500 font-medium">/bulan</span>
                            </div>
                            
                            <!-- Price Breakdown -->
                            <div class="mt-3 flex items-center justify-center gap-2">
                                <span class="text-sm text-gray-500">Hanya</span>
                                <span class="text-lg font-bold text-emerald-600">Rp667</span>
                                <span class="text-sm text-gray-500">per hari!</span>
                            </div>
                            
                            <!-- Coffee Comparison - Eye Catching -->
                            <div class="mt-4 p-4 rounded-2xl bg-gradient-to-r from-amber-50 via-orange-50 to-amber-50 ring-1 ring-amber-200/60">
                                <div class="flex items-center justify-center gap-3">
                                    <div class="text-3xl">☕</div>
                                    <div class="text-left">
                                        <p class="text-xs font-bold text-amber-700 uppercase tracking-wide">Lebih Hemat!</p>
                                        <p class="text-sm text-gray-700">Lebih murah dari <span class="font-bold text-amber-600">1x ngopi</span> per bulan</p>
                                    </div>
                                </div>
                                <div class="mt-3 flex items-center justify-center gap-3 text-sm">
                                    <span class="line-through text-gray-400">Rp35.000</span>
                                    <span class="text-xs">☕ Kopi Latte</span>
                                    <span class="text-gray-300">|</span>
                                    <span class="font-bold text-emerald-600">Rp20.000</span>
                                    <span class="text-xs">📊 FinWa</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Value Props -->
                        <div class="space-y-3 mb-8">
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <Check class="w-4 h-4 text-emerald-600" />
                                </div>
                                <span class="text-sm text-gray-700">Akses semua fitur premium</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <Check class="w-4 h-4 text-emerald-600" />
                                </div>
                                <span class="text-sm text-gray-700">Dukungan prioritas via WhatsApp</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-6 h-6 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <Check class="w-4 h-4 text-emerald-600" />
                                </div>
                                <span class="text-sm text-gray-700">Update fitur baru gratis</span>
                            </div>
                        </div>
                        
                        <!-- CTA Button -->
                        <div class="flex flex-col items-center gap-4">
                            <Link href="/checkout" class="inline-flex items-center justify-center gap-2 rounded-xl px-8 py-4 text-base font-bold text-white shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/30 hover:-translate-y-0.5 transition-all duration-200 w-full bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500">
                                <Sparkles class="w-5 h-5" />
                                Langganan Sekarang
                            </Link>
                            
                            <!-- Trust Badge -->
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 ring-1 ring-emerald-200/60">
                                <Lock class="w-3.5 h-3.5 text-emerald-600" />
                                <span class="text-xs font-medium text-emerald-700">Data Terenkripsi & Aman</span>
                            </div>
                        </div>
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
import { Link } from '@inertiajs/vue3';
import { Gift, ArrowRight, Users, Sparkles, Check, Lock, CreditCard, MessageCircle } from 'lucide-vue-next';
</script>
