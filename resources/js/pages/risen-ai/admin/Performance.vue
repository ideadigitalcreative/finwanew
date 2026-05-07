<template>
  <div class="p-6 max-w-7xl mx-auto">
    <Head title="Performance - Risen AI" />

    <div class="mb-8 flex justify-between items-end">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Performance Monitor</h1>
        <p class="text-slate-500 text-sm mt-1">Pantau performa SEO halaman Risen AI dari Google Search Console.</p>
      </div>
      <button 
        @click="syncGsc"
        :disabled="syncing"
        class="bg-green-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-green-700 transition-colors shadow-sm flex items-center disabled:opacity-50"
      >
        <svg v-if="syncing" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        {{ syncing ? 'Syncing GSC...' : 'Sinkronisasi GSC' }}
      </button>
    </div>

    <!-- Performance Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 text-slate-500 text-xs font-bold uppercase tracking-wider">
              <th class="px-6 py-4">Halaman</th>
              <th class="px-6 py-4">Impressions</th>
              <th class="px-6 py-4">Clicks</th>
              <th class="px-6 py-4">CTR</th>
              <th class="px-6 py-4">Avg. Position</th>
              <th class="px-6 py-4">Tanggal</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50 transition-colors">
              <td class="px-6 py-4">
                <div class="font-bold text-slate-900 text-sm">{{ log.page?.title || 'Unknown' }}</div>
                <div class="text-slate-400 text-xs mt-0.5 truncate max-w-xs">{{ log.page?.slug }}</div>
              </td>
              <td class="px-6 py-4 text-sm text-slate-700 font-medium">{{ log.impressions }}</td>
              <td class="px-6 py-4 text-sm text-slate-700 font-medium">{{ log.clicks }}</td>
              <td class="px-6 py-4">
                <span class="text-sm font-bold" :class="ctrColor(log.ctr)">{{ log.ctr }}%</span>
              </td>
              <td class="px-6 py-4 text-sm font-bold text-slate-700">{{ log.avg_position }}</td>
              <td class="px-6 py-4 text-xs text-slate-500">{{ log.recorded_date }}</td>
            </tr>
            <tr v-if="!logs.data.length">
              <td colspan="6" class="px-6 py-12 text-center text-slate-400 text-sm">
                Belum ada data performa GSC yang tersinkron.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'

const props = defineProps({
  logs: Object
})

const syncing = ref(false)

const ctrColor = (ctr) => {
  if (ctr >= 5) return 'text-green-600'
  if (ctr >= 2) return 'text-blue-600'
  return 'text-amber-600'
}

const syncGsc = () => {
  syncing.value = true
  router.post('/admin/risen-ai/performance/sync', {}, {
    onFinish: () => {
      syncing.value = false
    }
  })
}
</script>
