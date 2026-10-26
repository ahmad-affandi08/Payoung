import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanFormFaktur, { TambahHari } from '@/Halaman/Kelola/Pembelian/Faktur/Form';
import HalamanDaftarHutang, { FormatHariLewat } from '@/Halaman/Kelola/Pembelian/Hutang/Daftar';
import HalamanBuatPemasok from '@/Halaman/Kelola/Pembelian/Pemasok/Buat';
import HalamanDaftarPemasok from '@/Halaman/Kelola/Pembelian/Pemasok/Daftar';
import HalamanFormPembayaran, { PeriksaAlokasi } from '@/Halaman/Kelola/Pembelian/Pembayaran/Form';
import { BuatTautanLabel } from '@/Halaman/Kelola/Pembelian/Penerimaan/Detail';
import HalamanFormPenerimaan, { PeriksaBarisDariPesanan } from '@/Halaman/Kelola/Pembelian/Penerimaan/Form';
import HalamanPengaturanPembelian from '@/Halaman/Kelola/Pembelian/Pengaturan';
import HalamanDaftarPesanan from '@/Halaman/Kelola/Pembelian/Pesanan/Daftar';
import HalamanDetailPesanan from '@/Halaman/Kelola/Pembelian/Pesanan/Detail';
import HalamanFormPesanan from '@/Halaman/Kelola/Pembelian/Pesanan/Form';
import HalamanFormRetur, { PeriksaBarisRetur } from '@/Halaman/Kelola/Pembelian/Retur/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { BuatHasilTabel, GudangUtama, NamaPanjang } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { PilihOpsi, UbahNilai } from '@/Pengujian/InteraksiPilihan';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';
import type {
    BarisDetailPenerimaan,
    BarisPesananUntukPenerimaan,
    IzinPembelian,
    OpsiPemasok,
    PropsDetailPesanan,
    PropsFormRetur,
} from '@/Tipe/Pembelian';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const kamera = vi.hoisted(() => ({
    mulai: vi.fn(),
    daftar: vi.fn(),
}));

vi.mock('@/Pustaka/PemindaiKamera', async (impor) => ({
    ...(await impor<typeof import('@/Pustaka/PemindaiKamera')>()),
    MulaiPemindaian: kamera.mulai,
    DaftarKamera: kamera.daftar,
}));

const Uuid = '01J9ZC5V7Q8R2T4W6Y8A0B2C4D';
const UuidFaktur = '01J9ZC5V7Q8R2T4W6Y8A0B2C4E';
const IzinPenuh: IzinPembelian = { Kelola: true, Setujui: true, LihatJurnal: true, Persediaan: true };
const IzinLihat: IzinPembelian = { Kelola: false, Setujui: false, LihatJurnal: false, Persediaan: true };
const Pemasok: OpsiPemasok = {
    Uuid: '01J9PMS0000000000000000001',
    Kode: 'SUP-001',
    Nama: 'PT Sumber Rejeki Makmur Abadi Sentosa',
    Pkp: true,
    TerminHari: 30,
    Aktif: true,
};

const BarisSatuan = {
    Uuid: '01J9PRD0000000000000000001',
    Nama: NamaPanjang,
    Sku: 'KOPI-JMB',
    Pelacakan: 'Tidak' as const,
    SimbolSatuan: 'pcs',
    BolehDesimal: false,
    Satuan: [],
};

function BarisPesananPenerimaan(perubahan: Partial<BarisPesananUntukPenerimaan> = {}): BarisPesananUntukPenerimaan {
    return {
        IdBarisPesanan: 11,
        NamaProduk: 'Minyak Goreng Sawit 2 L',
        Sku: 'MYK-2L',
        Pelacakan: 'Tidak',
        SimbolSatuan: 'pcs',
        Konversi: '1',
        BolehDesimal: false,
        Harga: '38500.00',
        Jumlah: '24.0000',
        JumlahDiterima: '10.0000',
        Sisa: '14.0000',
        ...perubahan,
    };
}

