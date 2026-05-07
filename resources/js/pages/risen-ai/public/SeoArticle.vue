<template>
  <div class="seo-article bg-white min-h-screen">
    <!-- Head -->
    <Head>
      <title>{{ page.title }}</title>
      <meta name="description" :content="page.meta_description" />
      <link rel="canonical" :href="canonicalUrl" />

      <!-- Open Graph / Facebook -->
      <meta property="og:type" content="article" />
      <meta property="og:url" :content="canonicalUrl" />
      <meta property="og:title" :content="page.title" />
      <meta property="og:description" :content="page.meta_description" />
      <meta v-if="page.thumbnail" property="og:image" :content="baseUrl + '/storage/' + page.thumbnail" />

      <!-- Twitter -->
      <meta property="twitter:card" content="summary_large_image" />
      <meta property="twitter:url" :content="canonicalUrl" />
      <meta property="twitter:title" :content="page.title" />
      <meta property="twitter:description" :content="page.meta_description" />
      <meta v-if="page.thumbnail" property="twitter:image" :content="baseUrl + '/storage/' + page.thumbnail" />

      <component 
        :is="'script'" 
        type="application/ld+json" 
        v-if="page.schema_markup"
      >
        {{ JSON.stringify(page.schema_markup) }}
      </component>
    </Head>

    <!-- Navbar -->
    <Navbar />

    <!-- Hero Section -->
    <header class="bg-slate-50 pt-16 pb-16 border-b border-slate-100">
      <div class="max-w-4xl mx-auto px-4">
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <span 
            class="inline-block px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-wider"
            :class="intentClass"
          >
            {{ intentLabel }}
          </span>
          <span 
            v-if="page.category"
            class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-slate-200 text-slate-700 uppercase tracking-wider"
          >
            {{ page.category }}
          </span>
        </div>
        <h1 class="text-4xl md:text-5xl font-bold text-slate-900 leading-tight mb-6">
          {{ content.h1 || page.title }}
        </h1>
        <div class="flex items-center text-slate-500 text-sm">
          <span class="flex items-center mr-6">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Diperbarui {{ formatDate(page.updated_at) }}
          </span>
          <span class="flex items-center">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            5 menit baca
          </span>
        </div>
        <div v-if="page.thumbnail" class="mt-10 rounded-3xl overflow-hidden border border-slate-200 aspect-[16/9]">
          <img 
            :src="'/storage/' + page.thumbnail" 
            :alt="page.title"
            class="w-full h-full object-cover"
          />
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 py-12">
      <!-- Intro Paragraph -->
      <div 
        v-if="content.intro_paragraph" 
        class="text-xl text-slate-600 leading-relaxed mb-12 font-medium italic border-l-4 border-indigo-500 pl-6"
      >
        {{ content.intro_paragraph }}
      </div>

      <!-- Content Sections -->
      <article class="prose prose-slate prose-lg max-w-none">
        <section
          v-for="(section, i) in content.content_sections"
          :key="i"
          class="mb-12"
        >
          <h2 class="text-3xl font-bold text-slate-900 mb-6">{{ section.heading }}</h2>
          <div class="content-body text-slate-700" v-html="section.body"></div>
          
          <!-- Internal Links within section if any -->
          <div v-if="section.internal_links?.length" class="mt-6 flex flex-wrap gap-2">
            <Link 
              v-for="(link, j) in section.internal_links" 
              :key="j"
              :href="link.url"
              class="text-indigo-600 hover:text-indigo-700 font-medium underline"
            >
              {{ link.anchor }}
            </Link>
          </div>
        </section>
      </article>

      <!-- FAQ Section -->
      <section class="mt-16 bg-slate-50 rounded-2xl p-8 border border-slate-100" v-if="content.faq?.length">
        <h2 class="text-2xl font-bold text-slate-900 mb-8 flex items-center">
          <svg class="w-6 h-6 mr-3 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Pertanyaan yang Sering Ditanyakan
        </h2>
        <div class="space-y-4">
          <details
            v-for="(item, i) in content.faq"
            :key="i"
            class="group bg-white rounded-xl border border-slate-200 overflow-hidden transition-all duration-200"
          >
            <summary class="flex justify-between items-center p-5 cursor-pointer list-none font-semibold text-slate-800">
              {{ item.question }}
              <svg class="w-5 h-5 text-slate-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </summary>
            <div class="p-5 pt-0 text-slate-600 leading-relaxed border-t border-slate-50">
              {{ item.answer }}
            </div>
          </details>
        </div>
      </section>

      <!-- Tags Section -->
      <div v-if="page.tags?.length" class="mt-16 pt-8 border-t border-slate-100">
        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-4">Tags</h3>
        <div class="flex flex-wrap gap-2">
          <span 
            v-for="tag in page.tags" 
            :key="tag"
            class="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-600"
          >
            #{{ tag }}
          </span>
        </div>
      </div>
    </main>

    <!-- Global Footer -->
    <FooterSection />

  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import Navbar from '@/components/Landing/Navbar.vue'
