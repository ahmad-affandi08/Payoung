import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanTagihanLangganan from '@/Halaman/Kelola/Langganan/Tagihan';
import { AturHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PembayaranLangganan, PilihanBayar, TagihanLangganan } from '@/Tipe/TagihanLangganan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const tagihan: TagihanLangganan = {
    Uuid: 'TG-1',
    Nomor: 'INV-2026-0001',
    Jenis: 'Aktivasi',
    LabelJenis: 'Aktivasi',
    Status: 'Terbit',
    Manual: false,
    Keterangan: null,
    LabelStatus: 'Menunggu pembayaran',
    KodePaket: 'PRO',
    NamaPaket: 'Pro',
    Siklus: 'Bulanan',
    JumlahBulan: 1,
    Subtotal: '200000.00',
    SubtotalPaket: '200000.00',
    RincianAddon: [],
    NaikPaket: null,
    KodeKupon: null,
    Diskon: '0.00',
    TarifPpn: '12.000000',
    PengaliDppPembilang: 11,
    PengaliDppPenyebut: 12,
    DasarPengenaanPajak: '183333.00',
    JumlahPpn: '22000.00',
    Total: '222000.00',
    TerbitPada: '2026-09-20T02:00:00Z',
    JatuhTempoPada: '2026-09-27T02:00:00Z',
    DibayarPada: null,
    DibatalkanPada: null,
    AlasanBatal: null,
    DihapusBukuPada: null,
    AlasanHapusBuku: null,
    DikembalikanPada: null,
    PeriodeMulai: null,
    PeriodeSelesai: null,
};

function RenderTagihan(tambahan: Partial<PropsRender> = {}) {
    return render(
        <HalamanTagihanLangganan
            Tagihan={tambahan.Tagihan ?? tagihan}
            Pembayaran={tambahan.Pembayaran ?? []}
            BolehBayarOnline={tambahan.BolehBayarOnline ?? false}
            BolehBatalkan={tambahan.BolehBatalkan ?? true}
            PilihanBayar={tambahan.PilihanBayar ?? []}
            BolehCheckout={tambahan.BolehCheckout ?? tambahan.BolehBayarOnline ?? false}
        />,
    );
}

type PropsRender = {
    BolehBayarOnline: boolean;
    BolehBatalkan: boolean;
    BolehCheckout: boolean;
    Tagihan: TagihanLangganan;
    Pembayaran: PembayaranLangganan[];
    PilihanBayar: PilihanBayar[];
};

const BuatPembayaranGerbang = (tambahan: Partial<PembayaranLangganan> = {}): PembayaranLangganan => ({
    Uuid: '01JABCDEFGHJKMNPQRSTVWXYZ0',
    Metode: 'Gateway',
    LabelMetode: 'Virtual account BCA',
    Status: 'Ditolak',
    LabelStatus: 'Ditolak',
    Jumlah: '222000.00',
    TanggalTransfer: null,
    BankPengirim: null,
    NamaPengirim: null,
    BankTujuan: null,
    NomorRekeningTujuan: null,
    NamaFileBukti: null,
    MimeBukti: null,
    DiunggahPada: '2026-09-20T07:12:00Z',
    DiverifikasiPada: null,
    AlasanTolak: null,
    Keadaan: 'Kedaluwarsa',
    KodeReferensi: 'PYG-TVWXYZ00',
    ...tambahan,
});

const pilihanVa: PilihanBayar[] = [
    { Metode: 'VirtualAccount', Label: 'Virtual account BCA', Kanal: 'VIRTUAL_ACCOUNT_BCA' },
    { Metode: 'VirtualAccount', Label: 'Virtual account BRI', Kanal: 'VIRTUAL_ACCOUNT_BRI' },
];