function PropsDetail(perubahan: Partial<PropsDetailPesanan['Tindakan']> = {}): PropsDetailPesanan {
    return {
        Pesanan: {
            Uuid,
            Nomor: 'PO/UTAMA/2609/0001',
            Tanggal: '2026-09-20',
            PerkiraanTiba: null,
            Status: 'Draf',
            LabelStatus: 'Draf',
            Pemasok: {
                Uuid: Pemasok.Uuid,
                Kode: 'SUP-001',
                Nama: Pemasok.Nama,
                Alamat: null,
                NoHp: null,
                Npwp: null,
                Pkp: true,
            },
            UuidGudang: GudangUtama.Uuid,
            NamaGudang: 'Toko Utama',
            NamaOutlet: 'Outlet Utama',
            TerminHari: 30,
            TarifPpn: '12.00',
            Subtotal: '6750000.00',
            Diskon: '0.00',
            Pajak: '742500.00',
            Ongkir: '0.00',
            Total: '7492500.00',
            Catatan: null,
            AlasanDitolak: null,
            AlasanBatal: null,
            DibuatOleh: 'Sari',
            DisetujuiOleh: null,
            DisetujuiPada: null,
            IdPembuat: 1,
        },
        Baris: [
            {
                Id: 1,
                NamaProduk: NamaPanjang,
                Sku: 'KOPI-JMB',
                SimbolSatuan: 'pcs',
                Konversi: '1',
                Jumlah: '150.0000',
                Harga: '45000.00',
                Diskon: '0.00',
                Subtotal: '6750000.00',
                JumlahDiterima: '0.0000',
                Sisa: '150.0000',
            },
        ],
        Penerimaan: [],
        Pengiriman: [],
        Riwayat: [],
        Izin: IzinPenuh,
        Tindakan: {
            Ubah: true,
            Ajukan: true,
            Setujui: false,
            Terima: false,
            KirimWhatsapp: false,
            Batalkan: true,
            Tutup: false,
            ...perubahan,
        },
    };
}

function BarisPenerimaanRetur(perubahan: Partial<BarisDetailPenerimaan> = {}): BarisDetailPenerimaan {
    return {
        Id: 21,
        NamaProduk: 'Minyak Goreng Sawit 2 L',
        Sku: 'MYK-2L',
        SimbolSatuan: 'pcs',
        Konversi: '1',
        JumlahPesanan: null,
        Jumlah: '24.0000',
        JumlahDasar: '24.0000',
        Harga: '38500.00',
        Diskon: '0.00',
        Subtotal: '924000.00',
        AlokasiBiaya: '0.00',
        Nilai: '924000.00',
        HppSatuan: '38500.000000',
        NomorBatch: null,
        TanggalKedaluwarsa: null,
        NomorSeri: [],
        NomorSeriBisaDiretur: [],
        JumlahDiretur: '4.0000',
        SisaBisaDiretur: '20.0000',
        UuidSatuanLabel: '01JBMYKSATUAN0000000000001',
        JumlahLabel: 24,
        ...perubahan,
    };
}

