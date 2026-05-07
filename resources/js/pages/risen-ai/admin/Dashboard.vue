<template>
  <div class="p-6 max-w-7xl mx-auto">
    <Head title="SEO Dashboard - Risen AI" />

    <div class="mb-8">
      <h1 class="text-2xl font-bold text-slate-900">Risen AI Dashboard</h1>
      <p class="text-slate-500 text-sm mt-1">Ikhtisar performa dan status Programmatic SEO Finwa.</p>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Total Halaman</div>
        <div class="text-3xl font-extrabold text-slate-900">{{ stats.total }}</div>
        <div class="mt-4 text-xs text-slate-400">Halaman terdaftar di sistem</div>
      </div>
      
      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div class="text-green-500 text-xs font-bold uppercase tracking-wider mb-2">Published</div>
        <div class="text-3xl font-extrabold text-slate-900">{{ stats.published }}</div>
        <div class="mt-4 text-xs text-green-500 font-medium">Terindex atau siap index</div>
      </div>

      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div class="text-amber-500 text-xs font-bold uppercase tracking-wider mb-2">Draft</div>
        <div class="text-3xl font-extrabold text-slate-900">{{ stats.draft }}</div>
        <div class="mt-4 text-xs text-amber-500 font-medium">Menunggu review/audit</div>
      </div>

      <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div class="text-primary-500 text-xs font-bold uppercase tracking-wider mb-2">Avg. Intent Score</div>
        <div class="text-3xl font-extrabold text-slate-900">{{ Math.round(stats.avg_score || 0) }}%</div>
        <div class="mt-4 flex items-center">
          <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
            <div 
              class="bg-primary-500 h-full rounded-full transition-all duration-1000" 
              :style="{ width: (stats.avg_score || 0) + '%' }"
            ></div>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Recent Pages -->
      <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
          <div class="p-6 border-b border-slate-100 flex justify-between items-center">
            <h2 class="font-bold text-slate-900">Halaman Terbaru</h2>
            <Link href="/admin/risen-ai/pages" class="text-primary-600 text-sm font-semibold hover:underline">
              Lihat Semua
            </Link>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr class="bg-slate-50 text-slate-500 text-xs font-bold uppercase tracking-wider">
                  <th class="px-6 py-4">Halaman</th>
                  <th class="px-6 py-4">Intent</th>
                  <th class="px-6 py-4">Status</th>
                  <th class="px-6 py-4">Score</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="page in recent_pages" :key="page.id" class="hover:bg-slate-50 transition-colors">
                  <td class="px-6 py-4">
                    <div class="font-semibold text-slate-800 text-sm">{{ page.title }}</div>
                    <div class="text-slate-400 text-xs mt-0.5 truncate max-w-xs">{{ page.slug }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="intentClass(page.intent)">
                      {{ page.intent }}
                    </span>
                  </td>
                  <td class="px-6 py-4">
                    <span class="flex items-center text-xs font-medium" :class="page.status === 'published' ? 'text-green-600' : 'text-slate-500'">
                      <span class="w-1.5 h-1.5 rounded-full mr-2" :class="page.status === 'published' ? 'bg-green-500' : 'bg-slate-300'"></span>
                      {{ page.status }}
                    </span>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex items-center">
                      <span class="text-xs font-bold mr-2" :class="scoreClass(page.intent_score)">
                        {{ page.intent_score || 0 }}%
                      </span>
                    </div>
                  </td>
                </tr>
                <tr v-if="!recent_pages.length">
                  <td colspan="4" class="px-6 py-12 text-center text-slate-400 text-sm">
                    Belum ada data halaman.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="space-y-6">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
          <h3 class="font-bold text-slate-900 mb-4">Aksi Cepat</h3>
          <div class="grid grid-cols-1 gap-3">
            <Link 
              href="/admin/risen-ai/pages" 
              class="flex items-center p-3 rounded-xl bg-slate-50 border border-slate-100 hover:border-primary-300 hover:bg-primary-50 transition-all group"
            >
              <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center border border-slate-200 mr-4 shadow-sm">
                <svg class="w-5 h-5 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
              </div>
              <span class="font-semibold text-slate-700 text-sm group-hover:text-primary-700">Generate Halaman</span>
            </Link>
            
            <Link 
              href="/admin/risen-ai/data-lake" 
              class="flex items-center p-3 rounded-xl bg-slate-50 border border-slate-100 hover:border-blue-300 hover:bg-blue-50 transition-all group"
            >
              <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center border border-slate-200 mr-4 shadow-sm">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" />
                </svg>
              </div>
              <span class="font-semibold text-slate-700 text-sm group-hover:text-blue-700">Data Lake (Keyword)</span>
            </Link>

            <Link 
              href="/admin/risen-ai/performance" 
              class="flex items-center p-3 rounded-xl bg-slate-50 border border-slate-100 hover:border-green-300 hover:bg-green-50 transition-all group"
            >
              <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center border border-slate-200 mr-4 shadow-sm">
                <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </div>
              <span class="font-semibold text-slate-700 text-sm group-hover:text-green-700">Cek Performa GSC</span>
            </Link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link } from '@inertiajs/vue3'

defineProps({
  stats: Object,
  recent_pages: Array,
})

const intentClass = (intent) => ({
  informational : 'bg-blue-50 text-blue-600 border border-blue-100',
  transactional : 'bg-green-50 text-green-600 border border-green-100',
  comparison    : 'bg-purple-50 text-purple-600 border border-purple-100',
  navigational  : 'bg-amber-50 text-amber-600 border border-amber-100',
}[intent] || 'bg-slate-50 text-slate-600')

const scoreClass = (score) => {
  if (score >= 80) return 'text-green-600'
  if (score >= 60) return 'text-amber-600'
  return 'text-red-600'
}
</script>
