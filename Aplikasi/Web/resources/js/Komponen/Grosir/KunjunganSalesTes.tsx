import { cleanup, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarKunjunganSales, {
    AmbilJenisHasilKunjungan,
    BuatTautanPeta,
} from '@/Halaman/Kelola/Grosir/Kunjungan/Daftar';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { BarisKunjunganSales, IzinGrosir } from '@/Tipe/Grosir';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const izin: IzinGrosir = { Kelola: true, SetujuiKredit: false, LihatJurnal: false, LihatPiutang: true };

const denganPesanan: BarisKunjunganSales = {
    Uuid: '01J9KJG0000000000000000001',
    Tanggal: '2026-10-02',
    MasukPada: '2026-10-02T02:15:00Z',
    KeluarPada: '2026-10-02T02:40:00Z',
    DurasiMenit: 25,
    NamaSalesman: 'Budi Santoso',
    NamaPelanggan: 'Toko Kelontong Makmur Jaya Abadi',
    Hasil: 'PesananDibuat',
    LabelHasil: 'Pesanan dibuat',
    Catatan: 'Minta harga khusus bulan depan',
    UuidPesananGrosir: '01J9PGR0000000000000000001',
    NomorPesananGrosir: 'PG/SLO1/2610/0007',
    Latitude: '-7.5666001',
    Longitude: '110.8166002',
    AkurasiMeter: 12,
};

const tokoTutup: BarisKunjunganSales = {
    ...denganPesanan,
    Uuid: '01J9KJG0000000000000000002',
    NamaPelanggan: 'Warung Bu Sri Rejeki',
    Hasil: 'TokoTutup',
    LabelHasil: 'Toko tutup',
    Catatan: null,
    KeluarPada: null,
    DurasiMenit: null,
    UuidPesananGrosir: null,
    NomorPesananGrosir: null,
    Latitude: null,
    Longitude: null,
    AkurasiMeter: null,
};

describe('Grosir › Kunjungan salesman (Modul Salesman bagian 1)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/grosir/kunjungan');
        window.history.replaceState({}, '', '/kelola/grosir/kunjungan');
    });
    afterEach(() => cleanup());

    it('tautan peta memakai koordinat string apa adanya, tanpa koordinat tidak ada tautan', () => {
        expect(BuatTautanPeta('-7.5666001', '110.8166002')).toBe(
            'https://www.google.com/maps?q=-7.5666001,110.8166002',
        );
        expect(BuatTautanPeta(null, '110.8')).toBeNull();
        expect(AmbilJenisHasilKunjungan('PesananDibuat')).toBe('sukses');
        expect(AmbilJenisHasilKunjungan('TokoTutup')).toBe('peringatan');
        expect(AmbilJenisHasilKunjungan('TidakPesan')).toBe('netral');
    });

    it('daftar: salesman, pelanggan, hasil bertulisan, nomor pesanan tertaut, lokasi & tanpa lokasi', () => {
        RenderUji(
            <HalamanDaftarKunjunganSales
                Kunjungan={BuatHasilTabel([denganPesanan, tokoTutup])}
                OpsiSalesman={[{ Nilai: '01J9USR0000000000000000001', Label: 'Budi Santoso' }]}
                OpsiHasil={[
                    { Nilai: 'PesananDibuat', Label: 'Pesanan dibuat' },
                    { Nilai: 'TokoTutup', Label: 'Toko tutup' },
                ]}
                Izin={izin}
            />,
        );

        expect(screen.getAllByText('Toko Kelontong Makmur Jaya Abadi').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Pesanan dibuat').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Toko tutup').length).toBeGreaterThan(0);
        expect(screen.getAllByText('25 menit').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Belum check-out').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Tanpa lokasi').length).toBeGreaterThan(0);

        const pesanan = screen.getAllByRole('link', { name: 'PG/SLO1/2610/0007' })[0];
        expect(pesanan?.getAttribute('href')).toBe('/kelola/grosir/pesanan/01J9PGR0000000000000000001');
        const peta = screen.getAllByRole('link', { name: /Buka peta/ })[0];
        expect(peta?.getAttribute('href')).toBe('https://www.google.com/maps?q=-7.5666001,110.8166002');
        expect(peta?.getAttribute('target')).toBe('_blank');
    });

    it('daftar kosong menampilkan penjelasan, bukan tabel kosong tanpa arti', () => {
        RenderUji(
            <HalamanDaftarKunjunganSales Kunjungan={BuatHasilTabel([])} OpsiSalesman={[]} OpsiHasil={[]} Izin={izin} />,
        );

        expect(
            screen.getByText('Belum ada kunjungan. Kunjungan muncul di sini setelah salesman check-out dari aplikasi.'),
        ).toBeTruthy();
    });
});
