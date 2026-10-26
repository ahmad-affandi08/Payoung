import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarPermintaanStok from '@/Halaman/Kelola/Persediaan/Permintaan/Daftar';
import HalamanDetailPermintaanStok from '@/Halaman/Kelola/Persediaan/Permintaan/Detail';
import HalamanFormPermintaanStok, { PeriksaBarisPermintaan } from '@/Halaman/Kelola/Persediaan/Permintaan/Form';
import HalamanFormTransferStok from '@/Halaman/Kelola/Persediaan/Transfer/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel, GudangKedua, GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';
import type {
    BarisDaftarPermintaanStok,
    IzinDokumenPersediaan,
    PropsDetailPermintaanStok,
} from '@/Tipe/DokumenPersediaan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/* D-105: halaman permintaan stok (daftar, form, detail) dan form transfer yang menjawab permintaan. */

const Uuid = '01J9ZC5V7Q8R2T4W6Y8A0B2C4E';
const IzinPenuh: IzinDokumenPersediaan = { Lihat: true, Kelola: true, Setujui: false, LihatJurnal: false };
const IzinLihat: IzinDokumenPersediaan = { Lihat: true, Kelola: false, Setujui: false, LihatJurnal: false };

function BarisPermintaan(ubah: Partial<BarisDaftarPermintaanStok> = {}): BarisDaftarPermintaanStok {
    return {
        Uuid,
        Nomor: 'MS/CAB2-GU/2610/0001',
        Tanggal: '2026-10-10',
        NamaGudangAsal: 'Gudang Utama',
        NamaOutletAsal: 'Kopi Nusantara Solo',
        NamaGudangTujuan: 'Toko Cabang Dua',
        NamaOutletTujuan: 'Cabang Dua',
        Status: 'Diajukan',
        LabelStatus: 'Diajukan',
        JumlahBaris: 2,
        PerluDikirim: true,
        ...ubah,
    };
}

function PropsDetail(ubah: Partial<PropsDetailPermintaanStok> = {}): PropsDetailPermintaanStok {
    return {
        Permintaan: {
            Uuid,
            Nomor: 'MS/CAB2-GU/2610/0001',
            Status: 'Diajukan',
            LabelStatus: 'Diajukan',
            Tanggal: '2026-10-10',
            NamaGudangAsal: 'Gudang Utama',
            NamaOutletAsal: 'Kopi Nusantara Solo',
            UuidGudangAsal: GudangUtama.Uuid,
            NamaGudangTujuan: 'Toko Cabang Dua',
            NamaOutletTujuan: 'Cabang Dua',
            UuidGudangTujuan: GudangKedua.Uuid,
            Catatan: 'Rak depan kosong',
            JumlahBaris: 1,
            Alasan: null,
            DibuatOleh: 'Dewi',
            DibuatPada: '2026-10-10T02:00:00Z',
            DitutupOleh: null,
            DitutupPada: null,
        },
        Baris: [
            {
                Urutan: 1,
                UuidProduk: '01J9PRD0000000000000000001',
                NamaProduk: 'Minyak Goreng Sawit 2 L',
                Sku: 'MYK-2L',
                SimbolSatuan: 'pcs',
                BolehDesimal: false,
                Pelacakan: 'Tidak',
                JumlahDiminta: '30.0000',
                JumlahDikirim: '20.0000',
                JumlahSisa: '10.0000',
            },
        ],
        Transfer: [
            {
                Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4F',
                Nomor: 'TF/GU-CAB2/2610/0001',
                Tanggal: '2026-10-10',
                Status: 'Dikirim',
                LabelStatus: 'Dalam perjalanan',
                JumlahBaris: 1,
            },
        ],
        Riwayat: [],
        Tindakan: { Penuhi: true, Tolak: true, Batalkan: false, Tutup: false },
        Izin: IzinPenuh,
        ...ubah,
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/permintaan'));
afterEach(cleanup);

describe('D-105 daftar permintaan stok', () => {
    it('menandai permintaan yang perlu dikirim dan menawarkan "Minta stok" bagi pengelola persediaan', () => {
        RenderUji(
            <HalamanDaftarPermintaanStok
                Permintaan={BuatHasilTabel([BarisPermintaan()])}
                OpsiGudang={[GudangUtama]}
                OpsiStatus={[{ Nilai: 'Diajukan', Label: 'Diajukan' }]}
                Izin={IzinPenuh}
            />,
        );

        expect(screen.getAllByText('MS/CAB2-GU/2610/0001').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Perlu dikirim').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Minta stok' }).getAttribute('href')).toBe(
            '/kelola/persediaan/permintaan/buat',
        );
    });

    it('hanya-lihat: tanpa tombol minta, dengan pesan izin', () => {
        RenderUji(
            <HalamanDaftarPermintaanStok
                Permintaan={BuatHasilTabel([])}
                OpsiGudang={[GudangUtama]}
                OpsiStatus={[]}
                Izin={IzinLihat}
            />,
        );

        expect(screen.queryByRole('link', { name: 'Minta stok' })).toBeNull();
        expect(screen.getByText('Belum ada permintaan stok.')).toBeTruthy();
    });
});

