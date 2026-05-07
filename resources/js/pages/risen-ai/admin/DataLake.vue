<template>
  <div class="p-6 max-w-7xl mx-auto">
    <Head title="Data Lake - Risen AI" />

    <div class="mb-8 flex justify-between items-end">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Data Lake (Keyword Clusters)</h1>
        <p class="text-slate-500 text-sm mt-1">Kelola cluster kata kunci untuk riset topik programmatic SEO.</p>
      </div>
      <button 
        @click="showGenerateModal = true"
        class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-3 rounded-2xl font-bold text-sm hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg shadow-blue-200 flex items-center"
      >
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.675.337a4 4 0 01-2.574.345l-2.387-.477a2 2 0 00-1.022.547l-1.166 1.166a2 2 0 000 2.828l1.166 1.166a2 2 0 001.022.547l2.387.477a6 6 0 003.86-.517l.675-.337a4 4 0 012.574-.345l2.387.477a2 2 0 001.022-.547l1.166-1.166a2 2 0 000-2.828l-1.166-1.166z" />
        </svg>
        Generate Cluster Baru
      </button>
    </div>

    <!-- Cluster Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 text-slate-500 text-xs font-bold uppercase tracking-wider">
              <th class="px-6 py-4">Cluster Name</th>
              <th class="px-6 py-4">Primary Keyword</th>
              <th class="px-6 py-4">Secondary Keywords</th>
              <th class="px-6 py-4">Intent</th>
              <th class="px-6 py-4">Volume</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="cluster in clusters.data" :key="cluster.id" class="hover:bg-slate-50 transition-colors">
              <td class="px-6 py-4">
                <div class="font-bold text-slate-900 text-sm">{{ cluster.cluster_name }}</div>
                <div class="text-slate-400 text-xs mt-0.5">{{ cluster.niche }}</div>
              </td>
              <td class="px-6 py-4">
                <div class="text-sm text-slate-700 font-medium">{{ cluster.primary_keyword }}</div>
              </td>
              <td class="px-6 py-4">
                <div class="flex flex-wrap gap-1 max-w-xs">
                  <span 
                    v-for="(kw, index) in cluster.secondary_keywords" 
                    :key="index"
                    class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[10px]"
                  >
                    {{ kw }}
                  </span>
                </div>
              </td>
              <td class="px-6 py-4">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase" :class="intentClass(cluster.avg_intent)">
                  {{ cluster.avg_intent }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm font-medium uppercase" :class="volumeClass(cluster.estimated_volume)">
                {{ cluster.estimated_volume }}
              </td>
            </tr>
            <tr v-if="!clusters.data.length">
              <td colspan="5" class="px-6 py-12 text-center text-slate-400 text-sm">
                Belum ada cluster kata kunci.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Pagination placeholder -->
    <div v-if="clusters.links" class="mt-6">
      <!-- Standard pagination logic here -->
    </div>

    <!-- Modal Generate -->
    <div v-if="showGenerateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
      <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-8 border-b border-slate-100">
          <h2 class="text-2xl font-bold text-slate-900">Build Data Lake</h2>
          <p class="text-slate-500 text-sm mt-2">Gunakan AI untuk memetakan klaster kata kunci keuangan.</p>
        </div>
        
        <form @submit.prevent="submitGenerate" class="p-8 space-y-6">
          <div>
            <label class="block text-sm font-bold text-slate-700 mb-2">Seed Keywords (Pisahkan dengan koma)</label>
            <textarea 
              v-model="form.seed_keywords_raw"
              class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 h-24 text-sm"
              placeholder="misal: aplikasi keuangan, catat pengeluaran, tips menabung"
            ></textarea>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Lokasi Target</label>
              <input 
                v-model="form.locations_raw"
                type="text"
                class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
                placeholder="Jakarta, Surabaya, Makassar"
              />
            </div>
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Profesi Target</label>
              <input 
                v-model="form.professions_raw"
                type="text"
                class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
                placeholder="UMKM, Mahasiswa, Ojol"
              />
            </div>
          </div>

          <div class="flex justify-end gap-4 pt-4">
            <button 
              type="button" 
              @click="showGenerateModal = false"
              class="px-6 py-2.5 rounded-xl font-bold text-sm text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Batal
            </button>
            <button 
              type="submit"
              :disabled="loading"
              class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-8 py-3 rounded-2xl font-bold text-sm hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg disabled:opacity-50 flex items-center"
            >
              <svg v-if="loading" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ loading ? 'Memproses AI...' : 'Jalankan Brain' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { Head, router } from '@inertiajs/vue3'

const props = defineProps({
  clusters: Object
})

const showGenerateModal = ref(false)
const loading = ref(false)

const form = reactive({
  seed_keywords_raw: '',
  locations_raw: '',
  professions_raw: '',
})

const intentClass = (intent) => ({
  informational : 'bg-blue-50 text-blue-600 border border-blue-100',
  transactional : 'bg-green-50 text-green-600 border border-green-100',
  comparison    : 'bg-purple-50 text-purple-600 border border-purple-100',
  navigational  : 'bg-amber-50 text-amber-600 border border-amber-100',
}[intent] || 'bg-slate-50 text-slate-600')

const volumeClass = (vol) => ({
  high   : 'text-green-600',
  medium : 'text-blue-600',
  low    : 'text-slate-400',
}[vol] || 'text-slate-500')

const submitGenerate = () => {
  loading.value = true
  
  const payload = {
    seed_keywords: form.seed_keywords_raw.split(',').map(s => s.trim()).filter(Boolean),
    locations: form.locations_raw.split(',').map(s => s.trim()).filter(Boolean),
    professions: form.professions_raw.split(',').map(s => s.trim()).filter(Boolean),
  }

  router.post('/admin/risen-ai/data-lake/generate', payload, {
    onSuccess: () => {
      showGenerateModal.value = false
      loading.value = false
      form.seed_keywords_raw = ''
      form.locations_raw = ''
      form.professions_raw = ''
    },
    onError: () => {
      loading.value = false
    }
  })
}
</script>
