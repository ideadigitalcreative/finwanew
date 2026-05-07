<template>
  <div class="p-6 max-w-7xl mx-auto">
    <Head title="Page Generator - Risen AI" />

    <div class="mb-8 flex justify-between items-end">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Page Generator</h1>
        <p class="text-slate-500 text-sm mt-1">Generate halaman SEO otomatis menggunakan AI.</p>
      </div>
      <button 
        @click="showGenerateModal = true"
        class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-3 rounded-2xl font-bold text-sm hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg shadow-blue-200 flex items-center"
      >
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
        </svg>
        Generate Halaman Baru
      </button>
    </div>

    <!-- Pages Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 text-slate-500 text-xs font-bold uppercase tracking-wider">
              <th class="px-6 py-4">Halaman</th>
              <th class="px-6 py-4">Intent</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4">Score</th>
              <th class="px-6 py-4">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="page in pages.data" :key="page.id" class="hover:bg-slate-50 transition-colors">
              <td class="px-6 py-4">
                <div class="font-bold text-slate-900 text-sm">{{ page.title }}</div>
                <div class="text-slate-400 text-xs mt-0.5 max-w-xs truncate">{{ page.slug }}</div>
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
                <div v-if="page.intent_score !== null" class="flex flex-col gap-1">
                  <span class="text-xs font-bold" :class="scoreColor(page.intent_score)">{{ page.intent_score }}%</span>
                  <div class="w-16 bg-slate-100 rounded-full h-1 overflow-hidden">
                    <div class="h-full rounded-full" :class="scoreBg(page.intent_score)" :style="{ width: page.intent_score + '%' }"></div>
                  </div>
                </div>
                <span v-else class="text-slate-300 text-xs">Unscored</span>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                  <button 
                    v-if="page.status === 'draft'"
                    @click="publishPage(page.id)"
                    class="p-1.5 rounded-lg text-green-600 hover:bg-green-50 transition-colors"
                    title="Publish"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                  </button>
                  <button 
                    @click="auditPage(page.id)"
                    class="p-1.5 rounded-lg text-primary-600 hover:bg-primary-50 transition-colors"
                    title="Audit Intent"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </button>
                  <button 
                    @click="openEditModal(page)"
                    class="p-1.5 rounded-lg text-indigo-600 hover:bg-indigo-50 transition-colors"
                    title="Edit"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <a 
                    :href="'/artikel/' + page.slug" 
                    target="_blank"
                    class="p-1.5 rounded-lg text-slate-600 hover:bg-slate-50 transition-colors"
                    title="Preview"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                  </a>
                  <button 
                    @click="deletePage(page.id)"
                    class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition-colors"
                    title="Hapus"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!pages.data.length">
              <td colspan="5" class="px-6 py-12 text-center text-slate-400 text-sm">
                Belum ada halaman SEO.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Generate -->
    <div v-if="showGenerateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
      <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-8 border-b border-slate-100">
          <h2 class="text-2xl font-bold text-slate-900">Generate SEO Page</h2>
          <p class="text-slate-500 text-sm mt-2">Gunakan AI untuk membuat konten artikel finansial teroptimasi.</p>
        </div>
        
        <form @submit.prevent="submitGenerate" class="p-8 space-y-6">
          <!-- Dropdown Pilih dari Data Lake -->
          <div class="bg-blue-50 p-4 rounded-2xl border border-blue-100 mb-6">
            <label class="block text-xs font-bold text-blue-600 mb-2 uppercase tracking-wider">Cepat: Pilih dari Data Lake</label>
            <select 
              v-model="selectedClusterId"
              class="w-full rounded-xl border-blue-200 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white"
            >
              <option value="">-- Pilih Klaster Kata Kunci --</option>
              <option v-for="cluster in clusters" :key="cluster.id" :value="cluster.id">
                [{{ cluster.avg_intent }}] {{ cluster.cluster_name }}
              </option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Intent</label>
              <select 
                v-model="form.intent"
                class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
              >
                <option value="informational">Informational (Guide)</option>
                <option value="transactional">Transactional (Solution)</option>
                <option value="comparison">Comparison (Table)</option>
                <option value="navigational">Navigational</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Layanan/Topik Utama</label>
              <input 
                v-model="form.service"
                type="text"
                class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
                placeholder="misal: Catat Hutang Piutang"
              />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Lokasi (Opsional)</label>
              <input 
                v-model="form.location"
                type="text"
                class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
                placeholder="Jakarta, Surabaya"
              />
            </div>
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Profesi (Opsional)</label>
              <input 
                v-model="form.profession"
                type="text"
                class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
                placeholder="UMKM, Freelancer"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-bold text-slate-700 mb-2">Batch (Jumlah Halaman)</label>
            <input 
              v-model="form.batch"
              type="number"
              min="1"
              max="50"
              class="w-full rounded-xl border-slate-200 focus:ring-primary-500 focus:border-primary-500 text-sm"
            />
            <p class="text-[10px] text-slate-400 mt-2 italic">Halaman akan diproses di background queue.</p>
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
              {{ loading ? 'Enqueuing...' : 'Mulai Generate' }}
            </button>
          </div>
        </form>
      </div>
    </div>
    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center">
          <h2 class="text-xl font-bold text-slate-800">Edit Artikel SEO</h2>
          <button @click="showEditModal = false" class="text-slate-400 hover:text-slate-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        
        <form @submit.prevent="submitUpdate" class="p-8 space-y-6 overflow-y-auto">
          <div class="grid grid-cols-1 gap-6">
            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Judul Artikel</label>
              <input 
                v-model="editForm.title" 
                type="text" 
                class="w-full rounded-xl border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                placeholder="Judul SEO..."
              />
            </div>

            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">H1 Header</label>
              <input 
                v-model="editForm.h1" 
                type="text" 
                class="w-full rounded-xl border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                placeholder="H1 utama..."
              />
            </div>

            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Meta Description</label>
              <textarea 
                v-model="editForm.meta_description" 
                rows="3"
                class="w-full rounded-xl border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                placeholder="Maks 160 karakter..."
              ></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-bold text-slate-700 mb-2">Kategori</label>
                <input 
                  v-model="editForm.category" 
                  type="text" 
                  class="w-full rounded-xl border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                  placeholder="Misal: Keuangan UMKM"
                />
              </div>
              <div>
                <label class="block text-sm font-bold text-slate-700 mb-2">Tag (Pisah dengan koma)</label>
                <input 
                  v-model="editForm.tags_raw" 
                  type="text" 
                  class="w-full rounded-xl border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                  placeholder="tag1, tag2, tag3"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-bold text-slate-700 mb-2">Thumbnail</label>
              <input 
                type="file" 
                @input="editForm.thumbnail_file = $event.target.files[0]"
                class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
              />
              <p v-if="editForm.current_thumbnail" class="mt-2 text-xs text-slate-500">Thumbnail saat ini: {{ editForm.current_thumbnail }}</p>
            </div>
          </div>

          <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button 
              type="button"
              @click="showEditModal = false"
              class="px-6 py-3 rounded-2xl font-bold text-sm text-slate-600 hover:bg-slate-50 transition-colors"
            >
              Batal
            </button>
            <button 
              type="submit"
              :disabled="editForm.processing"
              class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-8 py-3 rounded-2xl font-bold text-sm hover:from-blue-700 hover:to-indigo-700 transition-all shadow-lg disabled:opacity-50 flex items-center"
            >
              <svg v-if="editForm.processing" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ editForm.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

