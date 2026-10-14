import { cleanup, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarPenjualan from '@/Halaman/Kelola/Penjualan/Daftar';
import HalamanDetailPenjualan from '@/Halaman/Kelola/Penjualan/Detail';
import HalamanDetailRetur from '@/Halaman/Kelola/Penjualan/Retur';
import HalamanDaftarVoidRetur from '@/Halaman/Kelola/Penjualan/VoidRetur';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { FormatDurasi } from '@/Pustaka/FormatWaktu';
import type {
    BarisPenjualan,
    BarisVoidRetur,
    PropsDaftarPenjualan,
    PropsDetailPenjualan,
    PropsDetailRetur,
} from '@/Tipe/Penjualan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const barisPenjualan: BarisPenjualan = {
    Uuid: '01K5PENJUALAN0000000000001',
    Nomor: 'INV/UTAMA/260924/UTAMA-K01-0042',
    DibuatOfflinePada: '2026-09-24T03:10:00Z',
    TanggalBisnis: '2026-09-24',
    NamaOutlet: 'Toko Kelontong Berkah Solo',
    NamaKasir: 'Rina Wulandari',
    Kanal: 'BawaPulang',
    LabelKanal: 'Bawa pulang',
    TotalAkhir: '12596570.00',
    Metode: ['QRIS Toko Berkah', 'Tunai'],
    Status: 'Lunas',
    LabelStatus: 'Lunas',
    PerluTinjauan: true,
};

function PropsDaftar(data: BarisPenjualan[], outlet = 1): PropsDaftarPenjualan {
    return {
        Penjualan: { Data: data, Meta: { Halaman: 1, PerHalaman: 25, Total: data.length, JumlahHalaman: 1 } },
        OpsiOutlet: Array.from({ length: outlet }, (_, i) => ({
            Uuid: `01K5OUTLET000000000000000${String(i + 1)}`,
            Nama: `Outlet ${String(i + 1)}`,
        })),
        OpsiStatus: [
            { Nilai: 'Lunas', Label: 'Lunas' },
            { Nilai: 'Void', Label: 'Dibatalkan (void)' },
        ],
        OpsiKanal: [{ Nilai: 'BawaPulang', Label: 'Bawa pulang' }],
    };
}

