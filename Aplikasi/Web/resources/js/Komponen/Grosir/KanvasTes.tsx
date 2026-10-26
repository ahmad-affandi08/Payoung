import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarKanvas, { BuatAlamatRekap } from '@/Halaman/Kelola/Grosir/Kanvas/Daftar';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import FormOutlet from '@/Komponen/Kelola/FormOutlet';
import type { IzinGrosir, KendaraanKanvas, PropsDaftarKanvas, RekapKanvas } from '@/Tipe/Grosir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const izin: IzinGrosir = { Kelola: true, SetujuiKredit: false, LihatJurnal: false, LihatPiutang: true };

const kendaraan: KendaraanKanvas = {
    Uuid: '01J9KNV0000000000000000001',
    Kode: 'KNV1',
    Nama: 'Kanvas AD 1234 XY',
    NomorKendaraan: 'AD 1234 XY',
    Status: 'Aktif',
    UuidGudang: '01J9GDG0000000000000000001',
    NamaGudang: 'Toko Kanvas AD 1234 XY',
};

const rekap: RekapKanvas = {
    Tanggal: '2026-10-02',
    Uang: {
        PenjualanTunai: '1500000.00',
        PenjualanTempo: '1000000.00',
        PenjualanLain: '0.00',
        RefundTunai: '100000.00',
        NilaiRetur: '100000.00',
        Bersih: '2400000.00',
        JumlahTransaksi: 2,
        JumlahVoid: 1,
        JumlahRetur: 1,
    },
    Setoran: {
        JumlahShiftTertutup: 1,
        JumlahShiftBelumDitutup: 0,
        KasAwal: '200000.00',
        KasSeharusnya: '1600000.00',
        KasAktual: '1575000.00',
        Selisih: '-25000.00',
    },
    Produk: [
        {
            UuidProduk: '01J9PRD0000000000000000001',
            NamaProduk: 'Mi Instan Goreng Spesial Rasa Ayam Bawang Karton 40 bungkus',
            Sku: 'MIG-40',
            Satuan: 'dus',
            Awal: '0.0000',
            Muat: '100.0000',
            Terjual: '50.0000',
            Retur: '2.0000',
            Bongkar: '40.0000',
            Lain: '0.0000',
            Sisa: '12.0000',
        },
    ],
};

function Props(ubah: Partial<PropsDaftarKanvas> = {}): PropsDaftarKanvas {
    return {
        Kanvas: [kendaraan],
        Transfer: [],
        UuidTerpilih: kendaraan.Uuid,
        Tanggal: '2026-10-02',
        Rekap: rekap,
        BatasOutlet: { Batas: 5, Terpakai: 2 },
        Izin: izin,
        IzinKanvas: { TambahKendaraan: true, Transfer: true },
        ...ubah,
    };
}

