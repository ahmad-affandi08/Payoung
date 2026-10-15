import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import PanelBayarSnap, { FormatSisaWaktu } from '@/Komponen/Langganan/PanelBayarSnap';
import type { PilihanBayar, TampilanBayar } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const { UnduhTiruan } = vi.hoisted(() => ({ UnduhTiruan: vi.fn() }));
vi.mock('@/Pustaka/UnduhQr', async (asli) => ({
    ...(await asli<typeof import('@/Pustaka/UnduhQr')>()),
    UnduhGambarQr: UnduhTiruan,
}));

const pilihan: PilihanBayar[] = [
    { Metode: 'Qris', Label: 'QRIS', Kanal: null },
    { Metode: 'Dana', Label: 'DANA', Kanal: null },
    { Metode: 'VirtualAccount', Label: 'Virtual account BNI', Kanal: 'VIRTUAL_ACCOUNT_BNI' },
    { Metode: 'VirtualAccount', Label: 'Virtual account BRI', Kanal: 'VIRTUAL_ACCOUNT_BRI' },
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

const tampilanVa: TampilanBayar = {
    ...tampilanQr,
    Metode: 'VirtualAccount',
    Tampilan: 'NomorVa',
    Label: 'Virtual account BNI',
    QrSvg: null,
    NomorVa: '9882917230000000000000000001',
    NamaBank: 'BNI',
};

function JawabJson(isi: unknown, status = 200) {
    return new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } });
}

