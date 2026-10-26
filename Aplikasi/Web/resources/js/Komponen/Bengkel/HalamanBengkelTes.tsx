import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarKendaraan from '@/Halaman/Kelola/Bengkel/Kendaraan/Daftar';
import HalamanDaftarPerintahKerja from '@/Halaman/Kelola/Bengkel/PerintahKerja/Daftar';
import HalamanDetailPerintahKerja from '@/Halaman/Kelola/Bengkel/PerintahKerja/Detail';
import HalamanFormPerintahKerja from '@/Halaman/Kelola/Bengkel/PerintahKerja/Form';
import HalamanPersetujuanServis from '@/Halaman/Publik/PersetujuanServis';
import { BuatHasilTabel } from '@/Komponen/Katalog/DataUjiKatalog';
import {
    AturHalamanUji,
    kirimanForm,
    RenderUji,
    TambahPropsHalamanUji,
    tiruanRouter,
} from '@/Komponen/Katalog/TiruanInertia';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type {
    BarisDaftarPerintahKerja,
    PerintahKerja,
    PropsDetailPerintahKerja,
    PropsFormPerintahKerja,
    PropsPersetujuanServis,
} from '@/Tipe/Bengkel';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Bengkel (§9.10): daftar perintah kerja (aksi status), detail (estimasi berharga server, tautan persetujuan yang bisa
 * disalin, minta lewat WhatsApp, catat persetujuan sebagian), formulir tanpa kolom harga (mekanik hanya baris jasa),
 * halaman persetujuan publik (setujui sebagian), dan dialog kendaraan.
 */

const uuidPk = '01JBENGKEL0000000000000001';

const barisDaftar: BarisDaftarPerintahKerja = {
    Uuid: uuidPk,
    Nomor: 'WO/SLO/2610/0001',
    DibuatPada: '2026-10-13T02:30:00Z',
    Status: 'Disetujui',
    LabelStatus: 'Disetujui pelanggan',
    Kendaraan: {
        Uuid: '01JKENDARAAN00000000000001',
        Jenis: 'Kendaraan',
        NomorPolisi: 'AD 1234 XY',
        Label: 'Honda Vario 125 | 2021',
    },
    Pelanggan: { Uuid: '01JPELANGGAN00000000000001', Nama: 'Budi Santoso Wicaksono' },
    KmMasuk: 18250,
    Keluhan: 'Tarikan berat',
    Total: '200000.00',
    TotalDisetujui: '105000.00',
    Mekanik: ['Joko Susilo'],
    Outlet: { Uuid: '01JOUTLET00000000000000001', Nama: 'Bengkel Solo' },
    EstimasiSelesaiPada: null,
    ServisBerikutnyaPada: null,
    ServisBerikutnyaKm: null,
    Penjualan: null,
    TujuanStatus: ['Diagnosis', 'Dikerjakan', 'Dibatalkan'],
};