const propsDetail: PropsDetailPenjualan = {
    Penjualan: {
        Uuid: barisPenjualan.Uuid,
        Nomor: barisPenjualan.Nomor,
        Status: 'Lunas',
        LabelStatus: 'Lunas',
        Kanal: 'BawaPulang',
        LabelKanal: 'Bawa pulang',
        NamaOutlet: 'Toko Kelontong Berkah Solo',
        Perangkat: 'UTAMA-K01 — Kasir Depan',
        NamaKasir: 'Rina Wulandari',
        NamaPenyetujuDiskon: 'Budi Santoso',
        Pelanggan: { Uuid: '01K5PELANGGAN0000000000001', Nama: 'Bu Ani Rahmawati' },
        DibuatOfflinePada: '2026-09-24T03:10:00Z',
        DiterimaPada: '2026-09-24T05:00:00Z',
        TanggalBisnis: '2026-09-24',
        HargaTermasukPajak: false,
        PersenBiayaLayanan: '0.00',
        Subtotal: '87000.00',
        DiskonBaris: '5000.00',
        DiskonPesanan: '0.00',
        PoinDitukar: 0,
        Promo: [
            { Uuid: '01K5PROMO00000000000000001', Kode: 'KOPI10', Nama: 'Diskon 10% kopi', JumlahDiskon: '7700.00' },
        ],
        DiskonPoin: '0.00',
        TotalDiskon: '5000.00',
        BiayaLayanan: '0.00',
        TotalPajak: '9570.00',
        Pembulatan: '-70.00',
        TotalAkhir: '96500.00',
        TotalDibayar: '100000.00',
        Kembalian: '3500.00',
        TotalHpp: '60000.00',
        Catatan: 'Pelanggan minta struk digital',
        NomorAntrian: '042',
        NamaPemesan: 'Budi',
        PerluTinjauan: true,
        AlasanTinjauan:
            'StokTidakCukup: Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter (sisa −3); DiskonMelebihiBatas: diskon 30,0001% melebihi batas persetujuan 30%',
        DaftarAlasanTinjauan: [
            {
                Kode: 'StokTidakCukup',
                Label: 'Stok tidak cukup saat penjualan diterima',
                Keterangan: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter (sisa −3)',
            },
            {
                Kode: 'DiskonMelebihiBatas',
                Label: 'Diskon melebihi batas yang berlaku',
                Keterangan: 'diskon 30,0001% melebihi batas persetujuan 30%',
            },
        ],
        UuidShift: '01K5SHIFT00000000000000001',
    },
    Baris: [
        {
            Uuid: '01K5BARIS0000000000000001',
            NamaProduk: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter',
            Jumlah: '2.0000',
            SimbolSatuan: 'pcs',
            HargaSatuan: '38500.00',
            HargaAsli: '40000.00',
            AlasanHargaManual: 'Kemasan penyok',
            HargaPilihan: '0.00',
            Pilihan: [],
            Bruto: '77000.00',
            JumlahDiskon: '5000.00',
            JumlahDiskonPesanan: '0.00',
            JumlahPajak: '8470.00',
            TotalBaris: '80470.00',
            JumlahDiretur: '0.0000',
            HppSatuan: '30000.000000',
            TotalHpp: '60000.00',
            Catatan: null,
            Racikan: null,
        },
    ],
    Pajak: [
        { KodeJenisPajak: 'Ppn', Tarif: '12.000000', DasarPengenaan: 'Subtotal', Dpp: '79750.00', Jumlah: '9570.00' },
    ],
    Pembayaran: [
        {
            Uuid: '01K5BAYAR0000000000000001',
            NamaMetode: 'QRIS Toko Berkah',
            LabelJenis: 'QRIS statis',
            Jumlah: '50000.00',
            Referensi: 'QR-88123',
        },
    ],
    MutasiStok: [
        {
            Kunci: '77',
            NamaProduk: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter',
            NamaGudang: 'Gudang Outlet Utama',
            Jumlah: '-2.0000',
            SimbolSatuan: 'pcs',
            TotalHpp: '-60000.00',
            TautanKartuStok:
                '/kelola/persediaan/kartu-stok?produk=01K5P&gudang=01K5G&dari=2026-09-24&sampai=2026-09-24',
        },
    ],
    Jurnal: [{ Uuid: '01K5JURNAL0000000000000009', Nomor: 'JU/2026/09/000009' }],
    Void: null,
    Retur: [],
    FakturPajak: null,
    Izin: { KelolaFakturPajak: true },
};

