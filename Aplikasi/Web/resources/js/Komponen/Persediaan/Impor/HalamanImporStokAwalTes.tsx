import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarImporStokAwal, {
    BuatUrlTemplatStokAwal,
    PeriksaBerkasImporStokAwal,
    RingkasHasilImporStokAwal,
} from '@/Halaman/Kelola/Persediaan/StokAwal/Impor/Daftar';
import HalamanDetailImporStokAwal, {
    BuatUrlLaporanImporStokAwal,
} from '@/Halaman/Kelola/Persediaan/StokAwal/Impor/Detail';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type {
    OpsiGudang,
    PropsDaftarImporStokAwal,
    PropsDetailImporStokAwal,
    RingkasanImporStokAwal,
} from '@/Tipe/Persediaan';

import { BatasiProgresStokAwal } from './KemajuanImporStokAwal';
import { PeriksaPemetaanStokAwal, type DataPemetaanStokAwal } from './PemetaanImporStokAwal';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const uuidImpor = '01J9STOKAWALIMPOR000000001';

const opsiGudang: OpsiGudang[] = [
    {
        Uuid: '01J9GUDANG0000000000000001',
        Kode: 'TK-01',
        Nama: 'Toko Depan',
        Jenis: 'Toko',
        NamaOutlet: 'Outlet Utama Solo',
        Aktif: true,
    },
    {
        Uuid: '01J9GUDANG0000000000000002',
        Kode: 'GDG-01',
        Nama: 'Gudang Belakang',
        Jenis: 'Gudang',
        NamaOutlet: null,
        Aktif: true,
    },
];

const batasBerkas: PropsDaftarImporStokAwal['BatasBerkas'] = {
    UkuranMaksimalKb: 10240,
    MaksimalBaris: 20000,
    Ekstensi: ['xlsx', 'csv'],
};

function BuatImpor(perubahan: Partial<RingkasanImporStokAwal> = {}): RingkasanImporStokAwal {
    return {
        Uuid: uuidImpor,
        NamaBerkas: 'stok awal toko sembako berkah jaya september 2026.xlsx',
        Status: 'MenungguPemetaan',
        LabelStatus: 'Menunggu pemetaan kolom',
        JumlahBaris: 1250,
        JumlahValid: 0,
        JumlahGalat: 0,
        JumlahDokumen: 0,
        Progres: 0,
        PesanGalat: null,
        NamaGudangBawaan: 'Toko Depan',
        BerkasPernahDiimpor: null,
        BolehLanjutkan: false,
        DibuatPada: '2026-09-24T03:00:00Z',
        SelesaiPada: null,
        NamaPengguna: 'Rina Wulandari',
        ...perubahan,
    };
}

const pemetaan: DataPemetaanStokAwal = {
    KolomSumber: [
        { Indeks: 0, Judul: 'SKU', Contoh: ['MGS-2L', 'GULA-CURAH'] },
        { Indeks: 1, Judul: 'Stok', Contoh: ['24', '12,5'] },
        { Indeks: 2, Judul: 'Harga Modal', Contoh: ['38.500', '14.250,75'] },
    ],
    Bidang: [
        { Kunci: 'Sku', Label: 'SKU', Wajib: false, Keterangan: 'Pencocok produk utama.' },
        { Kunci: 'Barcode', Label: 'Barcode', Wajib: false, Keterangan: '' },
        { Kunci: 'NamaProduk', Label: 'Nama Produk', Wajib: false, Keterangan: '' },
        { Kunci: 'Lokasi', Label: 'Lokasi Stok', Wajib: false, Keterangan: '' },
        { Kunci: 'Jumlah', Label: 'Stok', Wajib: true, Keterangan: '' },
        { Kunci: 'HargaModal', Label: 'Harga Modal', Wajib: true, Keterangan: '' },
        { Kunci: 'NomorBatch', Label: 'Nomor Batch', Wajib: false, Keterangan: '' },
        { Kunci: 'TanggalKedaluwarsa', Label: 'Kedaluwarsa', Wajib: false, Keterangan: '' },
        { Kunci: 'NomorSeri', Label: 'Nomor Seri', Wajib: false, Keterangan: '' },
    ],
    Pemetaan: {
        Sku: 0,
        Barcode: null,
        NamaProduk: null,
        Lokasi: null,
        Jumlah: 1,
        HargaModal: null,
        NomorBatch: null,
        TanggalKedaluwarsa: null,
        NomorSeri: null,
    },
    UuidGudangBawaan: null,
    Tanggal: '2026-09-23',
};

function PropsDetail(perubahan: Partial<PropsDetailImporStokAwal> = {}): PropsDetailImporStokAwal {
    return {
        Impor: BuatImpor(),
        Pemetaan: pemetaan,
        Pratinjau: null,
        Dokumen: [],
        OpsiGudang: opsiGudang,
        ...perubahan,
    };
}

