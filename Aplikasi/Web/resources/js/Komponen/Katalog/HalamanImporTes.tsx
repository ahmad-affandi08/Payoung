import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarImpor, { PeriksaBerkasImpor } from '@/Halaman/Kelola/Produk/Impor/Daftar';
import HalamanDetailImpor, { BuatUrlLaporanImpor } from '@/Halaman/Kelola/Produk/Impor/Detail';
import type { PropsDaftarImpor, PropsDetailImpor, RingkasanImpor } from '@/Tipe/Katalog';

import { AturanJenis, BuatHasilTabel, IzinPenuh, OpsiKelompokPajakUji } from './DataUjiKatalog';
import { BatasiProgres } from './KemajuanImpor';
import { AmbilLangkahImpor } from './LangkahImpor';
import { PeriksaPemetaan } from './PemetaanImpor';
import { AturHalamanUji, RenderUji, tiruanRouter } from './TiruanInertia';
import { UbahNilai } from '@/Pengujian/InteraksiPilihan';

vi.mock('@inertiajs/react', async () => (await import('./TiruanInertia')).TiruanInertia);

const batasBerkas: PropsDaftarImpor['BatasBerkas'] = {
    UkuranMaksimalKb: 10240,
    MaksimalBaris: 20000,
    Ekstensi: ['xlsx', 'csv'],
};

function PropsDaftar(perubahan: Partial<PropsDaftarImpor> = {}): PropsDaftarImpor {
    return {
        Riwayat: BuatHasilTabel([]),
        OpsiStatus: [{ Nilai: 'Selesai', Label: 'Selesai' }],
        Preset: [
            { Kode: 'Umum', Nama: 'Templat sistem', Keterangan: 'Templat dari tombol Unduh templat.', Asumsi: false },
            { Kode: 'Majoo', Nama: 'majoo', Keterangan: 'Ekspor Daftar Produk majoo.', Asumsi: true },
        ],
        BatasBerkas: batasBerkas,
        BatasSku: { Batas: 500, Terpakai: 120 },
        Izin: IzinPenuh,
        ...perubahan,
    };
}

function BuatImpor(perubahan: Partial<RingkasanImpor> = {}): RingkasanImpor {
    return {
        Uuid: '01J9IMPOR0000000000000000001',
        NamaBerkas: 'produk-majoo.xlsx',
        Sumber: 'Majoo',
        LabelSumber: 'majoo',
        Status: 'MenungguPemetaan',
        LabelStatus: 'Menunggu pemetaan',
        JumlahBaris: 1200,
        JumlahValid: 0,
        JumlahGalat: 0,
        JumlahDiterapkan: 0,
        JumlahDibuat: 0,
        JumlahDiperbarui: 0,
        JumlahDilewati: 0,
        JumlahGagal: 0,
        Progres: 0,
        PesanGalat: null,
        BerkasPernahDiimpor: null,
        BolehLanjutkan: false,
        DibuatPada: '2026-09-22T03:00:00Z',
        SelesaiPada: null,
        NamaPengguna: 'Rina',
        ...perubahan,
    };
}

const pemetaan: NonNullable<PropsDetailImpor['Pemetaan']> = {
    KolomSumber: [
        { Indeks: 0, Judul: 'Nama Item', Contoh: ['Kopi Susu', 'Teh Manis', ''] },
        { Indeks: 1, Judul: 'Harga', Contoh: ['18000', '8000', ''] },
    ],
    Bidang: [
        { Kunci: 'Nama', Label: 'Nama produk', Wajib: true, Harga: false, Keterangan: 'Wajib diisi.' },
        { Kunci: 'HargaJual', Label: 'Harga jual', Wajib: false, Harga: true, Keterangan: 'Rupiah, misal 15.000.' },
        { Kunci: 'Sku', Label: 'SKU', Wajib: false, Harga: false, Keterangan: 'Kosong = dibuat otomatis.' },
    ],
    Pemetaan: { Nama: null, HargaJual: 1, Sku: null },
    Opsi: {
        Mode: 'TambahDanPerbarui',
        UuidKelompokPajakBawaan: null,
        JenisBawaan: 'Stok',
        BuatKategoriBaru: true,
        BuatSatuanBaru: false,
    },
};

