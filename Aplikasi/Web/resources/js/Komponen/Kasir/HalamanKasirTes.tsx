import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanKategoriKas from '@/Halaman/Kelola/Kasir/KategoriKas';
import HalamanPengaturanKasir, {
    NormalisasiMasukanPersen,
    UbahKeMasukanPersen,
    UbahKeMasukanUang,
} from '@/Halaman/Kelola/Kasir/Pengaturan';
import HalamanDaftarShift from '@/Halaman/Kelola/Kasir/Shift/Daftar';
import HalamanDetailShift from '@/Halaman/Kelola/Kasir/Shift/Detail';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type {
    BarisShift,
    LaporanShift,
    PropsDaftarShift,
    PropsDetailShift,
    PropsKategoriKas,
    PropsPengaturanKasir,
} from '@/Tipe/Kasir';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const barisShift: BarisShift = {
    Uuid: '01K5SHIFT00000000000000001',
    NamaOutlet: 'Kopi Senja Solo Baru',
    Perangkat: 'POS-001 — Kasir Depan',
    NamaKasir: 'Rina Wulandari',
    DibukaPada: '2026-09-24T01:15:00Z',
    TanggalBisnis: '2026-09-24',
    Status: 'Terbuka',
    LabelStatus: 'Terbuka',
    Bersama: false,
    PerluTinjauan: true,
    KasAwal: '1250000.00',
    TotalMasuk: '20000.00',
    TotalKeluar: '45000.00',
    TotalSetoran: '300000.00',
    KasNonPenjualan: '925000.00',
    DitutupPada: null,
    KasAktual: null,
    Selisih: null,
};

const propsPengaturan: PropsPengaturanKasir = {
    BatasKasKeluar: '200000.00',
    ShiftBersama: false,
    BatasDiskonManual: '10.00',
    BatasDiskonPenyetuju: '30.00',
    PembulatanTunai: null,
    OpsiArahPembulatan: [
        { Nilai: 'Bawah', Label: 'Ke bawah' },
        { Nilai: 'Atas', Label: 'Ke atas' },
        { Nilai: 'Terdekat', Label: 'Ke terdekat' },
    ],
    TutupShiftButa: true,
    ToleransiSelisihKas: '10000.00',
    BatasHariRetur: 7,
    BatasHariLewatJatuhTempo: 0,
    BukaLaciPerluPin: false,
    BatasReturTanpaStrukHarian: '1000000.00',
    BatasNotifikasiTransaksi: '500000.00',
    BarcodeTimbangan: { Aktif: false, Awalan: ['27'], Nilai: 'Berat' },
};

const laporanShift: LaporanShift = {
    Penjualan: {
        JumlahTransaksi: 1,
        PenjualanKotor: '96570.00',
        TotalDiskon: '0.00',
        PenjualanBersih: '96570.00',
        TotalPajak: '0.00',
        BiayaLayanan: '0.00',
        Pembulatan: '0.00',
        TotalAkhir: '96570.00',
        PerMetode: [
            { UuidMetodePembayaran: '01K5METODE0000000000000001', Jenis: 'Tunai', Nama: 'Tunai', Jumlah: '50000.00' },
            {
                UuidMetodePembayaran: '01K5METODE0000000000000002',
                Jenis: 'QrisStatis',
                Nama: 'QRIS Kopi Senja',
                Jumlah: '46570.00',
            },
        ],
        TunaiMasukBersih: '50000.00',
        RefundTunai: '0.00',
        JumlahVoid: 0,
        NominalVoid: '0.00',
        JumlahRetur: 0,
        NominalRetur: '0.00',
    },
    Kas: {
        KasAwal: '1250000.00',
        TunaiMasukBersih: '50000.00',
        TotalMasuk: '20000.00',
        TotalKeluar: '45000.00',
        TotalSetoran: '300000.00',
        RefundTunai: '0.00',
        KasSeharusnya: '975000.00',
    },
};