const props = defineProps({
  pages: Object,
  clusters: Array // Data dari Data Lake
})

const showGenerateModal = ref(false)
const showEditModal = ref(false)
const loading = ref(false)
const selectedClusterId = ref('')

const form = reactive({
  intent: 'informational',
  service: '',
  location: '',
  profession: '',
  batch: 1,
  cluster_id: null
})

const editForm = useForm({
  id: null,
  title: '',
  h1: '',
  meta_description: '',
  category: '',
  tags_raw: '',
  thumbnail_file: null,
  current_thumbnail: '',
  _method: 'PUT' // Penting untuk spoofing method saat upload file dengan Inertia
})

const openEditModal = (page) => {
  editForm.id = page.id
  editForm.title = page.title
  editForm.h1 = page.h1
  editForm.meta_description = page.meta_description
  editForm.category = page.category || ''
  editForm.tags_raw = page.tags ? page.tags.join(', ') : ''
  editForm.current_thumbnail = page.thumbnail || ''
  editForm.thumbnail_file = null
  showEditModal.value = true
}

const submitUpdate = () => {
  editForm.post(`/admin/risen-ai/pages/${editForm.id}`, {
    onSuccess: () => {
      showEditModal.value = false
      editForm.reset()
    }
  })
}

// Auto-fill saat cluster dipilih
watch(selectedClusterId, (newId) => {
  if (newId) {
    const cluster = props.clusters.find(c => c.id == newId)
    if (cluster) {
      form.intent = cluster.avg_intent
      form.service = cluster.primary_keyword
      form.cluster_id = cluster.id
    }
  } else {
    form.cluster_id = null
  }
})

const intentClass = (intent) => ({
  informational : 'bg-blue-50 text-blue-600 border border-blue-100',
  transactional : 'bg-green-50 text-green-600 border border-green-100',
  comparison    : 'bg-purple-50 text-purple-600 border border-purple-100',
  navigational  : 'bg-amber-50 text-amber-600 border border-amber-100',
}[intent] || 'bg-slate-50 text-slate-600')

const scoreColor = (score) => {
  if (score >= 80) return 'text-green-600'
  if (score >= 60) return 'text-amber-600'
  return 'text-red-600'
}

const scoreBg = (score) => {
  if (score >= 80) return 'bg-green-500'
  if (score >= 60) return 'bg-amber-500'
  return 'bg-red-500'
}

const submitGenerate = () => {
  loading.value = true
  router.post('/admin/risen-ai/pages/generate', form, {
    onSuccess: () => {
      showGenerateModal.value = false
      loading.value = false
      form.service = ''
      form.location = ''
      form.profession = ''
      form.batch = 1
    },
    onError: () => {
      loading.value = false
    }
  })
}

const publishPage = (id) => {
  if (confirm('Publish halaman ini sekarang?')) {
    router.post(`/admin/risen-ai/pages/${id}/publish`)
  }
}

const auditPage = (id) => {
  router.post(`/admin/risen-ai/pages/${id}/audit`, {}, {
    onSuccess: (page) => {
      alert('Audit selesai. Score: ' + (page.props?.flash?.result?.intent_match_score || 'N/A'))
    }
  })
}

const deletePage = (id) => {
  if (confirm('Apakah Anda yakin ingin menghapus halaman ini?')) {
    router.delete(`/admin/risen-ai/pages/${id}`)
  }
}
</script>
