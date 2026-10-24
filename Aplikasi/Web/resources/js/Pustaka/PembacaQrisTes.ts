import { afterEach, describe, expect, it, vi } from 'vitest';

import { BacaQrDariBerkas } from '@/Pustaka/PembacaQris';

/* D-189: bacaan QR dari gambar QRIS memakai BarcodeDetector bila ada; gambar rusak atau tanpa QR = null. */

function PasangGambar(lebar: number, tinggi: number) {
    const Tutup = vi.fn();
    vi.stubGlobal(
        'createImageBitmap',
        vi.fn(() => Promise.resolve({ width: lebar, height: tinggi, close: Tutup })),
    );
    // jsdom tidak punya kanvas 2D; cukup konteks palsu.
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
        fillRect: vi.fn(),
        drawImage: vi.fn(),
        fillStyle: '',
    } as unknown as CanvasRenderingContext2D);

    return Tutup;
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('BacaQrDariBerkas', () => {
    it('mengembalikan teks QR dari BarcodeDetector dan menutup bitmap', async () => {
        const Tutup = PasangGambar(1500, 1500);
        const Deteksi = vi.fn(() => Promise.resolve([{ rawValue: '  000201010211  ' }]));
        vi.stubGlobal(
            'BarcodeDetector',
            class {
                detect = Deteksi;
            },
        );

        expect(await BacaQrDariBerkas(new Blob(['x']))).toBe('000201010211');
        expect(Deteksi).toHaveBeenCalledTimes(1);
        expect(Tutup).toHaveBeenCalled();
    });

    it('mencoba ukuran lebih kecil bila ukuran asli tidak terbaca', async () => {
        PasangGambar(3000, 3000);
        const Deteksi = vi
            .fn()
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce([{ rawValue: '000201AB' }]);
        vi.stubGlobal(
            'BarcodeDetector',
            class {
                detect = Deteksi;
            },
        );

        expect(await BacaQrDariBerkas(new Blob(['x']))).toBe('000201AB');
        expect(Deteksi).toHaveBeenCalledTimes(2);
    });

    it('berkas yang bukan gambar = null tanpa melempar galat', async () => {
        vi.stubGlobal(
            'createImageBitmap',
            vi.fn(() => Promise.reject(new Error('bukan gambar'))),
        );

        expect(await BacaQrDariBerkas(new Blob(['teks']))).toBeNull();
    });
});
