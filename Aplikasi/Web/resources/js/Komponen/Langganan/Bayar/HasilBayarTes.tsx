import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import HasilPembayaran from '@/Komponen/Langganan/Bayar/HasilPembayaran';
import IlustrasiBayar, { type JenisIlustrasiBayar } from '@/Komponen/Langganan/Bayar/IlustrasiBayar';
import LayarBerhasil from '@/Komponen/Langganan/Bayar/LayarBerhasil';
import MemeriksaPembayaran from '@/Komponen/Langganan/Bayar/MemeriksaPembayaran';
import RiwayatPembayaranTagihan from '@/Komponen/Langganan/RiwayatPembayaranTagihan';
import type { PembayaranLangganan, TagihanLangganan } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function JawabJson(isi: unknown, status = 200) {
    return new Response(JSON.stringify(isi), { status, headers: { 'Content-Type': 'application/json' } });
}

const BuatPembayaran = (tambahan: Partial<PembayaranLangganan> = {}): PembayaranLangganan => ({
    Uuid: '01JABCDEFGHJKMNPQRSTVWXYZ0',
    Metode: 'Gateway',
    LabelMetode: 'Virtual account BCA',
    Status: 'Ditolak',
    LabelStatus: 'Ditolak',
    Jumlah: '149000.00',
    TanggalTransfer: null,
    BankPengirim: null,
    NamaPengirim: null,
    BankTujuan: null,
    NomorRekeningTujuan: null,
    NamaFileBukti: null,
    MimeBukti: null,
    DiunggahPada: '2026-11-21T07:12:00Z',
    DiverifikasiPada: null,
    AlasanTolak: null,
    Keadaan: 'Kedaluwarsa',
    KodeReferensi: 'PYG-TVWXYZ00',
    ...tambahan,
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    vi.clearAllMocks();
});

describe('IlustrasiBayar (D-305): gerak SVG per hasil', () => {
    it.each<JenisIlustrasiBayar>(['berhasil', 'memeriksa', 'kedaluwarsa', 'tidakPasti', 'gagal'])(
        '%s: gambar penjelas yang disembunyikan dari pembaca layar dan membawa kelas geraknya',
        (jenis) => {
            const { container } = render(<IlustrasiBayar jenis={jenis} />);
            const svg = container.querySelector('svg');

            expect(svg?.getAttribute('aria-hidden')).toBe('true');
            expect(svg?.getAttribute('data-ilustrasi')).toBe(jenis);
            expect(svg?.getAttribute('class')).toContain(`ilustrasi-bayar--${jenis}`);
        },
    );

    it('berhasil menggambar lingkaran, centang, cincin, dan delapan percik', () => {
        const { container } = render(<IlustrasiBayar jenis="berhasil" />);

        expect(container.querySelector('.ib-lingkaran')).toBeTruthy();
        expect(container.querySelector('.ib-centang')).toBeTruthy();
        expect(container.querySelector('.ib-cincin')).toBeTruthy();
        expect(container.querySelectorAll('.ib-percik').length).toBe(8);
    });
});

describe('HasilPembayaran (D-305): hasil yang belum selesai dijelaskan, bukan "ditolak"', () => {
    it('kedaluwarsa: jelas tidak ada uang terpotong dan tanpa kode referensi', () => {
        render(<HasilPembayaran keadaan="Kedaluwarsa" kodeReferensi="PYG-TVWXYZ00" />);

        expect(screen.getByRole('heading', { name: 'Waktu bayar habis' })).toBeTruthy();
        expect(screen.getByText(/tidak ada uang Anda yang terpotong/)).toBeTruthy();
        expect(screen.queryByText('PYG-TVWXYZ00')).toBeNull();
    });

    it('belum pasti: bukan salah pengguna, jangan bayar lagi, dan kode referensi bisa disalin', async () => {
        const Tulis = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: Tulis }, configurable: true });
        render(<HasilPembayaran keadaan="TidakPasti" kodeReferensi="PYG-TVWXYZ00" />);

        expect(screen.getByRole('heading', { name: 'Kami masih memeriksa' })).toBeTruthy();
        expect(screen.getByText(/bukan salah Anda/)).toBeTruthy();
        expect(screen.getByText(/sebutkan kode referensi/)).toBeTruthy();
        expect(screen.getByText('PYG-TVWXYZ00')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Salin kode referensi' }));
        await waitFor(() => expect(Tulis).toHaveBeenCalledWith('PYG-TVWXYZ00'));
        expect(screen.getByRole('button', { name: 'Kode referensi tersalin' })).toBeTruthy();
    });

    it('gagal dan memeriksa memakai ilustrasinya masing-masing, memeriksa diumumkan sebagai status', () => {
        const { container, rerender: Gambar } = render(
            <HasilPembayaran keadaan="Gagal" kodeReferensi="PYG-TVWXYZ00" />,
        );
        expect(container.querySelector('[data-ilustrasi="gagal"]')).toBeTruthy();
        expect(screen.getByText('PYG-TVWXYZ00')).toBeTruthy();

        Gambar(<HasilPembayaran keadaan="Memeriksa" />);
        expect(container.querySelector('[data-ilustrasi="memeriksa"]')).toBeTruthy();
        expect(screen.getByRole('status')).toBeTruthy();
    });

    it('tidak pernah menampilkan kode gerbang atau kata Ditolak', () => {
        const { container } = render(<HasilPembayaran keadaan="TidakPasti" kodeReferensi="PYG-TVWXYZ00" />);

        expect(container.textContent).not.toMatch(/4002601|Ditolak|status gerbang/i);
    });
});

