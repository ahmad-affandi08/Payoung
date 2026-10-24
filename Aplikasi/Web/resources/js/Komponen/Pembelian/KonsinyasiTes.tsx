import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarKonsinyasi from '@/Halaman/Kelola/Pembelian/Konsinyasi/Daftar';
import HalamanDetailDokumenKonsinyasi from '@/Halaman/Kelola/Pembelian/Konsinyasi/Dokumen/Detail';
import HalamanFormKonsinyasi, { PeriksaBarisKonsinyasi } from '@/Halaman/Kelola/Pembelian/Konsinyasi/Form';
import HalamanPenitipKonsinyasi from '@/Halaman/Kelola/Pembelian/Konsinyasi/Penitip/Detail';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsPenitipKonsinyasi } from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const Izin = { Kelola: true, Setujui: false, LihatJurnal: true, Persediaan: true };
const Penitip = { Uuid: '01J9PMS0000000000000000001', Kode: 'SUP-001', Nama: 'Bu Sari Keripik Rumahan' };

const BarisDasar = {
    Kunci: 'ks-1',
    UuidProduk: '01J9PRD0000000000000000001',
    NamaProduk: 'Keripik Singkong Pedas 200 g',
    Sku: 'KRP-01',
    SimbolSatuan: 'pcs',
    BolehDesimal: false,
    SaldoDiGudang: '5.0000',
    Jumlah: '3',
    HargaTitip: '9000',
};

