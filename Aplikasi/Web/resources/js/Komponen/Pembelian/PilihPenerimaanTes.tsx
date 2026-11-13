import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanPilihPenerimaan, {
    CariPesananTerbuka,
    TeksPerkiraanTiba,
} from '@/Halaman/Kelola/Pembelian/Penerimaan/Pilih';
import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import SaranPesananTerbuka, {
    PesananTerbukaPemasok,
    PetunjukPesananDiBelanja,
} from '@/Komponen/Pembelian/SaranPesananTerbuka';
import type { PesananTerbukaDiterima } from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * F-04 "Terima barang" menyatu dengan pesanan pembelian: halaman pilih pesanan, saran di form tanpa pesanan, dan
 * petunjuk di Belanja stok.
 */

const UuidPemasokA = '01J9PMS0000000000000000001';
const UuidPemasokB = '01J9PMS0000000000000000002';

function Pesanan(perubahan: Partial<PesananTerbukaDiterima> = {}): PesananTerbukaDiterima {
    return {
        Uuid: '01J9PSN0000000000000000001',
        Nomor: 'PO/UTM/2611/0001',
        UuidPemasok: UuidPemasokA,
        NamaPemasok: 'PT Minyak Nusantara Sejahtera Abadi',
        NamaGudang: 'Gudang Utama Delanggu',
        Tanggal: '2026-11-02',
        PerkiraanTiba: '2026-11-10',
        HariTerlambat: 0,
        Terlambat: false,
        Status: 'Disetujui',
        LabelStatus: 'Disetujui',
        JumlahBarisSisa: 3,
        NilaiSisa: '2500000.00',
        ...perubahan,
    };
}

const Daftar: PesananTerbukaDiterima[] = [
    Pesanan(),
    Pesanan({
        Uuid: '01J9PSN0000000000000000002',
        Nomor: 'PO/UTM/2611/0002',
        UuidPemasok: UuidPemasokB,
        NamaPemasok: 'CV Beras Delanggu Makmur',
        PerkiraanTiba: '2026-11-05',
        HariTerlambat: 8,
        Terlambat: true,
        Status: 'DiterimaSebagian',
        LabelStatus: 'Diterima sebagian',
        JumlahBarisSisa: 1,
        NilaiSisa: '750000.00',
    }),
];

afterEach(cleanup);

describe('Halaman pilih pesanan untuk terima barang', () => {
    it('menampilkan tiap pesanan dengan pemasok, lokasi, sisa, dan tautan terima dari pesanan itu', () => {
        RenderUji(<HalamanPilihPenerimaan PesananTerbuka={Daftar} />);

        const daftar = screen.getByRole('list', { name: 'Pesanan yang menunggu barang' });
        const baris = within(daftar).getAllByRole('listitem');

        expect(baris).toHaveLength(2);
        expect(within(baris[0] as HTMLElement).getByText('PO/UTM/2611/0001')).toBeTruthy();
        expect(within(baris[0] as HTMLElement).getByText('PT Minyak Nusantara Sejahtera Abadi')).toBeTruthy();
        expect(within(baris[0] as HTMLElement).getByText(/sisa Rp\s?2\.500\.000 di 3 baris/)).toBeTruthy();
        expect(
            within(baris[0] as HTMLElement)
                .getByRole('link', { name: 'Terima barang' })
                .getAttribute('href'),
        ).toBe('/kelola/pembelian/penerimaan/buat?pesanan=01J9PSN0000000000000000001');
    });

    it('pesanan terlambat ditandai dengan teks, bukan hanya warna', () => {
        RenderUji(<HalamanPilihPenerimaan PesananTerbuka={Daftar} />);

        const baris = within(screen.getByRole('list', { name: 'Pesanan yang menunggu barang' })).getAllByRole(
            'listitem',
        );

        expect(within(baris[1] as HTMLElement).getByText('Terlambat')).toBeTruthy();
        expect(within(baris[1] as HTMLElement).getByText(/terlambat 8 hari/)).toBeTruthy();
        expect(within(baris[0] as HTMLElement).queryByText('Terlambat')).toBeNull();
    });

    it('cari menyaring menurut nomor, pemasok, atau lokasi dan memberi pesan bila tidak ada yang cocok', () => {
        RenderUji(<HalamanPilihPenerimaan PesananTerbuka={Daftar} />);

        fireEvent.change(screen.getByLabelText('Cari pesanan'), { target: { value: 'beras' } });
        expect(
            within(screen.getByRole('list', { name: 'Pesanan yang menunggu barang' })).getAllByRole('listitem'),
        ).toHaveLength(1);

        fireEvent.change(screen.getByLabelText('Cari pesanan'), { target: { value: 'tidak ada' } });
        expect(screen.getByText('Tidak ada pesanan yang cocok dengan pencarian.')).toBeTruthy();
    });

    it('selalu menyediakan jalan tanpa pesanan dan Belanja stok', () => {
        RenderUji(<HalamanPilihPenerimaan PesananTerbuka={Daftar} />);

        expect(screen.getByRole('link', { name: 'Terima tanpa pesanan' }).getAttribute('href')).toBe(
            '/kelola/pembelian/penerimaan/buat?tanpa-pesanan=1',
        );
        expect(screen.getByRole('link', { name: 'Belanja stok' }).getAttribute('href')).toBe(
            '/kelola/pembelian/belanja-stok',
        );
    });

    it('CariPesananTerbuka tidak membedakan huruf besar kecil dan kosong = semua', () => {
        expect(CariPesananTerbuka(Daftar, '')).toHaveLength(2);
        expect(CariPesananTerbuka(Daftar, '  GUDANG UTAMA ')).toHaveLength(2);
        expect(CariPesananTerbuka(Daftar, 'po/utm/2611/0002')).toHaveLength(1);
    });

    it('TeksPerkiraanTiba membedakan terlambat, sesuai jadwal, dan tanpa perkiraan', () => {
        expect(TeksPerkiraanTiba(Pesanan({ PerkiraanTiba: null }))).toBe('Tanpa perkiraan tiba');
        expect(TeksPerkiraanTiba(Pesanan({ PerkiraanTiba: null, Terlambat: true }))).toContain('sudah lama menunggu');
        expect(TeksPerkiraanTiba(Pesanan({ HariTerlambat: 3, Terlambat: true }))).toContain('terlambat 3 hari');
        expect(TeksPerkiraanTiba(Pesanan())).not.toContain('terlambat');
    });
});

