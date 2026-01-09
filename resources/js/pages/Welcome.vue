<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { defineAsyncComponent } from 'vue';

// Critical above-the-fold components - load immediately
import Navbar from '@/components/Landing/Navbar.vue';
import BackgroundAccents from '@/components/Landing/BackgroundAccents.vue';
import HeroSection from '@/components/Landing/HeroSection.vue';

// Below-the-fold components - lazy load for faster initial paint
const FeaturesSection = defineAsyncComponent(() => import('@/components/Landing/FeaturesSection.vue'));
const HowToUseSection = defineAsyncComponent(() => import('@/components/Landing/HowToUseSection.vue'));
const CTASection = defineAsyncComponent(() => import('@/components/Landing/CTASection.vue'));
const PricingSection = defineAsyncComponent(() => import('@/components/Landing/PricingSection.vue'));
const FAQSection = defineAsyncComponent(() => import('@/components/Landing/FAQSection.vue'));
const FooterSection = defineAsyncComponent(() => import('@/components/Landing/FooterSection.vue'));
const FloatingWhatsApp = defineAsyncComponent(() => import('@/components/Landing/FloatingWhatsApp.vue'));

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const schema = {
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "FinWa - Catat Keuangan via WhatsApp",
  "applicationCategory": "FinanceApplication",
  "operatingSystem": "Web, WhatsApp",
  "offers": {
    "@type": "Offer",
    "price": "0",
    "priceCurrency": "IDR"
  },
  "description": "Catat keuangan via WhatsApp dengan mudah! Aplikasi pencatatan keuangan otomatis dari WA. Cukup chat pengeluaran & pemasukan, langsung tercatat otomatis.",
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.8",
    "reviewCount": "150"
  }
};

const organizationSchema = {
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "FinWa",
  "url": "https://finwa.web.id",
  "logo": "https://finwa.web.id/finwalogo.png",
  "description": "FinWa adalah aplikasi untuk catat keuangan via WhatsApp. Pencatatan keuangan dari WA menjadi mudah dan otomatis.",
  "sameAs": [
    "https://wa.me/6285762000079"
  ]
};

const faqSchema = {
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "Bagaimana cara catat keuangan via WhatsApp?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Daftar FinWa, lalu kirim pesan ke nomor WhatsApp FinWa. Contoh: 'Makan siang 25rb' atau 'Gaji 5jt'. FinWa otomatis mencatat transaksi ke dashboard Anda."
      }
    },
    {
      "@type": "Question",
      "name": "Apakah bisa catat keuangan dari WA gratis?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Ya! FinWa menyediakan masa trial gratis 7 hari untuk mencoba semua fitur catat keuangan via WhatsApp."
      }
    }
  ]
};
</script>

<template>
    <Head>
        <title>Catat Keuangan via WhatsApp - FinWa | Pencatatan Otomatis dari WA</title>
        <meta name="description" content="Catat keuangan via WhatsApp dengan mudah! FinWa adalah aplikasi pencatatan keuangan otomatis dari WA. Cukup chat pengeluaran & pemasukan, langsung tercatat. Coba Gratis!" />
        <meta name="keywords" content="catat keuangan via whatsapp, catat keuangan dari wa, aplikasi catat keuangan whatsapp, pencatatan keuangan lewat wa, bot keuangan whatsapp, catat pengeluaran via wa, aplikasi keuangan di whatsapp, finwa, catatan keuangan otomatis whatsapp, buku kas whatsapp" />
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />
        <link rel="canonical" href="https://finwa.web.id" />

        <!-- Open Graph / Facebook -->
        <meta property="og:type" content="website" />
        <meta property="og:url" content="https://finwa.web.id/" />
        <meta property="og:title" content="Catat Keuangan via WhatsApp - FinWa | Pencatatan Otomatis dari WA" />
        <meta property="og:description" content="Catat keuangan via WhatsApp dengan mudah! Cukup chat pengeluaran & pemasukan di WA, FinWa otomatis mencatat. Aplikasi pencatatan keuangan terbaik dari WhatsApp." />
        <meta property="og:image" content="https://finwa.web.id/finwalogo.png" />
        <meta property="og:image:alt" content="FinWa - Aplikasi Catat Keuangan via WhatsApp" />

        <!-- Twitter -->
        <meta property="twitter:card" content="summary_large_image" />
        <meta property="twitter:url" content="https://finwa.web.id/" />
        <meta property="twitter:title" content="Catat Keuangan via WhatsApp - FinWa | Pencatatan Otomatis dari WA" />
        <meta property="twitter:description" content="Catat keuangan via WhatsApp dengan mudah! Cukup chat pengeluaran & pemasukan di WA, FinWa otomatis mencatat. Aplikasi pencatatan keuangan terbaik dari WhatsApp." />
        <meta property="twitter:image" content="https://finwa.web.id/finwalogo.png" />
        <meta property="twitter:image:alt" content="FinWa - Aplikasi Catat Keuangan via WhatsApp" />
        
        <!-- Structured Data -->
        <component is="script" type="application/ld+json">{{ JSON.stringify(schema) }}</component>
        <component is="script" type="application/ld+json">{{ JSON.stringify(organizationSchema) }}</component>
        <component is="script" type="application/ld+json">{{ JSON.stringify(faqSchema) }}</component>
    </Head>

    <div class="antialiased bg-white text-neutral-900 selection:bg-emerald-500/30 selection:text-emerald-900 font-sans">
        <BackgroundAccents />
        <Navbar />
        <HeroSection />
        
        <!-- Below-the-fold sections with content-visibility optimization -->
        <div data-lazy-section>
            <Suspense>
                <FeaturesSection />
            </Suspense>
        </div>
        <div data-lazy-section>
            <Suspense>
                <HowToUseSection />
            </Suspense>
        </div>
        <div data-lazy-section>
            <Suspense>
                <CTASection />
            </Suspense>
        </div>
        <div data-lazy-section>
            <Suspense>
                <PricingSection />
            </Suspense>
        </div>
        <div data-lazy-section>
            <Suspense>
                <FAQSection />
            </Suspense>
        </div>
        <div data-lazy-section>
            <Suspense>
                <FooterSection />
            </Suspense>
        </div>
        
        <!-- Floating WhatsApp Button -->
        <Suspense>
            <FloatingWhatsApp phone-number="6285762000079" message="Halo kak, saya mau daftar FinWa. Ketik *Daftar* untuk mulai ya!" />
        </Suspense>
    </div>
</template>

