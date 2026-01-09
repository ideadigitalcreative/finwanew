import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        // Temporarily disabled wayfinder due to build error
        // wayfinder({
        //     formVariants: true,
        // }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        hmr: {
            overlay: true,
        },
    },
    logLevel: 'warn', // Suppress info logs (including HMR updates), but show warnings and errors
    esbuild: {
        target: 'esnext',
        format: 'esm',
        treeShaking: true,
        legalComments: 'none',
        minifyIdentifiers: true,
        minifySyntax: true,
        minifyWhitespace: true,
    },
    build: {
        target: 'esnext',
        minify: 'esbuild',
        cssMinify: 'lightningcss',
        sourcemap: false,
        // Optimize chunk size
        chunkSizeWarningLimit: 500,
        rollupOptions: {
            output: {
                manualChunks: {
                    // Core vendor libraries - loaded first
                    'vendor-vue': ['vue'],
                    'vendor-inertia': ['@inertiajs/vue3'],
                    // Chart libraries - lazy loaded only when needed
                    'charts': ['chart.js', 'vue-chartjs'],
                    // Landing page below-the-fold components - lazy loaded
                    'landing-lazy': [
                        './resources/js/components/Landing/FeaturesSection.vue',
                        './resources/js/components/Landing/HowToUseSection.vue',
                        './resources/js/components/Landing/CTASection.vue',
                        './resources/js/components/Landing/PricingSection.vue',
                        './resources/js/components/Landing/FAQSection.vue',
                        './resources/js/components/Landing/FooterSection.vue',
                        './resources/js/components/Landing/FloatingWhatsApp.vue',
                    ],
                },
                // Optimize asset file names for better caching
                assetFileNames: 'assets/[name]-[hash][extname]',
                chunkFileNames: 'assets/[name]-[hash].js',
                entryFileNames: 'assets/[name]-[hash].js',
            },
        },
    },
    optimizeDeps: {
        include: [
            'vue',
            '@inertiajs/vue3',
            'chart.js',
            'vue-chartjs',
        ],
        esbuildOptions: {
            target: 'esnext',
        },
    },
});