function PropsRetur(baris: BarisDetailPenerimaan[] = [BarisPenerimaanRetur()]): PropsFormRetur {
    return {
        Penerimaan: {
            Uuid,
            Nomor: 'GR/UTAMA/2609/0001',
            Tanggal: '2026-09-22',
            Status: 'Diposting',
            LabelStatus: 'Diposting',
            Pemasok: { Uuid: Pemasok.Uuid, Kode: 'SUP-001', Nama: Pemasok.Nama },
            Pesanan: null,
            Faktur: null,
            NamaGudang: 'Toko Utama',
            NamaOutlet: 'Outlet Utama',
            NomorSuratJalan: null,
            Catatan: null,
            TarifPpn: null,
            PpnDikreditkan: false,
            Subtotal: '924000.00',
            Ongkir: '0.00',
            Pajak: '0.00',
            TotalNilai: '924000.00',
            BelanjaStok: false,
            Lampiran: null,
            AlasanBatal: null,
            DibuatOleh: 'Sari',
            DibatalkanOleh: null,
        },
        Baris: baris,
        Retur: [],
        Jurnal: [],
        Riwayat: [],
        HariIni: '2026-09-24',
        OpsiAkunRefund: [{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1200', Nama: 'Bank BCA' }],
    };
}

describe('Pintasan Cetak label barang diterima (PRD-10)', () => {
    it('satu stiker per satuan dasar. Baris tanpa satuan atau jumlah bulat dilewati. Kosong = tanpa tautan', () => {
        expect(
            BuatTautanLabel([
                BarisPenerimaanRetur(),
                BarisPenerimaanRetur({ UuidSatuanLabel: '01JBMYKSATUAN0000000000002', JumlahLabel: 288 }),
                BarisPenerimaanRetur({ UuidSatuanLabel: null, JumlahLabel: null }),
                BarisPenerimaanRetur({ UuidSatuanLabel: '01JBMYKSATUAN0000000000003', JumlahLabel: 0 }),
            ]),
        ).toBe('/kelola/produk/label?Item[]=01JBMYKSATUAN0000000000001:24&Item[]=01JBMYKSATUAN0000000000002:288');
        expect(BuatTautanLabel([])).toBeNull();
        expect(BuatTautanLabel([BarisPenerimaanRetur({ UuidSatuanLabel: null, JumlahLabel: null })])).toBeNull();
    });
});

describe('Aturan halaman pembelian (F-04 fase 1)', () => {
    it('BR-04.1 penerimaan dari PO: 0/kosong boleh, batch wajib, seri = jumlah unit', () => {
        const isian = { Jumlah: '', NomorBatch: '', TanggalKedaluwarsa: '', NomorSeri: [] };

        expect(PeriksaBarisDariPesanan(BarisPesananPenerimaan(), isian)).toBeNull();
        expect(PeriksaBarisDariPesanan(BarisPesananPenerimaan(), { ...isian, Jumlah: '1.5' })).toBe(
            'Jumlah harus bilangan bulat.',
        );
        expect(PeriksaBarisDariPesanan(BarisPesananPenerimaan({ Pelacakan: 'Batch' }), { ...isian, Jumlah: '2' })).toBe(
            'Isi nomor batch.',
        );
        expect(
            PeriksaBarisDariPesanan(BarisPesananPenerimaan({ Pelacakan: 'Seri', Konversi: '2' }), {
                ...isian,
                Jumlah: '2',
                NomorSeri: ['SN-1'],
            }),
        ).toBe('Isi tepat 4 nomor seri.');
    });

    it('alokasi pembayaran tidak boleh melebihi sisa. Retur tidak boleh melebihi sisa bisa diretur', () => {
        expect(PeriksaAlokasi('', { Sisa: '1000000.00' })).toBeNull();
        expect(PeriksaAlokasi('1000000.01', { Sisa: '1000000.00' })).toBe('Maksimal Rp 1.000.000 (sisa hutang).');
        expect(PeriksaBarisRetur(BarisPenerimaanRetur(), { Jumlah: '21', NomorSeri: [] })).toBe('Maksimal 20.');
        expect(PeriksaBarisRetur(BarisPenerimaanRetur(), { Jumlah: '20', NomorSeri: [] })).toBeNull();
    });

    it('jatuh tempo = tanggal + termin. Keterangan keterlambatan hutang', () => {
        expect(TambahHari('2026-09-24', 30)).toBe('2026-10-24');
        expect(TambahHari('2026-12-20', 14)).toBe('2027-01-03');
        expect(FormatHariLewat(-3)).toBe('3 hari lagi');
        expect(FormatHariLewat(0)).toBe('Jatuh tempo hari ini');
        expect(FormatHariLewat(45)).toBe('Lewat 45 hari');
    });
});

describe('Halaman pembelian (F-04 fase 1)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/pembelian/pesanan');
        window.history.replaceState({}, '', '/kelola/pembelian/pesanan');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('daftar pesanan: tombol buat hanya untuk pembelian.kelola', () => {
        RenderUji(
            <HalamanDaftarPesanan
                Pesanan={BuatHasilTabel([])}
                OpsiStatus={[{ Nilai: 'Draf', Label: 'Draf' }]}
                OpsiPemasok={[Pemasok]}
                Izin={IzinPenuh}
            />,
        );
        expect(screen.getAllByRole('link', { name: 'Buat pesanan pembelian' })[0]?.getAttribute('href')).toBe(
            '/kelola/pembelian/pesanan/buat',
        );
        // D-23 D: draf dari stok menipis bisa dipicu tanpa menunggu jadwal pagi.
        fireEvent.click(screen.getAllByRole('button', { name: 'Siapkan draf dari stok menipis' })[0] as HTMLElement);
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pembelian/pesanan/draf-otomatis',
            {},
            expect.anything(),
        );
        cleanup();
        RenderUji(
            <HalamanDaftarPesanan Pesanan={BuatHasilTabel([])} OpsiStatus={[]} OpsiPemasok={[]} Izin={IzinLihat} />,
        );
        expect(screen.queryByRole('link', { name: 'Buat pesanan pembelian' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Siapkan draf dari stok menipis' })).toBeNull();
    });

    it('daftar pemasok: tombol tambah membuka halaman penuh /kelola/pembelian/pemasok/buat (hanya pembelian.kelola)', () => {
        window.history.replaceState({}, '', '/kelola/pembelian/pemasok');
        RenderUji(<HalamanDaftarPemasok Pemasok={BuatHasilTabel([])} Izin={IzinPenuh} />);
        const tautan = screen.getAllByRole('link', { name: 'Tambah pemasok' });
        expect(tautan.length).toBeGreaterThan(0);
        expect(tautan.every((t) => t.getAttribute('href') === '/kelola/pembelian/pemasok/buat')).toBe(true);
        expect(screen.queryByRole('dialog')).toBeNull();
        cleanup();
        RenderUji(<HalamanDaftarPemasok Pemasok={BuatHasilTabel([])} Izin={IzinLihat} />);
        expect(screen.queryByRole('link', { name: 'Tambah pemasok' })).toBeNull();
    });

    it('halaman tambah pemasok: kirim POST ke /kelola/pembelian/pemasok; Batal kembali ke daftar', () => {
        window.history.replaceState({}, '', '/kelola/pembelian/pemasok/buat');
        RenderUji(<HalamanBuatPemasok />);
        UbahNilai(screen.getByLabelText('Kode pemasok'), 'SUP-001');
        UbahNilai(screen.getByLabelText('Nama pemasok'), 'PT Sumber Pangan Nusantara');
        UbahNilai(screen.getByLabelText('Termin bawaan (hari)'), '30');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemasok' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pembelian/pemasok',
            expect.objectContaining({
                Kode: 'SUP-001',
                Nama: 'PT Sumber Pangan Nusantara',
                TerminHari: '30',
                Pkp: false,
            }),
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/pembelian/pemasok');
    });

    it('form pesanan: di atas batas persetujuan tampil peringatan. Simpan mengirim PUT dengan baris', () => {
        RenderUji(
            <HalamanFormPesanan
                Mode="Ubah"
                Pesanan={{
                    Uuid,
                    Nomor: 'PO/UTAMA/2609/0001',
                    UuidPemasok: Pemasok.Uuid,
                    UuidGudang: GudangUtama.Uuid,
                    Tanggal: '2026-09-20',
                    PerkiraanTiba: null,
                    TerminHari: 30,
                    Ongkir: '0.00',
                    Catatan: null,
                    Baris: [
                        {
                            UuidProduk: BarisSatuan.Uuid,
                            NamaProduk: NamaPanjang,
                            Sku: 'KOPI-JMB',
                            Pelacakan: 'Tidak',
                            SimbolSatuan: 'pcs',
                            BolehDesimal: false,
                            Satuan: [],
                            UuidProdukSatuan: null,
                            Jumlah: '150',
                            Harga: '45000',
                            Diskon: '0',
                        },
                    ],
                }}
                OpsiPemasok={[Pemasok]}
                OpsiGudang={[GudangUtama]}
                HariIni="2026-09-24"
                BatasPersetujuanPo="5000000.00"
                MaksimalBaris={500}
            />,
        );
        expect(screen.getByText('Butuh persetujuan')).toBeTruthy();
        expect(screen.getAllByText('Rp 6.750.000', { selector: 'dd' })).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            `/kelola/pembelian/pesanan/${Uuid}`,
            expect.objectContaining({
                // Audit kemudahan pakai #13: tombol utama menyimpan lalu langsung mengajukan PO.
                Lanjutkan: true,
                UuidPemasok: Pemasok.Uuid,
                UuidGudang: GudangUtama.Uuid,
                TerminHari: 30,
                Baris: [
                    {
                        UuidProduk: BarisSatuan.Uuid,
                        UuidProdukSatuan: null,
                        Jumlah: '150',
                        Harga: '45000',
                        Diskon: '0',
                    },
                ],
            }),
            expect.anything(),
        );
    });

    it('detail pesanan: tombol mengikuti Tindakan. Tolak butuh alasan. Ajukan mengirim POST', () => {
        RenderUji(<HalamanDetailPesanan {...PropsDetail()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Ajukan pesanan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pembelian/pesanan/${Uuid}/ajukan`,
            {},
            expect.anything(),
        );
        expect(screen.queryByRole('button', { name: 'Setujui pesanan' })).toBeNull();
        cleanup();

        RenderUji(
            <HalamanDetailPesanan
                {...PropsDetail({ Ubah: false, Ajukan: false, Setujui: true })}
                Pesanan={{
                    ...PropsDetail().Pesanan,
                    Status: 'MenungguPersetujuan',
                    LabelStatus: 'Menunggu persetujuan',
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
        const dialog = screen.getByRole('dialog');
        const tombol = within(dialog).getByRole('button', { name: 'Tolak pesanan' });
        expect((tombol as HTMLButtonElement).disabled).toBe(true);
        UbahNilai(within(dialog).getByLabelText(/Alasan/), 'Harga di atas anggaran bulan ini');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tolak pesanan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pembelian/pesanan/${Uuid}/tolak`,
            { Alasan: 'Harga di atas anggaran bulan ini' },
            expect.anything(),
        );
    });

    it('detail pesanan: kirim ke WhatsApp pemasok hanya bila diizinkan. Riwayat pengiriman berstatus berteks', () => {
        RenderUji(<HalamanDetailPesanan {...PropsDetail()} />);
        expect(screen.queryByRole('button', { name: 'Kirim ke WhatsApp pemasok' })).toBeNull();
        expect(screen.queryByRole('list', { name: 'Riwayat pengiriman ke pemasok' })).toBeNull();
        cleanup();

        RenderUji(
            <HalamanDetailPesanan
                {...PropsDetail({ KirimWhatsapp: true })}
                Pengiriman={[
                    {
                        Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4D',
                        Status: 'Gagal',
                        LabelStatus: 'Gagal',
                        PesanGalat: 'Integrasi WhatsApp tidak aktif.',
                        DibuatPada: '2026-09-29T03:00:00Z',
                        TerkirimPada: null,
                    },
                    {
                        Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4E',
                        Status: 'Terkirim',
                        LabelStatus: 'Terkirim',
                        PesanGalat: null,
                        DibuatPada: '2026-09-28T03:00:00Z',
                        TerkirimPada: '2026-09-28T03:00:05Z',
                    },
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Kirim ke WhatsApp pemasok' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/pembelian/pesanan/${Uuid}/kirim-whatsapp`,
            {},
            expect.anything(),
        );
        const riwayat = screen.getByRole('list', { name: 'Riwayat pengiriman ke pemasok' });
        expect(within(riwayat).getByText('Gagal')).toBeTruthy();
        expect(within(riwayat).getByText('Terkirim')).toBeTruthy();
        expect(within(riwayat).getByText('Integrasi WhatsApp tidak aktif.')).toBeTruthy();
    });

    it('terima dari PO: tanggal bawaan mundur sehari, jumlah bawaan = sisa, baris 0 tidak dikirim', () => {
        RenderUji(
            <HalamanFormPenerimaan
                Mode="Penerimaan"
                Pesanan={{
                    Uuid,
                    Nomor: 'PO/UTAMA/2609/0001',
                    TanggalPenerimaan: '2026-09-29',
                    NamaPemasok: Pemasok.Nama,
                    UuidGudang: GudangUtama.Uuid,
                    NamaGudang: 'Toko Utama',
                    Ongkir: '50000.00',
                    OngkirTerpakai: '0.00',
                    Baris: [
                        BarisPesananPenerimaan(),
                        BarisPesananPenerimaan({ IdBarisPesanan: 12, NamaProduk: 'Gula Pasir 1 kg', Sku: 'GLA' }),
                    ],
                }}
                OpsiPemasok={[Pemasok]}
                OpsiGudang={[GudangUtama]}
                OpsiAkun={[]}
                HariIni="2026-09-30"
                Lampiran={{ Ekstensi: ['pdf', 'jpg'], UkuranMaksimalKb: 5120 }}
                MaksimalBaris={500}
            />,
        );
        expect((screen.getByLabelText('Tanggal terima') as HTMLInputElement).value).toBe('29/09/2026');
        expect((screen.getByLabelText('Diterima Minyak Goreng Sawit 2 L') as HTMLInputElement).value).toBe('14');
        UbahNilai(screen.getByLabelText('Diterima Gula Pasir 1 kg'), '0');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan penerimaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pembelian/penerimaan',
            expect.objectContaining({
                UuidPesananPembelian: Uuid,
                Tanggal: '2026-09-29',
                Baris: [
                    { IdBarisPesanan: 11, Jumlah: '14', NomorBatch: null, TanggalKedaluwarsa: null, NomorSeri: [] },
                ],
            }),
            expect.anything(),
        );
    });

    it('belanja stok bayar nanti: tanpa akun kas, pemasok wajib, tempo terisi dari termin pemasok (audit #20)', () => {
        RenderUji(
            <HalamanFormPenerimaan
                Mode="BelanjaStok"
                Pesanan={null}
                OpsiPemasok={[{ ...Pemasok, TerminHari: 14 }]}
                OpsiGudang={[GudangUtama]}
                OpsiAkun={[{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1100', Nama: 'Kas Toko' }]}
                HariIni="2026-09-30"
                Lampiran={{ Ekstensi: ['pdf', 'jpg'], UkuranMaksimalKb: 5120 }}
                MaksimalBaris={500}
            />,
        );
        expect(screen.getByRole('combobox', { name: 'Dibayar dari akun' })).toBeTruthy();
        PilihOpsi(screen.getByRole('combobox', { name: 'Pembayaran' }), 'Nanti');
        expect(screen.queryByRole('combobox', { name: 'Dibayar dari akun' })).toBeNull();
        expect((screen.getByLabelText(/Tempo \(hari\)/) as HTMLInputElement).value).toBe('30');

        fireEvent.click(screen.getByRole('button', { name: 'Simpan belanja stok' }));
        expect(screen.getByText('Pilih pemasok untuk belanja yang dibayar nanti.')).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        PilihOpsi(screen.getByRole('combobox', { name: /Pemasok/ }), Pemasok.Uuid);
        expect((screen.getByLabelText(/Tempo \(hari\)/) as HTMLInputElement).value).toBe('14');
    });

    it('faktur: harga faktur berbeda tampil sebagai selisih. Simpan mengirim harga per baris', () => {
        RenderUji(
            <HalamanFormFaktur
                OpsiPemasok={[Pemasok]}
                UuidPemasok={Pemasok.Uuid}
                TerminHari={30}
                UuidPenerimaanAwal={Uuid}
                Penerimaan={[
                    {
                        Uuid,
                        Nomor: 'GR/UTAMA/2609/0001',
                        Tanggal: '2026-09-22',
                        NamaOutlet: 'Outlet Utama',
                        Ongkir: '0.00',
                        Pkp: true,
                        TerminHari: 30,
                        Baris: [
                            {
                                Id: 31,
                                NamaProduk: 'Minyak Goreng Sawit 2 L',
                                SimbolSatuan: 'pcs',
                                Jumlah: '10.0000',
                                JumlahDasar: '10.0000',
                                JumlahDiretur: '0.0000',
                                Konversi: '1',
                                Harga: '38500.00',
                                Diskon: '0.00',
                                Subtotal: '385000.00',
                            },
                        ],
                    },
                ]}
                HariIni="2026-09-24"
                Lampiran={{ Ekstensi: ['pdf'], UkuranMaksimalKb: 5120 }}
            />,
        );
        expect((screen.getByLabelText('Jatuh tempo') as HTMLInputElement).value).toBe('24/10/2026');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan faktur' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText('Isi nomor faktur dari pemasok.')).toBeTruthy();

        UbahNilai(screen.getByLabelText('Nomor faktur pemasok'), 'INV-SRM-0921');
        UbahNilai(screen.getByLabelText('Harga faktur Minyak Goreng Sawit 2 L'), '39000');
        expect(screen.getByText('Beda dari penerimaan')).toBeTruthy();
        expect(screen.getByText('Rp 5.000')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan faktur' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pembelian/faktur',
            expect.objectContaining({
                NomorFakturPemasok: 'INV-SRM-0921',
                UuidPenerimaan: [Uuid],
                JatuhTempo: '2026-10-24',
                Baris: [{ IdBarisPenerimaan: 31, Harga: '39000', Diskon: '0.00' }],
            }),
            expect.anything(),
        );
    });

    it('pembayaran: faktur awal terisi sisa, melebihi sisa ditolak lokal', () => {
        RenderUji(
            <HalamanFormPembayaran
                OpsiPemasok={[Pemasok]}
                OpsiAkun={[{ Uuid: '01J9AKN0000000000000000001', Kode: '1-1200', Nama: 'Bank BCA' }]}
                UuidPemasok={Pemasok.Uuid}
                UuidFakturAwal={UuidFaktur}
                Faktur={[
                    {
                        Uuid: UuidFaktur,
                        Nomor: 'FB/2609/0001',
                        NomorFakturPemasok: 'INV-1',
                        Tanggal: '2026-09-01',
                        JatuhTempo: '2026-10-01',
                        Total: '1250000000.00',
                        Sisa: '1250000000.00',
                    },
                ]}
                HariIni="2026-09-24"
                Lampiran={{ Ekstensi: ['pdf'], UkuranMaksimalKb: 5120 }}
            />,
        );
        const isian = screen.getByLabelText('Bayar FB/2609/0001');
        expect((isian as HTMLInputElement).value).toBe('1.250.000.000');
        UbahNilai(isian, '1250000001');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pembayaran' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        UbahNilai(isian, '500000000');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pembayaran' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pembelian/pembayaran',
            expect.objectContaining({
                UuidAkun: '01J9AKN0000000000000000001',
                Alokasi: [{ UuidFaktur, Jumlah: '500000000' }],
            }),
            expect.anything(),
        );
    });

    it('retur: alasan minimal 5 karakter dan jumlah ≤ sisa bisa diretur', () => {
        const props = PropsRetur();
        RenderUji(<HalamanFormRetur {...props} />);
        UbahNilai(screen.getByLabelText('Jumlah retur Minyak Goreng Sawit 2 L'), '21');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan retur' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText('Tulis alasan minimal 5 karakter.')).toBeTruthy();
        expect(screen.getByText('Maksimal 20.')).toBeTruthy();

        UbahNilai(screen.getByLabelText('Jumlah retur Minyak Goreng Sawit 2 L'), '3');
        UbahNilai(screen.getByLabelText(/Alasan retur/), 'Kemasan bocor saat diterima');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan retur' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/pembelian/retur',
            {
                UuidPenerimaan: Uuid,
                Tanggal: '2026-09-24',
                Alasan: 'Kemasan bocor saat diterima',
                UuidAkunRefund: '01J9AKN0000000000000000001',
                Baris: [{ IdBarisPenerimaan: 21, Jumlah: '3', NomorSeri: [] }],
            },
            expect.anything(),
        );
    });

    it('retur bernomor seri: label yang dipindai mencentang nomor seri yang bisa diretur, nomor lain ditolak', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        RenderUji(
            <HalamanFormRetur
                {...PropsRetur([
                    BarisPenerimaanRetur({
                        NomorSeri: ['SN-1', 'SN-2', 'SN-3'],
                        NomorSeriBisaDiretur: ['SN-1', 'SN-2'],
                    }),
                ])}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pindai nomor seri retur Minyak Goreng Sawit 2 L' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('sn-2');
        expect(await screen.findByText('SN-2 dicentang (1 nomor).')).toBeTruthy();

        // Kamera berkelanjutan: selagi terbuka, halaman di belakangnya tidak terbaca; tutup lalu periksa centangnya.
        fireEvent.click(screen.getByRole('button', { name: 'Selesai' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(screen.getByRole('checkbox', { name: 'SN-2' }).getAttribute('aria-checked')).toBe('true');
        expect(screen.getByRole('checkbox', { name: 'SN-1' }).getAttribute('aria-checked')).toBe('false');
        expect(tiruan.opsiTerakhir()).toMatchObject({ jenisKode: 'Seri' });
    });

    it('hutang: ringkasan umur tampil. Pengaturan hanya bisa disimpan bila berubah', () => {
        RenderUji(
            <HalamanDaftarHutang
                Hutang={{
                    ...BuatHasilTabel([]),
                    Ringkasan: {
                        Total: '1250000000.00',
                        Kelompok: [
                            { Kunci: 'LebihDari90', Label: 'Lebih dari 90 hari', Sisa: '1250000000.00', Jumlah: 3 },
                        ],
                    },
                }}
                OpsiUmur={[{ Nilai: 'LebihDari90', Label: 'Lebih dari 90 hari' }]}
                OpsiPemasok={[Pemasok]}
                HariIni="2026-09-24"
                Izin={IzinPenuh}
            />,
        );
        expect(screen.getByText('Lebih dari 90 hari', { selector: 'p' })).toBeTruthy();
        expect(screen.getByText('3 faktur')).toBeTruthy();
        cleanup();

        RenderUji(
            <HalamanPengaturanPembelian
                Pengaturan={{
                    BatasPersetujuanPo: '5000000.00',
                    ToleransiPenerimaanPersen: '0.00',
                    DrafPoOtomatis: true,
                    SumberDrafPo: 'Minimum',
                    HariCakupanDrafPo: 14,
                }}
            />,
        );
        const simpan = screen.getByRole('button', { name: 'Simpan pengaturan' });
        expect((simpan as HTMLButtonElement).disabled).toBe(true);
        UbahNilai(screen.getByLabelText('Toleransi lebih dari pesanan'), '2,5');
        // D-23 D: draf PO otomatis bisa dimatikan.
        fireEvent.click(screen.getByRole('checkbox', { name: 'Siapkan draf pesanan pembelian otomatis' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/pembelian/pengaturan',
            {
                BatasPersetujuanPo: '5000000',
                ToleransiPenerimaanPersen: '2.5',
                DrafPoOtomatis: false,
                SumberDrafPo: 'Minimum',
                HariCakupanDrafPo: 14,
            },
            expect.anything(),
        );
    });

    it('F24: dasar draf PO bisa diganti ke perkiraan pemakaian dan cakupan harinya dikirim', () => {
        RenderUji(
            <HalamanPengaturanPembelian
                Pengaturan={{
                    BatasPersetujuanPo: '5000000.00',
                    ToleransiPenerimaanPersen: '0.00',
                    DrafPoOtomatis: true,
                    SumberDrafPo: 'Perkiraan',
                    HariCakupanDrafPo: 7,
                }}
            />,
        );

        expect(screen.getByText(/rata-rata pemakaian 28 hari terakhir/)).toBeTruthy();
        PilihOpsi(screen.getByRole('combobox', { name: /Cakupan kebutuhan/ }), '30');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/pembelian/pengaturan',
            {
                BatasPersetujuanPo: '5000000',
                ToleransiPenerimaanPersen: '0',
                DrafPoOtomatis: true,
                SumberDrafPo: 'Perkiraan',
                HariCakupanDrafPo: 30,
            },
            expect.anything(),
        );
    });
});
