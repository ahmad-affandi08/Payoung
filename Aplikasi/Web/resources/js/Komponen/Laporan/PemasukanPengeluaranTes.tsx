import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import HalamanPemasukanPengeluaran from '@/Halaman/Kelola/Laporan/PemasukanPengeluaran';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { LabelSumbuUang } from '@/Komponen/Laporan/GrafikUangHarian';
import { CariPeriodeAktif, FrasaPembanding, HitungPeriode } from '@/Pustaka/PeriodeLaporan';
import type { PropsPemasukanPengeluaran } from '@/Tipe/Laporan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * D-255 Pemasukan & Pengeluaran: tiga angka utama dengan pembanding yang dinyatakan dalam kata, dua panel kelompok
 * dengan rincian, catatan di luar hitungan, status kecocokan berteks, pilihan cepat periode, dan helper periodenya.
 */
function Props(sebagian: Partial<PropsPemasukanPengeluaran['Laporan']> = {}): PropsPemasukanPengeluaran {
    return {
        Saring: { Dari: '2026-10-01', Sampai: '2026-10-31', Outlet: '' },
        Laporan: {
            Periode: {
                Dari: '2026-10-01',
                Sampai: '2026-10-31',
                DariSebelumnya: '2026-09-01',
                SampaiSebelumnya: '2026-09-30',
                Pembanding: 'BulanLalu',
                Hari: 31,
            },
            Ringkasan: {
                Masuk: '134300000.00',
                Keluar: '102915000.00',
                Selisih: '31385000.00',
                MasukSebelumnya: '128900000.00',
                KeluarSebelumnya: '99600000.00',
                SelisihSebelumnya: '29300000.00',
                PerubahanMasuk: '4.2',
                PerubahanKeluar: '3.3',
                PerubahanSelisih: '7.1',
                DiLuarUsaha: '3500000.00',
                PerubahanKas: '34885000.00',
            },
            Masuk: [
                {
                    Label: 'Penjualan',
                    Kelompok: 'Penjualan',
                    Nilai: '126650000.00',
                    NilaiSebelumnya: '120200000.00',
                    Porsi: '94.3',
                },
                {
                    Label: 'Pelunasan piutang',
                    Kelompok: 'Piutang dan uang muka',
                    Nilai: '3500000.00',
                    NilaiSebelumnya: '4800000.00',
                    Porsi: '2.6',
                },
            ],
            Keluar: [
                {
                    Label: 'Belanja stok tunai',
                    Kelompok: 'Belanja barang dagangan',
                    Nilai: '48700000.00',
                    NilaiSebelumnya: '46600000.00',
                    Porsi: '47.3',
                },
            ],
            KelompokMasuk: [
                { Kelompok: 'Penjualan', Nilai: '126650000.00', NilaiSebelumnya: '120200000.00', Porsi: '94.3' },
                { Kelompok: 'Piutang dan uang muka', Nilai: '3500000.00', NilaiSebelumnya: '4800000.00', Porsi: '2.6' },
            ],
            KelompokKeluar: [
                {
                    Kelompok: 'Belanja barang dagangan',
                    Nilai: '48700000.00',
                    NilaiSebelumnya: '46600000.00',
                    Porsi: '47.3',
                },
            ],
            DiLuarUsahaRincian: [
                { Label: 'Modal Pemilik', Kelompok: 'Modal, prive, dan pinjaman', Nilai: '5000000.00' },
            ],
            Grafik: {
                Satuan: 'Hari',
                Titik: [
                    { Kunci: '2026-10-01', Masuk: '3376000.00', Keluar: '12342000.00' },
                    { Kunci: '2026-10-02', Masuk: '3903000.00', Keluar: '0.00' },
                ],
            },
            Catatan: { Hutang: '12000000.00', Piutang: '2400000.00', MenungguCair: '2150000.00' },
            BelumDikelompokkan: '0.00',
            Kecocokan: { Cocok: true, Selisih: '0.00' },
            AdaAkunKas: true,
            ...sebagian,
        },
        OpsiOutlet: [{ Uuid: '01J9OUT0000000000000000001', Nama: 'Kopi Senja Solo Baru' }],
    };
}