describe('D-105 detail permintaan stok', () => {
    it('pengirim: tombol Penuhi menuju form transfer terisi, Tolak butuh alasan lalu mengirim POST', () => {
        RenderUji(<HalamanDetailPermintaanStok {...PropsDetail()} />);

        expect(screen.getByRole('link', { name: 'Penuhi dengan transfer' }).getAttribute('href')).toBe(
            `/kelola/persediaan/transfer/buat?permintaan=${Uuid}`,
        );
        expect(screen.queryByRole('button', { name: 'Batalkan permintaan' })).toBeNull();
        expect(screen.getByRole('link', { name: 'TF/GU-CAB2/2610/0001' })).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Tolak permintaan' }));
        const dialog = screen.getByRole('alertdialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak permintaan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        UbahNilai(within(dialog).getByLabelText(/Alasan/), 'Stok gudang sedang opname');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak permintaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/permintaan/${Uuid}/tolak`,
            { Alasan: 'Stok gudang sedang opname' },
            expect.anything(),
        );
    });

    it('peminta: bisa membatalkan, tidak melihat Penuhi/Tolak, dan melihat info menunggu pengirim', () => {
        RenderUji(
            <HalamanDetailPermintaanStok
                {...PropsDetail({ Tindakan: { Penuhi: false, Tolak: false, Batalkan: true, Tutup: false } })}
            />,
        );

        expect(screen.getByRole('button', { name: 'Batalkan permintaan' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Penuhi dengan transfer' })).toBeNull();
        expect(screen.getByText('Menunggu pengirim')).toBeTruthy();
    });

    it('permintaan yang berakhir menampilkan alasan', () => {
        const dasar = PropsDetail();
        RenderUji(
            <HalamanDetailPermintaanStok
                {...PropsDetail({
                    Permintaan: {
                        ...dasar.Permintaan,
                        Status: 'Ditolak',
                        LabelStatus: 'Ditolak',
                        Alasan: 'Sedang opname',
                    },
                    Tindakan: { Penuhi: false, Tolak: false, Batalkan: false, Tutup: false },
                })}
            />,
        );

        expect(screen.getByText('Alasan: Sedang opname')).toBeTruthy();
    });
});

describe('D-105 form permintaan stok', () => {
    it('PeriksaBarisPermintaan: jumlah wajib > 0 dan bulat untuk produk bulat', () => {
        const dasar = {
            UuidProduk: 'P',
            NamaProduk: 'Minyak',
            Sku: null,
            SimbolSatuan: 'pcs',
            BolehDesimal: false,
            Pelacakan: 'Tidak' as const,
        };

        expect(PeriksaBarisPermintaan({ ...dasar, Jumlah: '' })).toBe('Isi jumlah lebih dari 0.');
        expect(PeriksaBarisPermintaan({ ...dasar, Jumlah: '0' })).toBe('Isi jumlah lebih dari 0.');
        expect(PeriksaBarisPermintaan({ ...dasar, Jumlah: '2.5' })).toBe('Jumlah harus bilangan bulat.');
        expect(PeriksaBarisPermintaan({ ...dasar, Jumlah: '12' })).toBeNull();
        expect(PeriksaBarisPermintaan({ ...dasar, BolehDesimal: true, Jumlah: '2.5' })).toBeNull();
    });

    it('lokasi peminta terisi otomatis bila hanya satu. Ajukan tanpa barang ditolak lokal', () => {
        RenderUji(
            <HalamanFormPermintaanStok
                OpsiGudangTujuan={[GudangKedua]}
                OpsiGudangAsal={[GudangUtama, GudangKedua]}
                UuidGudangTujuanAwal={GudangKedua.Uuid}
                HariIni="2026-10-11"
                BatasBaris={500}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Ajukan permintaan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        // Pengirim satu-satunya (selain lokasi peminta) juga terisi otomatis (aturan outlet diisi otomatis, v3.23).
        expect(screen.queryByText('Pilih lokasi pengirim.')).toBeNull();
        expect(screen.getByText('Tambahkan minimal satu produk.')).toBeTruthy();
    });
});

describe('D-105 form transfer yang menjawab permintaan', () => {
    it('menampilkan pemberitahuan permintaan dan memuat baris dari permintaan', () => {
        RenderUji(
            <HalamanFormTransferStok
                Mode="Buat"
                Transfer={null}
                OpsiGudangAsal={[GudangUtama]}
                OpsiGudangTujuan={[GudangUtama, GudangKedua]}
                HariIni="2026-10-11"
                BatasBaris={500}
                Awal={{
                    UuidGudangAsal: GudangUtama.Uuid,
                    UuidGudangTujuan: GudangKedua.Uuid,
                    UuidPermintaanStok: Uuid,
                    NomorPermintaan: 'MS/CAB2-GU/2610/0001',
                    Baris: [
                        {
                            UuidProduk: '01J9PRD0000000000000000001',
                            NamaProduk: 'Minyak Goreng Sawit 2 L',
                            Sku: 'MYK-2L',
                            SimbolSatuan: 'pcs',
                            BolehDesimal: false,
                            Pelacakan: 'Tidak',
                            Jumlah: '10',
                            UuidBatchStok: null,
                            NomorBatch: null,
                            UuidNomorSeri: null,
                            NomorSeri: null,
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Menjawab permintaan MS/CAB2-GU/2610/0001')).toBeTruthy();
        expect(screen.getByText('Minyak Goreng Sawit 2 L')).toBeTruthy();
        expect((screen.getByLabelText('Jumlah Minyak Goreng Sawit 2 L') as HTMLInputElement).value).toBe('10');
    });
});
