import { cleanup, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBerandaKelola from '@/Halaman/Kelola/Beranda';
import HalamanLaporanPajak from '@/Halaman/Kelola/Laporan/Pajak';
import HalamanLaporanPembelian from '@/Halaman/Kelola/Laporan/Pembelian';
import HalamanLaporanPenjualan from '@/Halaman/Kelola/Laporan/Penjualan';
import HalamanLaporanStok from '@/Halaman/Kelola/Laporan/Stok';
import { AmbilHrefEkspor } from '@/Pengujian/InteraksiRadix';
import { AturHalamanUji, RenderUji, TambahPropsHalamanUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { AmbilMaksimum, HitungPerubahanPersen, HitungTingkatPanas } from '@/Pustaka/Laporan';
import { PilihOpsi } from '@/Pengujian/InteraksiPilihan';
import type {
    AngkaPenjualan,
    DasborPemilik,
    PropsLaporanPajak,
    PropsLaporanPenjualan,
    PropsLaporanStok,
} from '@/Tipe/Laporan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Angka(bersih: string, transaksi: number, laba: string): AngkaPenjualan {
    return {
        Kotor: bersih,
        Diskon: '0.00',
        Retur: '0.00',
        Bersih: bersih,
        Pajak: '0.00',
        BiayaLayanan: '0.00',
        Hpp: '0.00',
        LabaKotor: laba,
        JumlahTransaksi: transaksi,
        JumlahRetur: 0,
        RataRataKeranjang: transaksi === 0 ? '0.00' : bersih,
        JumlahBarang: null,
    };
}

const dasbor: DasborPemilik = {
    Tanggal: '2026-10-07',
    HariIni: Angka('12500000.00', 125, '3750000.00'),
    Kemarin: Angka('10000000.00', 100, '3000000.00'),
    MingguLalu: Angka('0.00', 0, '0.00'),
    Grafik: Array.from({ length: 14 }, (_, i) => ({
        Tanggal: i < 7 ? `2026-09-${String(24 + i)}` : `2026-10-0${String(i - 6)}`,
        Bersih: i === 13 ? '12500000.00' : '0.00',
        LabaKotor: '0.00',
        JumlahTransaksi: i === 13 ? 125 : 0,
    })),
    ProdukTerlaris: [
        {
            Kunci: '1',
            NamaProduk: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter',
            Qty: '42.0000',
            Bersih: '1617000.00',
        },
    ],
    PerOutlet: [
        { Kunci: '01K5OUTLET', NamaOutlet: 'Toko Kelontong Berkah Solo', Bersih: '12500000.00', JumlahTransaksi: 125 },
    ],
    StokKritis: {
        Jumlah: 1,
        Baris: [
            {
                Kunci: 'a-b',
                UuidProduk: 'a',
                NamaProduk: 'Gula Pasir Kristal Putih Premium 1 kg',
                Sku: 'GLP-1KG',
                SimbolSatuan: 'pcs',
                UuidGudang: 'b',
                NamaGudang: 'Toko',
                NamaOutlet: 'Toko Kelontong Berkah Solo',
                Saldo: '2.0000',
                StokMinimum: '5.0000',
                Kekurangan: '3.0000',
            },
        ],
    },
    Shift: {
        JumlahTerbuka: 1,
        Terbuka: [
            {
                Uuid: 's1',
                NamaOutlet: 'Toko Kelontong Berkah Solo',
                NamaKasir: 'Rina Wulandari',
                DibukaPada: '2026-10-07T01:00:00Z',
            },
        ],
        Tertutup: [
            {
                Uuid: 's0',
                NamaOutlet: 'Toko Kelontong Berkah Solo',
                NamaKasir: 'Budi Santoso',
                DitutupPada: '2026-10-06T14:00:00Z',
                Selisih: '-15000.00',
            },
        ],
    },
    JumlahPerluTinjauan: 3,
};

function PropsPenjualan(ubah: Partial<PropsLaporanPenjualan> = {}): PropsLaporanPenjualan {
    return {
        Saring: { Tab: 'harian', Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '', Kasir: '', Kanal: '' },
        Peringatan: null,
        MaksHari: 92,
        OpsiOutlet: [{ Nilai: 'o1', Label: 'Toko Kelontong Berkah Solo' }],
        OpsiKasir: [{ Nilai: 'k1', Label: 'Rina Wulandari' }],
        OpsiKanal: [
            { Nilai: 'BawaPulang', Label: 'Bawa pulang' },
            { Nilai: 'MakanDiTempat', Label: 'Makan di tempat' },
        ],
        Total: {
            ...Angka('83150.00', 2, '23150.00'),
            Kotor: '125500.00',
            Diskon: '3850.00',
            Retur: '38500.00',
            Pajak: '9146.50',
        },
        Isi: [{ Tanggal: '2026-10-07', ...Angka('83150.00', 2, '23150.00') }],
        ...ubah,
    };
}

afterEach(cleanup);

describe('F-14a pustaka laporan', () => {
    it('perubahan persen & tingkat heatmap dihitung tanpa pecahan biner', () => {
        expect(HitungPerubahanPersen('12500000.00', '10000000.00')).toBe('+25%');
        expect(HitungPerubahanPersen('0.10', '0.30')).toBe('−66,7%');
        expect(HitungPerubahanPersen('100.00', '100.00')).toBe('0%');
        expect(HitungPerubahanPersen('100.00', '0.00')).toBeNull();
        expect(HitungTingkatPanas('0.00', '100.00')).toBe(0);
        expect(HitungTingkatPanas('1.00', '100.00')).toBe(1);
        expect(HitungTingkatPanas('100.00', '100.00')).toBe(4);
        expect(AmbilMaksimum(['9000.00', '10000.00', '999.99'])).toBe('10000.00');
    });
});

describe('F-14a dasbor pemilik di beranda', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola'));

    it('angka hari ini dengan perbandingan kemarin & minggu lalu, grafik 14 hari, produk terlaris, perlu perhatian', async () => {
        RenderUji(<HalamanBerandaKelola Dasbor={dasbor} />);

        expect(screen.getByRole('heading', { name: /Ringkasan hari ini/ })).toBeTruthy();
        expect(screen.getAllByText('Rp 12.500.000').length).toBeGreaterThan(0);
        expect(screen.getAllByText(/\+25% dari kemarin/).length).toBe(4);
        expect(screen.getAllByText(/Rabu lalu: belum ada/).length).toBe(4);
        expect(screen.getByText('Rp 3.750.000')).toBeTruthy();
        expect(screen.getByText('Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter')).toBeTruthy();
        expect(screen.getByText('42 terjual')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Stok kritis' }).getAttribute('href')).toBe(
            '/kelola/laporan/stok?tab=kritis',
        );
        expect(screen.getByText(/Gula Pasir Kristal Putih Premium 1 kg \(2 pcs\)/)).toBeTruthy();
        expect(screen.getByText('−Rp 15.000')).toBeTruthy();
        expect(screen.getByText('Kurang')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Tinjau penjualan' }).getAttribute('href')).toBe(
            '/kelola/penjualan?saring[PerluTinjauan]=1',
        );
        // Tabel alternatif grafik untuk pembaca layar.
        // T1 (v3.30): grafik dimuat belakangan (React.lazy), jadi ditunggu sampai tampil.
        expect(await screen.findByText(/7 Okt 2026: Rp 12.500.000, 125 transaksi/)).toBeTruthy();
    });

    it('tanpa izin laporan: tanpa angka', () => {
        RenderUji(<HalamanBerandaKelola Dasbor={null} />);

        expect(screen.queryByText(/Ringkasan hari ini/)).toBeNull();
        expect(screen.queryByText(/Rp /)).toBeNull();
        expect(screen.getByText(/hanya tampil untuk pengguna yang punya izin/)).toBeTruthy();
    });
});

