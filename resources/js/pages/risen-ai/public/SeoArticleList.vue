<template>
  <div class="bg-slate-50 min-h-screen">
    <Head title="Pusat Artikel Keuangan - Finwa" />

    <header class="bg-white border-b border-slate-200 py-12">
      <div class="max-w-7xl mx-auto px-4 text-center">
        <h1 class="text-4xl font-extrabold text-slate-900 mb-4">Pusat Artikel Finwa</h1>
        <p class="text-slate-600 text-lg max-w-2xl mx-auto">
          Temukan panduan, tips, dan solusi cerdas untuk mengelola keuangan pribadi dan UMKM Anda.
        </p>
      </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-12">
      <div v-if="pages.data.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        <Link 
          v-for="page in pages.data" 
          :key="page.id"
          :href="'/artikel/' + page.slug" 
          class="group bg-white rounded-2xl border border-slate-200 p-6 hover:shadow-xl hover:border-primary-300 transition-all duration-300"
        >
          <div class="flex items-center justify-between mb-4">
            <span 
              class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider"
              :class="intentClass(page.intent)"
            >
              {{ intentLabel(page.intent) }}
            </span>
            <span class="text-slate-400 text-xs">{{ formatDate(page.created_at) }}</span>
          </div>
          <h2 class="text-xl font-bold text-slate-900 group-hover:text-primary-600 transition-colors mb-3 leading-snug">
            {{ page.title }}
          </h2>
          <p class="text-slate-600 text-sm line-clamp-3 mb-6">
            {{ page.meta_description }}
          </p>
          <div class="flex items-center text-primary-600 font-bold text-sm">
            Baca Selengkapnya
            <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
          </div>
        </Link>
      </div>
      
      <div v-else class="text-center py-24 bg-white rounded-3xl border border-dashed border-slate-300">
        <svg class="w-16 h-16 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10l4 4v10a2 2 0 01-2 2z" />
        </svg>
        <h3 class="text-xl font-bold text-slate-900 mb-2">Belum ada artikel</h3>
        <p class="text-slate-500">Silakan kembali lagi nanti untuk konten terbaru.</p>
      </div>

      <!-- Pagination -->
      <div v-if="pages.links.length > 3" class="mt-12 flex justify-center">
        <nav class="flex items-center space-x-2">
          <template v-for="(link, k) in pages.links" :key="k">
            <Link
              v-if="link.url"
              :href="link.url"
              class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
              :class="link.active ? 'bg-primary-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200'"
              v-html="link.label"
            />
            <span
              v-else
              class="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 bg-slate-50 border border-slate-100 cursor-not-allowed"
              v-html="link.label"
            />
          </template>
        </nav>
      </div>
    </main>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'

defineProps({
  pages: Object
})

const intentLabel = (intent) => ({
  informational : 'Panduan',
  transactional : 'Solusi',
  comparison    : 'Perbandingan',
  navigational  : 'Navigasi',
}[intent] || 'Artikel')

const intentClass = (intent) => ({
  informational : 'bg-blue-100 text-blue-700',
  transactional : 'bg-green-100 text-green-700',
  comparison    : 'bg-purple-100 text-purple-700',
  navigational  : 'bg-amber-100 text-amber-700',
}[intent] || 'bg-slate-100 text-slate-700')

const formatDate = (date) =>
  new Date(date).toLocaleDateString('id-ID', {
    month: 'short', day: 'numeric', year: 'numeric'
  })
</script>