describe('Impor stok awal langkah 1: unggah (DesainF05a E)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/stok-awal/impor'));
    afterEach(() => cleanup());

    it('keadaan kosong, tautan templat, pemeriksaan berkas, lalu unggah multipart { Berkas, UuidGudangBawaan }', () => {
        RenderUji(
            <HalamanDaftarImporStokAwal
                Riwayat={{ Data: [], Meta: { Halaman: 1, PerHalaman: 25, Total: 0, JumlahHalaman: 1 } }}
                OpsiStatus={[{ Nilai: 'Selesai', Label: 'Selesai' }]}
                OpsiGudang={opsiGudang}
                BatasBerkas={batasBerkas}
            />,
        );

        expect(screen.getByText('Belum pernah mengimpor stok awal. Riwayat disimpan 30 hari.')).toBeTruthy();
        expect(PeriksaBerkasImporStokAwal(new File(['x'], 'stok.pdf'), batasBerkas)).toBe(
            'Format stok.pdf tidak didukung. Pilih berkas xlsx atau csv.',
        );
        expect(BuatUrlTemplatStokAwal('xlsx', true, 'U1')).toBe(
            '/kelola/persediaan/stok-awal/impor/templat?format=xlsx&isi=produk&gudang=U1',
        );
        expect(BuatUrlTemplatStokAwal('csv', false, 'U1')).toBe(
            '/kelola/persediaan/stok-awal/impor/templat?format=csv',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Unggah & lanjut ke pemetaan' }));
        expect(screen.getByText('Pilih berkas Excel atau CSV lebih dulu.')).toBeTruthy();

        UbahNilai(screen.getByLabelText('Lokasi stok bawaan'), opsiGudang[1]?.Uuid ?? '');
        expect(
            screen.getByRole('link', { name: 'Unduh templat Excel berisi daftar produk' }).getAttribute('href'),
        ).toBe(`/kelola/persediaan/stok-awal/impor/templat?format=xlsx&isi=produk&gudang=${opsiGudang[1]?.Uuid ?? ''}`);

        const berkas = new File(['SKU,Stok\nMGS-2L,24'], 'stok.csv', { type: 'text/csv' });
        fireEvent.change(screen.getByLabelText('Berkas Excel atau CSV'), { target: { files: [berkas] } });
        fireEvent.click(screen.getByRole('button', { name: 'Unggah & lanjut ke pemetaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/persediaan/stok-awal/impor',
            { Berkas: berkas, UuidGudangBawaan: opsiGudang[1]?.Uuid },
            expect.objectContaining({ forceFormData: true }),
        );
    });

    it('tanpa lokasi stok yang bisa diakses: pesan kosong, tanpa form unggah. Ringkasan hasil riwayat', () => {
        RenderUji(
            <HalamanDaftarImporStokAwal
                Riwayat={{
                    Data: [
                        BuatImpor({ Status: 'Selesai', LabelStatus: 'Selesai', JumlahValid: 12000, JumlahDokumen: 6 }),
                    ],
                    Meta: { Halaman: 1, PerHalaman: 25, Total: 1, JumlahHalaman: 1 },
                }}
                OpsiStatus={[{ Nilai: 'Selesai', Label: 'Selesai' }]}
                OpsiGudang={[]}
                BatasBerkas={batasBerkas}
            />,
        );

        expect(screen.getByText('Belum ada lokasi stok yang bisa Anda akses.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Unggah & lanjut ke pemetaan' })).toBeNull();
        expect(screen.getByText('6 draf dibuat dari 12.000 baris')).toBeTruthy();
        expect(RingkasHasilImporStokAwal(BuatImpor({ Status: 'Pratinjau', JumlahValid: 3, JumlahGalat: 2 }))).toBe(
            '3 valid, 2 bermasalah',
        );
    });
});