describe('F-14a laporan penjualan', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/laporan/penjualan'));

    it('ringkasan periode, tab sebagai tautan yang membawa saring, ekspor CSV sesuai saring, peringatan periode', () => {
        RenderUji(
            <HalamanLaporanPenjualan {...PropsPenjualan({ Peringatan: 'Periode laporan paling panjang 92 hari.' })} />,
        );

        expect(screen.getAllByText('Rp 83.150').length).toBeGreaterThan(0);
        expect(screen.getByText('Rp 125.500')).toBeTruthy();
        expect(screen.getByRole('alert').textContent).toContain('92 hari');
        const nav = screen.getByRole('navigation', { name: 'Jenis laporan penjualan' });
        expect(within(nav).getByRole('link', { name: 'Ringkasan harian' }).getAttribute('aria-current')).toBe('page');
        expect(within(nav).getByRole('link', { name: 'Per produk' }).getAttribute('href')).toBe(
            '/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=produk',
        );
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=harian&format=csv',
        );
        expect(screen.getByRole('table', { name: 'Ringkasan penjualan harian' })).toBeTruthy();
    });

    it('X6: sakelar insight mingguan mengirim pilihan langganan ke server', () => {
        RenderUji(
            <HalamanLaporanPenjualan {...PropsPenjualan({ InsightWhatsapp: { BisaWhatsapp: true, Aktif: true } })} />,
        );

        const sakelar = screen.getByRole('switch', { name: 'Kirim insight mingguan ke WhatsApp saya' });
        expect(sakelar.getAttribute('aria-checked')).toBe('true');
        sakelar.click();
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/laporan/penjualan/insight-whatsapp',
            { Aktif: false },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('saring kanal lewat PilihanCari memuat ulang laporan dengan saring di URL', () => {
        RenderUji(<HalamanLaporanPenjualan {...PropsPenjualan()} />);

        PilihOpsi(screen.getByRole('combobox', { name: /Kanal/ }), 'MakanDiTempat');

        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/laporan/penjualan',
            { tab: 'harian', dari: '2026-10-01', sampai: '2026-10-07', kanal: 'MakanDiTempat' },
            { preserveScroll: true, preserveState: true },
        );
    });

    it('tab per jam: heatmap hari × jam dengan nama aksesibel dan tabel per jam', () => {
        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: { Tab: 'jam', Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '', Kasir: '', Kanal: '' },
                    Isi: {
                        Sel: [
                            { Hari: 3, Jam: 11, Bersih: '121650.00', JumlahTransaksi: 2 },
                            { Hari: 6, Jam: 19, Bersih: '30000.00', JumlahTransaksi: 1 },
                        ],
                        PerJam: [
                            { Jam: 11, Bersih: '121650.00', JumlahTransaksi: 2 },
                            { Jam: 19, Bersih: '30000.00', JumlahTransaksi: 1 },
                        ],
                    },
                })}
            />,
        );

        const sel = screen.getByRole('img', { name: 'Rabu 11.00: Rp 121.650, 2 transaksi' });
        expect(sel.getAttribute('data-tingkat')).toBe('4');
        expect(
            screen.getByRole('img', { name: 'Sabtu 19.00: Rp 30.000, 1 transaksi' }).getAttribute('data-tingkat'),
        ).toBe('1');
        expect(screen.getByRole('img', { name: 'Senin 00.00: tidak ada penjualan' }).getAttribute('data-tingkat')).toBe(
            '0',
        );
        expect(screen.getByRole('table', { name: 'Penjualan per jam' })).toBeTruthy();
    });

    it('§10.10 tab per pelanggan & perbandingan outlet: nama, porsi, margin, dan ekspor sesuai saring', () => {
        const { unmount: Lepas } = RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: {
                        Tab: 'pelanggan',
                        Dari: '2026-10-01',
                        Sampai: '2026-10-07',
                        Outlet: '',
                        Kasir: '',
                        Kanal: '',
                    },
                    Isi: [
                        {
                            Kunci: 'u1',
                            NamaPelanggan: 'Ani Wijayanti',
                            ...Angka('115500.00', 2, '106500.00'),
                            Porsi: '50.00',
                        },
                        {
                            Kunci: '',
                            NamaPelanggan: 'Tanpa pelanggan',
                            ...Angka('77000.00', 1, '71000.00'),
                            Porsi: '33.33',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Penjualan per pelanggan' })).toBeTruthy();
        expect(screen.getByText('Ani Wijayanti')).toBeTruthy();
        expect(screen.getByText('Tanpa pelanggan')).toBeTruthy();
        expect(screen.getByText('50,00%')).toBeTruthy();
        Lepas();

        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: {
                        Tab: 'outlet',
                        Dari: '2026-10-01',
                        Sampai: '2026-10-07',
                        Outlet: '',
                        Kasir: '',
                        Kanal: '',
                    },
                    Isi: [
                        {
                            Kunci: 'o1',
                            NamaOutlet: 'Warung Kopi Klaten',
                            ...Angka('231000.00', 4, '51000.00'),
                            Porsi: '100.00',
                            Margin: '22.08',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Perbandingan outlet' })).toBeTruthy();
        expect(screen.getByText('Warung Kopi Klaten')).toBeTruthy();
        expect(screen.getByText('22,08%')).toBeTruthy();
    });

    it('§10.10 tab waktu saji, food cost, dan performa kasir: durasi terbaca, persen, dan pilihan target', () => {
        const Saring = (tab: 'waktu-saji' | 'food-cost' | 'performa-kasir') => ({
            Tab: tab,
            Dari: '2026-10-01',
            Sampai: '2026-10-07',
            Outlet: '',
            Kasir: '',
            Kanal: '',
            Target: 15,
        });
        const { unmount: LepasSaji } = RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: Saring('waktu-saji'),
                    OpsiTarget: [5, 15, 30],
                    Isi: [
                        {
                            Kunci: 's1',
                            NamaStasiun: 'Dapur',
                            JumlahTiket: 4,
                            TungguRataRata: null,
                            MasakRataRata: 1200,
                            TotalRataRata: 1200,
                            TotalMedian: 1100,
                            TotalP90: 1500,
                            TotalTerlama: 1800,
                            SajikanRataRata: 90,
                            LewatTarget: 3,
                            PersenLewatTarget: '75.00',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Waktu saji dapur per stasiun' })).toBeTruthy();
        expect(screen.getAllByText('20 menit').length).toBeGreaterThan(0);
        expect(screen.getByText('3 (75,00%)')).toBeTruthy();
        expect(screen.getByRole('combobox', { name: /Target waktu saji/ })).toBeTruthy();
        LepasSaji();

        const { unmount: LepasFood } = RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: Saring('food-cost'),
                    Isi: {
                        Ringkasan: {
                            Penjualan: '125000.00',
                            Teoretis: '40000.00',
                            Terbuang: '16000.00',
                            Selisih: '8000.00',
                            Aktual: '64000.00',
                            TeoretisPersen: '32.00',
                            AktualPersen: '51.20',
                            SelisihPoin: '19.20',
                        },
                        Baris: [
                            {
                                Kunci: 'p1',
                                NamaProduk: 'Biji Kopi Arabika',
                                SimbolSatuan: 'g',
                                QtyTeoretis: '500.0000',
                                NilaiTeoretis: '40000.00',
                                QtyTerbuang: '200.0000',
                                NilaiTerbuang: '16000.00',
                                QtySelisih: '100.0000',
                                NilaiSelisih: '8000.00',
                                NilaiAktual: '64000.00',
                                VarianPersen: '60.00',
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Food cost per bahan' })).toBeTruthy();
        expect(screen.getByText(/32,00%/)).toBeTruthy();
        expect(screen.getByText(/51,20%/)).toBeTruthy();
        expect(screen.getByText('19,20 poin persen')).toBeTruthy();
        expect(screen.getByText('60,00%')).toBeTruthy();
        LepasFood();

        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: Saring('performa-kasir'),
                    Isi: [
                        {
                            Kunci: 'k1',
                            NamaKasir: 'Rina Wulandari',
                            JumlahTransaksi: 2,
                            Bersih: '75000.00',
                            RataRataKeranjang: '37500.00',
                            JamKerja: '2.00',
                            ShiftDitutup: 1,
                            TransaksiPerJam: '1.00',
                            OmzetPerJam: '37500.00',
                            JumlahVoid: 1,
                            PersenVoid: '33.33',
                            SelisihKurang: '0.00',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Performa kasir' })).toBeTruthy();
        expect(screen.getByText('2,00 jam')).toBeTruthy();
        expect(screen.getByText('1 (33,33%)')).toBeTruthy();
    });

    it('§10.10 tab prediksi dan bundling: tabel, faktor tren, saran harga paket, dan keadaan data belum cukup', () => {
        const Saring = (tab: 'prediksi' | 'bundling') => ({
            Tab: tab,
            Dari: '2026-10-01',
            Sampai: '2026-10-07',
            Outlet: '',
            Kasir: '',
            Kanal: '',
        });
        const { unmount: LepasKurang } = RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: Saring('prediksi'),
                    Isi: { Cukup: false, HariData: 9, FaktorTren: '1.00', Baris: [], TotalPerkiraan: '0.00' },
                })}
            />,
        );

        expect(screen.getByText(/Data penjualan belum cukup/)).toBeTruthy();
        expect(screen.getByText(/saat ini 9 hari/)).toBeTruthy();
        LepasKurang();

        const { unmount: LepasRamal } = RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: Saring('prediksi'),
                    Isi: {
                        Cukup: true,
                        HariData: 60,
                        FaktorTren: '1.03',
                        TotalPerkiraan: '220375.00',
                        Baris: [
                            {
                                Tanggal: '2026-10-14',
                                Hari: 3,
                                Perkiraan: '110187.50',
                                PerkiraanTransaksi: 5,
                                Terendah: '100000.00',
                                Tertinggi: '160000.00',
                                Sampel: 8,
                            },
                            {
                                Tanggal: '2026-10-15',
                                Hari: 4,
                                Perkiraan: '110187.50',
                                PerkiraanTransaksi: 5,
                                Terendah: '100000.00',
                                Tertinggi: '100000.00',
                                Sampel: 8,
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Prediksi penjualan harian' })).toBeTruthy();
        expect(screen.getByText(/faktor tren 1,03/)).toBeTruthy();
        expect(screen.getByText('Rp 220.375')).toBeTruthy();
        expect(screen.getByText(/Rabu, /)).toBeTruthy();
        LepasRamal();

        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: Saring('bundling'),
                    Isi: {
                        JumlahTransaksi: 120,
                        Terpotong: true,
                        Baris: [
                            {
                                Kunci: '1-2',
                                NamaA: 'Kopi Susu',
                                NamaB: 'Roti Bakar',
                                Support: 18,
                                PorsiTransaksi: '15.00',
                                KeyakinanAB: '40.00',
                                KeyakinanBA: '60.00',
                                Lift: '2.10',
                                HargaNormal: '40000.00',
                                HargaPaket: '38000.00',
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Rekomendasi bundling' })).toBeTruthy();
        expect(screen.getByText('Kopi Susu + Roti Bakar')).toBeTruthy();
        expect(screen.getByText('Rp 38.000 (normal Rp 40.000)')).toBeTruthy();
        expect(screen.getByText('2,10')).toBeTruthy();
        expect(screen.getByText(/sebagian tidak ikut dianalisis/)).toBeTruthy();
    });

    it('F7 tab umpan balik: rata-rata, tingkat respons, sebaran, dan komentar pelanggan terbaca', () => {
        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: {
                        Tab: 'umpan-balik',
                        Dari: '2026-10-01',
                        Sampai: '2026-10-07',
                        Outlet: '',
                        Kasir: '',
                        Kanal: '',
                    },
                    Isi: {
                        Ringkasan: {
                            Jumlah: 2,
                            RataRata: '4.50',
                            PerNilai: { '1': 0, '2': 0, '3': 0, '4': 1, '5': 1 },
                            JumlahTransaksi: 8,
                            TingkatRespons: '25.00',
                        },
                        Baris: [
                            {
                                Kunci: 'u1',
                                Nomor: 'INV/SLO/261005/K01-0003',
                                Waktu: '2026-10-05T03:00:00Z',
                                NamaOutlet: 'Toko Kelontong Berkah Solo',
                                NamaPelanggan: null,
                                Nilai: 5,
                                Komentar: 'Pelayanan ramah',
                            },
                            {
                                Kunci: 'u2',
                                Nomor: 'INV/SLO/261006/K01-0004',
                                Waktu: '2026-10-06T03:00:00Z',
                                NamaOutlet: 'Toko Kelontong Berkah Solo',
                                NamaPelanggan: 'Budi',
                                Nilai: 4,
                                Komentar: null,
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Umpan balik pelanggan' })).toBeTruthy();
        expect(screen.getByText('4,50 dari 5')).toBeTruthy();
        expect(screen.getByText('2 dari 8 transaksi')).toBeTruthy();
        expect(screen.getByText('25,00%')).toBeTruthy();
        expect(screen.getByText('1 | 1 | 0 | 0 | 0')).toBeTruthy();
        expect(screen.getByText('Pelayanan ramah')).toBeTruthy();
        expect(screen.getByText('5 dari 5')).toBeTruthy();
    });

    it('tab anti-fraud: tingkat risiko berteks + alasan, penjelasan skor', () => {
        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: {
                        Tab: 'anti-fraud',
                        Dari: '2026-10-01',
                        Sampai: '2026-10-07',
                        Outlet: '',
                        Kasir: '',
                        Kanal: '',
                    },
                    Isi: [
                        {
                            Kunci: 'k1',
                            NamaKasir: 'Rina Wulandari',
                            JumlahTransaksi: 4,
                            JumlahVoid: 3,
                            NilaiVoid: '115500.00',
                            VoidCepatTunai: 3,
                            JumlahRetur: 0,
                            NilaiRetur: '0.00',
                            JumlahBerdiskon: 0,
                            TotalDiskon: '0.00',
                            BukaLaciManual: 3,
                            ShiftSelisihKurang: 1,
                            SelisihKurang: '150000.00',
                            Skor: 80,
                            Tingkat: 'Tinggi',
                            Alasan: ['3 void tunai ≤ 10 menit setelah bayar', 'Buka laci tanpa transaksi 3 kali'],
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByRole('table', { name: 'Anti-fraud per kasir' })).toBeTruthy();
        expect(screen.getAllByText('Tinggi | 80').length).toBeGreaterThan(0);
        expect(screen.getAllByText('3 void tunai ≤ 10 menit setelah bayar').length).toBeGreaterThan(0);
        expect(screen.getByText(/Skor risiko adalah petunjuk untuk diperiksa, bukan bukti/)).toBeTruthy();
    });

    it('D-111: tab anti-fraud disembunyikan bila paket belum memuat fitur anti-fraud', () => {
        TambahPropsHalamanUji({
            FiturPaket: {
                NamaPaket: 'Pro',
                Terkunci: {
                    'keamanan.anti-fraud': {
                        Nama: 'Anti-fraud',
                        Paket: { Kode: 'BISNIS', Nama: 'Bisnis', HargaBulanan: '349000.00' },
                        Addon: null,
                    },
                },
            },
        });
        RenderUji(<HalamanLaporanPenjualan {...PropsPenjualan()} />);

        expect(screen.queryByRole('link', { name: 'Anti-fraud' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Ringkasan harian' })).toBeTruthy();
    });

    it('tab per produk: TabelData mode server dengan ekspor sesuai saring; keadaan kosong', () => {
        RenderUji(
            <HalamanLaporanPenjualan
                {...PropsPenjualan({
                    Saring: {
                        Tab: 'produk',
                        Dari: '2026-10-01',
                        Sampai: '2026-10-07',
                        Outlet: '',
                        Kasir: '',
                        Kanal: '',
                    },
                    Isi: {
                        Data: [
                            {
                                IdProduk: 1,
                                NamaProduk: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter',
                                Qty: '2.0000',
                                Kotor: '115500.00',
                                Diskon: '3850.00',
                                Retur: '38500.00',
                                Bersih: '73150.00',
                                Pajak: '8046.50',
                                BiayaLayanan: '0.00',
                                Hpp: '60000.00',
                                LabaKotor: '13150.00',
                                JumlahTransaksi: 2,
                                JumlahRetur: 1,
                            },
                        ],
                        Meta: { Halaman: 1, PerHalaman: 25, Total: 1, JumlahHalaman: 1 },
                    },
                })}
            />,
        );

        expect(screen.getByText('Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter')).toBeTruthy();
        expect(screen.getByText('Rp 73.150')).toBeTruthy();
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toContain('/kelola/laporan/penjualan/ekspor?format=csv');
    });
});

describe('F-14a laporan pajak & stok', () => {
    it('pajak: tabel PB1/PBJT per outlet & PPN per bulan, tarif & bulan terbaca, ekspor per jenis', () => {
        AturHalamanUji({}, '/kelola/laporan/pajak');
        const baris = {
            Kunci: '2026-10|Ppn|12.000000',
            Bulan: '2026-10',
            KodeJenisPajak: 'Ppn',
            NamaJenisPajak: 'PPN',
            LabelKategori: 'PPN',
            Tarif: '12.000000',
            Dpp: '111512.50',
            Pajak: '13381.50',
            DppRetur: '35291.67',
            PajakRetur: '4235.00',
            DppBersih: '76220.83',
            PajakBersih: '9146.50',
            JumlahTransaksi: 2,
        };
        const props: PropsLaporanPajak = {
            Saring: { Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '' },
            Peringatan: null,
            OpsiOutlet: [],
            Pbjt: [],
            Ppn: [baris],
            PpnMasukan: [
                {
                    Kunci: '2026-10|12.000000|1',
                    Bulan: '2026-10',
                    Tarif: '12.000000',
                    Dikreditkan: true,
                    JumlahFaktur: 2,
                    Nilai: '600000.00',
                    Pajak: '72000.00',
                    NilaiRetur: '50000.00',
                    PajakRetur: '6000.00',
                    PajakBersih: '66000.00',
                },
            ],
            RingkasanPpn: {
                PpnKeluaran: '9146.50',
                PpnMasukanKredit: '66000.00',
                PpnMasukanTanpaKredit: '0.00',
                Selisih: '-56853.50',
            },
        };
        RenderUji(<HalamanLaporanPajak {...props} />);

        expect(screen.getAllByText('Oktober 2026').length).toBe(2);
        expect(screen.getByText('PPN 12%')).toBeTruthy();
        expect(screen.getAllByText('Rp 9.146,50').length).toBeGreaterThan(0);
        expect(screen.getByText('Tidak ada PB1/PBJT pada periode ini.')).toBeTruthy();
        // TAX-05: PPN masukan, selisih lebih bayar (keluaran lebih kecil dari masukan yang dikreditkan).
        expect(screen.getByText('PPN lebih bayar')).toBeTruthy();
        expect(screen.getAllByText('Rp 66.000').length).toBeGreaterThan(1);
        expect(screen.getByText('Rp 56.853,50')).toBeTruthy();
        expect(screen.getByText('Ya')).toBeTruthy();
        expect(AmbilHrefEkspor('csv', /Ekspor PPN masukan/)).toBe(
            '/kelola/laporan/pajak/ekspor?dari=2026-10-01&sampai=2026-10-07&jenis=ppn-masukan&format=csv',
        );
        expect(AmbilHrefEkspor('csv', /^Ekspor PPN$/)).toBe(
            '/kelola/laporan/pajak/ekspor?dari=2026-10-01&sampai=2026-10-07&jenis=ppn&format=csv',
        );
    });

    it('stok: nilai persediaan per lokasi & kategori; tab stok kritis bertautan kartu stok', () => {
        AturHalamanUji({}, '/kelola/laporan/stok');
        const nilai: PropsLaporanStok = {
            Saring: { Tab: 'nilai', Tanggal: '2026-10-07', Gudang: '', Hari: 14 },
            OpsiGudang: [{ Nilai: 'g1', Label: 'Toko (Toko Kelontong Berkah Solo)' }],
            Nilai: {
                Total: { Nilai: '380000.00', JumlahProduk: 2 },
                PerGudang: [
                    {
                        Kunci: 'g1',
                        NamaGudang: 'Toko',
                        NamaOutlet: 'Toko Kelontong Berkah Solo',
                        JumlahProduk: 2,
                        Nilai: '380000.00',
                    },
                ],
                PerKategori: [
                    { Kunci: '1', NamaKategori: 'Sembako & Kebutuhan Dapur', JumlahProduk: 1, Nilai: '240000.00' },
                ],
            },
            Kritis: null,
            Kedaluwarsa: null,
            Restock: null,
            Perputaran: null,
            Rencana: null,
        };
        RenderUji(<HalamanLaporanStok {...nilai} />);
        expect(screen.getAllByText('Rp 380.000').length).toBeGreaterThan(0);
        expect(screen.getByText('Sembako & Kebutuhan Dapur')).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanLaporanStok
                {...nilai}
                Saring={{ Tab: 'kritis', Tanggal: '2026-10-07', Gudang: '', Hari: 14 }}
                Nilai={null}
                Kritis={dasbor.StokKritis}
            />,
        );
        expect(screen.getByRole('heading', { name: 'Stok kritis (1)' })).toBeTruthy();
        expect(screen.getByText('2 pcs')).toBeTruthy();
        expect(screen.getByText('GLP-1KG')).toBeTruthy();
        cleanup();

        // F-05g: tab kedaluwarsa memuat batch yang sudah lewat & yang mendekati.
        RenderUji(
            <HalamanLaporanStok
                {...nilai}
                Saring={{ Tab: 'kedaluwarsa', Tanggal: '2026-10-07', Gudang: '', Hari: 14 }}
                Nilai={null}
                Kedaluwarsa={{
                    Jumlah: 2,
                    JumlahLewat: 1,
                    Baris: [
                        {
                            Kunci: 'p1-g1-UHT-A',
                            UuidProduk: 'p1',
                            NamaProduk: 'Susu UHT Full Cream 1 Liter',
                            Sku: null,
                            SimbolSatuan: 'pcs',
                            UuidGudang: 'g1',
                            NamaGudang: 'Toko',
                            NamaOutlet: 'Toko Kelontong Berkah Solo',
                            NomorBatch: 'UHT-A',
                            TanggalKedaluwarsa: '2026-10-04',
                            SisaHari: -3,
                            Status: 'Lewat',
                            Sisa: '4.0000',
                        },
                        {
                            Kunci: 'p1-g1-UHT-B',
                            UuidProduk: 'p1',
                            NamaProduk: 'Susu UHT Full Cream 1 Liter',
                            Sku: null,
                            SimbolSatuan: 'pcs',
                            UuidGudang: 'g1',
                            NamaGudang: 'Toko',
                            NamaOutlet: 'Toko Kelontong Berkah Solo',
                            NomorBatch: 'UHT-B',
                            TanggalKedaluwarsa: '2026-10-12',
                            SisaHari: 5,
                            Status: 'Segera',
                            Sisa: '10.0000',
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByRole('heading', { name: 'Batch kedaluwarsa (2)' })).toBeTruthy();
        expect(screen.getByText('Batch UHT-A')).toBeTruthy();
        expect(screen.getByText(/Sudah lewat \(3 hari lalu\)/)).toBeTruthy();
        expect(screen.getByText(/Segera \(5 hari lagi\)/)).toBeTruthy();
    });
});

describe('§10.10 laporan pembelian', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/laporan/pembelian');
    });

    const dasar = {
        Peringatan: null,
        MaksHari: 92,
        OpsiOutlet: [{ Nilai: 'o1', Label: 'Toko Kelontong Berkah Solo' }],
    };

    it('per pemasok: nama, pembelian bersih, sisa hutang; ekspor sesuai saring', () => {
        RenderUji(
            <HalamanLaporanPembelian
                {...dasar}
                Saring={{ Tab: 'pemasok', Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '' }}
                Isi={[
                    {
                        Kunci: 'p1',
                        KodePemasok: 'SPN',
                        NamaPemasok: 'PT Sumber Pangan Nusantara',
                        JumlahFaktur: 1,
                        Subtotal: '756000.00',
                        Ongkir: '0.00',
                        Pajak: '0.00',
                        Total: '756000.00',
                        Retur: '0.00',
                        Bersih: '756000.00',
                        Dibayar: '0.00',
                        SisaHutang: '756000.00',
                    },
                ]}
            />,
        );

        expect(screen.getByRole('table', { name: 'Pembelian per pemasok' })).toBeTruthy();
        expect(screen.getByText('PT Sumber Pangan Nusantara')).toBeTruthy();
        expect(screen.getAllByText('Rp 756.000').length).toBeGreaterThan(1);
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toContain('/kelola/laporan/pembelian/ekspor?');
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toContain('tab=pemasok');
    });

    it('PO outstanding: nilai sisa dan label terlambat berteks', () => {
        RenderUji(
            <HalamanLaporanPembelian
                {...dasar}
                Saring={{ Tab: 'po', Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '' }}
                Isi={[
                    {
                        Kunci: 'u1',
                        Nomor: 'PO/SOLO/2610/0001',
                        NamaPemasok: 'PT Sumber Pangan Nusantara',
                        Tanggal: '2026-10-01',
                        PerkiraanTiba: '2026-10-04',
                        Status: 'DiterimaSebagian',
                        LabelStatus: 'Diterima sebagian',
                        UmurHari: 6,
                        HariTerlambat: 3,
                        JumlahBaris: 1,
                        JumlahBarisSisa: 1,
                        Total: '280000.00',
                        NilaiSisa: '210000.00',
                    },
                ]}
            />,
        );

        expect(screen.getByRole('table', { name: 'Pesanan pembelian outstanding' })).toBeTruthy();
        expect(screen.getByText('Terlambat 3 hari')).toBeTruthy();
        expect(screen.getAllByText('Rp 210.000').length).toBeGreaterThan(0);
        expect(screen.getByText(/periode tidak berlaku/)).toBeTruthy();
    });

    it('per produk: harga rata-rata dan keadaan kosong', () => {
        RenderUji(
            <HalamanLaporanPembelian
                {...dasar}
                Saring={{ Tab: 'produk', Dari: '2026-10-01', Sampai: '2026-10-07', Outlet: '' }}
                Isi={[]}
            />,
        );

        expect(screen.getByText('Belum ada pembelian produk pada periode ini.')).toBeTruthy();
    });
});