function BuatPerintahKerja(ubah: Partial<PerintahKerja> = {}): PerintahKerja {
    return {
        Uuid: uuidPk,
        Nomor: 'WO/SLO/2610/0001',
        Status: 'MenungguPersetujuan',
        LabelStatus: 'Menunggu persetujuan',
        DibuatPada: '2026-10-13T02:30:00Z',
        KmMasuk: 18250,
        Keluhan: 'Tarikan berat, rem depan bunyi',
        Diagnosis: 'Busi aus',
        EstimasiSelesaiPada: null,
        CatatanQc: null,
        AlasanBatal: null,
        Subtotal: '200000.00',
        Diskon: '0.00',
        Pajak: '0.00',
        Total: '200000.00',
        TotalDisetujui: '0.00',
        Outlet: { Uuid: '01JOUTLET00000000000000001', Nama: 'Bengkel Solo', Kode: 'SLO', Alamat: null },
        Pelanggan: {
            Uuid: '01JPELANGGAN00000000000001',
            Nama: 'Budi Santoso Wicaksono',
            NoHp: '0812-3456-7890',
            Alamat: null,
        },
        Kendaraan: {
            Uuid: '01JKENDARAAN00000000000001',
            Jenis: 'Kendaraan',
            NomorPolisi: 'AD 1234 XY',
            Label: 'Honda Vario 125 | 2021',
            Warna: 'Hitam',
            KmTerakhir: 18250,
        },
        Persetujuan: {
            Tautan: 'https://dashboard.payoung.id/bengkel-jaya/servis/' + 'a'.repeat(40),
            KedaluwarsaPada: '2026-10-20T02:30:00Z',
            DikirimPada: null,
            DiputuskanPada: null,
            DiputuskanLewat: null,
            CatatanPelanggan: null,
        },
        Penjualan: null,
        DitagihPada: null,
        ServisBerikutnyaPada: null,
        ServisBerikutnyaKm: null,
        PengingatServisTerkirimPada: null,
        Baris: [
            {
                Uuid: '01JBARIS000000000000000001',
                Urutan: 1,
                Jenis: 'Jasa',
                UuidProduk: '01JPRODUK00000000000000001',
                UuidProdukSatuan: '01JSATUAN00000000000000001',
                NamaProduk: 'Servis Ringan Motor Matik (Tune Up)',
                Sku: null,
                SimbolSatuan: 'pcs',
                Jumlah: '1.0000',
                HargaSatuan: '50000.00',
                Diskon: '0.00',
                Subtotal: '50000.00',
                Karyawan: { Uuid: '01JKARYAWAN000000000000001', Nama: 'Joko Susilo' },
                Catatan: null,
                NomorSeri: [],
                Disetujui: false,
                StokTersedia: null,
            },
            {
                Uuid: '01JBARIS000000000000000002',
                Urutan: 2,
                Jenis: 'Sparepart',
                UuidProduk: '01JPRODUK00000000000000002',
                UuidProdukSatuan: '01JSATUAN00000000000000002',
                NamaProduk: 'Busi Motor Iridium',
                Sku: 'BUSI-IR',
                SimbolSatuan: 'pcs',
                Jumlah: '1.0000',
                HargaSatuan: '85000.00',
                Diskon: '0.00',
                Subtotal: '85000.00',
                Karyawan: null,
                Catatan: null,
                NomorSeri: [],
                Disetujui: false,
                StokTersedia: '5.0000',
            },
        ],
        ...ubah,
    };
}

function PropsDetail(ubah: Partial<PropsDetailPerintahKerja> = {}): PropsDetailPerintahKerja {
    return {
        PerintahKerja: BuatPerintahKerja(),
        Riwayat: [{ Dari: null, Ke: 'Diterima', Label: 'Diterima', Pada: '2026-10-13T02:30:00Z', Alasan: null }],
        Tindakan: {
            Ubah: false,
            MintaPersetujuan: true,
            CatatPersetujuan: true,
            TujuanStatus: [
                { Nilai: 'Diagnosis', Label: 'Diagnosis' },
                { Nilai: 'Dibatalkan', Label: 'Dibatalkan' },
            ],
            AturServis: true,
        },
        HariIni: '2026-10-13',
        LihatPenjualan: true,
        ...ubah,
    };
}