describe('F-07b halaman penjualan back-office', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/penjualan'));
    afterEach(() => cleanup());

    it('daftar (TabelData D-16): nomor bertautan detail, Rupiah jutaan terformat, metode, penanda perlu ditinjau, saring outlet hanya bila > 1; keadaan kosong', () => {
        window.history.replaceState({}, '', '/kelola/penjualan');
        RenderUji(<HalamanDaftarPenjualan {...PropsDaftar([barisPenjualan])} />);
        const tabel = screen.getByRole('table', { name: 'Daftar penjualan' });

        expect(within(tabel).getByRole('link', { name: barisPenjualan.Nomor }).getAttribute('href')).toBe(
            `/kelola/penjualan/${barisPenjualan.Uuid}`,
        );
        expect(within(tabel).getByText('Rp 12.596.570')).toBeTruthy();
        expect(within(tabel).getByText('QRIS Toko Berkah, Tunai')).toBeTruthy();
        expect(within(tabel).getByText('Perlu ditinjau')).toBeTruthy();
        expect(screen.getByRole('searchbox', { name: 'Cari di Daftar penjualan' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Hanya yang perlu ditinjau' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /^Outlet/ })).toBeNull();

        cleanup();
        RenderUji(<HalamanDaftarPenjualan {...PropsDaftar([], 2)} />);
        expect(screen.getByText(/Belum ada penjualan/)).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Outlet/ })).toBeTruthy();
    });

    it('detail: alasan tinjauan, ringkasan (pembulatan negatif), baris, pajak, pembayaran, mutasi stok bertautan kartu stok, jurnal & shift', () => {
        RenderUji(<HalamanDetailPenjualan {...propsDetail} />);
        expect(screen.getByText('Promo Diskon 10% kopi')).toBeTruthy();
        // v3.52: nomor antrian & nama pemesan penjualan bayar-dulu.
        expect(screen.getByText('042')).toBeTruthy();
        expect(screen.getByText('Nama pemesan')).toBeTruthy();

        // PRD v1.46: alasan tinjauan tampil sebagai kalimat manusiawi, bukan kode mesin.
        expect(screen.getByText('Stok tidak cukup saat penjualan diterima')).toBeTruthy();
        expect(screen.getByText(/Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter \(sisa −3\)/)).toBeTruthy();
        expect(screen.getByText('Diskon melebihi batas yang berlaku')).toBeTruthy();
        expect(screen.queryByText(/StokTidakCukup|DiskonMelebihiBatas/)).toBeNull();
        expect(screen.getByText('−Rp 70')).toBeTruthy();
        expect(screen.getByText('Rp 96.500')).toBeTruthy();
        expect(screen.getByText('Budi Santoso')).toBeTruthy();
        expect(screen.getByText('Harga diubah kasir dari Rp 40.000 | Kemasan penyok')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'JU/2026/09/000009' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/01K5JURNAL0000000000000009',
        );
        expect(screen.getByRole('link', { name: 'Lihat shift' }).getAttribute('href')).toBe(
            '/kelola/kasir/shift/01K5SHIFT00000000000000001',
        );
        expect(
            within(screen.getByRole('table', { name: 'Baris penjualan' })).getByText('2 pcs × Rp 38.500'),
        ).toBeTruthy();
        expect(
            within(screen.getByRole('table', { name: 'Rincian pajak penjualan' })).getByText('Ppn 12%'),
        ).toBeTruthy();
        expect(within(screen.getByRole('table', { name: 'Pembayaran penjualan' })).getByText('QR-88123')).toBeTruthy();
        const mutasi = within(screen.getByRole('table', { name: 'Mutasi stok penjualan' }));
        expect(mutasi.getByText('−2 pcs')).toBeTruthy();
        expect(mutasi.getByRole('link', { name: /Minyak Goreng/ }).getAttribute('href')).toContain(
            '/kelola/persediaan/kartu-stok?',
        );
    });

    it('detail tanpa jurnal, pajak, atau mutasi (jasa gratis): keadaan kosong jelas', () => {
        RenderUji(
            <HalamanDetailPenjualan
                {...propsDetail}
                Penjualan={{
                    ...propsDetail.Penjualan,
                    PerluTinjauan: false,
                    AlasanTinjauan: null,
                    DaftarAlasanTinjauan: [],
                    UuidShift: null,
                }}
                Pajak={[]}
                MutasiStok={[]}
                Jurnal={[]}
            />,
        );

        expect(screen.queryByText(/perlu ditinjau/i)).toBeNull();
        expect(screen.getByText('Tanpa jurnal (nilai Rp 0)')).toBeTruthy();
        expect(screen.queryByRole('table', { name: 'Rincian pajak penjualan' })).toBeNull();
        expect(screen.getByText(/tidak mengurangi stok/)).toBeTruthy();
    });

    it('detail: baris racikan apotek menampilkan nama, kemasan, aturan pakai, dan komposisi per racikan', () => {
        const [baris] = propsDetail.Baris;

        if (baris === undefined) {
            throw new Error('Fixture baris kosong');
        }

        RenderUji(
            <HalamanDetailPenjualan
                {...propsDetail}
                Baris={[
                    {
                        ...baris,
                        NamaProduk: 'Jasa Racik Puyer',
                        Racikan: {
                            Nama: 'Puyer batuk anak',
                            JumlahKemasan: 10,
                            AturanPakai: '3 x 1 bungkus sesudah makan',
                            Komponen: [
                                { NamaProduk: 'Paracetamol 500 mg Tablet', Jumlah: '2.5000', GolonganObat: 'Bebas' },
                                { NamaProduk: 'CTM 4 mg Tablet', Jumlah: '1.0000', GolonganObat: 'BebasTerbatas' },
                            ],
                        },
                    },
                ]}
            />,
        );

        expect(
            screen.getByText(
                /Racikan Puyer batuk anak \| 10 kemasan \| 3 x 1 bungkus sesudah makan: Paracetamol 500 mg Tablet 2,5, CTM 4 mg Tablet 1/,
            ),
        ).toBeTruthy();
    });
});

