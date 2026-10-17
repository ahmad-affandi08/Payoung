import { cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarStokOpname from '@/Halaman/Kelola/Persediaan/Opname/Daftar';
import HalamanDetailStokOpname, { PeriksaJumlahFisik, TambahSatu } from '@/Halaman/Kelola/Persediaan/Opname/Detail';
import HalamanPengaturanPersediaan from '@/Halaman/Kelola/Persediaan/Pengaturan';
import HalamanDetailPenyesuaianStok from '@/Halaman/Kelola/Persediaan/Penyesuaian/Detail';
import HalamanFormPenyesuaianStok, { PeriksaBarisPenyesuaian } from '@/Halaman/Kelola/Persediaan/Penyesuaian/Form';
import HalamanDaftarTransferStok from '@/Halaman/Kelola/Persediaan/Transfer/Daftar';
import HalamanDetailTransferStok, {
    PeriksaBagianTerima,
    PeriksaJumlahTerima,
} from '@/Halaman/Kelola/Persediaan/Transfer/Detail';
import { PeriksaBarisTransfer } from '@/Halaman/Kelola/Persediaan/Transfer/Form';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { AmbilJenisLabelDokumen, PeriksaAlasan } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import { BuatHasilTabel, GudangKedua, GudangUtama, NamaPanjang } from '@/Komponen/Persediaan/DataUjiPersediaan';
import { PilihOpsi, UbahNilai } from '@/Pengujian/InteraksiPilihan';
import { SiapkanKameraTiruan } from '@/Pengujian/KameraTiruan';
import type {
    BarisDetailTransferStok,
    BarisStokOpname,
    IzinDokumenPersediaan,
    PropsDetailPenyesuaianStok,
    PropsDetailStokOpname,
    PropsDetailTransferStok,
    PropsFormPenyesuaianStok,
} from '@/Tipe/DokumenPersediaan';

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
const IzinPenuh: IzinDokumenPersediaan = { Lihat: true, Kelola: true, Setujui: true, LihatJurnal: true };
const IzinLihat: IzinDokumenPersediaan = { Lihat: true, Kelola: false, Setujui: false, LihatJurnal: false };

function BarisTransfer(perubahan: Partial<BarisDetailTransferStok> = {}): BarisDetailTransferStok {
    return {
        Urutan: 1,
        UuidProduk: '01J9PRD0000000000000000001',
        NamaProduk: 'Minyak Goreng Sawit 2 L',
        Sku: 'MYK-2L',
        SimbolSatuan: 'pcs',
        BolehDesimal: false,
        Pelacakan: 'Tidak',
        JumlahDikirim: '30.0000',
        JumlahDiterima: '12.0000',
        JumlahSusut: '0.0000',
        JumlahDitolak: '0.0000',
        JumlahSisa: '18.0000',
        NilaiKirim: '1155000.00',
        NilaiDiterima: '462000.00',
        NilaiSusut: '0.00',
        NilaiDitolak: '0.00',
        UuidBatchStok: null,
        NomorBatch: null,
        TanggalKedaluwarsa: null,
        UuidNomorSeri: null,
        NomorSeri: null,
        ...perubahan,
    };
}

function PropsTransfer(perubahan: Partial<PropsDetailTransferStok> = {}): PropsDetailTransferStok {
    return {
        Transfer: {
            Uuid,
            Nomor: 'TF/TOKO-SOLO/2609/0001',
            Status: 'DiterimaSebagian',
            LabelStatus: 'Diterima sebagian',
            Tanggal: '2026-09-23',
            NamaGudangAsal: 'Toko Utama',
            NamaOutletAsal: 'Outlet Utama',
            NamaGudangTujuan: NamaPanjang,
            NamaOutletTujuan: 'Cabang Solo Baru',
            NamaGudangTransit: 'Dalam perjalanan',
            Catatan: null,
            JumlahBaris: 1,
            TotalNilaiKirim: '1155000.00',
            TotalNilaiDiterima: '462000.00',
            TotalNilaiSusut: '0.00',
            AlasanSelisih: null,
            AlasanBatal: null,
            DibuatOleh: 'Sari',
            DibuatPada: '2026-09-23T02:00:00Z',
            DikirimOleh: 'Sari',
            DikirimPada: '2026-09-23T03:00:00Z',
            DiterimaPada: null,
            DitutupOleh: null,
            DitutupPada: null,
            VersiDiubahPada: '2026-09-23T03:00:00Z',
        },
        Baris: [BarisTransfer()],
        Jurnal: [],
        Riwayat: [
            {
                StatusDari: null,
                StatusKe: 'Draf',
                LabelStatusKe: 'Draf',
                Oleh: 'Sari',
                Pada: '2026-09-23T02:00:00Z',
                Alasan: null,
            },
        ],
        Tindakan: { Ubah: false, Kirim: false, Batalkan: false, Terima: true, Tutup: true },
        Izin: IzinPenuh,
        HariIni: '2026-09-24',
        ...perubahan,
    };
}

function BarisOpname(perubahan: Partial<BarisStokOpname> = {}): BarisStokOpname {
    return {
        Urutan: 1,
        UuidProduk: '01J9PRD0000000000000000001',
        NamaProduk: 'Minyak Goreng Sawit 2 L',
        Sku: 'MYK-2L',
        SimbolSatuan: 'pcs',
        BolehDesimal: false,
        Pelacakan: 'Tidak',
        NomorBatch: null,
        TanggalKedaluwarsa: null,
        NomorSeri: null,
        DariSnapshot: true,
        JumlahSistem: null,
        JumlahFisik: null,
        DihitungOleh: null,
        DihitungPada: null,
        MutasiSelamaOpname: null,
        Selisih: null,
        NilaiSelisih: null,
        ...perubahan,
    };
}

function PropsOpname(perubahan: Partial<PropsDetailStokOpname> = {}): PropsDetailStokOpname {
    return {
        Opname: {
            Uuid,
            Nomor: 'SO/TOKO/2609/001',
            Status: 'Berlangsung',
            LabelStatus: 'Sedang dihitung',
            UuidGudang: GudangUtama.Uuid,
            NamaGudang: 'Toko Utama',
            NamaOutlet: 'Outlet Utama',
            NamaKategori: null,
            NamaRak: null,
            HitungButa: true,
            SistemTersembunyi: true,
            TanggalSnapshot: '2026-09-24',
            SnapshotPada: '2026-09-24T01:00:00Z',
            TanggalPosting: null,
            Catatan: null,
            JumlahBaris: 1,
            JumlahDihitung: 0,
            TotalNilaiLebih: '0.00',
            TotalNilaiKurang: '0.00',
            AlasanBatal: null,
            DibuatOleh: 'Sari',
            DiajukanOleh: null,
            DisetujuiOleh: null,
            DisetujuiPada: null,
        },
        Baris: [BarisOpname()],
        Penghitung: [],
        Jurnal: [],
        Riwayat: [],
        Tindakan: { Hitung: true, Ajukan: true, Kembalikan: false, Setujui: false, Batalkan: true },
        Izin: IzinPenuh,
        ...perubahan,
    };
}

function PropsPenyesuaian(perubahan: Partial<PropsDetailPenyesuaianStok> = {}): PropsDetailPenyesuaianStok {
    return {
        Penyesuaian: {
            Uuid,
            Nomor: null,
            Status: 'Draf',
            LabelStatus: 'Draf',
            NamaGudang: 'Toko Utama',
            NamaOutlet: 'Outlet Utama',
            Tanggal: '2026-09-23',
            KodeAlasan: 'Rusak',
            LabelAlasan: 'Rusak',
            Keterangan: null,
            JumlahBaris: 1,
            NilaiPerkiraan: '770000.00',
            TotalNilaiMasuk: '0.00',
            TotalNilaiKeluar: '0.00',
            PerluPersetujuan: false,
            AlasanTolak: null,
            DibuatOleh: 'Sari',
            DibuatPada: '2026-09-23T02:00:00Z',
            DiajukanOleh: null,
            DisetujuiOleh: null,
            DipostingOleh: null,
            DipostingPada: null,
            VersiDiubahPada: '2026-09-23T02:00:00Z',
        },
        Baris: [
            {
                Urutan: 1,
                UuidProduk: '01J9PRD0000000000000000001',
                NamaProduk: 'Minyak Goreng Sawit 2 L',
                Sku: 'MYK-2L',
                SimbolSatuan: 'pcs',
                BolehDesimal: false,
                Pelacakan: 'Tidak',
                Jumlah: '-20.0000',
                HppSatuan: null,
                Nilai: null,
                UuidBatchStok: null,
                NomorBatch: null,
                TanggalKedaluwarsa: null,
                UuidNomorSeri: null,
                NomorSeri: null,
            },
        ],
        Jurnal: [],
        Riwayat: [],
        Tindakan: { Ubah: true, Ajukan: true, Batalkan: true, Setujui: false, Tolak: false },
        Izin: IzinPenuh,
        BatasPersetujuan: '500000.00',
        ...perubahan,
    };
}

describe('F-05b aturan murni dokumen persediaan', () => {
    it('label status, alasan, jumlah terima/fisik, dan baris form', () => {
        expect(AmbilJenisLabelDokumen('Diterima')).toBe('sukses');
        expect(AmbilJenisLabelDokumen('MenungguPersetujuan')).toBe('peringatan');
        expect(AmbilJenisLabelDokumen('Dibatalkan')).toBe('bahaya');
        expect(PeriksaAlasan('ok')).toBe('Tulis alasan minimal 5 karakter.');
        expect(PeriksaAlasan('Barang pecah di jalan')).toBeNull();
        expect(PeriksaJumlahTerima('', '18.0000', false)).toBeNull();
        expect(PeriksaJumlahTerima('19', '18.0000', false)).toBe('Melebihi sisa dalam perjalanan.');
        expect(PeriksaJumlahTerima('1.5', '18.0000', false)).toBe('Jumlah tidak valid.');
        expect(PeriksaJumlahFisik('2', false, true)).toBe('Nomor seri dihitung 1 (ada) atau 0 (tidak ada).');
        expect(PeriksaJumlahFisik('-1', true, false)).toBe('Jumlah fisik tidak valid.');
        expect(TambahSatu('')).toBe('1');
        expect(TambahSatu('41')).toBe('42');
        const baris = {
            UuidProduk: 'P',
            NamaProduk: 'Susu',
            Sku: null,
            SimbolSatuan: 'pcs',
            BolehDesimal: false,
            UuidNomorSeri: null,
            NomorSeri: null,
            NomorBatch: null,
        };
        expect(PeriksaBarisTransfer({ ...baris, Pelacakan: 'Batch', Jumlah: '3', UuidBatchStok: null })).toBe(
            'Pilih batch.',
        );
        expect(PeriksaBarisTransfer({ ...baris, Pelacakan: 'Tidak', Jumlah: '0', UuidBatchStok: null })).toBe(
            'Isi jumlah lebih dari 0.',
        );
        expect(
            PeriksaBarisPenyesuaian(
                {
                    ...baris,
                    Pelacakan: 'Tidak',
                    Arah: 'Masuk',
                    Jumlah: '2',
                    HppSatuan: null,
                    UuidBatchStok: null,
                    TanggalKedaluwarsa: null,
                },
                true,
            ),
        ).toBe('Isi harga modal per satuan untuk stok masuk.');
    });
});

describe('Kelola/Persediaan/Transfer (F-05b)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/transfer');
        window.history.replaceState({}, '', '/kelola/persediaan/transfer');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('daftar kosong: ajakan buat untuk persediaan.kelola; hanya lihat tanpa tombol', () => {
        RenderUji(
            <HalamanDaftarTransferStok
                Transfer={BuatHasilTabel([])}
                OpsiGudang={[GudangUtama]}
                OpsiStatus={[{ Nilai: 'Draf', Label: 'Draf' }]}
                Izin={IzinPenuh}
            />,
        );
        expect(screen.getAllByRole('link', { name: 'Buat transfer' })[0]?.getAttribute('href')).toBe(
            '/kelola/persediaan/transfer/buat',
        );
        // D-96: pintasan retur stok dari area jual ke Gudang.
        expect(screen.getAllByRole('link', { name: 'Retur ke Gudang' })[0]?.getAttribute('href')).toBe(
            '/kelola/persediaan/transfer/buat?arah=retur-ke-gudang',
        );
        cleanup();

        RenderUji(
            <HalamanDaftarTransferStok
                Transfer={BuatHasilTabel([])}
                OpsiGudang={[GudangUtama]}
                OpsiStatus={[]}
                Izin={IzinLihat}
            />,
        );
        expect(screen.queryByRole('link', { name: 'Buat transfer' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Retur ke Gudang' })).toBeNull();
        expect(screen.getByText('Minta pengelola persediaan membuat transfer.')).toBeTruthy();
    });

    it('terima: jumlah bawaan = sisa, melebihi sisa ditolak lokal, kirim POST terima per baris', () => {
        RenderUji(<HalamanDetailTransferStok {...PropsTransfer()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Terima barang' }));
        const dialog = screen.getByRole('dialog');
        const isian = within(dialog).getByLabelText('Diterima Minyak Goreng Sawit 2 L');

        expect((isian as HTMLInputElement).value).toBe('18');
        UbahNilai(isian, '19');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan penerimaan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(
            within(dialog).getByText('Diterima + rusak + dikembalikan melebihi sisa dalam perjalanan.'),
        ).toBeTruthy();

        UbahNilai(isian, '10');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan penerimaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/transfer/${Uuid}/terima`,
            { Tanggal: '2026-09-24', Baris: [{ Urutan: 1, Jumlah: '10' }] },
            expect.anything(),
        );
    });

    it('D-105 terima per barang: bagi sisa jadi diterima, rusak, dan dikembalikan; total tidak boleh melebihi sisa', () => {
        RenderUji(<HalamanDetailTransferStok {...PropsTransfer()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Terima barang' }));
        const dialog = screen.getByRole('dialog');

        UbahNilai(within(dialog).getByLabelText('Diterima Minyak Goreng Sawit 2 L'), '10');
        UbahNilai(within(dialog).getByLabelText('Rusak Minyak Goreng Sawit 2 L'), '3');
        UbahNilai(within(dialog).getByLabelText('Dikembalikan Minyak Goreng Sawit 2 L'), '6');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan penerimaan' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(
            within(dialog).getByText('Diterima + rusak + dikembalikan melebihi sisa dalam perjalanan.'),
        ).toBeTruthy();

        UbahNilai(within(dialog).getByLabelText('Dikembalikan Minyak Goreng Sawit 2 L'), '5');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan penerimaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/transfer/${Uuid}/terima`,
            { Tanggal: '2026-09-24', Baris: [{ Urutan: 1, Jumlah: '10', Rusak: '3', Ditolak: '5' }] },
            expect.anything(),
        );
    });

    it('D-105 PeriksaBagianTerima: bagian tak valid dilaporkan per bagian; semua kosong dianggap tidak diproses', () => {
        expect(PeriksaBagianTerima({ Terima: '', Rusak: '', Ditolak: '' }, '18.0000', false)).toBeNull();
        expect(PeriksaBagianTerima({ Terima: '10', Rusak: '3', Ditolak: '5' }, '18.0000', false)).toBeNull();
        expect(PeriksaBagianTerima({ Terima: '1.5', Rusak: '', Ditolak: '' }, '18.0000', false)).toEqual({
            Terima: 'Jumlah tidak valid.',
        });
        expect(PeriksaBagianTerima({ Terima: '', Rusak: '-1', Ditolak: '' }, '18.0000', false)).toEqual({
            Rusak: 'Jumlah tidak valid.',
        });
        expect(PeriksaBagianTerima({ Terima: '', Rusak: '', Ditolak: '19' }, '18.0000', false)).toEqual({
            Total: 'Diterima + rusak + dikembalikan melebihi sisa dalam perjalanan.',
        });
    });

    it('tutup dengan selisih wajib alasan; draf menampilkan kirim dengan konfirmasi', () => {
        RenderUji(<HalamanDetailTransferStok {...PropsTransfer()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Tutup dengan selisih' }));
        const dialog = screen.getByRole('alertdialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tutup transfer' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        UbahNilai(within(dialog).getByLabelText(/Alasan selisih/), 'Tiga botol pecah');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Tutup transfer' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/transfer/${Uuid}/tutup`,
            { Alasan: 'Tiga botol pecah' },
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDetailTransferStok
                {...PropsTransfer({
                    Transfer: { ...PropsTransfer().Transfer, Status: 'Draf', LabelStatus: 'Draf', Nomor: null },
                    Tindakan: { Ubah: true, Kirim: true, Batalkan: true, Terima: false, Tutup: false },
                })}
            />,
        );
        expect(screen.getByText('Draf tanpa nomor')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Kirim transfer' }));
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Kirim transfer' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/transfer/${Uuid}/kirim`,
            {},
            expect.anything(),
        );
    });
});

describe('Kelola/Persediaan/Opname (F-05b, BR-05.3)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/opname');
        window.history.replaceState({}, '', '/kelola/persediaan/opname');
        vi.clearAllMocks();
    });
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('hitung buta: tidak ada jumlah sistem di layar; simpan hanya baris yang berubah', () => {
        RenderUji(<HalamanDetailStokOpname {...PropsOpname()} />);

        expect(screen.getByText('Hitung buta')).toBeTruthy();
        expect(document.body.textContent).not.toContain('sistem 1');
        UbahNilai(screen.getByLabelText('Fisik Minyak Goreng Sawit 2 L'), '98');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan hasil hitung' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            `/kelola/persediaan/opname/${Uuid}/hitung`,
            {
                Hitung: [
                    {
                        Urutan: 1,
                        UuidProduk: null,
                        JumlahFisik: '98',
                        NomorBatch: null,
                        TanggalKedaluwarsa: null,
                        NomorSeri: null,
                    },
                ],
            },
            expect.anything(),
        );
    });

    it('pindai barcode menambah 1 ke baris produk yang sama', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            Data: [
                                {
                                    Uuid: '01J9PRD0000000000000000001',
                                    Nama: 'Minyak Goreng Sawit 2 L',
                                    Sku: 'MYK-2L',
                                    Jenis: 'Stok',
                                    Pelacakan: 'Tidak',
                                    SimbolSatuan: 'pcs',
                                    BolehDesimal: false,
                                    SaldoDiGudang: null,
                                    HppRataRata: null,
                                    StokAwalSudahAda: false,
                                },
                            ],
                        }),
                }),
            ),
        );
        RenderUji(<HalamanDetailStokOpname {...PropsOpname({ Baris: [BarisOpname({ JumlahFisik: '4.0000' })] })} />);
        const pindai = screen.getByLabelText('Pindai barcode');
        fireEvent.change(pindai, { target: { value: '8991234567890' } });
        fireEvent.keyDown(pindai, { key: 'Enter' });

        await waitFor(() =>
            expect((screen.getByLabelText('Fisik Minyak Goreng Sawit 2 L') as HTMLInputElement).value).toBe('5'),
        );
        expect(screen.getByText('Minyak Goreng Sawit 2 L: +1')).toBeTruthy();
    });

    it('pindai kamera berkelanjutan: kamera tetap terbuka, tiap barcode menambah 1 ke baris produknya', async () => {
        const tiruan = SiapkanKameraTiruan(kamera);

        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            Data: [
                                {
                                    Uuid: '01J9PRD0000000000000000001',
                                    Nama: 'Minyak Goreng Sawit 2 L',
                                    Sku: 'MYK-2L',
                                    Jenis: 'Stok',
                                    Pelacakan: 'Tidak',
                                    SimbolSatuan: 'pcs',
                                    BolehDesimal: false,
                                    SaldoDiGudang: null,
                                    HppRataRata: null,
                                    StokAwalSudahAda: false,
                                },
                            ],
                        }),
                }),
            ),
        );
        RenderUji(<HalamanDetailStokOpname {...PropsOpname({ Baris: [BarisOpname({ JumlahFisik: '4.0000' })] })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pindai barcode dengan kamera' }));
        await waitFor(() => expect(screen.queryByText('Membuka kamera…')).toBeNull());
        tiruan.terbaca('8991234567890');

        await waitFor(() =>
            expect((screen.getByLabelText('Fisik Minyak Goreng Sawit 2 L') as HTMLInputElement).value).toBe('5'),
        );
        // Dialog kamera tetap terbuka untuk barang berikutnya dan menampilkan hasil proses terakhir.
        expect(screen.getByRole('dialog', { name: 'Pindai barcode dengan kamera' })).toBeTruthy();
        expect(await screen.findAllByText('Minyak Goreng Sawit 2 L: +1')).not.toHaveLength(0);
        expect(tiruan.Berhenti).not.toHaveBeenCalled();
    });

    it('ditinjau: selisih tampil, tombol setujui untuk pemegang izin', () => {
        RenderUji(
            <HalamanDetailStokOpname
                {...PropsOpname({
                    Opname: {
                        ...PropsOpname().Opname,
                        Status: 'Ditinjau',
                        LabelStatus: 'Menunggu tinjauan',
                        SistemTersembunyi: false,
                        JumlahDihitung: 1,
                    },
                    Baris: [
                        BarisOpname({
                            JumlahSistem: '100.0000',
                            JumlahFisik: '88.0000',
                            MutasiSelamaOpname: '-10.0000',
                            Selisih: '-2.0000',
                        }),
                    ],
                    Tindakan: { Hitung: false, Ajukan: false, Kembalikan: true, Setujui: true, Batalkan: true },
                })}
            />,
        );
        expect(screen.getByText('−2 pcs')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Setujui opname' }));
        fireEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Setujui opname' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/opname/${Uuid}/setujui`,
            {},
            expect.anything(),
        );
    });

    it('mulai opname: lokasi wajib, hitung buta ikut dikirim', () => {
        RenderUji(
            <HalamanDaftarStokOpname
                Opname={BuatHasilTabel([])}
                OpsiGudang={[GudangUtama, GudangKedua]}
                OpsiKategori={[]}
                OpsiRak={[]}
                OpsiStatus={[]}
                Izin={IzinPenuh}
            />,
        );
        fireEvent.click(screen.getAllByRole('button', { name: 'Mulai stok opname' })[0] as HTMLElement);
        const dialog = screen.getByRole('dialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mulai opname' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        PilihOpsi(within(dialog).getByRole('combobox', { name: 'Lokasi stok' }), GudangUtama.Uuid);
        fireEvent.click(within(dialog).getByRole('checkbox', { name: /Hitung buta/ }));
        fireEvent.click(within(dialog).getByRole('button', { name: 'Mulai opname' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/persediaan/opname',
            expect.objectContaining({ UuidGudang: GudangUtama.Uuid, HitungButa: true, UuidKategori: null }),
            expect.anything(),
        );
    });
});

describe('Kelola/Persediaan/Penyesuaian (F-05b, §19.2)', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/persediaan/penyesuaian');
        vi.clearAllMocks();
    });
    afterEach(() => cleanup());

    it('ajukan di atas batas menjelaskan butuh persetujuan; menunggu persetujuan tanpa tombol setujui untuk pembuat', () => {
        RenderUji(<HalamanDetailPenyesuaianStok {...PropsPenyesuaian()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Ajukan penyesuaian' }));
        const dialog = screen.getByRole('alertdialog');
        expect(dialog.textContent).toContain('menunggu persetujuan pengguna lain');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Ajukan penyesuaian' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/persediaan/penyesuaian/${Uuid}/ajukan`,
            {},
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDetailPenyesuaianStok
                {...PropsPenyesuaian({
                    Penyesuaian: {
                        ...PropsPenyesuaian().Penyesuaian,
                        Status: 'MenungguPersetujuan',
                        LabelStatus: 'Menunggu persetujuan',
                        PerluPersetujuan: true,
                    },
                    Tindakan: { Ubah: false, Ajukan: false, Batalkan: false, Setujui: false, Tolak: true },
                })}
            />,
        );
        expect(screen.getByText('Butuh persetujuan')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Setujui & posting' })).toBeNull();
    });

    it('form: alasan Rusak hanya boleh kurangi stok; payload bertanda arah', () => {
        const props: PropsFormPenyesuaianStok = {
            Mode: 'Ubah',
            Penyesuaian: {
                Uuid,
                UuidGudang: GudangUtama.Uuid,
                Tanggal: '2026-09-23',
                KodeAlasan: 'Rusak',
                Keterangan: null,
                VersiDiubahPada: '2026-09-23T02:00:00Z',
                Baris: [
                    {
                        UuidProduk: '01J9PRD0000000000000000001',
                        NamaProduk: 'Minyak Goreng Sawit 2 L',
                        Sku: 'MYK-2L',
                        SimbolSatuan: 'pcs',
                        BolehDesimal: false,
                        Pelacakan: 'Tidak',
                        Arah: 'Keluar',
                        Jumlah: '2',
                        HppSatuan: null,
                        UuidBatchStok: null,
                        NomorBatch: null,
                        TanggalKedaluwarsa: null,
                        UuidNomorSeri: null,
                        NomorSeri: null,
                    },
                ],
            },
            OpsiGudang: [GudangUtama],
            OpsiAlasan: [
                { Nilai: 'Rusak', Label: 'Rusak', BolehMasuk: false, WajibKeterangan: false },
                { Nilai: 'Lainnya', Label: 'Lainnya', BolehMasuk: true, WajibKeterangan: true },
            ],
            HariIni: '2026-09-24',
            BatasBaris: 500,
            WajibKedaluwarsaBatch: true,
        };
        RenderUji(<HalamanFormPenyesuaianStok {...props} />);

        fireEvent.click(screen.getByRole('combobox', { name: 'Arah Minyak Goreng Sawit 2 L' }));
        expect(screen.queryByText('Tambah stok')).toBeNull();
        fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' });

        fireEvent.click(screen.getByRole('button', { name: 'Simpan draf' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            `/kelola/persediaan/penyesuaian/${Uuid}`,
            expect.objectContaining({
                KodeAlasan: 'Rusak',
                Baris: [expect.objectContaining({ Arah: 'Keluar', Jumlah: '2', HppSatuan: null })],
                Lanjutkan: false,
            }),
            expect.anything(),
        );

        // Audit kemudahan pakai #13: tombol utama menyimpan lalu langsung mengajukan.
        fireEvent.click(screen.getByRole('button', { name: 'Simpan & ajukan' }));
        expect(tiruanRouter.put).toHaveBeenLastCalledWith(
            `/kelola/persediaan/penyesuaian/${Uuid}`,
            expect.objectContaining({ Lanjutkan: true }),
            expect.anything(),
        );
    });

    it('form: arah Tambah stok mengisi harga modal dari HPP rata-rata (audit kemudahan pakai #22)', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                status: 200,
                json: () =>
                    Promise.resolve({
                        Data: [
                            {
                                Uuid: '01J9PRD0000000000000000001',
                                Nama: 'Minyak Goreng Sawit 2 L',
                                Sku: 'MYK-2L',
                                Jenis: 'Stok',
                                Pelacakan: 'Tidak',
                                SimbolSatuan: 'pcs',
                                BolehDesimal: false,
                                SaldoDiGudang: '12.0000',
                                HppRataRata: '31250.000000',
                                StokAwalSudahAda: true,
                            },
                        ],
                    }),
            }),
        );
        RenderUji(
            <HalamanFormPenyesuaianStok
                Mode="Buat"
                Penyesuaian={null}
                OpsiGudang={[GudangUtama]}
                OpsiAlasan={[{ Nilai: 'Lainnya', Label: 'Lainnya', BolehMasuk: true, WajibKeterangan: true }]}
                HariIni="2026-09-24"
                BatasBaris={500}
                WajibKedaluwarsaBatch
            />,
        );
        PilihOpsi(screen.getByRole('combobox', { name: 'Alasan' }), 'Lainnya');
        await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
        fireEvent.click(screen.getByRole('combobox', { name: 'Tambah produk' }));
        const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
        fireEvent.change(cari, { target: { value: 'minyak' } });
        await waitFor(() => expect(screen.getByRole('option', { name: /Minyak Goreng/ })).toBeTruthy());
        fireEvent.keyDown(cari, { key: 'Enter' });

        PilihOpsi(screen.getByRole('combobox', { name: 'Arah Minyak Goreng Sawit 2 L' }), 'Masuk');
        const hpp = screen.getByRole<HTMLInputElement>('textbox', {
            name: 'Harga modal Minyak Goreng Sawit 2 L',
        });
        expect(hpp.value).toBe('31.250');
        expect(screen.getByText('Diisi dari HPP rata-rata saat ini Rp 31.250; boleh diubah.')).toBeTruthy();
    });

    it('pengaturan: ubah batas persetujuan dikirim bersama pengaturan lain', () => {
        AturHalamanUji({}, '/kelola/persediaan/pengaturan');
        RenderUji(
            <HalamanPengaturanPersediaan
                MetodeHpp="RataRata"
                StokBolehMinus={false}
                MetodeHppTerkunci
                AlasanTerkunci={null}
                OpsiMetodeHpp={[{ Nilai: 'RataRata', Label: 'Rata-rata bergerak', Keterangan: 'x' }]}
                BatasPersetujuanPenyesuaian="500000.00"
            />,
        );
        UbahNilai(screen.getByLabelText('Batas nilai tanpa persetujuan'), '1000000');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/persediaan/pengaturan',
            { MetodeHpp: 'RataRata', StokBolehMinus: false, BatasPersetujuanPenyesuaian: '1000000' },
            expect.anything(),
        );
    });
});
