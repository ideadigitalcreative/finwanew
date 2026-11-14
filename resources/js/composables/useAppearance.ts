import { onMounted, ref } from 'vue';

type Appearance = 'light' | 'dark' | 'system';

// Menandai tema aktif yang benar-benar dipakai (sudah memperhitungkan 'system').
const isDark = ref(false);

const syncIsDark = () => {
    if (typeof document === 'undefined') {
        return;
    }

    isDark.value = document.documentElement.classList.contains('dark');
};

export function updateTheme(value: Appearance) {
    if (typeof window === 'undefined') {
        return;
    }

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        const systemTheme = mediaQueryList.matches ? 'dark' : 'light';

        document.documentElement.classList.toggle(
            'dark',
            systemTheme === 'dark',
        );
    } else {
        document.documentElement.classList.toggle('dark', value === 'dark');
    }

    syncIsDark();
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('appearance') as Appearance | null;
};

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'system');

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');

/**
 * Menyimpan titik asal ripple (posisi klik) sebagai CSS variable,
 * dipakai oleh keyframes finwa-circle-in/out di app.css.
 */
const setTransitionOrigin = (event?: MouseEvent | { x: number; y: number }) => {
    if (typeof document === 'undefined') {
        return;
    }

    let x = window.innerWidth / 2;
    let y = window.innerHeight / 2;

    if (event) {
        if (event instanceof MouseEvent) {
            // clientX/Y bernilai 0 saat tombol diaktifkan lewat keyboard;
            // pada kasus itu pakai titik tengah tombolnya.
            if (event.clientX || event.clientY) {
                x = event.clientX;
                y = event.clientY;
            } else {
                const target = event.currentTarget as HTMLElement | null;

                if (target?.getBoundingClientRect) {
                    const rect = target.getBoundingClientRect();

                    x = rect.left + rect.width / 2;
                    y = rect.top + rect.height / 2;
                }
            }
        } else {
            x = event.x;
            y = event.y;
        }
    }

    document.documentElement.style.setProperty('--click-x', `${x}px`);
    document.documentElement.style.setProperty('--click-y', `${y}px`);
};

export function useAppearance() {
    onMounted(() => {
        const savedAppearance = localStorage.getItem(
            'appearance',
        ) as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }

        syncIsDark();
    });

    function applyAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value);
    }

    /**
     * Ganti tema dengan animasi lingkaran (View Transitions API).
     * Browser yang belum mendukung tetap berganti tema, hanya tanpa animasi.
     */
    function updateAppearance(
        value: Appearance,
        event?: MouseEvent | { x: number; y: number },
    ) {
        const prefersReducedMotion =
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const startViewTransition = (
            document as Document & {
                startViewTransition?: (cb: () => void) => unknown;
            }
        ).startViewTransition;

        if (!startViewTransition || prefersReducedMotion) {
            applyAppearance(value);

            return;
        }

        setTransitionOrigin(event);

        startViewTransition.call(document, () => applyAppearance(value));
    }

    /** Toggle cepat terang <-> gelap berdasarkan tema yang sedang tampil. */
    function toggleAppearance(event?: MouseEvent) {
        updateAppearance(isDark.value ? 'light' : 'dark', event);
    }

    return {
        appearance,
        isDark,
        updateAppearance,
        toggleAppearance,
    };
}
