import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PanelBayarSnap, { FormatSisaWaktu } from '@/Komponen/Langganan/PanelBayarSnap';
import { tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PilihanBayar, TampilanBayar } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const pilihan: PilihanBayar[] = [
    { Metode: 'Qris', Label: 'QRIS', Kanal: null },
    { Metode: 'VirtualAccount', Label: 'Virtual account BNI', Kanal: 'VIRTUAL_ACCOUNT_BNI' },
    { Metode: 'Dana', Label: 'DANA', Kanal: null },
];

const tampilanQr: TampilanBayar = {
    Metode: 'Qris',
    Tampilan: 'Qr',
    Label: 'QRIS',
    QrSvg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
    NomorVa: null,
    NamaBank: null,
    UrlAlih: null,
    Jumlah: '222000.00',
    KedaluwarsaPada: new Date(Date.now() + 30 * 60_000).toISOString(),
};

function JawabJson(isi: unknown, status = 200) {
    return new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('PanelBayarSnap (D-159)', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });
    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.clearAllMocks();
    });

    it('menawarkan QRIS, tiap bank virtual account, dan e-wallet sebagai tombol terpisah', () => {
        render(<PanelBayarSnap uuidTagihan="TG-1" pilihan={pilihan} />);

        expect(screen.getByRole('button', { name: 'QRIS' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'DANA' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'BNI' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Virtual account' })).toBeTruthy();
    });

    it('memilih QRIS meminta server lalu menampilkan gambar QR dan sisa waktu', async () => {
        const Ambil = vi.fn().mockResolvedValue(JawabJson({ Pembayaran: { TampilanBayar: tampilanQr } }));
        vi.stubGlobal('fetch', Ambil);
        render(<PanelBayarSnap uuidTagihan="TG-1" pilihan={pilihan} />);

        fireEvent.click(screen.getByRole('button', { name: 'QRIS' }));

        await waitFor(() => expect(screen.getByAltText('Kode QRIS tagihan langganan')).toBeTruthy());
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ Metode: 'Qris', Kanal: null }) }),
        );
        expect(screen.getByText(/Berlaku \d{2}:\d{2} lagi/)).toBeTruthy();
    });

    it('virtual account mengirim kanal bank dan menampilkan nomor yang bisa disalin', async () => {
        const Ambil = vi.fn().mockResolvedValue(
            JawabJson({
                Pembayaran: {
                    TampilanBayar: {
                        ...tampilanQr,
                        Metode: 'VirtualAccount',
                        Tampilan: 'NomorVa',
                        Label: 'Virtual account BNI',
                        QrSvg: null,
                        NomorVa: '9882917230000000000000000001',
                        NamaBank: 'BNI',
                    },
                },
            }),
        );
        const Tulis = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', Ambil);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: Tulis }, configurable: true });
        render(<PanelBayarSnap uuidTagihan="TG-1" pilihan={pilihan} />);

        fireEvent.click(screen.getByRole('button', { name: 'BNI' }));

        await waitFor(() => expect(screen.getByText('9882917230000000000000000001')).toBeTruthy());
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({
                body: JSON.stringify({ Metode: 'VirtualAccount', Kanal: 'VIRTUAL_ACCOUNT_BNI' }),
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Salin nomor' }));
        await waitFor(() => expect(Tulis).toHaveBeenCalledWith('9882917230000000000000000001'));
    });

    it('e-wallet mengalihkan peramban ke aplikasi dompet lewat tombol, bukan otomatis', async () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        render(
            <PanelBayarSnap
                uuidTagihan="TG-1"
                pilihan={pilihan}
                awal={{
                    ...tampilanQr,
                    Metode: 'Dana',
                    Tampilan: 'Alihkan',
                    Label: 'DANA',
                    QrSvg: null,
                    UrlAlih: 'https://link.dana.id/pay/uji',
                }}
            />,
        );

        expect(Alihkan).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut ke DANA' }));

        expect(Alihkan).toHaveBeenCalledWith('https://link.dana.id/pay/uji');
    });

    it('galat server ditampilkan dan pilihan bayar tetap bisa dicoba lagi', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    JawabJson({ Galat: { Kode: 'GerbangMenolak', Pesan: 'DOKU menolak permintaan.' } }, 422),
                ),
        );
        render(<PanelBayarSnap uuidTagihan="TG-1" pilihan={pilihan} />);

        fireEvent.click(screen.getByRole('button', { name: 'QRIS' }));

        await waitFor(() => expect(screen.getByText('DOKU menolak permintaan.')).toBeTruthy());
        expect((screen.getByRole('button', { name: 'QRIS' }) as HTMLButtonElement).disabled).toBe(false);
    });

    it('menanyakan status ke server berkala dan memuat ulang halaman begitu tagihan lunas', async () => {
        const Ambil = vi
            .fn()
            .mockResolvedValueOnce(JawabJson({ Lunas: false, MasihMenunggu: true }))
            .mockResolvedValueOnce(JawabJson({ Lunas: true, MasihMenunggu: false }));
        vi.stubGlobal('fetch', Ambil);
        render(<PanelBayarSnap uuidTagihan="TG-1" pilihan={pilihan} awal={tampilanQr} jedaPolling={5000} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(tiruanRouter.reload).not.toHaveBeenCalled();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });

        expect(Ambil).toHaveBeenCalledWith('/kelola/langganan/tagihan/TG-1/status-bayar', expect.anything());
        expect(tiruanRouter.reload).toHaveBeenCalledTimes(1);
    });

    it('FormatSisaWaktu: mm:ss menuju batas, null bila sudah lewat atau tidak ada', () => {
        const sekarang = new Date('2026-09-27T03:00:00Z').getTime();

        expect(FormatSisaWaktu('2026-09-27T03:45:12Z', sekarang)).toBe('45:12');
        expect(FormatSisaWaktu('2026-09-27T03:00:00Z', sekarang)).toBeNull();
        expect(FormatSisaWaktu('2026-09-27T02:59:00Z', sekarang)).toBeNull();
        expect(FormatSisaWaktu(null, sekarang)).toBeNull();
    });
});
