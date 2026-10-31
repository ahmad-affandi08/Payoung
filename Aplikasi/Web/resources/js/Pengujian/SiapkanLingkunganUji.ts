import { beforeEach } from 'vitest';

/**
 * Satu-satunya pelengkap jsdom untuk Vitest (didaftarkan di `test.setupFiles` pada `vite.config.ts`).
 * Bukan kode produksi: peramban sungguhan sudah punya semua API ini.
 *
 * Komponen shadcn/ui (Radix, cmdk) memakai API peramban yang belum ada di jsdom:
 * - `matchMedia`: `SidebarProvider` (hook `useIsMobile`) di tata letak back-office & Platform Pengelola;
 * - `ResizeObserver`: `Checkbox`/`Switch`/`RadioGroup` di dalam formulir, `DropdownMenu`, `Popover`, `Tooltip`;
 * - `scrollIntoView`: `Select` dan daftar `Command` (cmdk);
 * - pointer capture: pemicu `DropdownMenu`/`Select`/`Popover` Radix;
 * - `DOMRect`: pengukuran Popper Radix.
 *
 * Setiap pengganti hanya dipasang bila API-nya belum ada, dan tidak melakukan apa pun: media query dianggap
 * tidak cocok (tata letak desktop), perubahan ukuran tidak dilaporkan, gulir & pointer capture diabaikan.
 */

if (typeof window.matchMedia !== 'function') {
    window.matchMedia = (kueri: string): MediaQueryList => ({
        matches: false,
        media: kueri,
        onchange: null,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        addListener: () => undefined,
        removeListener: () => undefined,
        dispatchEvent: () => false,
    });
}

if (typeof globalThis.ResizeObserver !== 'function') {
    globalThis.ResizeObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}

const prototipeElemen = Element.prototype as Partial<
    Pick<Element, 'scrollIntoView' | 'hasPointerCapture' | 'setPointerCapture' | 'releasePointerCapture'>
>;
prototipeElemen.scrollIntoView ??= () => undefined;
prototipeElemen.hasPointerCapture ??= () => false;
prototipeElemen.setPointerCapture ??= () => undefined;
prototipeElemen.releasePointerCapture ??= () => undefined;

if (typeof globalThis.DOMRect !== 'function') {
    class DOMRectUji {
        constructor(
            public x = 0,
            public y = 0,
            public width = 0,
            public height = 0,
        ) {}

        get top(): number {
            return this.y;
        }

        get left(): number {
            return this.x;
        }

        get right(): number {
            return this.x + this.width;
        }

        get bottom(): number {
            return this.y + this.height;
        }

        toJSON(): Record<string, number> {
            return { x: this.x, y: this.y, width: this.width, height: this.height };
        }

        static fromRect(persegi?: DOMRectInit): DOMRectUji {
            return new DOMRectUji(persegi?.x, persegi?.y, persegi?.width, persegi?.height);
        }
    }
    globalThis.DOMRect = DOMRectUji as unknown as typeof DOMRect;
}

/* Draf otomatis form (`useDrafLokal`) memakai localStorage. Dikosongkan sebelum tiap tes supaya isian satu tes tidak
 * muncul sebagai tawaran draf di tes berikutnya dalam berkas yang sama. */
beforeEach(() => {
    try {
        window.localStorage.clear();
    } catch {
        // Penyimpanan tidak tersedia di lingkungan ini.
    }
});
