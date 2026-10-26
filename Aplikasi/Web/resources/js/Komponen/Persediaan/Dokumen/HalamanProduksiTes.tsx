import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarOrderProduksi from '@/Halaman/Kelola/Persediaan/Produksi/Daftar';
import HalamanDetailOrderProduksi from '@/Halaman/Kelola/Persediaan/Produksi/Detail';
import HalamanFormOrderProduksi, { CekJumlahProduksi } from '@/Halaman/Kelola/Persediaan/Produksi/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel, GudangKedua, GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { IzinDokumenPersediaan } from '@/Tipe/DokumenPersediaan';
import type { PropsDetailOrderProduksi } from '@/Tipe/Produksi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Uuid = '01J9ZC5V7Q8R2T4W6Y8A0B2C4D';
const IzinPenuh: IzinDokumenPersediaan = { Lihat: true, Kelola: true, Setujui: true, LihatJurnal: true };
const IzinLihat: IzinDokumenPersediaan = { Lihat: true, Kelola: false, Setujui: false, LihatJurnal: false };

function PropsDetail(perubahan: Partial<PropsDetailOrderProduksi> = {}): PropsDetailOrderProduksi {
    return {
        Order: {
            Uuid,
            Nomor: 'PR/TOKO/2609/0001',
            Status: 'Diposting',
            LabelStatus: 'Diposting',
            NamaGudang: 'Dapur',
            NamaOutlet: 'Outlet Utama',
            Tanggal: '2026-09-26',
            NamaProduk: 'Roti Sobek Cokelat',
            Sku: 'RTI-01',
            SimbolSatuan: 'pcs',
            JumlahHasil: '20.0000',
            VersiResep: 1,
            BiayaOverhead: '50000.00',
            NomorBatch: null,
            TanggalKedaluwarsa: null,
            Keterangan: null,
            TotalNilaiBahan: '144500.00',
            NilaiHasil: '194500.00',
            HppSatuanHasil: '9725.000000',
            AlasanBatal: null,
            DibuatOleh: 'Sari',
            DipostingOleh: 'Sari',
            DipostingPada: '2026-09-26T03:00:00Z',
            DibatalkanOleh: null,
            DibatalkanPada: null,
        },
        Bahan: [
            {
                UuidProduk: '01J9PRD0000000000000000001',
                NamaProduk: 'Gula Pasir',
                Sku: null,
                SimbolSatuan: 'kg',
                BolehDesimal: true,
                JumlahStandar: '4.0000',
                Jumlah: '4.5000',
                Selisih: '0.5000',
                Nilai: '67500.00',
            },
        ],
        Jurnal: [],
        Riwayat: [],
        Tindakan: { Ubah: false, Posting: false, Batalkan: true, WajibAlasanBatal: true },
        Izin: IzinPenuh,
        ...perubahan,
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/persediaan/produksi'));
afterEach(cleanup);

describe('F-05e produksi: halaman', () => {
    it('daftar menampilkan order & tombol buat hanya untuk pengelola', () => {
        const baris = {
            Uuid,
            Nomor: 'PR/TOKO/2609/0001',
            Tanggal: '2026-09-26',
            NamaGudang: 'Dapur',
            NamaOutlet: null,
            NamaProduk: 'Roti Sobek Cokelat',
            Sku: null,
            JumlahHasil: '20.0000',
            NomorBatch: 'RT-0926',
            Status: 'Diposting' as const,
            LabelStatus: 'Diposting',
            NilaiHasil: '194500.00',
            HppSatuanHasil: '9725.000000',
            DiubahPada: '2026-09-26T03:00:00Z',
        };
        const props = { Order: BuatHasilTabel([baris]), OpsiGudang: [GudangUtama], OpsiStatus: [], Izin: IzinPenuh };
        RenderUji(<HalamanDaftarOrderProduksi {...props} />);

        expect(screen.getAllByText('PR/TOKO/2609/0001').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Buat order produksi' })).toBeTruthy();

        cleanup();
        RenderUji(<HalamanDaftarOrderProduksi {...props} Izin={IzinLihat} />);
        expect(screen.queryByRole('link', { name: 'Buat order produksi' })).toBeNull();
    });

    it('detail terposting: nilai hasil & HPP, bahan boros ditandai, batalkan wajib alasan', () => {
        RenderUji(<HalamanDetailOrderProduksi {...PropsDetail()} />);

        expect(screen.getAllByText(/Rp\s?194\.500/).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Boros/).length).toBeGreaterThan(0);

        fireEvent.click(screen.getByRole('button', { name: 'Batalkan produksi' }));
        expect(screen.getByLabelText(/Alasan pembatalan/)).toBeTruthy();
    });

    it('detail draf: posting lewat konfirmasi mengirim ke rute posting', () => {
        RenderUji(
            <HalamanDetailOrderProduksi
                {...PropsDetail({
                    Order: { ...PropsDetail().Order, Nomor: null, Status: 'Draf', LabelStatus: 'Draf' },
                    Tindakan: { Ubah: true, Posting: true, Batalkan: true, WajibAlasanBatal: false },
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Catat produksi' }));
        const tombol = screen.getAllByRole('button', { name: 'Catat produksi' }).at(-1);
        fireEvent.click(tombol ?? document.body);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/produksi/${Uuid}/posting`,
            {},
            expect.anything(),
        );
    });

    it('form kosong: simpan tanpa lokasi/produk/bahan menampilkan masalah dan tidak mengirim', () => {
        RenderUji(
            <HalamanFormOrderProduksi
                Mode="Buat"
                Order={null}
                Awal={null}
                OpsiGudang={[GudangUtama, GudangKedua]}
                HariIni="2026-09-27"
                MaksBahan={100}
                WajibKedaluwarsaBatch
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));
        expect(screen.getByText('Pilih lokasi produksi.')).toBeTruthy();
        expect(screen.getByText('Tambahkan minimal satu bahan.')).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('CekJumlahProduksi menolak nol, minus, dan desimal untuk satuan bulat', () => {
        expect(CekJumlahProduksi('2.5', true)).toBe(true);
        expect(CekJumlahProduksi('2.5', false)).toBe(false);
        expect(CekJumlahProduksi('0', true)).toBe(false);
        expect(CekJumlahProduksi('', true)).toBe(false);
    });
});