function Panel(tambahan: Partial<React.ComponentProps<typeof PanelBayarSnap>> = {}) {
    return render(
        <PanelBayarSnap
            uuidTagihan="TG-1"
            nomorTagihan="INV-2026-0042"
            total="222000.00"
            pilihan={pilihan}
            {...tambahan}
        />,
    );
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

    it('kepala menampilkan total dan nomor tagihan; pilihan dikelompokkan QRIS, dompet digital, virtual account', () => {
        Panel();

        expect(screen.getByText('Total pembayaran')).toBeTruthy();
        expect(screen.getByText('Rp 222.000')).toBeTruthy();
        expect(screen.getByText('Tagihan INV-2026-0042')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'QRIS' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Dompet digital' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Virtual account' })).toBeTruthy();
        expect(screen.getByRole('button', { name: /^DANA/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Virtual account BNI' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Virtual account BRI' })).toBeTruthy();
    });

    it('logo yang berkasnya belum dipasang tampil sebagai lencana teks, bukan gambar rusak', () => {
        const { container } = Panel();

        // Repo sengaja tanpa logo berlisensi tak jelas (lihat Aset/Bayar/README.md): semuanya lencana cadangan.
        expect(container.querySelectorAll('[data-logo="cadangan"]').length).toBeGreaterThanOrEqual(4);
        expect(container.querySelector('img')).toBeNull();
    });

    it('memilih QRIS meminta server lalu menampilkan QR, hitung mundur, dan petunjuk', async () => {
        const Ambil = vi.fn().mockResolvedValue(JawabJson({ Pembayaran: { TampilanBayar: tampilanQr } }));
        vi.stubGlobal('fetch', Ambil);
        Panel();

        fireEvent.click(screen.getByRole('button', { name: /^QRIS/ }));

        await waitFor(() => expect(screen.getByAltText('Kode QRIS tagihan langganan')).toBeTruthy());
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ Metode: 'Qris', Kanal: null }) }),
        );
        expect(screen.getByRole('timer').textContent).toMatch(/Selesaikan dalam \d{2}:\d{2}/);
        expect(screen.getByRole('button', { name: 'Unduh QR' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Pindai dari aplikasi bank atau dompet digital' })).toBeTruthy();
    });

    it('Unduh QR menyimpan gambar dengan nama berkas dari nomor tagihan', async () => {
        UnduhTiruan.mockResolvedValue(undefined);
        Panel({ awal: tampilanQr });

        fireEvent.click(screen.getByRole('button', { name: 'Unduh QR' }));

        await waitFor(() => expect(UnduhTiruan).toHaveBeenCalledWith(tampilanQr.QrSvg, 'QRIS-INV-2026-0042.png'));
    });

    it('gagal mengunduh QR menampilkan pesan, bukan diam', async () => {
        UnduhTiruan.mockRejectedValue(new Error('Gambar QR gagal dibuat.'));
        Panel({ awal: tampilanQr });

        fireEvent.click(screen.getByRole('button', { name: 'Unduh QR' }));

        await waitFor(() => expect(screen.getByText('Gambar QR gagal dibuat.')).toBeTruthy());
    });

    it('virtual account mengirim kanal bank; nomor VA dan jumlah masing-masing bisa disalin', async () => {
        const Ambil = vi.fn().mockResolvedValue(JawabJson({ Pembayaran: { TampilanBayar: tampilanVa } }));
        const Tulis = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', Ambil);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: Tulis }, configurable: true });
        Panel();

        fireEvent.click(screen.getByRole('button', { name: 'Virtual account BNI' }));

        await waitFor(() => expect(screen.getByText('9882917230000000000000000001')).toBeTruthy());
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({
                body: JSON.stringify({ Metode: 'VirtualAccount', Kanal: 'VIRTUAL_ACCOUNT_BNI' }),
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Salin nomor' }));
        await waitFor(() => expect(Tulis).toHaveBeenCalledWith('9882917230000000000000000001'));
        // Jumlah disalin sebagai angka bulat tanpa pemisah supaya bisa ditempel ke kolom nominal bank.
        fireEvent.click(screen.getByRole('button', { name: 'Salin jumlah' }));
        await waitFor(() => expect(Tulis).toHaveBeenCalledWith('222000'));
        expect(
            within(screen.getByRole('region', { name: 'Pembayaran tagihan' })).getAllByText('Rp 222.000').length,
        ).toBeGreaterThan(0);
    });

    it('virtual account menampilkan petunjuk ATM, mobile banking, dan internet banking', () => {
        Panel({ awal: tampilanVa });

        expect(screen.getByRole('button', { name: 'ATM' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Mobile banking' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Internet banking' })).toBeTruthy();
    });

    it('e-wallet mengalihkan peramban ke aplikasi dompet lewat tombol, bukan otomatis', () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        Panel({
            awal: {
                ...tampilanQr,
                Metode: 'Dana',
                Tampilan: 'Alihkan',
                Label: 'DANA',
                QrSvg: null,
                UrlAlih: 'https://link.dana.id/pay/uji',
            },
        });

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
        Panel();

        fireEvent.click(screen.getByRole('button', { name: /^QRIS/ }));

        await waitFor(() => expect(screen.getByText('DOKU menolak permintaan.')).toBeTruthy());
        expect((screen.getByRole('button', { name: /^QRIS/ }) as HTMLButtonElement).disabled).toBe(false);
    });

    it('menanyakan status ke server berkala dan memuat ulang halaman begitu tagihan lunas', async () => {
        const Ambil = vi
            .fn()
            .mockResolvedValueOnce(JawabJson({ Lunas: false, MasihMenunggu: true }))
            .mockResolvedValueOnce(JawabJson({ Lunas: true, MasihMenunggu: false }));
        vi.stubGlobal('fetch', Ambil);
        Panel({ awal: tampilanQr, jedaPolling: 5000 });

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

    it('Cek status pembayaran: belum terbayar memberi kabar, sudah lunas memuat ulang', async () => {
        const Ambil = vi
            .fn()
            .mockResolvedValueOnce(JawabJson({ Lunas: false, MasihMenunggu: true }))
            .mockResolvedValueOnce(JawabJson({ Lunas: true, MasihMenunggu: false }));
        vi.stubGlobal('fetch', Ambil);
        Panel({ awal: tampilanQr, jedaPolling: 3_600_000 });

        fireEvent.click(screen.getByRole('button', { name: 'Cek status pembayaran' }));
        await waitFor(() => expect(screen.getByText(/Pembayaran belum kami terima/)).toBeTruthy());
        expect(tiruanRouter.reload).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Cek status pembayaran' }));
        await waitFor(() => expect(tiruanRouter.reload).toHaveBeenCalledTimes(1));
    });

    it('FormatSisaWaktu: mm:ss menuju batas, null bila sudah lewat atau tidak ada', () => {
        const sekarang = new Date('2026-09-27T03:00:00Z').getTime();

        expect(FormatSisaWaktu('2026-09-27T03:45:12Z', sekarang)).toBe('45:12');
        expect(FormatSisaWaktu('2026-09-27T03:00:00Z', sekarang)).toBeNull();
        expect(FormatSisaWaktu('2026-09-27T02:59:00Z', sekarang)).toBeNull();
        expect(FormatSisaWaktu(null, sekarang)).toBeNull();
    });
});