describe('Impor stok awal langkah 2–5 (DesainF05a E)', () => {
    beforeEach(() => AturHalamanUji({}, `/kelola/persediaan/stok-awal/impor/${uuidImpor}`));
    afterEach(() => cleanup());

    it('pemeriksaan pemetaan lokal: pencocok produk, bidang wajib, lokasi atau lokasi bawaan, kolom ganda', () => {
        const kosong = { ...pemetaan.Pemetaan, Sku: null };
        expect(PeriksaPemetaanStokAwal(pemetaan.Bidang, kosong, null, '2026-09-23')).toEqual({
            Sku: 'Petakan minimal satu kolom pencocok produk: SKU, Barcode, atau Nama Produk.',
            Lokasi: 'Petakan kolom Lokasi Stok atau pilih lokasi stok bawaan.',
            HargaModal: 'Harga Modal wajib dipetakan ke satu kolom.',
        });
        expect(PeriksaPemetaanStokAwal(pemetaan.Bidang, { ...pemetaan.Pemetaan, HargaModal: 1 }, 'U', '')).toEqual({
            HargaModal: 'Kolom ini sudah dipakai untuk Stok.',
            Tanggal: 'Isi tanggal stok awal.',
        });
        expect(BatasiProgresStokAwal(-3)).toBe(0);
        expect(BuatUrlLaporanImporStokAwal('U', 'semua', 'csv')).toBe(
            '/kelola/persediaan/stok-awal/impor/U/laporan?jenis=semua&format=csv',
        );
    });

    it('pemetaan: masalah ditampilkan, lalu PUT { Pemetaan, UuidGudangBawaan, Tanggal }', () => {
        RenderUji(<HalamanDetailImporStokAwal {...PropsDetail()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Periksa data' }));
        expect(tiruanRouter.put).not.toHaveBeenCalled();
        expect(screen.getByText('Ada 2 isian yang perlu diperbaiki.')).toBeTruthy();

        UbahNilai(screen.getByLabelText('Kolom untuk Harga Modal'), '2');
        expect(screen.getByText('38.500 | 14.250,75')).toBeTruthy();
        UbahNilai(screen.getByLabelText('Lokasi stok bawaan'), opsiGudang[0]?.Uuid ?? '');
        UbahNilai(screen.getByLabelText('Tanggal stok awal'), '2026-09-01');
        fireEvent.click(screen.getByRole('button', { name: 'Periksa data' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            `/kelola/persediaan/stok-awal/impor/${uuidImpor}/pemetaan`,
            {
                Pemetaan: { ...pemetaan.Pemetaan, HargaModal: 2 },
                UuidGudangBawaan: opsiGudang[0]?.Uuid,
                Tanggal: '2026-09-01',
            },
            expect.anything(),
        );
    });

    it('pratinjau: draf per lokasi dengan total Rupiah, baris masalah, laporan masalah, dan tombol buat draf', () => {
        RenderUji(
            <HalamanDetailImporStokAwal
                {...PropsDetail({
                    Impor: BuatImpor({
                        Status: 'Pratinjau',
                        LabelStatus: 'Siap diimpor',
                        JumlahValid: 2400,
                        JumlahGalat: 3,
                    }),
                    Pratinjau: {
                        BarisGalat: [
                            {
                                NomorBaris: 7,
                                Galat: [{ Bidang: 'Produk', Pesan: 'Produk "KOPI-X" tidak ditemukan.' }],
                                Data: { Produk: 'KOPI-X', SKU: 'KOPI-X' },
                            },
                        ],
                        RingkasanDokumen: [
                            { NamaGudang: 'Toko Depan', JumlahBaris: 2000, TotalNilai: '1250000000.00' },
                            { NamaGudang: 'Toko Depan', JumlahBaris: 400, TotalNilai: '38500.50' },
                        ],
                        Peringatan: [],
                    },
                })}
            />,
        );

        expect(screen.getByText('Rp 1.250.000.000')).toBeTruthy();
        expect(screen.getByText('Produk "KOPI-X" tidak ditemukan.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Unduh laporan masalah (Excel)' }).getAttribute('href')).toBe(
            `/kelola/persediaan/stok-awal/impor/${uuidImpor}/laporan?jenis=galat&format=xlsx`,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Buat draf dari 2.400 baris valid' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/stok-awal/impor/${uuidImpor}/terapkan`,
            {},
            expect.anything(),
        );
    });

    it('selesai: daftar draf bertaut ke detail stok awal; gagal: pesan dan tombol lanjutkan', () => {
        RenderUji(
            <HalamanDetailImporStokAwal
                {...PropsDetail({
                    Impor: BuatImpor({ Status: 'Selesai', LabelStatus: 'Selesai', JumlahValid: 2, JumlahDokumen: 1 }),
                    Pemetaan: null,
                    Dokumen: [
                        {
                            Uuid: '01J9STOKAWAL00000000000001',
                            NamaGudang: 'Gudang Belakang',
                            JumlahBaris: 2,
                            Status: 'Draf',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('link', { name: 'Gudang Belakang' }).getAttribute('href')).toBe(
            '/kelola/persediaan/stok-awal/01J9STOKAWAL00000000000001',
        );
        expect(screen.queryByRole('button', { name: 'Batalkan impor' })).toBeNull();
        cleanup();

        RenderUji(
            <HalamanDetailImporStokAwal
                {...PropsDetail({
                    Impor: BuatImpor({
                        Status: 'Gagal',
                        LabelStatus: 'Gagal',
                        PesanGalat: 'Draf stok awal gagal dibuat: produk diarsipkan.',
                        BolehLanjutkan: true,
                        JumlahDokumen: 2,
                    }),
                    Pemetaan: null,
                })}
            />,
        );

        expect(screen.getByText('Draf stok awal gagal dibuat: produk diarsipkan.')).toBeTruthy();
        expect(screen.getByText('2 draf sudah dibuat dan tidak akan diulang.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Lanjutkan impor' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/stok-awal/impor/${uuidImpor}/lanjutkan`,
            {},
            expect.anything(),
        );
    });
});