describe('Grosir › Kanvas (Modul Salesman bagian 3)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/grosir/kanvas');
        window.history.replaceState({}, '', '/kelola/grosir/kanvas');
        kirimanForm.length = 0;
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('alamat rekap menyimpan kendaraan & tanggal di URL', () => {
        expect(BuatAlamatRekap('01J9KNV0000000000000000001', '2026-10-02')).toBe(
            '/kelola/grosir/kanvas?outlet=01J9KNV0000000000000000001&tanggal=2026-10-02',
        );
    });

    it('daftar kendaraan + rekap: muat, terjual, retur, bongkar, sisa per produk. Uang, setoran, dan selisih bertulisan', () => {
        RenderUji(<HalamanDaftarKanvas {...Props()} />);

        expect(screen.getAllByRole('link', { name: 'Kanvas AD 1234 XY' })[0]?.getAttribute('href')).toBe(
            '/kelola/grosir/kanvas?outlet=01J9KNV0000000000000000001&tanggal=2026-10-02',
        );
        expect(screen.getAllByText('AD 1234 XY').length).toBeGreaterThan(0);
        expect(screen.getByRole('region', { name: 'Rekap Kanvas AD 1234 XY' })).toBeTruthy();

        expect(
            screen.getAllByText('Mi Instan Goreng Spesial Rasa Ayam Bawang Karton 40 bungkus').length,
        ).toBeGreaterThan(0);
        expect(screen.getAllByText('100 dus').length).toBeGreaterThan(0);
        expect(screen.getAllByText('50 dus').length).toBeGreaterThan(0);
        expect(screen.getAllByText('40 dus').length).toBeGreaterThan(0);
        expect(screen.getAllByText('12 dus').length).toBeGreaterThan(0);

        expect(screen.getByText('Rp 1.500.000')).toBeTruthy();
        expect(screen.getByText('Rp 1.000.000')).toBeTruthy();
        expect(screen.getByText('Rp 1.575.000')).toBeTruthy();
        expect(screen.getByText('Kurang Rp 25.000')).toBeTruthy();
        expect(screen.getByText('2 transaksi | 1 void | 1 retur')).toBeTruthy();

        // D-95: tombol membawa lokasi bak kendaraan, jadi form transfer terisi lokasi tujuan (muat) / asal (bongkar).
        expect(screen.getByRole('link', { name: 'Muat stok' }).getAttribute('href')).toBe(
            '/kelola/persediaan/transfer/buat?tujuan=01J9GDG0000000000000000001',
        );
        expect(screen.getByRole('link', { name: 'Bongkar stok' }).getAttribute('href')).toBe(
            '/kelola/persediaan/transfer/buat?arah=bongkar&asal=01J9GDG0000000000000000001',
        );
    });

    it('D-95: transfer muat & bongkar hari itu tampil dengan tautan detail, surat jalan, dan daftar ambil', () => {
        RenderUji(
            <HalamanDaftarKanvas
                {...Props({
                    Transfer: [
                        {
                            Uuid: '01J9TRF0000000000000000001',
                            Nomor: 'TRF/20261002/0001',
                            Arah: 'Muat',
                            Status: 'Dikirim',
                            LabelStatus: 'Dikirim',
                            NamaLawan: 'Gudang Pusat',
                            JumlahBaris: 3,
                            BisaCetak: true,
                        },
                        {
                            Uuid: '01J9TRF0000000000000000002',
                            Nomor: null,
                            Arah: 'Bongkar',
                            Status: 'Draf',
                            LabelStatus: 'Draf',
                            NamaLawan: 'Gudang Pusat',
                            JumlahBaris: 1,
                            BisaCetak: false,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'TRF/20261002/0001' }).getAttribute('href')).toBe(
            '/kelola/persediaan/transfer/01J9TRF0000000000000000001',
        );
        expect(screen.getAllByText('Muat dari Gudang Pusat').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Bongkar ke Gudang Pusat').length).toBeGreaterThan(0);
        // Surat jalan hanya untuk yang sudah dikirim; daftar ambil tersedia juga untuk draf.
        const panel = within(screen.getByRole('region', { name: 'Transfer muat dan bongkar hari ini' }));
        expect(panel.getAllByRole('link', { name: 'Surat jalan' })).toHaveLength(1);
        expect(panel.getAllByRole('link', { name: 'Daftar ambil' })).toHaveLength(2);
    });

    it('tanpa izin transfer, tautan muat & bongkar tidak tampil. Shift terbuka ditandai', () => {
        RenderUji(
            <HalamanDaftarKanvas
                {...Props({
                    IzinKanvas: { TambahKendaraan: false, Transfer: false },
                    Rekap: {
                        ...rekap,
                        Setoran: { ...rekap.Setoran, JumlahShiftTertutup: 0, JumlahShiftBelumDitutup: 1 },
                    },
                })}
            />,
        );

        expect(screen.queryByRole('link', { name: 'Muat stok' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Tambah kendaraan kanvas' })).toBeNull();
        expect(screen.getByText('Belum ada shift yang ditutup')).toBeTruthy();
        expect(screen.getByText('1 shift belum ditutup')).toBeTruthy();
    });

    it('tambah kendaraan: plat dikirim huruf besar ke POST /kelola/grosir/kanvas', () => {
        RenderUji(<HalamanDaftarKanvas {...Props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tambah kendaraan kanvas' }));
        fireEvent.change(screen.getByLabelText('Nomor kendaraan'), { target: { value: 'ad 9876 zz' } });
        fireEvent.submit(screen.getByRole('form', { name: 'Tambah kendaraan kanvas' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/grosir/kanvas',
            { NomorKendaraan: 'AD 9876 ZZ' },
            expect.anything(),
        );
    });

    it('batas outlet paket penuh menonaktifkan tambah kendaraan dan menjelaskan sebabnya', () => {
        RenderUji(<HalamanDaftarKanvas {...Props({ BatasOutlet: { Batas: 2, Terpakai: 2 } })} />);

        expect((screen.getByRole('button', { name: 'Tambah kendaraan kanvas' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
        expect(screen.getByText('Batas outlet paket sudah tercapai')).toBeTruthy();
    });

    it('belum ada kendaraan: keadaan kosong menjelaskan cara menambah, tanpa panel rekap', () => {
        RenderUji(<HalamanDaftarKanvas {...Props({ Kanvas: [], UuidTerpilih: null, Rekap: null })} />);

        expect(
            screen.getByText(
                'Belum ada kendaraan kanvas. Tambahkan kendaraan, atau tandai outlet yang sudah ada sebagai outlet kanvas di menu Outlet.',
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('region', { name: /^Rekap/ })).toBeNull();
    });

    it('formulir outlet: sakelar "Outlet kanvas" memunculkan nomor kendaraan dan ikut dikirim', () => {
        RenderUji(
            <FormOutlet
                uuid="O-1"
                awal={{
                    Nama: 'Kopi Nusantara Sudirman',
                    Kode: 'JKT1',
                    Merek: 'M-1',
                    Alamat: '',
                    Telepon: '',
                    KodeKota: '',
                    ZonaWaktu: 'WIB',
                    JamTutupBuku: '04:00',
                    Pkp: false,
                    Nitku: '',
                    PungutPbjt: false,
                    Kanvas: false,
                    NomorKendaraan: '',
                }}
                merek={[{ Nilai: 'M-1', Label: 'Kopi Nusantara' }]}
                kota={[]}
            />,
        );

        expect(screen.queryByLabelText('Nomor kendaraan (opsional)')).toBeNull();
        fireEvent.click(screen.getByRole('switch', { name: 'Outlet kanvas (kendaraan salesman)' }));
        fireEvent.change(screen.getByLabelText('Nomor kendaraan (opsional)'), { target: { value: 'b 1 abc' } });
        fireEvent.submit(screen.getByLabelText('Nama outlet').closest('form') as HTMLFormElement);

        expect(kirimanForm[0]).toMatchObject({
            metode: 'put',
            url: '/kelola/outlet/O-1',
            data: { Kanvas: true, NomorKendaraan: 'B 1 ABC' },
        });
    });
});