import CTASection from '@/components/Landing/CTASection.vue'
import FooterSection from '@/components/Landing/FooterSection.vue'

const props = defineProps({
  page: Object,
  content: Object,
})

const canonicalUrl = computed(() =>
  `${window.location.origin}/artikel/${props.page.slug}`
)

const baseUrl = computed(() => window.location.origin)

const intentLabel = computed(() => ({
  informational : 'Panduan',
  transactional : 'Solusi',
  comparison    : 'Perbandingan',
  navigational  : 'Navigasi',
}[props.page.intent] || 'Artikel'))

const intentClass = computed(() => ({
  informational : 'bg-blue-100 text-blue-700',
  transactional : 'bg-green-100 text-green-700',
  comparison    : 'bg-purple-100 text-purple-700',
  navigational  : 'bg-amber-100 text-amber-700',
}[props.page.intent] || 'bg-slate-100 text-slate-700'))

const formatDate = (date) =>
  new Date(date).toLocaleDateString('id-ID', {
    year: 'numeric', month: 'long', day: 'numeric'
  })
</script>

<style scoped>
.content-body :deep(p) {
  margin-bottom: 0.85rem;
  line-height: 1.9;
  color: #334155;
}
.content-body :deep(p + p) {
  margin-top: 0;
}
.content-body :deep(h3) {
  font-size: 1.5rem;
  font-weight: 700;
  margin-top: 2.5rem;
  margin-bottom: 1.25rem;
  color: #0f172a;
}
.content-body :deep(ul), .content-body :deep(ol) {
  margin-top: 1rem;
  margin-bottom: 1.75rem;
  padding-left: 1.75rem;
}
.content-body :deep(ul) {
  list-style-type: disc;
}
.content-body :deep(ol) {
  list-style-type: decimal;
}
.content-body :deep(li) {
  margin-bottom: 0.75rem;
  line-height: 1.8;
}
.content-body :deep(strong), .content-body :deep(b) {
  font-weight: 700;
  color: #1e293b;
}
.content-body :deep(blockquote) {
  border-left: 4px solid #6366f1;
  padding-left: 1.25rem;
  margin: 1.5rem 0;
  font-style: italic;
  color: #475569;
}
.content-body :deep(a) {
  color: #4f46e5;
  text-decoration: underline;
  font-weight: 500;
}
.content-body :deep(a:hover) {
  color: #3730a3;
}

/* Table Styles */
.content-body :deep(.table-wrapper) {
  overflow-x: auto;
  margin: 2rem 0;
  border-radius: 1rem;
  border: 1px solid #e2e8f0;
  box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
}

.content-body :deep(table) {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 0.95rem;
  background-color: #ffffff;
}

.content-body :deep(thead) {
  background-color: #f8fafc;
  border-bottom: 2px solid #e2e8f0;
}

.content-body :deep(th) {
  padding: 1rem;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
}

.content-body :deep(td) {
  padding: 1rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: top;
  color: #475569;
  line-height: 1.6;
}

.content-body :deep(tr:last-child td) {
  border-bottom: none;
}

.content-body :deep(tr:hover) {
  background-color: #fcfdfe;
}
</style>