describe('LayarBerhasil (D-305)', () => {
    const tagihan = {
        Uuid: 'TG-1',
        Nomor: 'INV-2026-0042',
        NamaPaket: 'Pro',
        Total: '149000.00',
        DibayarPada: '2026-11-21T07:31:00Z',
        PeriodeSelesai: '2026-12-31T16:59:59Z',
    } as TagihanLangganan;

    it('menampilkan centang beranimasi, ringkasan pembayaran, dan dua jalan keluar', () => {
        const { container } = render(
            <LayarBerhasil
                tagihan={tagihan}
                pembayaran={BuatPembayaran({ Status: 'Diterima', Keadaan: 'Berhasil', LabelMetode: 'QRIS lewat DOKU' })}
            />,
        );

        expect(container.querySelector('[data-ilustrasi="berhasil"]')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Pembayaran berhasil' })).toBeTruthy();
        expect(screen.getByText(/Langganan Pro aktif sampai/)).toBeTruthy();
        expect(screen.getByText('Rp 149.000')).toBeTruthy();
        expect(screen.getByText('QRIS lewat DOKU')).toBeTruthy();
        expect(screen.getByText('INV-2026-0042')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Kembali ke Beranda' }).getAttribute('href')).toBe('/kelola');
        expect(screen.getByRole('link', { name: 'Lihat langganan' }).getAttribute('href')).toBe('/kelola/langganan');
    });

    it('tanpa baris cara bayar (mis. lunas lewat kupon) tetap tampil', () => {
        render(<LayarBerhasil tagihan={tagihan} pembayaran={null} />);

        expect(screen.queryByText('Cara bayar')).toBeNull();
        expect(screen.getByRole('heading', { name: 'Pembayaran berhasil' })).toBeTruthy();
    });
});

describe('MemeriksaPembayaran (D-305): kembali dari halaman DOKU', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    it('menanyakan status berkala dan memuat ulang begitu pembayaran berubah', async () => {
        const Ambil = vi
            .fn()
            .mockResolvedValueOnce(JawabJson({ Lunas: false, MasihMenunggu: true }))
            .mockResolvedValueOnce(JawabJson({ Lunas: true, MasihMenunggu: false }));
        vi.stubGlobal('fetch', Ambil);
        render(<MemeriksaPembayaran uuidTagihan="TG-1" jedaPolling={5000} />);

        expect(screen.getByRole('heading', { name: 'Memeriksa pembayaran Anda' })).toBeTruthy();

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

    it('Cek sekarang: belum terbayar memberi kabar, bukan memuat ulang', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(JawabJson({ Lunas: false, MasihMenunggu: true })));
        render(<MemeriksaPembayaran uuidTagihan="TG-1" jedaPolling={3_600_000} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cek sekarang' }));

        await waitFor(() => expect(screen.getByText(/Pembayaran belum kami terima/)).toBeTruthy());
        expect(tiruanRouter.reload).not.toHaveBeenCalled();
    });

    it('pembayaran sudah tidak menunggu (mis. ditutup) memuat ulang halaman', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(JawabJson({ Lunas: false, MasihMenunggu: false })));
        render(<MemeriksaPembayaran uuidTagihan="TG-1" jedaPolling={3_600_000} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cek sekarang' }));

        await waitFor(() => expect(tiruanRouter.reload).toHaveBeenCalledTimes(1));
    });
});

describe('RiwayatPembayaranTagihan (D-305)', () => {
    it('tertutup lebih dulu dan menghitung percobaan, lalu membuka dengan status berbahasa layar tanpa kode gerbang', () => {
        const { container } = render(
            <RiwayatPembayaranTagihan
                pembayaran={[
                    BuatPembayaran({
                        Keadaan: 'TidakPasti',
                        AlasanTolak: 'Pembayaran online tidak selesai (status gerbang: 4002601).',
                    }),
                    BuatPembayaran({ Uuid: '01JB', Keadaan: 'Kedaluwarsa', KodeReferensi: 'PYG-AAAABBBB' }),
                    BuatPembayaran({
                        Uuid: '01JC',
                        Metode: 'TransferManual',
                        LabelMetode: 'Transfer manual',
                        Keadaan: 'Ditolak',
                        AlasanTolak: 'Bukti buram',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('3 percobaan untuk tagihan ini')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /Riwayat pembayaran/ }));

        expect(screen.getByText('Belum pasti')).toBeTruthy();
        expect(screen.getByText('Kedaluwarsa')).toBeTruthy();
        expect(screen.getByText('Ditolak')).toBeTruthy();
        expect(screen.getByText('Alasan: Bukti buram')).toBeTruthy();
        expect(screen.getByText(/PYG-AAAABBBB/)).toBeTruthy();
        // Kode gerbang dari catatan lama tidak pernah sampai ke layar.
        expect(container.textContent).not.toMatch(/4002601|status gerbang/);
    });

    it('tanpa pembayaran tidak menampilkan apa pun', () => {
        const { container } = render(<RiwayatPembayaranTagihan pembayaran={[]} />);

        expect(container.textContent).toBe('');
    });
});