describe('Saran pesanan terbuka di form tanpa pesanan', () => {
    it('tidak tampil sebelum pemasok dipilih atau bila pemasok tidak punya pesanan terbuka', () => {
        const { container, rerender: RenderUlang } = RenderUji(<SaranPesananTerbuka pesanan={Daftar} uuidPemasok="" />);
        expect(container.textContent).toBe('');

        RenderUlang(<SaranPesananTerbuka pesanan={Daftar} uuidPemasok="01J9PMS0000000000000000099" />);
        expect(container.textContent).toBe('');
    });

    it('menawarkan terima dari pesanan milik pemasok yang dipilih saja', () => {
        RenderUji(<SaranPesananTerbuka pesanan={Daftar} uuidPemasok={UuidPemasokB} />);

        expect(screen.getByText('Pemasok ini punya satu pesanan yang belum diterima')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Terima dari PO/UTM/2611/0002' }).getAttribute('href')).toBe(
            '/kelola/pembelian/penerimaan/buat?pesanan=01J9PSN0000000000000000002',
        );
        expect(screen.queryByText(/PO\/UTM\/2611\/0001/)).toBeNull();
    });

    it('lebih dari tiga pesanan: tiga pertama ditampilkan dan sisanya lewat tautan lihat semua', () => {
        const banyak = Array.from({ length: 5 }, (_, i) =>
            Pesanan({ Uuid: `01J9PSN00000000000000001${String(i)}`, Nomor: `PO/UTM/2611/01${String(i)}` }),
        );
        RenderUji(<SaranPesananTerbuka pesanan={banyak} uuidPemasok={UuidPemasokA} />);

        expect(screen.getByText('Pemasok ini punya 5 pesanan yang belum diterima')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: /^Terima dari / })).toHaveLength(3);
        expect(screen.getByRole('link', { name: 'Lihat semua pesanan terbuka' })).toBeTruthy();
    });

    it('PesananTerbukaPemasok memilih menurut Uuid pemasok', () => {
        expect(PesananTerbukaPemasok(Daftar, UuidPemasokA)).toHaveLength(1);
        expect(PesananTerbukaPemasok(Daftar, '')).toHaveLength(0);
    });

    it('petunjuk di Belanja stok hanya tampil bila ada pesanan terbuka dan menaut ke terima dari pesanan', () => {
        const { container, rerender: RenderUlang } = RenderUji(<PetunjukPesananDiBelanja jumlah={0} />);
        expect(container.textContent).toBe('');

        RenderUlang(<PetunjukPesananDiBelanja jumlah={2} />);
        expect(screen.getByText('Ada 2 pesanan pembelian yang belum diterima')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'terima dari pesanan' }).getAttribute('href')).toBe(
            '/kelola/pembelian/penerimaan/buat',
        );
    });
});