const PropsPenitip: PropsPenitipKonsinyasi = {
    Penitip: {
        ...Penitip,
        NoHp: '081234567890',
        NamaBank: 'BRI',
        NomorRekening: '0123456789',
        AtasNamaRekening: 'Sari Wulandari',
    },
    Hutang: { Terjual: '18000.00', Dibayar: '0.00', Sisa: '18000.00' },
    Periode: { Dari: '2026-10-01', Sampai: '2026-10-01', NilaiTerjual: '18000.00' },
    Produk: [
        {
            Uuid: BarisDasar.UuidProduk,
            NamaProduk: BarisDasar.NamaProduk,
            Sku: 'KRP-01',
            SimbolSatuan: 'pcs',
            Masuk: '24.0000',
            Retur: '0.0000',
            Terjual: '2.0000',
            NilaiTerjual: '18000.00',
            Saldo: '22.0000',
        },
    ],
    Setoran: [
        {
            Uuid: '01J9BK00000000000000000001',
            Nomor: 'BK/2610/0001',
            Tanggal: '2026-10-01',
            Jumlah: '10000.00',
            Status: 'Diposting',
            NamaAkun: 'Kas Outlet',
            Catatan: null,
            AlasanBatal: null,
            Jurnal: [],
        },
    ],
    OpsiAkun: [{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1100', Nama: 'Kas Outlet' }],
    HariIni: '2026-10-01',
    Izin,
};

describe('Konsinyasi (F-05i, v3.40)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pembelian/konsinyasi');
        window.history.replaceState({}, '', '/kelola/pembelian/konsinyasi');
    });
    afterEach(() => cleanup());

    it('periksa baris: jumlah, harga titip (masuk), dan stok (retur)', () => {
        expect(PeriksaBarisKonsinyasi(BarisDasar, true)).toBeNull();
        expect(PeriksaBarisKonsinyasi({ ...BarisDasar, HargaTitip: '' }, true)).toBe('Isi harga titip lebih dari 0.');
        expect(PeriksaBarisKonsinyasi({ ...BarisDasar, Jumlah: '1.5' }, true)).toBe('Isi jumlah lebih dari 0.');
        expect(PeriksaBarisKonsinyasi({ ...BarisDasar, Jumlah: '6' }, false)).toBe('Stok di lokasi ini tinggal 5 pcs.');
        expect(PeriksaBarisKonsinyasi({ ...BarisDasar, HargaTitip: '' }, false)).toBeNull();
    });

    it('daftar penitip: hutang & tombol titipan untuk pembelian.kelola', () => {
        RenderUji(
            <HalamanDaftarKonsinyasi
                Penitip={[{ ...Penitip, JumlahProduk: 1, Terjual: '18000.00', Dibayar: '10000.00', Sisa: '8000.00' }]}
                TotalSisa="8000.00"
                Izin={Izin}
            />,
        );
        expect(screen.getByText('Total hutang ke penitip Rp 8.000')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Catat titipan masuk' }).getAttribute('href')).toBe(
            '/kelola/pembelian/konsinyasi/buat?jenis=Masuk',
        );
        expect(screen.getAllByRole('link', { name: Penitip.Nama })[0]?.getAttribute('href')).toBe(
            `/kelola/pembelian/konsinyasi/penitip/${Penitip.Uuid}`,
        );
        cleanup();

        RenderUji(<HalamanDaftarKonsinyasi Penitip={[]} TotalSisa="0.00" Izin={{ ...Izin, Kelola: false }} />);
        expect(screen.queryByRole('link', { name: 'Catat titipan masuk' })).toBeNull();
    });

    it('form titipan: lokasi terisi otomatis bila satu, masalah penitip & barang sebelum kirim', () => {
        RenderUji(
            <HalamanFormKonsinyasi
                Jenis="Masuk"
                LabelJenis="Titipan masuk"
                UuidPemasokAwal={null}
                OpsiPemasok={[{ ...Penitip, Pkp: false, TerminHari: 0, Aktif: true }]}
                OpsiGudang={[
                    {
                        Uuid: '01J9GDG0000000000000000001',
                        Kode: 'UTM',
                        Nama: 'Gudang Utama',
                        Jenis: 'Toko',
                        NamaOutlet: 'Solo Baru',
                        Aktif: true,
                    },
                ]}
                HariIni="2026-10-01"
                MaksBaris={200}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Simpan titipan masuk' }));
        expect(screen.getByText('Pilih penitip.')).toBeTruthy();
        expect(screen.getByText('Tambahkan minimal satu barang titipan.')).toBeTruthy();
        expect(screen.queryByText('Pilih lokasi stok.')).toBeNull();
        expect(tiruanRouter.post).not.toHaveBeenCalled();
    });

    it('rincian dokumen: harga titip 6 desimal ditampilkan sebagai rupiah', () => {
        RenderUji(
            <HalamanDetailDokumenKonsinyasi
                Dokumen={{
                    Uuid: '01J9KS00000000000000000001',
                    Nomor: 'KS/SB/2610/0001',
                    Jenis: 'Masuk',
                    LabelJenis: 'Titipan masuk',
                    Tanggal: '2026-10-01',
                    TotalNilai: '216000.00',
                    Catatan: null,
                    UuidPemasok: Penitip.Uuid,
                    NamaPemasok: Penitip.Nama,
                    NamaGudang: 'Gudang Utama',
                    NamaOutlet: 'Solo Baru',
                }}
                Baris={[
                    {
                        Id: 1,
                        NamaProduk: BarisDasar.NamaProduk,
                        Sku: 'KRP-01',
                        SimbolSatuan: 'pcs',
                        Jumlah: '24.0000',
                        HargaSatuan: '9000.000000',
                        Nilai: '216000.00',
                    },
                ]}
            />,
        );
        expect(screen.getByText('Rp 9.000')).toBeTruthy();
        expect(screen.getAllByText('Rp 216.000').length).toBeGreaterThan(0);
    });

    it('rincian penitip: setor mengirim jumlah sisa hutang bawaan', () => {
        RenderUji(<HalamanPenitipKonsinyasi {...PropsPenitip} />);
        expect(screen.getByText('BRI 0123456789 a.n. Sari Wulandari')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Setor ke penitip' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan setoran' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: `/kelola/pembelian/konsinyasi/penitip/${Penitip.Uuid}/setoran`,
            data: { Jumlah: '18000.00', UuidAkun: '01J9AKN0000000000000000001', Tanggal: '2026-10-01' },
        });
        cleanup();

        RenderUji(
            <HalamanPenitipKonsinyasi {...PropsPenitip} Hutang={{ Terjual: '0.00', Dibayar: '0.00', Sisa: '0.00' }} />,
        );
        expect((screen.getByRole('button', { name: 'Setor ke penitip' }) as HTMLButtonElement).disabled).toBe(true);
    });
});