const barisVoid: BarisVoidRetur = {
    Kunci: 'Void-01K5VOID00000000000000001',
    Jenis: 'Void',
    Uuid: '01K5VOID00000000000000001',
    Waktu: '2026-09-24T03:12:00Z',
    TanggalBisnis: '2026-09-24',
    Nomor: 'INV/UTAMA/260924/UTAMA-K01-0042',
    NomorPenjualan: 'INV/UTAMA/260924/UTAMA-K01-0042',
    UuidPenjualan: '01K5PENJUALAN0000000000001',
    TanpaStruk: false,
    Tautan: '/kelola/penjualan/01K5PENJUALAN0000000000001',
    NamaOutlet: 'Toko Kelontong Berkah Solo',
    NamaKasir: 'Rina Wulandari',
    NamaPenyetuju: 'Budi Santoso',
    Nominal: '12596570.00',
    RefundTunai: '12596570.00',
    Alasan: 'Pelanggan membatalkan setelah bayar tunai',
    JedaDetik: 120,
};

const barisRetur: BarisVoidRetur = {
    ...barisVoid,
    Kunci: 'Retur-01K5RETUR0000000000000001',
    Jenis: 'Retur',
    Uuid: '01K5RETUR0000000000000001',
    Nomor: 'RJ/UTAMA/260925/UTAMA-K01-0001',
    Tautan: '/kelola/penjualan/retur/01K5RETUR0000000000000001',
    Nominal: '38500.00',
    RefundTunai: '0.00',
    Alasan: 'Kemasan bocor',
    JedaDetik: 90000,
};

