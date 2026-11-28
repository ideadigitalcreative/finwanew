import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

type Orientation = 'horizontal' | 'vertical';

/**
 * Indikator "elastic tabs": pil yang meluncur ke item aktif sambil
 * memanjang-memendek karena tepi depan dan tepi belakangnya punya
 * kecepatan berbeda (tepi belakang tertinggal sebesar LAG).
 */
const SPEED = 18;
const LAG = 0.3;
const BOUNCE = 0.5;

/** Ambang berhenti: di bawah ini pergerakan sudah tak terlihat mata. */
const EPSILON = 0.2;

interface Options {
    /** horizontal: geser X + lebar. vertical: geser Y + tinggi. */
    orientation?: Orientation;
    /** Selector item aktif di dalam container. */
    activeSelector?: string;
    /**
     * Navigasi Inertia me-remount komponennya, jadi posisi terakhir pil
     * disimpan di sini supaya animasinya tetap mengalir antar halaman.
     * Beri key unik per menu.
     */
    persistKey?: string;
}

/** Posisi [back, front] terakhir tiap menu, bertahan melewati remount. */
const lastPositions = new Map<string, [number, number]>();

export function useElasticIndicator(options: Options = {}) {
    const {
        orientation = 'horizontal',
        activeSelector = '[data-elastic-active="true"]',
        persistKey,
    } = options;

    const container: Ref<HTMLElement | null> = ref(null);
    const indicator: Ref<HTMLElement | null> = ref(null);
    const visible = ref(false);

    const isVertical = orientation === 'vertical';

    // front = tepi akhir (kanan/bawah), back = tepi awal (kiri/atas).
    let front = 0;
    let back = 0;
    let velocityFront = 0;
    let velocityBack = 0;
    let frameId: number | null = null;

    const prefersReducedMotion = () =>
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** Posisi tepi item aktif, relatif terhadap container. */
    const measure = (): [number, number] | null => {
        const root = container.value;
        const active = root?.querySelector<HTMLElement>(activeSelector);

        if (!root || !active) {
            return null;
        }

        const itemRect = active.getBoundingClientRect();
        const rootRect = root.getBoundingClientRect();

        return isVertical
            ? [itemRect.top - rootRect.top, itemRect.bottom - rootRect.top]
            : [itemRect.left - rootRect.left, itemRect.right - rootRect.left];
    };

    const paint = () => {
        const el = indicator.value;

        if (!el) {
            return;
        }

        const size = Math.max(4, front - back);

        if (persistKey) {
            lastPositions.set(persistKey, [back, front]);
        }

        if (isVertical) {
            el.style.transform = `translateY(${back.toFixed(1)}px)`;
            el.style.height = `${size.toFixed(1)}px`;
        } else {
            el.style.transform = `translateX(${back.toFixed(1)}px)`;
            el.style.width = `${size.toFixed(1)}px`;
        }
    };

    const stop = () => {
        if (frameId !== null) {
            cancelAnimationFrame(frameId);
            frameId = null;
        }
    };

    const snapTo = ([targetBack, targetFront]: [number, number]) => {
        back = targetBack;
        front = targetFront;
        velocityBack = 0;
        velocityFront = 0;
        paint();
    };

    const frame = () => {
        const target = measure();

        if (!target) {
            frameId = null;

            return;
        }

        const [targetBack, targetFront] = target;
        const k = SPEED * 0.012;

        // Tepi yang memimpin arah gerak memakai k penuh, tepi yang
        // tertinggal memakai k * LAG — dari situlah efek memanjangnya.
        const movingForward = targetBack > back;
        const kBack = movingForward ? k * LAG : k;
        const kFront = movingForward ? k : k * LAG;

        velocityFront += (targetFront - front) * kFront;
        velocityFront *= BOUNCE;
        front += velocityFront;

        velocityBack += (targetBack - back) * kBack;
        velocityBack *= BOUNCE;
        back += velocityBack;

        paint();

        const settled =
            Math.abs(targetFront - front) < EPSILON &&
            Math.abs(targetBack - back) < EPSILON &&
            Math.abs(velocityFront) < EPSILON &&
            Math.abs(velocityBack) < EPSILON;

        if (settled) {
            frameId = null;
            snapTo([targetBack, targetFront]);

            return;
        }

        frameId = requestAnimationFrame(frame);
    };

    /**
     * Hitung ulang posisi indikator. `animate: false` untuk langsung
     * menempel (render pertama, resize, ganti halaman).
     */
    const sync = (animate = true) => {
        const target = measure();

        visible.value = target !== null;

        if (!target) {
            stop();

            return;
        }

        if (!animate || prefersReducedMotion()) {
            stop();
            snapTo(target);

            return;
        }

        if (frameId === null) {
            frameId = requestAnimationFrame(frame);
        }
    };

    let resizeObserver: ResizeObserver | null = null;
    let mutationObserver: MutationObserver | null = null;

    // Saat sedang beranimasi, resize cukup mengubah targetnya — jangan di-snap,
    // supaya luncuran setelah pindah halaman tidak terpotong.
    const handleResize = () => sync(frameId !== null);

    onMounted(() => {
        // Tunggu satu frame supaya layout (font, ikon) sudah final.
        requestAnimationFrame(() => {
            const previous = persistKey ? lastPositions.get(persistKey) : undefined;

            if (previous) {
                // Lanjutkan dari posisi sebelum pindah halaman, lalu meluncur.
                [back, front] = previous;
                velocityBack = 0;
                velocityFront = 0;
                paint();
                sync(true);

                return;
            }

            sync(false);
        });

        if (container.value) {
            // ResizeObserver selalu menembak sekali saat observe(); panggilan
            // pertama itu diabaikan agar tidak membatalkan animasi awal.
            let firstResizeSeen = false;

            resizeObserver = new ResizeObserver(() => {
                if (!firstResizeSeen) {
                    firstResizeSeen = true;

                    return;
                }

                handleResize();
            });
            resizeObserver.observe(container.value);

            // Item aktif berubah lewat atribut/DOM saat navigasi Inertia.
            mutationObserver = new MutationObserver(() => sync(true));
            mutationObserver.observe(container.value, {
                attributes: true,
                attributeFilter: ['data-elastic-active'],
                childList: true,
                subtree: true,
            });
        }

        window.addEventListener('resize', handleResize);
    });

    onBeforeUnmount(() => {
        stop();
        resizeObserver?.disconnect();
        mutationObserver?.disconnect();
        window.removeEventListener('resize', handleResize);
    });

    return {
        container,
        indicator,
        visible,
        sync,
    };
}
