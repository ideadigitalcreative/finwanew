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
const AppDownloadSection = defineAsyncComponent(() => import('@/components/Landing/AppDownloadSection.vue'));
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
  "@type": ["SoftwareApplication", "FinancialProduct"],
  "name": "FinWa - Aplikasi Keuangan WhatsApp Indonesia",
  "applicationCategory": "FinanceApplication",
  "operatingSystem": "Web, WhatsApp",
  "offers": {
    "@type": "Offer",
    "price": "0",
    "priceCurrency": "IDR"
  },
  "areaServed": "ID",
  "description": "FinWa adalah aplikasi keuangan WhatsApp Indonesia untuk UMKM dan freelancer. Catat pengeluaran dan pemasukan otomatis hanya dengan chat WA. Coba Gratis!",
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
  "description": "FinWa adalah aplikasi keuangan WhatsApp Indonesia. Pencatatan keuangan dari WA menjadi mudah dan otomatis untuk UMKM dan freelancer.",
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
        <title>FinWa: Aplikasi Keuangan WhatsApp Indonesia</title>
        <meta name="description" content="Catat keuangan via WhatsApp dengan mudah. FinWa adalah aplikasi keuangan WhatsApp Indonesia. Cukup chat pengeluaran, otomatis tercatat. Coba Gratis!" />
        <meta name="keywords" content="aplikasi keuangan whatsapp indonesia, catat keuangan via whatsapp, aplikasi catat keuangan umkm, finwa, bot keuangan whatsapp, pencatatan otomatis dari wa" />
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />
        <link rel="canonical" href="https://finwa.web.id" />

        <!-- Open Graph / Facebook -->
        <meta property="og:type" content="website" />
        <meta property="og:url" content="https://finwa.web.id/" />
        <meta property="og:title" content="FinWa: Aplikasi Keuangan WhatsApp Indonesia" />
        <meta property="og:description" content="Catat keuangan via WhatsApp dengan mudah. FinWa adalah aplikasi keuangan WhatsApp Indonesia. Cukup chat pengeluaran, otomatis tercatat. Coba Gratis!" />
        <meta property="og:image" content="https://finwa.web.id/finwalogo.png" />
        <meta property="og:image:alt" content="FinWa - Aplikasi Keuangan WhatsApp Indonesia" />

        <!-- Twitter -->
        <meta property="twitter:card" content="summary_large_image" />
        <meta property="twitter:url" content="https://finwa.web.id/" />
        <meta property="twitter:title" content="FinWa: Aplikasi Keuangan WhatsApp Indonesia" />
        <meta property="twitter:description" content="Catat keuangan via WhatsApp dengan mudah. FinWa adalah aplikasi keuangan WhatsApp Indonesia. Cukup chat pengeluaran, otomatis tercatat. Coba Gratis!" />
        <meta property="twitter:image" content="https://finwa.web.id/finwalogo.png" />
        <meta property="twitter:image:alt" content="FinWa - Aplikasi Keuangan WhatsApp Indonesia" />
        
        <!-- Structured Data -->
        <component is="script" type="application/ld+json">{{ JSON.stringify(schema) }}</component>
        <component is="script" type="application/ld+json">{{ JSON.stringify(organizationSchema) }}</component>
        <component is="script" type="application/ld+json">{{ JSON.stringify(faqSchema) }}</component>
    </Head>

    <div class="landing-brand antialiased bg-white text-neutral-900 selection:bg-[var(--fw-a20)] selection:text-[var(--fw-900)] font-sans">
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
                <AppDownloadSection />
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

<style>
/* Palet hijau landing: aksen = oklch(0.65 0.19 137.46). Di :root agar ikut Teleport (menu mobile). */
:root:has(.landing-brand) {
    --fw-h: 137.46;
    --fw-50: oklch(0.98 0.015 var(--fw-h));
    --fw-100: oklch(0.95 0.035 var(--fw-h));
    --fw-200: oklch(0.9 0.06 var(--fw-h));
    --fw-300: oklch(0.82 0.1 var(--fw-h));
    --fw-400: oklch(0.76 0.14 var(--fw-h));
    --fw-500: oklch(0.71 0.17 var(--fw-h));
    --fw-550: oklch(0.68 0.18 var(--fw-h));
    --fw-600: oklch(0.6 0.17 var(--fw-h));
    --fw-650: oklch(0.65 0.19 var(--fw-h));
    --fw-700: oklch(0.5 0.14 var(--fw-h));
    --fw-800: oklch(0.4 0.11 var(--fw-h));
    --fw-900: oklch(0.3 0.08 var(--fw-h));
    --fw-a10: oklch(0.65 0.19 var(--fw-h) / 0.1);
    --fw-a15: oklch(0.65 0.19 var(--fw-h) / 0.15);
    --fw-a20: oklch(0.65 0.19 var(--fw-h) / 0.2);
    --fw-a30: oklch(0.65 0.19 var(--fw-h) / 0.3);
    --fw-ring-35: oklch(0.65 0.19 var(--fw-h) / 0.35);
    --fw-mulai-tint: oklch(0.97 0.02 var(--fw-h) / 0.5);
    --fw-cardwash-from: oklch(0.88 0.06 var(--fw-h) / 0.12);
    --fw-cardwash-mid: oklch(0.97 0.02 var(--fw-h) / 0.2);
    --fw-icon-faint: oklch(0.6 0.17 var(--fw-h) / 0.09);
    --fw-icon-faint-hover: oklch(0.6 0.17 var(--fw-h) / 0.12);
    --fw-glow: oklch(0.65 0.19 var(--fw-h) / 0.15);
    --fw-ring-soft: oklch(0.95 0.035 var(--fw-h) / 0.8);
    --fw-ring-badge: oklch(0.9 0.06 var(--fw-h) / 0.6);
    --fw-dark-surface: oklch(0.3 0.08 var(--fw-h) / 0.22);
    --fw-shadow-drop: 0 10px 25px -5px oklch(0.65 0.19 var(--fw-h) / 0.28);
    --fw-shadow-drop-hover: 0 12px 28px -5px oklch(0.65 0.19 var(--fw-h) / 0.36);
}
</style>

