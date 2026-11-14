import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import { initNativePush, trySync } from './nativePush';
import { initPwaInstallCapture } from './pwaInstallState';
import { registerPwa } from './registerPwa';

/** Token FCM aplikasi Android dikirim begitu ada user login (lihat nativePush.ts) */
let currentPageProps: Record<string, any> = {};
const isLoggedIn = () => Boolean(currentPageProps?.auth?.user);

const appName = import.meta.env.VITE_APP_NAME || 'Finwa';

/** Harus sebelum mount Inertia — jangan tunggu halaman Login mount */
initPwaInstallCapture();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        currentPageProps = (props.initialPage?.props ?? {}) as Record<string, any>;
        initNativePush(isLoggedIn);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// Setelah login / pindah halaman: daftarkan token FCM bila tersedia
router.on('navigate', (event) => {
    currentPageProps = (event.detail.page.props ?? {}) as Record<string, any>;
    trySync(isLoggedIn);
});

// This will set light / dark mode on page load...
initializeTheme();

registerPwa();
