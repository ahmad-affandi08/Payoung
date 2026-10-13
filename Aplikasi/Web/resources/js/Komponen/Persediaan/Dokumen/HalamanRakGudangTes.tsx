import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarStokOpname from '@/Halaman/Kelola/Persediaan/Opname/Daftar';
import HalamanDetailStokOpname from '@/Halaman/Kelola/Persediaan/Opname/Detail';
import HalamanDaftarRakGudang from '@/Halaman/Kelola/Persediaan/Rak/Daftar';
import HalamanDetailRakGudang from '@/Halaman/Kelola/Persediaan/Rak/Detail';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel, GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { AmbilNilaiPilihan, PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import { BukaMenu } from '@/Pengujian/InteraksiRadix';
import type {
    BarisProdukRak,
    BarisRakGudang,
    IzinDokumenPersediaan,
    PropsDetailStokOpname,
} from '@/Tipe/DokumenPersediaan';

/*
 * F-05c INV-07 halaman rak gudang dan opname per rak: daftar & ubah rak, isi rak (dibekukan saat diopname), pilihan rak
 * di formulir mulai opname, serta ringkasan penghitung di detail opname.
 */

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Izin: IzinDokumenPersediaan = { Lihat: true, Kelola: true, Setujui: true, LihatJurnal: true };
const IzinLihat: IzinDokumenPersediaan = { Lihat: true, Kelola: false, Setujui: false, LihatJurnal: false };

function Rak(perubahan: Partial<BarisRakGudang> = {}): BarisRakGudang {
    return {
        Uuid: '01J9RAK0000000000000000001',
        UuidGudang: GudangUtama.Uuid,
        NamaGudang: GudangUtama.Nama,
        NamaOutlet: 'Outlet Utama',
        Kode: 'A-01',
        Nama: 'Rak Minyak & Sembako',
        Urutan: 1,
        Aktif: true,
        JumlahProduk: 12,
        OpnameBerjalan: null,
        ...perubahan,
    };
}

const produk: BarisProdukRak = {
    Uuid: '01J9PRD0000000000000000001',
    Nama: 'Minyak Goreng 2 L',
    Sku: 'MYK-2L',
    SimbolSatuan: 'pcs',
};

describe('Kelola/Persediaan/Rak (F-05c)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/rak');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('daftar: tambah rak mewajibkan lokasi, kode, dan nama sebelum dikirim', () => {
        RenderUji(
            <HalamanDaftarRakGudang Rak={[Rak()]} OpsiGudang={[GudangUtama]} Saring={{ Gudang: '' }} Izin={Izin} />,
        );
        expect(screen.getByText('Rak Minyak & Sembako')).toBeTruthy();

        fireEvent.click(screen.getAllByRole('button', { name: 'Tambah rak' })[0] as HTMLElement);
        const dialog = screen.getByRole('dialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan rak' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        PilihOpsi(within(dialog).getByRole('combobox', { name: 'Lokasi stok' }), GudangUtama.Uuid);
        fireEvent.change(within(dialog).getByLabelText(/Kode rak/), { target: { value: 'B-02' } });
        fireEvent.change(within(dialog).getByLabelText(/Nama rak/), { target: { value: 'Rak Kulkas' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan rak' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/persediaan/rak',
            { UuidGudang: GudangUtama.Uuid, Kode: 'B-02', Nama: 'Rak Kulkas', Urutan: 0 },
            expect.anything(),
        );
    });

    it('daftar: menu baris menawarkan ubah dan nonaktifkan; rak nonaktif ditawari aktifkan kembali; hanya lihat tanpa aksi', () => {
        RenderUji(
            <HalamanDaftarRakGudang Rak={[Rak()]} OpsiGudang={[GudangUtama]} Saring={{ Gudang: '' }} Izin={Izin} />,
        );
        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Ubah nama atau kode' })).toBeTruthy();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Nonaktifkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/persediaan/rak/01J9RAK0000000000000000001/nonaktifkan',
            {},
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDaftarRakGudang
                Rak={[Rak({ Aktif: false })]}
                OpsiGudang={[GudangUtama]}
                Saring={{ Gudang: '' }}
                Izin={Izin}
            />,
        );
        BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        expect(screen.getByRole('menuitem', { name: 'Aktifkan kembali' })).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanDaftarRakGudang
                Rak={[Rak()]}
                OpsiGudang={[GudangUtama]}
                Saring={{ Gudang: '' }}
                Izin={IzinLihat}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Tambah rak' })).toBeNull();
        expect(screen.queryAllByRole('button', { name: /Aksi/ })).toHaveLength(0);
    });

    it('detail: tempatkan satu kategori mengirim UuidKategori; tanpa kategori tombol mati', () => {
        RenderUji(
            <HalamanDetailRakGudang
                Produk={BuatHasilTabel([produk])}
                Rak={Rak()}
                OpsiKategori={[{ Uuid: '01J9KAT0000000000000000001', Nama: 'Minuman', Jalur: 'Minuman' }]}
                Izin={Izin}
            />,
        );
        const tombol = screen.getByRole('button', { name: 'Tempatkan semua produk kategori' });
        expect((tombol as HTMLButtonElement).disabled).toBe(true);

        PilihOpsi(screen.getByRole('combobox', { name: 'Atau satu kategori sekaligus' }), '01J9KAT0000000000000000001');
        fireEvent.click(screen.getByRole('button', { name: 'Tempatkan semua produk kategori' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/persediaan/rak/01J9RAK0000000000000000001/produk',
            { UuidKategori: '01J9KAT0000000000000000001' },
            expect.anything(),
        );
    });

    it('detail: rak yang sedang diopname menampilkan penjelasan dan menyembunyikan penempatan produk', () => {
        RenderUji(
            <HalamanDetailRakGudang
                Produk={BuatHasilTabel([produk])}
                Rak={Rak({ OpnameBerjalan: 'SO/TOKO/2610/001' })}
                OpsiKategori={[]}
                Izin={Izin}
            />,
        );
        expect(screen.getByText(/sedang diopname \(SO\/TOKO\/2610\/001\)/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Tempatkan semua produk kategori' })).toBeNull();
        expect(screen.getByText('Minyak Goreng 2 L')).toBeTruthy();
    });

    it('detail: rak nonaktif memberi peringatan dan tanpa penempatan', () => {
        RenderUji(
            <HalamanDetailRakGudang
                Produk={BuatHasilTabel([])}
                Rak={Rak({ Aktif: false })}
                OpsiKategori={[]}
                Izin={Izin}
            />,
        );
        expect(screen.getByText(/Rak nonaktif/)).toBeTruthy();
        expect(screen.queryByText('Tempatkan produk')).toBeNull();
        expect(screen.getByText('Rak ini belum berisi produk.')).toBeTruthy();
    });
});

describe('Opname per rak di halaman opname (F-05c)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/opname');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('mulai opname: pilihan rak muncul setelah lokasi dipilih dan dikirim sebagai UuidRak', () => {
        RenderUji(
            <HalamanDaftarStokOpname
                Opname={BuatHasilTabel([])}
                OpsiGudang={[GudangUtama]}
                OpsiKategori={[]}
                OpsiRak={[
                    {
                        Uuid: '01J9RAK0000000000000000001',
                        UuidGudang: GudangUtama.Uuid,
                        Kode: 'A-01',
                        Nama: 'Rak Minyak',
                        JumlahProduk: 12,
                    },
                    {
                        Uuid: '01J9RAK0000000000000000002',
                        UuidGudang: 'lokasi-lain',
                        Kode: 'Z-01',
                        Nama: 'Rak Lain',
                        JumlahProduk: 3,
                    },
                ]}
                OpsiStatus={[]}
                Izin={Izin}
            />,
        );
        fireEvent.click(screen.getAllByRole('button', { name: 'Mulai stok opname' })[0] as HTMLElement);
        const dialog = screen.getByRole('dialog');
        // Hanya satu lokasi stok, jadi terisi otomatis (BidangOutlet) dan rak lokasi itu langsung ditawarkan.
        const rak = within(dialog).getByRole('combobox', { name: 'Rak (opsional)' });
        expect(AmbilNilaiPilihan(rak)).toEqual(['', '01J9RAK0000000000000000001']);
        PilihOpsi(rak, '01J9RAK0000000000000000001');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mulai opname' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/persediaan/opname',
            expect.objectContaining({
                UuidGudang: GudangUtama.Uuid,
                UuidRak: '01J9RAK0000000000000000001',
                UuidKategori: null,
            }),
            expect.anything(),
        );
    });

    it('detail opname: cakupan rak dan ringkasan per penghitung tampil', () => {
        const props = {
            Opname: {
                Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4D',
                Nomor: 'SO/TOKO/2610/001',
                Status: 'Berlangsung',
                LabelStatus: 'Sedang dihitung',
                UuidGudang: GudangUtama.Uuid,
                NamaGudang: 'Toko Utama',
                NamaOutlet: 'Outlet Utama',
                NamaKategori: null,
                NamaRak: 'Rak Minyak',
                HitungButa: false,
                SistemTersembunyi: false,
                TanggalSnapshot: '2026-10-20',
                SnapshotPada: '2026-10-20T03:00:00Z',
                TanggalPosting: null,
                Catatan: null,
                JumlahBaris: 2,
                JumlahDihitung: 2,
                TotalNilaiLebih: '0.00',
                TotalNilaiKurang: '0.00',
                AlasanBatal: null,
                DibuatOleh: 'Sari',
                DiajukanOleh: null,
                DisetujuiOleh: null,
                DisetujuiPada: null,
            },
            Baris: [],
            Penghitung: [
                { Nama: 'Sari', JumlahBaris: 5, TerakhirPada: '2026-10-20T04:00:00Z' },
                { Nama: 'Budi', JumlahBaris: 2, TerakhirPada: null },
            ],
            Jurnal: [],
            Riwayat: [],
            Tindakan: { Hitung: false, Ajukan: false, Kembalikan: false, Setujui: false, Batalkan: false },
            Izin,
        } as PropsDetailStokOpname;
        RenderUji(<HalamanDetailStokOpname {...props} />);

        expect(screen.getByText('Rak Rak Minyak')).toBeTruthy();
        const daftar = screen.getByRole('list', { name: 'Ringkasan per penghitung' });
        expect(within(daftar).getByText('Sari')).toBeTruthy();
        expect(within(daftar).getByText(/5 baris/)).toBeTruthy();
        expect(within(daftar).getByText(/2 baris/)).toBeTruthy();
    });
});