describe('Langganan/Tagihan (P-08): bayar online & konfirmasi batal', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/langganan/tagihan/TG-1'));
    afterEach(() => cleanup());

    it('batalkan tagihan hanya setelah dikonfirmasi', () => {
        RenderTagihan();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan tagihan' }));
        expect(screen.getByRole('alertdialog', { name: 'Batalkan tagihan INV-2026-0001?' })).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Jangan batalkan' }));
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan tagihan' }));
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan tagihan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/batalkan',
            {},
            expect.anything(),
        );
    });

    it('D-98: tagihan naik paket menjelaskan paket asal, paket baru, dan prorata harinya', () => {
        RenderTagihan({
            Tagihan: {
                ...tagihan,
                Jenis: 'NaikPaket',
                LabelJenis: 'Naik paket',
                NamaPaket: 'Bisnis',
                SubtotalPaket: '66667.00',
                Subtotal: '66667.00',
                NaikPaket: { NamaPaketAsal: 'Pro', HariDitagih: 10, HariPeriode: 30 },
            },
        });

        expect(screen.getByText(/Naik paket Pro ke Bisnis/)).toBeTruthy();
        expect(screen.getByText(/prorata 10 dari 30 hari periode berjalan/)).toBeTruthy();
    });

    it('tanpa izin batalkan: tidak ada tombol batalkan', () => {
        RenderTagihan({ BolehBatalkan: false });

        expect(screen.queryByRole('button', { name: 'Batalkan tagihan' })).toBeNull();
    });

    it('BR-P08.11: tombol bayar online hanya muncul saat gerbang billing DOKU aktif (BolehBayarOnline)', () => {
        RenderTagihan({ BolehBayarOnline: true });

        expect(screen.getByRole('button', { name: 'Bayar online' })).toBeTruthy();
    });

    it('BR-P08.11: gerbang belum dikonfigurasi atau tagihan tidak terbuka = tidak ada tombol bayar online', () => {
        RenderTagihan({ BolehBayarOnline: false });

        expect(screen.queryByRole('button', { name: 'Bayar online' })).toBeNull();
    });

    it('BR-P08.11: Bayar online meminta transaksi ke server lalu mengarahkan peramban ke halaman bayar DOKU', async () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        const Ambil = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ UrlBayar: 'https://sandbox.doku.com/checkout/link/abc' }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        vi.stubGlobal('fetch', Ambil);
        RenderTagihan({ BolehBayarOnline: true });

        fireEvent.click(screen.getByRole('button', { name: 'Bayar online' }));

        await waitFor(() => expect(Alihkan).toHaveBeenCalledWith('https://sandbox.doku.com/checkout/link/abc'));
        expect(Ambil).toHaveBeenCalledWith(
            '/kelola/langganan/tagihan/TG-1/bayar-online',
            expect.objectContaining({ method: 'POST' }),
        );
        vi.unstubAllGlobals();
    });

    it('BR-P08.11: gerbang menolak = pesan masalah tampil dan tombol bisa dicoba lagi (tanpa pengalihan)', async () => {
        const Alihkan = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign: Alihkan });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({ Galat: { Kode: 'GerbangMenolak', Pesan: 'Gerbang menolak permintaan.' } }),
                    {
                        status: 422,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            ),
        );
        RenderTagihan({ BolehBayarOnline: true });

        fireEvent.click(screen.getByRole('button', { name: 'Bayar online' }));

        expect(await screen.findByText('Gerbang menolak permintaan.')).toBeTruthy();
        expect(Alihkan).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Bayar online' }).hasAttribute('disabled')).toBe(false);
        vi.unstubAllGlobals();
    });
    it('D-305: tagihan lunas menampilkan layar berhasil beranimasi, bukan hanya banner', () => {
        const { container } = RenderTagihan({
            Tagihan: { ...tagihan, Status: 'Lunas', LabelStatus: 'Lunas', DibayarPada: '2026-09-21T03:00:00Z' },
            Pembayaran: [
                BuatPembayaranGerbang({ Status: 'Diterima', Keadaan: 'Berhasil', LabelMetode: 'QRIS lewat DOKU' }),
            ],
            BolehBatalkan: false,
        });

        expect(container.querySelector('[data-ilustrasi="berhasil"]')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Pembayaran berhasil' })).toBeTruthy();
        expect(screen.queryByRole('radiogroup', { name: 'Cara bayar' })).toBeNull();
    });

    it('D-305: tanpa riwayat, langsung pilihan cara bayar dan satu tombol utama', () => {
        RenderTagihan({ BolehBayarOnline: true, PilihanBayar: pilihanVa });

        expect(screen.getByRole('radiogroup', { name: 'Cara bayar' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Lanjut dengan BCA' })).toBeTruthy();
        expect(screen.queryByText('Riwayat pembayaran')).toBeNull();
    });

    it('D-305: pembayaran kedaluwarsa dijelaskan dulu, lalu Pilih cara bayar membuka pilihan lagi', () => {
        RenderTagihan({
            BolehBayarOnline: true,
            PilihanBayar: pilihanVa,
            Pembayaran: [BuatPembayaranGerbang({ Keadaan: 'Kedaluwarsa' })],
        });

        expect(screen.getByRole('heading', { name: 'Waktu bayar habis' })).toBeTruthy();
        expect(screen.queryByRole('radiogroup', { name: 'Cara bayar' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Pilih cara bayar' }));

        expect(screen.getByRole('radiogroup', { name: 'Cara bayar' })).toBeTruthy();
    });

    it('D-305: belum pasti (kasus 4002601 lama) tampil sebagai Kami masih memeriksa dengan kode referensi, bukan Ditolak', () => {
        const { container } = RenderTagihan({
            BolehBayarOnline: true,
            PilihanBayar: pilihanVa,
            Pembayaran: [
                BuatPembayaranGerbang({
                    Keadaan: 'TidakPasti',
                    AlasanTolak: 'Pembayaran online tidak selesai (status gerbang: 4002601).',
                }),
            ],
        });

        expect(screen.getByRole('heading', { name: 'Kami masih memeriksa' })).toBeTruthy();
        expect(screen.getAllByText('PYG-TVWXYZ00').length).toBeGreaterThan(0);
        expect(screen.getByRole('button', { name: 'Cek lagi' })).toBeTruthy();
        expect(container.textContent).not.toMatch(/4002601|status gerbang/);
    });

    it('D-305: kembali dari DOKU (pembayaran menunggu tanpa kode bayar) tampil sebagai Memeriksa pembayaran Anda', () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response(JSON.stringify({ Lunas: false, MasihMenunggu: true }))),
        );
        const { container } = RenderTagihan({
            BolehBayarOnline: true,
            PilihanBayar: pilihanVa,
            Pembayaran: [BuatPembayaranGerbang({ Status: 'Menunggu', Keadaan: 'Menunggu', LabelMetode: 'DOKU' })],
        });

        expect(screen.getByRole('heading', { name: 'Memeriksa pembayaran Anda' })).toBeTruthy();
        expect(container.querySelector('[data-ilustrasi="memeriksa"]')).toBeTruthy();
        vi.unstubAllGlobals();
    });

    it('D-305: kode bayar yang masih berlaku tampil sebagai instruksi transfer', () => {
        RenderTagihan({
            BolehBayarOnline: true,
            PilihanBayar: pilihanVa,
            Pembayaran: [
                BuatPembayaranGerbang({
                    Status: 'Menunggu',
                    Keadaan: 'Menunggu',
                    TampilanBayar: {
                        Metode: 'VirtualAccount',
                        Tampilan: 'NomorVa',
                        Label: 'Virtual account BCA',
                        QrSvg: null,
                        NomorVa: '3901081234567890',
                        NamaBank: 'BCA',
                        UrlAlih: null,
                        Jumlah: '222000.00',
                        KedaluwarsaPada: new Date(Date.now() + 30 * 60_000).toISOString(),
                    },
                }),
            ],
        });

        expect(screen.getByText('3901081234567890')).toBeTruthy();
        expect(
            within(screen.getByRole('region', { name: 'Pembayaran tagihan' })).getByText('Menunggu pembayaran'),
        ).toBeTruthy();
    });
});