const propsRetur: PropsDetailRetur = {
    Retur: {
        Uuid: '01K5RETUR0000000000000001',
        Nomor: 'RJ/UTAMA/260925/UTAMA-K01-0001',
        LabelStatus: 'Selesai',
        LabelMetodeRefund: 'Transfer manual',
        TanpaStruk: false,
        UuidPenjualan: '01K5PENJUALAN0000000000001',
        NomorPenjualan: 'INV/UTAMA/260924/UTAMA-K01-0042',
        WaktuPenjualan: '2026-09-24T03:10:00Z',
        NamaOutlet: 'Toko Kelontong Berkah Solo',
        Perangkat: 'UTAMA-K01 — Kasir Depan',
        NamaKasir: 'Rina Wulandari',
        NamaPenyetuju: 'Budi Santoso',
        Alasan: 'Kemasan bocor saat dibuka pelanggan',
        DibuatOfflinePada: '2026-09-25T02:00:00Z',
        DiterimaPada: '2026-09-25T02:01:00Z',
        TanggalBisnis: '2026-09-25',
        TotalNilai: '42735.00',
        TotalPajak: '4235.00',
        TotalBiayaLayanan: '0.00',
        TotalRefund: '42735.00',
        RefundTunai: '0.00',
        TotalHpp: '30000.00',
        PerluTinjauan: true,
        AlasanTinjauan: 'LokasiRusakTidakAda: barang rusak dikembalikan ke lokasi Toko',
        DaftarAlasanTinjauan: [
            {
                Kode: 'LokasiRusakTidakAda',
                Label: 'Lokasi stok Rusak belum ada',
                Keterangan: 'barang rusak dikembalikan ke lokasi Toko',
            },
        ],
        UuidShift: '01K5SHIFT00000000000000002',
    },
    Baris: [
        {
            Uuid: '01K5RETURBARIS00000000001',
            NamaProduk: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter',
            Jumlah: '1.0000',
            Kondisi: 'Rusak',
            LabelKondisi: 'Rusak',
            NamaGudang: 'Gudang Outlet Utama',
            NilaiBaris: '42735.00',
            Pajak: '4235.00',
            BiayaLayanan: '0.00',
            TotalHpp: '30000.00',
        },
    ],
    Refund: [
        { Uuid: '01K5REFUND000000000000001', NamaMetode: 'Transfer BRI', LabelJenis: 'Transfer', Jumlah: '42735.00' },
    ],
    MutasiStok: [
        {
            Kunci: '91',
            NamaProduk: 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter',
            NamaGudang: 'Gudang Outlet Utama',
            Jumlah: '1.0000',
            SimbolSatuan: 'pcs',
            TotalHpp: '30000.00',
            TautanKartuStok: '/kelola/persediaan/kartu-stok?produk=01K5P&gudang=01K5G',
        },
    ],
    Jurnal: [{ Uuid: '01K5JURNAL0000000000000011', Nomor: 'JU/2026/09/000011' }],
};

