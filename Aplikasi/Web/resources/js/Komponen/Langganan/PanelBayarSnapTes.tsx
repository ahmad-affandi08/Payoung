import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import PanelBayarSnap, { FormatSisaWaktu, PisahBank } from '@/Komponen/Langganan/PanelBayarSnap';
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
            namaPenyedia="DOKU"
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

    it('kepala menampilkan total dan nomor tagihan. Cara bayar berupa pilihan tunggal dengan QRIS terpilih dan lencana Tercepat', () => {
        Panel();

        expect(screen.getByText('Total pembayaran')).toBeTruthy();
        expect(screen.getByText('Rp 222.000')).toBeTruthy();
        expect(screen.getByText('Tagihan INV-2026-0042')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Pilih cara bayar' })).toBeTruthy();
        expect(screen.getByRole('radio', { name: /^QRIS/ }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByRole('radio', { name: /^Transfer bank/ }).getAttribute('aria-checked')).toBe('false');
        expect(screen.getByRole('radio', { name: /^Dompet digital/ })).toBeTruthy();
        expect(screen.getByText('Tercepat')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Tampilkan kode QRIS' })).toBeTruthy();
    });

    it('D-305: hanya virtual account tersedia = dua jalur nyata: Transfer bank (Paling mudah) dan QRIS, e-wallet, atau kartu di halaman DOKU', () => {
        Panel({
            pilihan: pilihan.filter((baris) => baris.Metode === 'VirtualAccount'),
            bolehCheckout: true,
        });

        expect(screen.getAllByRole('radio', { name: /^(Transfer bank|QRIS, e-wallet, atau kartu)/ }).length).toBe(2);
        expect(screen.queryByRole('radio', { name: /^QRIS$/ })).toBeNull();
        expect(screen.queryByRole('radio', { name: /^Dompet digital/ })).toBeNull();
        expect(screen.getByRole('radio', { name: /^Transfer bank/ }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByText('Paling mudah')).toBeTruthy();
        expect(screen.getByText('Halaman DOKU')).toBeTruthy();
        // Bank populer lebih dulu (BRI sebelum BNI), yang pertama terpilih, dan tombol utama menyebut banknya.
        expect(screen.getByRole('radio', { name: 'BRI' }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByRole('button', { name: 'Lanjut dengan BRI' })).toBeTruthy();
    });

    it('D-305: Bank lain membuka sisa bank, dan memilihnya mengubah label tombol utama', () => {
        Panel({
            pilihan: [
                ...pilihan.filter((baris) => baris.Metode === 'VirtualAccount'),
                {
                    Metode: 'VirtualAccount',
                    Label: 'Virtual account Bank Antah Berantah',
                    Kanal: 'VIRTUAL_ACCOUNT_ANTAH',
                },
            ],
            bolehCheckout: true,
        });

        expect(screen.queryByRole('radio', { name: 'Bank Antah Berantah' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /^Bank lain \(1\)/ }));
        fireEvent.click(screen.getByRole('radio', { name: 'Bank Antah Berantah' }));

        expect(screen.getByRole('button', { name: 'Lanjut dengan Bank Antah Berantah' })).toBeTruthy();
    });

    it('D-305: nama penyedia di teks layar datang dari server, bukan tertulis di halaman (ganti penyedia tanpa sentuh teks)', () => {
        Panel({
            pilihan: pilihan.filter((baris) => baris.Metode === 'VirtualAccount'),
            bolehCheckout: true,
            namaPenyedia: 'Xendit',
        });

        expect(screen.getByText('Halaman Xendit')).toBeTruthy();
        expect(screen.getByText(/Pembayaran diproses Xendit/)).toBeTruthy();
        expect(screen.getByText(/halaman pembayaran Xendit/)).toBeTruthy();
        expect(document.body.textContent).not.toContain('DOKU');
    });

    it('PisahBank: bank populer berurutan BCA, BRI, Mandiri, BNI. Sisanya di lain', () => {
        const bank = ['BNI', 'Permata', 'BCA', 'Mandiri', 'BRI'].map((nama): PilihanBayar => ({
            Metode: 'VirtualAccount',
            Label: `Virtual account ${nama}`,
            Kanal: nama,
        }));

        const { utama, lain } = PisahBank(bank);

        expect(utama.map((baris) => baris.Kanal)).toEqual(['BCA', 'BRI', 'Mandiri', 'BNI']);
        expect(lain.map((baris) => baris.Kanal)).toEqual(['Permata']);
    });

    it('logo bank yang berkasnya terpasang tampil sebagai gambar, yang belum ada sebagai lencana teks', () => {
        const { container } = Panel({
            pilihan: [
                ...pilihan,
                {
                    Metode: 'VirtualAccount',
                    Label: 'Virtual account Bank Antah Berantah',
                    Kanal: 'VIRTUAL_ACCOUNT_ANTAH',
                },
            ],
        });

        fireEvent.click(screen.getByRole('radio', { name: /^Transfer bank/ }));
        fireEvent.click(screen.getByRole('button', { name: /^Bank lain/ }));

        // BRI dan BNI punya berkas di Aset/Bayar. Bank tak dikenal jatuh ke lencana, bukan gambar rusak.
        expect(container.querySelectorAll('[data-logo] img').length).toBeGreaterThanOrEqual(2);
        expect(container.querySelectorAll('[data-logo="cadangan"]').length).toBe(1);
    });

    it('QRIS terpilih sejak awal: satu ketukan meminta server lalu menampilkan QR, hitung mundur, dan petunjuk', async () => {
        const Ambil = vi.fn().mockResolvedValue(JawabJson({ Pembayaran: { TampilanBayar: tampilanQr } }));
        vi.stubGlobal('fetch', Ambil);
        Panel();

        fireEvent.click(screen.getByRole('button', { name: 'Tampilkan kode QRIS' }));

        await waitFor(() => expect(screen.getByAltText('Kode QRIS tagihan langganan')).toBeTruthy());
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ Metode: 'Qris', Kanal: null }) }),
        );
        expect(screen.getByRole('timer').textContent).toMatch(/Selesaikan dalam \d{2}:\d{2}/);
        expect(screen.getByRole('button', { name: 'Unduh QR' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Pindai dari aplikasi bank atau dompet digital' })).toBeTruthy();
        expect(screen.getByText('Menunggu pembayaran')).toBeTruthy();
    });

    it('D-305: Lanjut ke halaman DOKU meminta transaksi Checkout lalu mengarahkan peramban', async () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        const Ambil = vi.fn().mockResolvedValue(JawabJson({ UrlBayar: 'https://sandbox.doku.com/checkout/link/abc' }));
        vi.stubGlobal('fetch', Ambil);
        Panel({ pilihan: pilihan.filter((baris) => baris.Metode === 'VirtualAccount'), bolehCheckout: true });

        fireEvent.click(screen.getByRole('radio', { name: /^QRIS, e-wallet, atau kartu/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut ke halaman DOKU' }));

        await waitFor(() => expect(Alihkan).toHaveBeenCalledWith('https://sandbox.doku.com/checkout/link/abc'));
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('batas bayar yang lewat menampilkan Waktu bayar habis dan Pilih cara bayar kembali ke pilihan', () => {
        Panel({ awal: { ...tampilanVa, KedaluwarsaPada: new Date(Date.now() - 60_000).toISOString() } });

        expect(screen.getByText('Waktu bayar habis')).toBeTruthy();
        expect(screen.getByText(/tidak ada uang Anda yang terpotong/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Pilih cara bayar' }));

        expect(screen.getByRole('radiogroup', { name: 'Cara bayar' })).toBeTruthy();
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

    it('virtual account mengirim kanal bank. Nomor VA dan jumlah masing-masing bisa disalin', async () => {
        const Ambil = vi.fn().mockResolvedValue(JawabJson({ Pembayaran: { TampilanBayar: tampilanVa } }));
        const Tulis = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', Ambil);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: Tulis }, configurable: true });
        Panel();

        fireEvent.click(screen.getByRole('radio', { name: /^Transfer bank/ }));
        fireEvent.click(screen.getByRole('radio', { name: 'BNI' }));
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut dengan BNI' }));

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

    it('masalah server ditampilkan dan pilihan bayar tetap bisa dicoba lagi', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    JawabJson({ Galat: { Kode: 'GerbangMenolak', Pesan: 'DOKU menolak permintaan.' } }, 422),
                ),
        );
        Panel();

        fireEvent.click(screen.getByRole('button', { name: 'Tampilkan kode QRIS' }));

        await waitFor(() => expect(screen.getByText('DOKU menolak permintaan.')).toBeTruthy());
        expect((screen.getByRole('button', { name: 'Tampilkan kode QRIS' }) as HTMLButtonElement).disabled).toBe(false);
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