function Pasang(props: PropsPemasukanPengeluaran) {
    AturHalamanUji({}, '/kelola/laporan/pemasukan-pengeluaran');
    RenderUji(<HalamanPemasukanPengeluaran {...props} />);
}

describe('Halaman Pemasukan & Pengeluaran', () => {
    afterEach(() => {
        cleanup();
        vi.useRealTimers();
    });

    it('tiga angka utama dengan pembanding dalam kata, uang keluar naik bernada peringatan', () => {
        Pasang(Props());

        const ringkasan = screen.getByLabelText('Ringkasan pemasukan dan pengeluaran');

        expect(within(ringkasan).getByText('Rp 134.300.000')).toBeTruthy();
        expect(within(ringkasan).getByText('Rp 102.915.000')).toBeTruthy();
        expect(within(ringkasan).getByText('Rp 31.385.000')).toBeTruthy();
        expect(within(ringkasan).getByText('naik 4,2% dari bulan lalu').className).toContain('text-sukses');
        expect(within(ringkasan).getByText('naik 3,3% dari bulan lalu').className).toContain('text-peringatan');
        expect(within(ringkasan).getByText('naik 7,1% dari bulan lalu')).toBeTruthy();
    });

    it('tanpa pembanding tertulis "Belum ada pembanding", perubahan nol tertulis "sama dengan"', () => {
        const dasar = Props();
        Pasang(
            Props({
                Ringkasan: { ...dasar.Laporan.Ringkasan, PerubahanMasuk: null, PerubahanKeluar: '0.0' },
            }),
        );

        expect(screen.getByText('Belum ada pembanding')).toBeTruthy();
        expect(screen.getByText('sama dengan bulan lalu')).toBeTruthy();
    });

    it('panel kelompok menampilkan nilai dan porsi, rincian per kategori ada di dalamnya', () => {
        Pasang(Props());

        const masuk = screen.getByRole('region', { name: 'Uang masuk dari mana' });
        const keluar = screen.getByRole('region', { name: 'Uang keluar ke mana' });

        // Nama kelompok dan nama kategori sama-sama "Penjualan": satu di daftar kelompok, satu di rincian.
        expect(within(masuk).getAllByText('Penjualan')).toHaveLength(2);
        expect(within(masuk).getByText('94,3% dari total')).toBeTruthy();
        expect(within(keluar).getAllByText('Rp 48.700.000').length).toBeGreaterThan(0);
        expect(within(keluar).getByText('Belanja stok tunai')).toBeTruthy();
    });

    it('catatan hutang, piutang, dana menunggu cair, dan uang di luar usaha tampil, kecocokan berteks', () => {
        Pasang(Props());

        const catatan = screen.getByRole('region', { name: 'Perlu Anda tahu' });

        expect(within(catatan).getByText('Rp 12.000.000')).toBeTruthy();
        expect(within(catatan).getByText('Rp 2.400.000')).toBeTruthy();
        expect(within(catatan).getByText('Rp 2.150.000')).toBeTruthy();
        expect(within(catatan).getByText('Bukan pendapatan atau biaya usaha')).toBeTruthy();
        expect(within(catatan).getByText('Modal Pemilik')).toBeTruthy();
        expect(within(catatan).getByText(/Cocok dengan perubahan saldo kas dan bank/)).toBeTruthy();
    });

    it('tidak cocok dengan saldo kas dan bank menampilkan peringatan berselisih, uang belum dikelompokkan juga', () => {
        Pasang(Props({ Kecocokan: { Cocok: false, Selisih: '1500.00' }, BelumDikelompokkan: '250000.00' }));

        expect(screen.getByText('Belum cocok dengan saldo kas dan bank')).toBeTruthy();
        expect(screen.getByText(/selisih Rp 1\.500/)).toBeTruthy();
        expect(screen.getByText('Ada uang yang belum bisa dikelompokkan')).toBeTruthy();
    });

    it('periode kosong menampilkan penjelasan, tanpa akun kas menampilkan peringatan pemetaan', () => {
        Pasang(
            Props({
                Masuk: [],
                Keluar: [],
                KelompokMasuk: [],
                KelompokKeluar: [],
                DiLuarUsahaRincian: [],
                AdaAkunKas: false,
            }),
        );

        expect(screen.getByText('Belum ada uang masuk atau keluar')).toBeTruthy();
        expect(screen.getByText('Belum ada akun kas atau bank')).toBeTruthy();
        expect(screen.queryByText('Uang masuk dari mana')).toBeNull();
    });

    it('pilihan cepat periode memuat ulang laporan dengan rentang yang sama dengan saringan outlet', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 10, 10, 9, 0, 0)); // Selasa 10 November 2026
        const props = Props();
        props.Saring.Outlet = '01J9OUT0000000000000000001';
        Pasang(props);

        fireEvent.click(screen.getByRole('button', { name: 'Minggu ini' }));

        const alamat = String(tiruanRouter.get.mock.calls.at(-1)?.[0]);
        expect(alamat).toContain('/kelola/laporan/pemasukan-pengeluaran?');
        expect(alamat).toContain('dari=2026-11-09');
        expect(alamat).toContain('sampai=2026-11-10');
        expect(alamat).toContain('outlet=01J9OUT0000000000000000001');
    });
});