describe('F-09 halaman void & retur back-office', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/penjualan/void-retur'));
    afterEach(() => cleanup());

    it('FormatDurasi: detik, menit, jam, hari', () => {
        expect(FormatDurasi(45)).toBe('45 detik');
        expect(FormatDurasi(720)).toBe('12 menit');
        expect(FormatDurasi(7500)).toBe('2 jam 5 menit');
        expect(FormatDurasi(90000)).toBe('1 hari 1 jam');
        expect(FormatDurasi(-3)).toBe('0 detik');
    });

    it('daftar (TabelData D-16): jenis, nomor bertautan, penjualan asal retur, nominal jutaan, penanda segera setelah bayar, saring jenis; keadaan kosong', () => {
        window.history.replaceState({}, '', '/kelola/penjualan/void-retur');
        RenderUji(
            <HalamanDaftarVoidRetur
                VoidRetur={{
                    Data: [barisVoid, barisRetur],
                    Meta: { Halaman: 1, PerHalaman: 25, Total: 2, JumlahHalaman: 1 },
                }}
                OpsiOutlet={[{ Uuid: '01K5OUTLET0000000000000001', Nama: 'Outlet 1' }]}
            />,
        );
        const tabel = within(screen.getByRole('table', { name: 'Daftar void dan retur' }));

        expect(tabel.getByRole('link', { name: barisRetur.Nomor }).getAttribute('href')).toBe(barisRetur.Tautan);
        expect(tabel.getAllByRole('link', { name: barisVoid.Nomor })[0]?.getAttribute('href')).toBe(barisVoid.Tautan);
        expect(tabel.getByText('Rp 12.596.570')).toBeTruthy();
        expect(tabel.getAllByText('Segera setelah bayar')).toHaveLength(1);
        expect(tabel.getByText('1 hari 1 jam')).toBeTruthy();
        expect(tabel.getByText('Pelanggan membatalkan setelah bayar tunai')).toBeTruthy();
        expect(screen.getByRole('button', { name: /^Jenis/ })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /^Outlet/ })).toBeNull();

        cleanup();
        RenderUji(
            <HalamanDaftarVoidRetur
                VoidRetur={{ Data: [], Meta: { Halaman: 1, PerHalaman: 25, Total: 0, JumlahHalaman: 1 } }}
                OpsiOutlet={[]}
            />,
        );
        expect(screen.getByText(/Belum ada void atau retur/)).toBeTruthy();
    });

    it('detail penjualan: void (alasan, penyetuju, refund, jeda) dan tabel retur bertautan', () => {
        RenderUji(
            <HalamanDetailPenjualan
                {...propsDetail}
                Penjualan={{ ...propsDetail.Penjualan, Status: 'Void', LabelStatus: 'Dibatalkan (void)' }}
                Baris={propsDetail.Baris.map((b) => ({ ...b, JumlahDiretur: '1.0000' }))}
                Void={{
                    Uuid: '01K5VOID00000000000000001',
                    Alasan: 'Salah input barang',
                    NamaKasir: 'Rina Wulandari',
                    NamaPenyetuju: 'Budi Santoso',
                    DivoidPada: '2026-09-24T03:12:00Z',
                    RefundTunai: '46570.00',
                    RefundNonTunai: '50000.00',
                    JedaDetik: 120,
                }}
                Retur={[
                    {
                        Uuid: '01K5RETUR0000000000000001',
                        Nomor: 'RJ/UTAMA/260925/UTAMA-K01-0001',
                        DibuatOfflinePada: '2026-09-25T02:00:00Z',
                        NamaKasir: 'Rina Wulandari',
                        Alasan: 'Kemasan bocor',
                        LabelMetodeRefund: 'Tunai',
                        TotalRefund: '38500.00',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Penjualan ini dibatalkan (void)')).toBeTruthy();
        expect(screen.getByText('Salah input barang')).toBeTruthy();
        expect(screen.getByText(/2 menit setelah bayar/)).toBeTruthy();
        expect(screen.getByText('Rp 46.570')).toBeTruthy();
        expect(screen.getByText('Diretur 1 pcs')).toBeTruthy();
        const retur = within(screen.getByRole('table', { name: 'Retur penjualan ini' }));
        expect(retur.getByRole('link', { name: 'RJ/UTAMA/260925/UTAMA-K01-0001' }).getAttribute('href')).toBe(
            '/kelola/penjualan/retur/01K5RETUR0000000000000001',
        );
    });

    it('detail retur: tinjauan, penjualan asal, ringkasan refund, baris kondisi rusak, refund, mutasi stok bertautan kartu stok, jurnal, shift', () => {
        RenderUji(<HalamanDetailRetur {...propsRetur} />);

        expect(screen.getByText('Lokasi stok Rusak belum ada')).toBeTruthy();
        expect(screen.queryByText(/LokasiRusakTidakAda/)).toBeNull();
        expect(screen.getByRole('link', { name: 'INV/UTAMA/260924/UTAMA-K01-0042' }).getAttribute('href')).toBe(
            '/kelola/penjualan/01K5PENJUALAN0000000000001',
        );
        expect(screen.getByText('Refund (Transfer manual)')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'JU/2026/09/000011' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/01K5JURNAL0000000000000011',
        );
        expect(screen.getByRole('link', { name: 'Lihat shift' }).getAttribute('href')).toBe(
            '/kelola/kasir/shift/01K5SHIFT00000000000000002',
        );
        const baris = within(screen.getByRole('table', { name: 'Baris retur' }));
        expect(baris.getByText('Rusak')).toBeTruthy();
        expect(baris.getByText('Masuk ke Gudang Outlet Utama')).toBeTruthy();
        expect(within(screen.getByRole('table', { name: 'Refund retur' })).getByText('Transfer BRI')).toBeTruthy();
        const mutasi = within(screen.getByRole('table', { name: 'Mutasi stok retur' }));
        expect(mutasi.getByText('1 pcs')).toBeTruthy();
        expect(mutasi.getByRole('link', { name: /Minyak Goreng/ }).getAttribute('href')).toContain(
            '/kelola/persediaan/kartu-stok?',
        );
    });
});