function PropsDaftar(data: BarisShift[]): PropsDaftarShift {
    return {
        Shift: { Data: data, Meta: { Halaman: 1, PerHalaman: 25, Total: data.length, JumlahHalaman: 1 } },
        OpsiOutlet: [{ Uuid: '01K5OUTLET0000000000000001', Nama: 'Kopi Senja Solo Baru' }],
        OpsiStatus: [{ Nilai: 'Terbuka', Label: 'Terbuka' }],
    };
}

describe('F-06 halaman kasir back-office', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/kasir/shift'));
    afterEach(() => cleanup());

    it('daftar shift (TabelData D-16): Rupiah terformat, penanda perlu ditinjau, tautan ke detail, saring; keadaan kosong', () => {
        window.history.replaceState({}, '', '/kelola/kasir/shift');
        RenderUji(<HalamanDaftarShift {...PropsDaftar([barisShift])} />);
        const tabel = screen.getByRole('table', { name: 'Daftar shift' });

        expect(within(tabel).getByText('Rp 1.250.000')).toBeTruthy();
        expect(within(tabel).getByText('Perlu ditinjau')).toBeTruthy();
        expect(within(tabel).getByRole('link').getAttribute('href')).toBe(`/kelola/kasir/shift/${barisShift.Uuid}`);
        expect(screen.getByRole('searchbox', { name: 'Cari di Daftar shift' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Hanya yang perlu ditinjau' })).toBeTruthy();
        // Satu outlet: saring outlet tidak ditampilkan.
        expect(screen.queryByRole('button', { name: /^Outlet/ })).toBeNull();

        cleanup();
        RenderUji(<HalamanDaftarShift {...PropsDaftar([])} />);
        expect(screen.getByText(/Belum ada shift/)).toBeTruthy();
    });

    it('detail shift: alasan tinjauan, pecahan kas awal, mutasi dengan tautan jurnal; tanpa mutasi = keadaan kosong', () => {
        const props: PropsDetailShift = {
            Shift: {
                ...barisShift,
                DiterimaPada: '2026-09-24T05:00:00Z',
                AlasanTinjauan: 'BR-06.1: kasir sudah punya shift terbuka di perangkat lain.',
                PecahanKasAwal: [{ Nominal: '100000', Jumlah: 12 }],
            },
            MutasiKas: [
                {
                    Uuid: '01K5MUTASI0000000000000001',
                    Jenis: 'Keluar',
                    LabelJenis: 'Kas keluar',
                    NamaKategori: 'Beli es batu & galon',
                    Jumlah: '45000.00',
                    Catatan: 'Es batu 3 karung',
                    AdaBukti: true,
                    DicatatOleh: 'Rina Wulandari',
                    DicatatPada: '2026-09-24T03:00:00Z',
                    DisetujuiOleh: 'Budi Santoso',
                    NomorJurnal: 'JU/2026/09/000007',
                    UuidJurnal: '01K5JURNAL0000000000000007',
                    PerluTinjauan: false,
                    AlasanTinjauan: null,
                },
            ],
            BukaLaci: [
                {
                    Uuid: '01K5BUKALACI00000000000001',
                    Alasan: 'Tukar uang kecil untuk kembalian',
                    DibukaOleh: 'Rina Wulandari',
                    DisetujuiOleh: null,
                    DibukaPada: '2026-09-24T04:00:00Z',
                    PerluTinjauan: true,
                    AlasanTinjauan: 'PenyetujuTidakAda: buka laci wajib PIN penyetuju',
                },
            ],
            BukaUlang: [
                {
                    Uuid: '01K5BUKAULANG0000000000001',
                    Urutan: 1,
                    Alasan: 'Salah tekan tutup, pelanggan masih antre',
                    DimintaOleh: 'Rina Wulandari',
                    DisetujuiOleh: 'Budi Santoso',
                    DibukaUlangPada: '2026-09-24T09:05:00Z',
                    DitutupOlehSebelumnya: 'Rina Wulandari',
                    DitutupPadaSebelumnya: '2026-09-24T09:00:00Z',
                    KasAktualSebelumnya: '448000.00',
                    SelisihSebelumnya: '-7000.00',
                },
            ],
            Penjualan: {
                Daftar: [
                    {
                        Uuid: '01K5PENJUALAN0000000000001',
                        Nomor: 'INV/UTAMA/260924/UTAMA-K01-0042',
                        DibuatOfflinePada: '2026-09-24T03:10:00Z',
                        TanggalBisnis: '2026-09-24',
                        NamaOutlet: 'Kopi Senja Solo Baru',
                        NamaKasir: 'Rina Wulandari',
                        Kanal: 'BawaPulang',
                        LabelKanal: 'Bawa pulang',
                        TotalAkhir: '96570.00',
                        Metode: ['QRIS statis', 'Tunai'],
                        Status: 'Lunas',
                        LabelStatus: 'Lunas',
                        PerluTinjauan: false,
                    },
                ],
                DaftarTerpotong: false,
                JumlahTransaksi: 1,
                TotalPenjualan: '96570.00',
            },
            Laporan: laporanShift,
            Tutup: null,
            Izin: { TutupPaksa: false },
        };

        RenderUji(<HalamanDetailShift {...props} />);
        expect(screen.getByText(/kasir sudah punya shift terbuka/)).toBeTruthy();
        // F-07b: penjualan shift dengan tautan ke detail penjualan.
        expect(
            within(screen.getByRole('table', { name: 'Penjualan shift' }))
                .getByRole('link', { name: 'INV/UTAMA/260924/UTAMA-K01-0042' })
                .getAttribute('href'),
        ).toBe('/kelola/penjualan/01K5PENJUALAN0000000000001');
        expect(screen.getByText(/1 transaksi, total/)).toBeTruthy();
        expect(screen.queryByText(/penjualan terakhir/)).toBeNull();
        expect(screen.getByText('Rp 100.000 × 12')).toBeTruthy();
        expect(screen.getByText('Disetujui Budi Santoso')).toBeTruthy();
        // Cetak struk bagian 4: log buka laci tanpa transaksi beserta tanda tinjauan.
        const tabelLaci = screen.getByRole('table', { name: 'Buka laci tanpa transaksi' });
        expect(within(tabelLaci).getByText('Tukar uang kecil untuk kembalian')).toBeTruthy();
        expect(within(tabelLaci).getByText(/Perlu ditinjau: PenyetujuTidakAda/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'JU/2026/09/000007' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/01K5JURNAL0000000000000007',
        );
        // K-18: foto bukti kas & riwayat buka ulang shift beserta hasil tutup yang dibatalkan.
        expect(screen.getByRole('link', { name: 'Lihat foto bukti' }).getAttribute('href')).toBe(
            '/kelola/kasir/mutasi-kas/01K5MUTASI0000000000000001/bukti',
        );
        const tabelBukaUlang = screen.getByRole('table', { name: 'Buka ulang shift' });
        expect(within(tabelBukaUlang).getByText('Salah tekan tutup, pelanggan masih antre')).toBeTruthy();
        expect(within(tabelBukaUlang).getByText('Diminta Rina Wulandari | disetujui Budi Santoso')).toBeTruthy();
        expect(within(tabelBukaUlang).getByText(/Kas aktual Rp 448\.000/)).toBeTruthy();

        cleanup();
        RenderUji(
            <HalamanDetailShift
                {...props}
                MutasiKas={[]}
                Penjualan={{ Daftar: [], DaftarTerpotong: false, JumlahTransaksi: 0, TotalPenjualan: '0.00' }}
            />,
        );
        expect(screen.getByText(/Belum ada kas masuk/)).toBeTruthy();
        expect(screen.getByText('Belum ada penjualan di shift ini.')).toBeTruthy();

        // Shift ramai (> 200 penjualan): tabel hanya memuat penjualan terakhir dan menautkan daftar penjualan lengkap.
        cleanup();
        RenderUji(
            <HalamanDetailShift
                {...props}
                Penjualan={{
                    ...props.Penjualan,
                    DaftarTerpotong: true,
                    JumlahTransaksi: 1250,
                    TotalPenjualan: '187500000.00',
                }}
            />,
        );
        expect(screen.getByText(/1250 transaksi, total/)).toBeTruthy();
        expect(screen.getByText(/Tabel menampilkan 1 penjualan terakhir/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'daftar penjualan' }).getAttribute('href')).toBe('/kelola/penjualan');
    });

    it('kategori kas: keadaan kosong dan formulir tambah mengirim nama, jenis, akun', () => {
        const props: PropsKategoriKas = {
            Kategori: [],
            OpsiAkun: {
                Keluar: [
                    { Uuid: '01K5AKUN000000000000000001', Kode: '6-9000', Nama: 'Beban Lain-lain', Jenis: 'Beban' },
                ],
                Masuk: [],
            },
        };
        tiruanRouter.post.mockClear();
        RenderUji(<HalamanKategoriKas {...props} />);
        expect(screen.getByText(/Belum ada kategori kas/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Tambah kategori kas' }));
        UbahNilai(screen.getByLabelText('Nama kategori'), 'Bayar parkir motor');
        // Audit kemudahan pakai #15: akun dipilih otomatis dari nama kategori (tanpa kode akun).
        expect(screen.getByLabelText('Dicatat sebagai biaya').textContent).toContain('Beban Lain-lain');
        expect(screen.queryByText(/6-9000/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan kategori' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/kasir/kategori-kas',
            { Nama: 'Bayar parkir motor', Jenis: 'Keluar', UuidAkun: '01K5AKUN000000000000000001' },
            expect.anything(),
        );
    });

    it('D-106 kategori kas: tombol "Tambah biaya umum" mengirim satu permintaan tanpa mengisi apa pun', () => {
        tiruanRouter.post.mockClear();
        RenderUji(<HalamanKategoriKas Kategori={[]} OpsiAkun={{ Keluar: [], Masuk: [] }} />);

        fireEvent.click(screen.getByRole('button', { name: /Tambah biaya umum/ }));

        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/kasir/kategori-kas/biaya-umum', {}, expect.anything());
    });

    it('pengaturan kasir: batas tanpa float ("200000.00" → "200000") dan tombol simpan aktif setelah berubah', () => {
        expect(UbahKeMasukanUang('200000.00')).toBe('200000');
        expect(UbahKeMasukanUang('150000.50')).toBe('150000.50');

        render(<HalamanPengaturanKasir {...propsPengaturan} />);
        const simpan = screen.getByRole('button', { name: 'Simpan pengaturan' });
        expect(simpan.hasAttribute('disabled')).toBe(true);

        fireEvent.click(screen.getByRole('checkbox', { name: /berbagi satu laci/ }));
        expect(simpan.hasAttribute('disabled')).toBe(false);
    });

    it('pengaturan kasir: bidang kosong dikirim apa adanya (tidak diam-diam menjadi 0) agar server meminta diisi', () => {
        tiruanRouter.put.mockClear();
        render(<HalamanPengaturanKasir {...propsPengaturan} />);

        expect(screen.getByLabelText('Batas kas keluar tanpa persetujuan').hasAttribute('required')).toBe(true);
        UbahNilai(screen.getByLabelText('Batas kas keluar tanpa persetujuan'), '');
        UbahNilai(screen.getByLabelText('Toleransi selisih kas'), '');
        UbahNilai(screen.getByLabelText('Batas hari lewat jatuh tempo'), '');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/kasir/pengaturan',
            expect.objectContaining({ BatasKasKeluar: '', ToleransiSelisihKas: '', BatasHariLewatJatuhTempo: null }),
            expect.anything(),
        );
    });

    it('pengaturan kasir F-07b: batas diskon (persen tanpa float) & pembulatan tunai terkirim; pembulatan mati = null', () => {
        expect(UbahKeMasukanPersen('10.00')).toBe('10');
        expect(UbahKeMasukanPersen('25.50')).toBe('25.5');
        expect(NormalisasiMasukanPersen('12,755%')).toBe('12.75');
        expect(NormalisasiMasukanPersen('1000')).toBe('100');

        tiruanRouter.put.mockClear();
        RenderUji(<HalamanPengaturanKasir {...propsPengaturan} />);
        expect(screen.queryByLabelText('Kelipatan')).toBeNull();

        UbahNilai(screen.getByLabelText('Batas diskon kasir (%)'), '5');
        fireEvent.click(screen.getByRole('checkbox', { name: 'Bulatkan pembayaran tunai' }));
        UbahNilai(screen.getByLabelText('Kelipatan'), '500');
        UbahNilai(screen.getByLabelText('Arah pembulatan'), 'Terdekat');
        UbahNilai(screen.getByLabelText('Batas hari lewat jatuh tempo'), '14');
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Wajib PIN supervisor untuk membuka laci tanpa transaksi' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));

        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/kasir/pengaturan',
            {
                BatasKasKeluar: '200000',
                ShiftBersama: false,
                BatasDiskonManual: '5',
                BatasDiskonPenyetuju: '30',
                PembulatanTunai: { Kelipatan: 500, Arah: 'Terdekat' },
                TutupShiftButa: true,
                ToleransiSelisihKas: '10000',
                BatasHariRetur: 7,
                BatasHariLewatJatuhTempo: 14,
                BukaLaciPerluPin: true,
                BatasReturTanpaStrukHarian: '1000000',
                BatasNotifikasiTransaksi: '500000',
            },
            expect.anything(),
        );

        cleanup();
        tiruanRouter.put.mockClear();
        RenderUji(<HalamanPengaturanKasir {...propsPengaturan} PembulatanTunai={{ Kelipatan: 100, Arah: 'Bawah' }} />);
        fireEvent.click(screen.getByRole('checkbox', { name: 'Bulatkan pembayaran tunai' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/kasir/pengaturan',
            expect.objectContaining({ PembulatanTunai: null }),
            expect.anything(),
        );
    });
    it('F-11 daftar shift: selisih tutup shift bertanda; belum ditutup = "Belum ditutup"', () => {
        window.history.replaceState({}, '', '/kelola/kasir/shift');
        RenderUji(
            <HalamanDaftarShift
                {...PropsDaftar([
                    barisShift,
                    {
                        ...barisShift,
                        Uuid: '01K5SHIFT00000000000000002',
                        Status: 'Tertutup',
                        LabelStatus: 'Tertutup',
                        PerluTinjauan: false,
                        DitutupPada: '2026-09-24T10:00:00Z',
                        KasAktual: '903000.00',
                        Selisih: '-22000.00',
                    },
                ])}
            />,
        );
        const tabel = screen.getByRole('table', { name: 'Daftar shift' });
        expect(within(tabel).getByText('Belum ditutup')).toBeTruthy();
        expect(within(tabel).getByText('−Rp 22.000')).toBeTruthy();
    });

    it('F-11 detail shift: laporan X saat berjalan; laporan Z + tutup shift (selisih, alasan, penyetuju, non-tunai vs sistem, jurnal)', () => {
        const dasar: PropsDetailShift = {
            Shift: { ...barisShift, DiterimaPada: '2026-09-24T05:00:00Z', AlasanTinjauan: null, PecahanKasAwal: [] },
            MutasiKas: [],
            BukaLaci: [],
            BukaUlang: [],
            Penjualan: { Daftar: [], DaftarTerpotong: false, JumlahTransaksi: 0, TotalPenjualan: '0.00' },
            Laporan: laporanShift,
            Tutup: null,
            Izin: { TutupPaksa: true },
        };

        RenderUji(<HalamanDetailShift {...dasar} />);
        // Shift masih terbuka + izin supervisor: tombol tutup paksa tersedia, dan dialognya meminta alasan.
        fireEvent.click(screen.getByRole('button', { name: 'Tutup paksa shift' }));
        expect(screen.getByLabelText(/Alasan tutup paksa/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        cleanup();
        RenderUji(<HalamanDetailShift {...dasar} Izin={{ TutupPaksa: false }} />);
        expect(screen.queryByRole('button', { name: 'Tutup paksa shift' })).toBeNull();
        cleanup();
        RenderUji(<HalamanDetailShift {...dasar} />);
        expect(screen.getByRole('heading', { name: 'Laporan X (shift berjalan)' })).toBeTruthy();
        expect(screen.getByText('QRIS Kopi Senja')).toBeTruthy();
        expect(screen.getByText('Rp 975.000')).toBeTruthy();
        expect(screen.queryByRole('heading', { name: 'Tutup shift' })).toBeNull();

        cleanup();
        RenderUji(
            <HalamanDetailShift
                {...dasar}
                Shift={{ ...dasar.Shift, Status: 'Tertutup', LabelStatus: 'Tertutup' }}
                Tutup={{
                    DitutupOleh: 'Rina Wulandari',
                    DitutupPada: '2026-09-24T10:00:00Z',
                    KasSeharusnya: '975000.00',
                    KasAktual: '953000.00',
                    Selisih: '-22000.00',
                    AlasanSelisih: 'Uang kembalian salah hitung saat ramai',
                    Penyetuju: 'Budi Santoso',
                    PecahanKasAkhir: [{ Nominal: '100000.00', Jumlah: 9 }],
                    NonTunai: [
                        {
                            UuidMetodePembayaran: '01K5METODE0000000000000002',
                            Jenis: 'QrisStatis',
                            Nama: 'QRIS Kopi Senja',
                            JumlahSistem: '46570.00',
                            JumlahDilaporkan: '45000.00',
                        },
                    ],
                    NomorJurnal: 'JU/2026/09/000011',
                    UuidJurnal: '01K5JURNAL0000000000000011',
                }}
            />,
        );
        expect(screen.getByRole('heading', { name: 'Laporan Z (shift ditutup)' })).toBeTruthy();
        expect(screen.getByText('−Rp 22.000 (kurang)')).toBeTruthy();
        expect(screen.getByText('Uang kembalian salah hitung saat ramai')).toBeTruthy();
        expect(screen.getByText('Budi Santoso')).toBeTruthy();
        expect(screen.getByText(/beda −Rp 1.570/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'JU/2026/09/000011' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/01K5JURNAL0000000000000011',
        );
    });

    it('pengaturan kasir F-11: tutup shift buta & toleransi selisih kas terkirim', () => {
        tiruanRouter.put.mockClear();
        RenderUji(<HalamanPengaturanKasir {...propsPengaturan} />);
        fireEvent.click(screen.getByRole('checkbox', { name: /Tutup shift buta/ }));
        UbahNilai(screen.getByLabelText('Toleransi selisih kas'), '25000');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/kasir/pengaturan',
            expect.objectContaining({ TutupShiftButa: false, ToleransiSelisihKas: '25000' }),
            expect.anything(),
        );
    });

    it('pengaturan kasir v3.55: barcode timbangan diaktifkan dengan awalan & jenis nilai, disimpan terpisah', () => {
        tiruanRouter.put.mockClear();
        RenderUji(<HalamanPengaturanKasir {...propsPengaturan} />);
        expect(screen.queryByText('Awalan barcode')).toBeNull();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Kasir membaca barcode timbangan' }));
        fireEvent.click(screen.getByRole('checkbox', { name: '28' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan barcode timbangan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/kasir/pengaturan/barcode-timbangan',
            { Aktif: true, Awalan: ['27', '28'], Nilai: 'Berat' },
            expect.anything(),
        );
    });
});
