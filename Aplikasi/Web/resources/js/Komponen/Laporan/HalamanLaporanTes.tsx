import { cleanup, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBerandaKelola from '@/Halaman/Kelola/Beranda';
import HalamanLaporanPajak from '@/Halaman/Kelola/Laporan/Pajak';
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
        };
        RenderUji(<HalamanLaporanPajak {...props} />);

        expect(screen.getByText('Oktober 2026')).toBeTruthy();
        expect(screen.getByText('PPN 12%')).toBeTruthy();
        expect(screen.getByText('Rp 9.146,50')).toBeTruthy();
        expect(screen.getByText('Tidak ada PB1/PBJT pada periode ini.')).toBeTruthy();
        expect(AmbilHrefEkspor('csv', /Ekspor PPN/)).toBe(
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