describe('Helper periode laporan', () => {
    const selasa = new Date(2026, 10, 10); // Selasa 10 November 2026

    it('pilihan cepat dihitung dari hari ini, minggu dimulai Senin', () => {
        expect(HitungPeriode('HariIni', selasa)).toEqual({ Dari: '2026-11-10', Sampai: '2026-11-10' });
        expect(HitungPeriode('MingguIni', selasa)).toEqual({ Dari: '2026-11-09', Sampai: '2026-11-10' });
        expect(HitungPeriode('BulanIni', selasa)).toEqual({ Dari: '2026-11-01', Sampai: '2026-11-10' });
        expect(HitungPeriode('BulanLalu', selasa)).toEqual({ Dari: '2026-10-01', Sampai: '2026-10-31' });
        expect(HitungPeriode('TahunIni', selasa)).toEqual({ Dari: '2026-01-01', Sampai: '2026-11-10' });
        // Hari Minggu masih termasuk pekan yang dimulai Senin sebelumnya.
        expect(HitungPeriode('MingguIni', new Date(2026, 10, 15))).toEqual({
            Dari: '2026-11-09',
            Sampai: '2026-11-15',
        });
    });

    it('mengenali pilihan cepat yang aktif dan membiarkan rentang bebas tanpa pilihan', () => {
        expect(CariPeriodeAktif('2026-11-01', '2026-11-10', selasa)).toBe('BulanIni');
        expect(CariPeriodeAktif('2026-10-01', '2026-10-31', selasa)).toBe('BulanLalu');
        expect(CariPeriodeAktif('2026-10-05', '2026-10-14', selasa)).toBeNull();
    });

    it('frasa pembanding dan label sumbu grafik', () => {
        expect(FrasaPembanding('Kemarin')).toBe('dari kemarin');
        expect(FrasaPembanding('MingguLalu')).toBe('dari minggu lalu');
        expect(FrasaPembanding('TahunLalu')).toBe('dari tahun lalu');
        expect(FrasaPembanding('PeriodeSebelumnya')).toBe('dari periode sebelumnya');
        expect(LabelSumbuUang('2026-10-07', 'Hari')).toBe('7/10');
        expect(LabelSumbuUang('2026-10', 'Bulan')).toBe('Okt 26');
    });
});