describe('Bengkel (§9.10)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/bengkel/perintah-kerja'));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('daftar: nomor polisi, status berteks, total disetujui. Aksi baris mengirim status', () => {
        RenderUji(
            <HalamanDaftarPerintahKerja
                PerintahKerja={BuatHasilTabel([barisDaftar])}
                OpsiStatus={[{ Nilai: 'Disetujui', Label: 'Disetujui pelanggan' }]}
                OpsiOutlet={[{ Uuid: '01JOUTLET00000000000000001', Nama: 'Bengkel Solo' }]}
                OpsiMekanik={[{ Uuid: '01JKARYAWAN000000000000001', Nama: 'Joko Susilo' }]}
            />,
        );

        expect(screen.getAllByText('AD 1234 XY').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Disetujui pelanggan').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Disetujui Rp\s?105\.000/).length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Buat perintah kerja' }).getAttribute('href')).toBe(
            '/kelola/bengkel/perintah-kerja/buat',
        );

        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.queryByRole('menuitem', { name: 'Batalkan' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Mulai dikerjakan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/bengkel/perintah-kerja/${uuidPk}/status`,
            { Status: 'Dikerjakan' },
            { preserveScroll: true },
        );
    });

    it('detail: estimasi berharga server, salin tautan, minta lewat WhatsApp, catat persetujuan sebagian', () => {
        const Tulis = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: Tulis } });
        RenderUji(<HalamanDetailPerintahKerja {...PropsDetail()} />);

        expect(screen.getAllByText(/Rp\s?85\.000/).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Stok tersedia 5/).length).toBeGreaterThan(0);
        expect(screen.getByText(/servis\/a{40}/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Salin tautan' }));
        expect(Tulis).toHaveBeenCalledWith(`https://dashboard.payoung.id/bengkel-jaya/servis/${'a'.repeat(40)}`);

        fireEvent.click(screen.getByRole('button', { name: 'Kirim ulang lewat WhatsApp' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/bengkel/perintah-kerja/${uuidPk}/persetujuan`,
            { KirimWhatsapp: true },
            expect.objectContaining({ preserveScroll: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Catat persetujuan langsung' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.click(within(dialog).getByRole('checkbox', { name: /Busi Motor Iridium/ }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan persetujuan' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `/kelola/bengkel/perintah-kerja/${uuidPk}/persetujuan/catat`,
            { Setuju: true, Baris: ['01JBARIS000000000000000001'], Catatan: null },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('detail: batalkan butuh alasan minimal 5 huruf. Ditagih menautkan penjualan', () => {
        RenderUji(
            <HalamanDetailPerintahKerja
                {...PropsDetail({
                    PerintahKerja: BuatPerintahKerja({
                        Status: 'Ditagih',
                        LabelStatus: 'Sudah ditagih',
                        Penjualan: {
                            Uuid: '01JPENJUALAN00000000000001',
                            Nomor: 'INV/SLO/261013/K01-0001',
                            TanggalBisnis: '2026-10-13',
                            TotalAkhir: '105000.00',
                            Status: 'Selesai',
                        },
                        Persetujuan: { ...BuatPerintahKerja().Persetujuan, Tautan: null },
                    }),
                    Tindakan: {
                        Ubah: false,
                        MintaPersetujuan: false,
                        CatatPersetujuan: false,
                        TujuanStatus: [],
                        AturServis: true,
                    },
                })}
            />,
        );
        expect(screen.getByRole('link', { name: 'INV/SLO/261013/K01-0001' }).getAttribute('href')).toBe(
            '/kelola/penjualan/01JPENJUALAN00000000000001',
        );
        expect(screen.queryByRole('button', { name: /lewat WhatsApp/ })).toBeNull();
        cleanup();

        RenderUji(<HalamanDetailPerintahKerja {...PropsDetail()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Batalkan' }));
        const dialog = screen.getByRole('dialog');
        const kirim = within(dialog).getAllByRole('button', { name: 'Batalkan' }).at(-1) as HTMLButtonElement;
        expect(kirim.disabled).toBe(true);
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Alasan pembatalan/ }), {
            target: { value: 'Pelanggan ambil kendaraan' },
        });
        fireEvent.click(kirim);
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            `/kelola/bengkel/perintah-kerja/${uuidPk}/status`,
            { Status: 'Dibatalkan', Alasan: 'Pelanggan ambil kendaraan' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('formulir ubah: tanpa bidang harga. Mekanik hanya baris jasa. Dikirim PUT tanpa HargaSatuan', () => {
        const props: PropsFormPerintahKerja = {
            Isian: {
                Uuid: uuidPk,
                Nomor: 'WO/SLO/2610/0001',
                UuidOutlet: '01JOUTLET00000000000000001',
                UuidPelanggan: '01JPELANGGAN00000000000001',
                NamaPelanggan: 'Budi Santoso Wicaksono',
                Kendaraan: {
                    Uuid: '01JKENDARAAN00000000000001',
                    Jenis: 'Kendaraan',
                    NomorPolisi: 'AD 1234 XY',
                    Label: 'Honda Vario 125',
                    KmTerakhir: 18250,
                },
                KmMasuk: 18250,
                Keluhan: 'Tarikan berat',
                Diagnosis: null,
                EstimasiSelesaiPada: null,
                Baris: BuatPerintahKerja().Baris.map((b) => ({
                    Jenis: b.Jenis,
                    UuidProduk: b.UuidProduk ?? '',
                    UuidProdukSatuan: b.UuidProdukSatuan,
                    NamaProduk: b.NamaProduk,
                    SimbolSatuan: b.SimbolSatuan,
                    Jumlah: b.Jumlah,
                    Diskon: b.Diskon,
                    UuidKaryawan: b.Karyawan?.Uuid ?? null,
                    Catatan: null,
                    NomorSeri: b.Jenis === 'Sparepart' ? ['NGK-0001'] : [],
                    Pelacakan: b.Jenis === 'Sparepart' ? ('Seri' as const) : ('Tidak' as const),
                    StokTersedia: b.StokTersedia,
                    Satuan: [{ Uuid: b.UuidProdukSatuan ?? '', Simbol: 'pcs', Konversi: '1.0000' }],
                })),
            },
            Awal: null,
            OpsiOutlet: [{ Uuid: '01JOUTLET00000000000000001', Nama: 'Bengkel Solo' }],
            OpsiMekanik: [{ Uuid: '01JKARYAWAN000000000000001', Nama: 'Joko Susilo' }],
            HariBerlakuTautan: 7,
        };
        RenderUji(<HalamanFormPerintahKerja {...props} />);

        expect(screen.queryByRole('textbox', { name: /Harga/ })).toBeNull();
        expect(screen.getByRole('combobox', { name: /Mekanik Servis Ringan/ })).toBeTruthy();
        expect(screen.queryByRole('combobox', { name: /Mekanik Busi/ })).toBeNull();
        // Sparepart bernomor seri: kotak nomor seri terisi dari perintah kerja; satu nomor per baris (koma juga boleh).
        const kotakSeri = screen.getByRole('textbox', { name: /Nomor seri Busi Motor Iridium/ });
        expect((kotakSeri as HTMLTextAreaElement).value).toBe('NGK-0001');
        expect(screen.queryByRole('textbox', { name: /Nomor seri Servis/ })).toBeNull();
        fireEvent.change(kotakSeri, { target: { value: 'NGK-0001\n ngk-0002 ,\n' } });

        fireEvent.click(screen.getByRole('button', { name: 'Simpan perintah kerja' }));
        expect(tiruanRouter.put).toHaveBeenCalledTimes(1);
        const [url, data] = tiruanRouter.put.mock.calls[0] as [string, { Baris: Record<string, unknown>[] }];
        expect(url).toBe(`/kelola/bengkel/perintah-kerja/${uuidPk}`);
        expect(data.Baris).toEqual([
            {
                Jenis: 'Jasa',
                UuidProduk: '01JPRODUK00000000000000001',
                UuidProdukSatuan: '01JSATUAN00000000000000001',
                Jumlah: '1',
                Diskon: null,
                UuidKaryawan: '01JKARYAWAN000000000000001',
                Catatan: null,
                NomorSeri: [],
            },
            {
                Jenis: 'Sparepart',
                UuidProduk: '01JPRODUK00000000000000002',
                UuidProdukSatuan: '01JSATUAN00000000000000002',
                Jumlah: '1',
                Diskon: null,
                UuidKaryawan: null,
                Catatan: null,
                NomorSeri: ['NGK-0001', 'ngk-0002'],
            },
        ]);
        expect(data.Baris.some((b) => 'HargaSatuan' in b)).toBe(false);
    });

    it('halaman persetujuan publik: setujui sebagian mengirim baris terpilih. Setelah diputuskan tanpa tombol', () => {
        const pk = BuatPerintahKerja();
        const props: PropsPersetujuanServis = {
            NamaToko: 'Bengkel Jaya Motor',
            AlamatDasar: `https://dashboard.payoung.id/bengkel-jaya/servis/${'a'.repeat(40)}`,
            PerintahKerja: {
                Nomor: pk.Nomor,
                Status: 'MenungguPersetujuan',
                LabelStatus: 'Menunggu persetujuan',
                NamaPelanggan: pk.Pelanggan.Nama,
                Kendaraan: { Jenis: 'Kendaraan', NomorPolisi: 'AD 1234 XY', Label: 'Honda Vario 125 | 2021' },
                KmMasuk: 18250,
                Keluhan: pk.Keluhan,
                Diagnosis: pk.Diagnosis,
                EstimasiSelesaiPada: null,
                Subtotal: '135000.00',
                Diskon: '0.00',
                Pajak: '0.00',
                Total: '135000.00',
                TotalDisetujui: '0.00',
                BolehDiputuskan: true,
                DiputuskanPada: null,
                CatatanPelanggan: null,
                Baris: pk.Baris.map((b) => ({
                    Uuid: b.Uuid,
                    Jenis: b.Jenis,
                    NamaProduk: b.NamaProduk,
                    Jumlah: b.Jumlah,
                    SimbolSatuan: b.SimbolSatuan,
                    HargaSatuan: b.HargaSatuan,
                    Diskon: b.Diskon,
                    Subtotal: b.Subtotal,
                    Catatan: null,
                    Disetujui: false,
                })),
            },
        };
        RenderUji(<HalamanPersetujuanServis {...props} />);

        expect(screen.getByRole('button', { name: 'Setujui semua' })).toBeTruthy();
        fireEvent.click(screen.getByRole('checkbox', { name: /Busi Motor Iridium/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Setujui 1 pekerjaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `${props.AlamatDasar}/setujui`,
            { Baris: ['01JBARIS000000000000000001'], Catatan: null },
            expect.objectContaining({ preserveScroll: true }),
        );
        cleanup();

        RenderUji(
            <HalamanPersetujuanServis
                {...props}
                PerintahKerja={{
                    ...props.PerintahKerja,
                    BolehDiputuskan: false,
                    Status: 'Disetujui',
                    LabelStatus: 'Disetujui pelanggan',
                    TotalDisetujui: '50000.00',
                }}
            />,
        );
        expect(screen.queryByRole('button', { name: /Setujui/ })).toBeNull();
        expect(screen.getByText('Estimasi sudah diputuskan')).toBeTruthy();
        expect(screen.getByText('Total yang Anda setujui')).toBeTruthy();
    });

    it('kendaraan: tambah kendaraan lewat dialog mengirim ke /kelola/bengkel/kendaraan', () => {
        AturHalamanUji({}, '/kelola/bengkel/kendaraan');
        TambahPropsHalamanUji({ SektorOutlet: ['SVC-WRK'] });
        RenderUji(<HalamanDaftarKendaraan Kendaraan={BuatHasilTabel([])} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah kendaraan' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Nomor polisi/ }), {
            target: { value: 'ad1234xy' },
        });
        fireEvent.change(within(dialog).getByRole('textbox', { name: /Merek/ }), { target: { value: 'Honda' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan kendaraan' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/bengkel/kendaraan',
            data: { NomorPolisi: 'ad1234xy', Merek: 'Honda', Aktif: true },
        });
    });

    it('perangkat (toko elektronik): dialog meminta IMEI opsional tanpa KM/rangka/mesin dan mengirim Jenis Perangkat', () => {
        AturHalamanUji({}, '/kelola/bengkel/kendaraan');
        TambahPropsHalamanUji({ SektorOutlet: ['RTL-ELC'] });
        RenderUji(<HalamanDaftarKendaraan Kendaraan={BuatHasilTabel([])} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tambah perangkat' }));
        const dialog = screen.getByRole('dialog');

        expect(within(dialog).getByRole('textbox', { name: /IMEI \/ nomor seri/ })).toBeTruthy();
        expect(within(dialog).queryByRole('textbox', { name: /Nomor rangka/ })).toBeNull();
        expect(within(dialog).queryByRole('textbox', { name: /Nomor mesin/ })).toBeNull();
        expect(within(dialog).queryByText(/KM terakhir/)).toBeNull();

        fireEvent.change(within(dialog).getByRole('textbox', { name: /Merek/ }), { target: { value: 'Samsung' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan perangkat' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/bengkel/kendaraan',
            data: { Jenis: 'Perangkat', NomorPolisi: '', Merek: 'Samsung', Aktif: true },
        });
    });

    it('daftar perintah kerja memakai merek & tipe sebagai judul unit perangkat dan IMEI sebagai keterangan', () => {
        RenderUji(
            <HalamanDaftarPerintahKerja
                PerintahKerja={BuatHasilTabel([
                    {
                        ...barisDaftar,
                        Kendaraan: {
                            Uuid: '01JKENDARAAN00000000000002',
                            Jenis: 'Perangkat',
                            NomorPolisi: '356938035643809',
                            Label: 'Samsung Galaxy A54',
                        },
                    },
                ])}
                OpsiStatus={[{ Nilai: 'Disetujui', Label: 'Disetujui pelanggan' }]}
                OpsiOutlet={[{ Uuid: '01JOUTLET00000000000000001', Nama: 'Bengkel Solo' }]}
                OpsiMekanik={[]}
            />,
        );

        expect(screen.getAllByText('Samsung Galaxy A54').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/356938035643809/).length).toBeGreaterThan(0);
    });
});