function PropsDetail(perubahan: Partial<PropsDetailImpor> = {}): PropsDetailImpor {
    return {
        Impor: BuatImpor(),
        Pemetaan: pemetaan,
        Pratinjau: null,
        KelompokPajak: OpsiKelompokPajakUji,
        Jenis: AturanJenis,
        Izin: IzinPenuh,
        ...perubahan,
    };
}

describe('Impor produk langkah 1: unggah (E.10)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('preset asumsi menampilkan "Periksa pemetaan kolom sebelum mengimpor"', () => {
        RenderUji(<HalamanDaftarImpor {...PropsDaftar()} />);
        expect(screen.queryByText('Periksa pemetaan kolom sebelum mengimpor')).toBeNull();

        UbahNilai(screen.getByLabelText('Format berkas dari'), 'Majoo');
        expect(screen.getByText('Periksa pemetaan kolom sebelum mengimpor')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Unduh templat Excel' }).getAttribute('href')).toBe(
            '/kelola/produk/impor/templat?format=xlsx',
        );
        expect(screen.getByText('Belum pernah mengimpor produk. Riwayat disimpan 30 hari.')).toBeTruthy();
    });

    it('berkas diperiksa sebelum unggah lalu dikirim multipart { Berkas, Sumber }', () => {
        const besar = new File(['x'], 'produk.xlsx');
        Object.defineProperty(besar, 'size', { value: 11 * 1024 * 1024 });
        expect(PeriksaBerkasImpor(new File(['x'], 'produk.pdf'), batasBerkas)).toBe(
            'Format produk.pdf tidak didukung. Pilih berkas xlsx atau csv.',
        );
        expect(PeriksaBerkasImpor(besar, batasBerkas)).toMatch(/melebihi batas/);

        RenderUji(<HalamanDaftarImpor {...PropsDaftar()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Unggah & lanjut ke pemetaan' }));
        expect(screen.getByText('Pilih berkas Excel atau CSV lebih dulu.')).toBeTruthy();

        const berkas = new File(['Nama Produk\nKopi'], 'produk.csv', { type: 'text/csv' });
        fireEvent.change(screen.getByLabelText('Berkas Excel atau CSV'), { target: { files: [berkas] } });
        fireEvent.click(screen.getByRole('button', { name: 'Unggah & lanjut ke pemetaan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/impor',
            { Berkas: berkas, Sumber: 'Umum' },
            expect.objectContaining({ forceFormData: true }),
        );
    });
});

describe('Impor produk langkah 2–5 (E.10)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/produk/impor/01J9IMPOR0000000000000000001'));
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('langkah wizard mengikuti status', () => {
        expect(AmbilLangkahImpor(null)).toBe(0);
        expect(AmbilLangkahImpor('MenungguPemetaan')).toBe(1);
        expect(AmbilLangkahImpor('Pratinjau')).toBe(2);
        expect(AmbilLangkahImpor('Menerapkan')).toBe(3);
        expect(AmbilLangkahImpor('Selesai')).toBe(4);
        expect(BatasiProgres(140)).toBe(100);
        expect(BuatUrlLaporanImpor('U', 'galat')).toBe('/kelola/produk/impor/U/laporan?jenis=galat&format=xlsx');
    });

    it('pemetaan: Nama wajib, kolom harga butuh izin, satu kolom satu bidang', () => {
        expect(PeriksaPemetaan(pemetaan.Bidang, pemetaan.Pemetaan, true)).toEqual({
            Nama: 'Nama produk wajib dipetakan ke satu kolom.',
        });
        expect(PeriksaPemetaan(pemetaan.Bidang, { Nama: 0, HargaJual: 1, Sku: null }, false)).toEqual({
            HargaJual:
                'Anda tidak boleh mengubah harga produk, jadi kolom harga tidak bisa diimpor. Pilih "Tidak diimpor".',
        });
        expect(PeriksaPemetaan(pemetaan.Bidang, { Nama: 0, HargaJual: 1, Sku: 0 }, true)).toEqual({
            Sku: 'Kolom ini sudah dipakai untuk Nama produk.',
        });
    });

    it('pemetaan preset asumsi: peringatan, contoh isi, lalu PUT { Pemetaan, Opsi }', () => {
        RenderUji(<HalamanDetailImpor {...PropsDetail()} />);

        expect(screen.getByText('Periksa pemetaan kolom sebelum mengimpor')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Periksa data' }));
        expect(tiruanRouter.put).not.toHaveBeenCalled();
        expect(screen.getByText('Nama produk wajib dipetakan ke satu kolom.')).toBeTruthy();

        UbahNilai(screen.getByLabelText('Kolom untuk Nama produk'), '0');
        expect(screen.getByText('Kopi Susu | Teh Manis')).toBeTruthy();
        fireEvent.click(screen.getByLabelText(/Lewati produk yang sudah ada/));
        fireEvent.click(screen.getByRole('button', { name: 'Periksa data' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/impor/01J9IMPOR0000000000000000001/pemetaan',
            {
                Pemetaan: { Nama: 0, HargaJual: 1, Sku: null },
                Opsi: { ...pemetaan.Opsi, Mode: 'TambahSaja' },
            },
            expect.anything(),
        );
    });

    it('pratinjau: ringkasan, baris masalah, laporan masalah, dan blokir batas SKU', () => {
        const props = PropsDetail({
            Impor: BuatImpor({ Status: 'Pratinjau', LabelStatus: 'Siap diimpor', JumlahValid: 1180, JumlahGalat: 20 }),
            Pratinjau: {
                BarisGalat: [
                    {
                        NomorBaris: 7,
                        Galat: [{ Bidang: 'Harga jual', Pesan: 'Harga tidak boleh negatif.' }],
                        Data: { Nama: 'Kopi Aren' },
                    },
                ],
                RingkasanAksi: { Buat: 1000, Perbarui: 180, Lewati: 0 },
                Peringatan: ['Diabaikan: HPP & stok awal diisi di menu Stok awal'],
                DiblokirBatasSku: 'Produk melebihi batas paket (120 + 1.000 > 500).',
            },
        });
        RenderUji(<HalamanDetailImpor {...props} />);

        expect(screen.getByText('1.000')).toBeTruthy();
        expect(screen.getByText('Harga tidak boleh negatif.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Unduh laporan masalah (Excel)' }).getAttribute('href')).toBe(
            '/kelola/produk/impor/01J9IMPOR0000000000000000001/laporan?jenis=galat&format=xlsx',
        );
        expect(screen.getByText('Impor melebihi batas produk paket')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Impor 1.180 baris valid' }).disabled).toBe(true);
    });

    it('proses: progres dari polling status; status berubah → router.reload()', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        Status: 'Selesai',
                        LabelStatus: 'Selesai',
                        Progres: 100,
                        JumlahDiterapkan: 1180,
                        JumlahGagal: 0,
                        PesanGalat: null,
                    }),
            }),
        );
        RenderUji(
            <HalamanDetailImpor
                {...PropsDetail({
                    Impor: BuatImpor({
                        Status: 'Menerapkan',
                        LabelStatus: 'Sedang diimpor',
                        Progres: 40,
                        JumlahDiterapkan: 480,
                    }),
                    Pemetaan: null,
                })}
            />,
        );

        expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('40');
        await waitFor(() => expect(tiruanRouter.reload).toHaveBeenCalled());
    });

    it('gagal: pesan masalah dan tombol lanjutkan; selesai: ringkasan hasil', () => {
        render(
            <HalamanDetailImpor
                {...PropsDetail({
                    Impor: BuatImpor({
                        Status: 'Gagal',
                        LabelStatus: 'Gagal',
                        PesanGalat: 'Pekerja antrean berhenti.',
                        BolehLanjutkan: true,
                        JumlahDiterapkan: 600,
                    }),
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Lanjutkan impor' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/impor/01J9IMPOR0000000000000000001/lanjutkan',
            {},
            expect.anything(),
        );
        expect(screen.getByText('600 baris sudah diterapkan dan tidak akan diulang.')).toBeTruthy();
        cleanup();

        render(
            <HalamanDetailImpor
                {...PropsDetail({
                    Impor: BuatImpor({
                        Status: 'Selesai',
                        LabelStatus: 'Selesai',
                        JumlahDibuat: 1000,
                        JumlahDiperbarui: 180,
                        JumlahGagal: 2,
                        SelesaiPada: '2026-09-22T03:10:00Z',
                    }),
                })}
            />,
        );
        expect(screen.getByText('Impor selesai')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Unduh laporan masalah (Excel)' })).toBeTruthy();
    });
});
