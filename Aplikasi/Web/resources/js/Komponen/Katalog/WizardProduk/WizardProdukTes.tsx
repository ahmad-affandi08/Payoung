import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanFormProduk from '@/Halaman/Kelola/Produk/Form';
import {
    AturanJenis,
    IzinPenuh,
    OpsiKategoriUji,
    OpsiKelompokPajakUji,
    OpsiSatuanUji,
} from '@/Komponen/Katalog/DataUjiKatalog';
import { AturGalatBalasanUji, AturHalamanUji, kirimanForm } from '@/Komponen/Katalog/TiruanInertia';
import type { AturanJenisProduk, FormProduk, FormSatuanProduk, JenisProduk, PropsFormProduk } from '@/Tipe/Katalog';

import {
    AmbilGalatLangkah,
    CariLangkahTerdekat,
    HitungLangkahBergalat,
    PeriksaLangkahProduk,
    PetakanGalatKeLangkah,
    SiapkanIsianProdukBaru,
    SusunLangkahProduk,
    type KonteksPeriksaLangkah,
    type KunciLangkah,
} from './LangkahProduk';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function Aturan(jenis: JenisProduk): AturanJenisProduk {
    const aturan = AturanJenis.find((item) => item.Nilai === jenis);

    if (!aturan) {
        throw new Error(`Aturan jenis ${jenis} tidak ada di data uji`);
    }

    return aturan;
}

function Kunci(jenis: JenisProduk | undefined): KunciLangkah[] {
    return SusunLangkahProduk(jenis ? Aturan(jenis) : undefined).map((langkah) => langkah.Kunci);
}

describe('SusunLangkahProduk (daftar langkah wizard per jenis)', () => {
    it('barang stok: semua langkah kecuali Varian', () => {
        expect(Kunci('Stok')).toEqual(['JenisNama', 'Satuan', 'HargaStok', 'Pajak', 'Tinjau']);
        expect(SusunLangkahProduk(Aturan('Stok')).map((langkah) => langkah.Label)).toEqual([
            'Jenis & nama',
            'Satuan & barcode',
            'Harga & stok',
            'Pajak & tampilan',
            'Tinjau & simpan',
        ]);
    });

    it('resep (menu): tanpa langkah satuan & barcode, harga tetap ada', () => {
        expect(Kunci('Resep')).toEqual(['JenisNama', 'HargaStok', 'Pajak', 'Tinjau']);
    });

    it('jasa (termasuk paket sesi): tanpa langkah satuan & barcode, langkah bernama "Harga" karena tanpa stok', () => {
        expect(Kunci('Jasa')).toEqual(['JenisNama', 'HargaStok', 'Pajak', 'Tinjau']);
        expect(SusunLangkahProduk(Aturan('Jasa'))[1]?.Label).toBe('Harga');
    });

    it('induk varian: langkah Varian menggantikan satuan dan harga (harga per varian)', () => {
        expect(Kunci('IndukVarian')).toEqual(['JenisNama', 'Varian', 'Pajak', 'Tinjau']);
    });

    it('bahan baku: tidak dijual, jadi langkahnya "Stok" saja tanpa harga jual', () => {
        expect(Kunci('BahanBaku')).toEqual(['JenisNama', 'Satuan', 'HargaStok', 'Pajak', 'Tinjau']);
        expect(SusunLangkahProduk(Aturan('BahanBaku'))[2]?.Label).toBe('Stok');
    });

    it('non-stok, produksi, konsinyasi, paket: memakai satuan & barcode', () => {
        for (const jenis of ['NonStok', 'Produksi', 'Konsinyasi', 'Paket'] as JenisProduk[]) {
            expect(Kunci(jenis)).toContain('Satuan');
            expect(Kunci(jenis)).toContain('HargaStok');
            expect(Kunci(jenis)).not.toContain('Varian');
        }

        expect(SusunLangkahProduk(Aturan('Stok'))[2]?.Label).toBe('Harga & stok');
        expect(SusunLangkahProduk(Aturan('NonStok'))[2]?.Label).toBe('Harga');
    });

    it('jenis belum dikenal: hanya langkah yang tidak bergantung jenis', () => {
        expect(Kunci(undefined)).toEqual(['JenisNama', 'Pajak', 'Tinjau']);
    });

    it('langkah yang ditolak server dipaksa tampil walau jenisnya melewatkannya', () => {
        expect(SusunLangkahProduk(Aturan('Jasa'), { paksa: ['Satuan'] }).map((langkah) => langkah.Kunci)).toEqual([
            'JenisNama',
            'Satuan',
            'HargaStok',
            'Pajak',
            'Tinjau',
        ]);
    });
});

describe('CariLangkahTerdekat', () => {
    it('langkah yang masih ada tetap; yang hilang mundur ke langkah relevan terdekat sebelumnya', () => {
        const jasa = SusunLangkahProduk(Aturan('Jasa'));

        expect(CariLangkahTerdekat(jasa, 'HargaStok')).toBe('HargaStok');
        expect(CariLangkahTerdekat(jasa, 'Satuan')).toBe('JenisNama');
        expect(CariLangkahTerdekat(SusunLangkahProduk(Aturan('IndukVarian')), 'HargaStok')).toBe('JenisNama');
        expect(CariLangkahTerdekat(SusunLangkahProduk(Aturan('Stok')), 'Varian')).toBe('HargaStok');
    });
});

describe('PetakanGalatKeLangkah (kunci masalah server -> langkah)', () => {
    it.each([
        ['Nama', 'JenisNama'],
        ['NamaStruk', 'JenisNama'],
        ['Sku', 'JenisNama'],
        ['Jenis', 'JenisNama'],
        ['UuidKategori', 'JenisNama'],
        ['Merek', 'JenisNama'],
        ['UuidSatuanDasar', 'Satuan'],
        ['Satuan', 'Satuan'],
        ['Satuan.0.Barcode.1', 'Satuan'],
        ['Satuan.1.KonversiKeDasar', 'Satuan'],
        ['Satuan.0.HargaAwal', 'HargaStok'],
        ['Satuan.0.HargaAwal.1.Harga', 'HargaStok'],
        ['StokAwal.Jumlah', 'HargaStok'],
        ['StokAwal.UuidGudang', 'HargaStok'],
        ['PaketSesi.JumlahSesi', 'HargaStok'],
        ['Pelacakan', 'HargaStok'],
        ['MasaGaransiBulan', 'HargaStok'],
        ['GolonganObat', 'HargaStok'],
        ['HargaTerbuka', 'HargaStok'],
        ['AtributVarian', 'Varian'],
        ['AtributVarian.0.Nilai', 'Varian'],
        ['UuidKelompokPajak', 'Pajak'],
        ['HargaTermasukPajak', 'Pajak'],
        ['BolehMinus', 'Pajak'],
        ['TampilDiPos', 'Pajak'],
        ['Favorit', 'Pajak'],
        ['DurasiMenit', 'Pajak'],
        ['KodeUnitCoretax', 'Pajak'],
    ] as [string, KunciLangkah][])('%s -> %s', (kunci, langkah) => {
        expect(PetakanGalatKeLangkah(kunci)).toBe(langkah);
    });

    it('kunci tak terikat isian (Umum, Uuid) tidak dipetakan', () => {
        expect(PetakanGalatKeLangkah('Umum')).toBeNull();
        expect(PetakanGalatKeLangkah('Uuid')).toBeNull();
    });

    it('HitungLangkahBergalat berurutan baku, mengabaikan pesan kosong; AmbilGalatLangkah memfilter per langkah', () => {
        const galat = {
            KodeUnitCoretax: 'x',
            'Satuan.0.Barcode.0': 'y',
            Sku: 'z',
            Nama: undefined,
            Umum: 'abaikan',
        };

        expect(HitungLangkahBergalat(galat)).toEqual(['JenisNama', 'Satuan', 'Pajak']);
        expect(AmbilGalatLangkah(galat, 'Satuan')).toEqual({ 'Satuan.0.Barcode.0': 'y' });
        expect(AmbilGalatLangkah(galat, 'Tinjau')).toEqual({});
    });
});

const BarisDasar: FormSatuanProduk = {
    Uuid: null,
    UuidSatuan: 'SAT-PCS',
    KonversiKeDasar: '1',
    DefaultJual: true,
    DefaultBeli: true,
    Barcode: [],
    HargaAwal: [],
};

const produkKosong: FormProduk = {
    Uuid: '01J9PRODUKBARU000000000000A',
    Nama: '',
    NamaStruk: '',
    Sku: '',
    Jenis: 'Stok',
    UuidKategori: null,
    Merek: '',
    UuidSatuanDasar: 'SAT-PCS',
    Pelacakan: 'Tidak',
    UuidKelompokPajak: 'KP-PBJT',
    HargaTermasukPajak: 'Ikut',
    BolehMinus: 'Ikut',
    TampilDiPos: true,
    TampilOnline: false,
    Favorit: false,
    Satuan: [BarisDasar],
    AtributVarian: [],
};

function Konteks(
    perubahan: Partial<FormProduk> = {},
    tambahan: Partial<KonteksPeriksaLangkah> = {},
): KonteksPeriksaLangkah {
    const data = { ...produkKosong, ...perubahan };

    return {
        data,
        aturan: AturanJenis.find((item) => item.Nilai === data.Jenis),
        opsiSatuan: OpsiSatuanUji,
        izinUbahHarga: true,
        bolehPaketSesi: false,
        bolehStokAwal: false,
        ...tambahan,
    };
}

describe('PeriksaLangkahProduk (validasi klien per langkah, cermin aturan server)', () => {
    it('Jenis & nama: nama wajib, SKU berpola, panjang bidang dibatasi', () => {
        expect(PeriksaLangkahProduk('JenisNama', Konteks({ Nama: '   ' }))).toEqual({ Nama: 'Isi nama produk dulu.' });
        expect(PeriksaLangkahProduk('JenisNama', Konteks({ Nama: 'Sabun' }))).toEqual({});
        expect(Object.keys(PeriksaLangkahProduk('JenisNama', Konteks({ Nama: 'Sabun', Sku: '-salah' })))).toEqual([
            'Sku',
        ]);
        expect(PeriksaLangkahProduk('JenisNama', Konteks({ Nama: 'Sabun', Sku: 'SBN-001' }))).toEqual({});
        expect(
            Object.keys(PeriksaLangkahProduk('JenisNama', Konteks({ Nama: 'x'.repeat(151), Merek: 'm'.repeat(61) }))),
        ).toEqual(['Nama', 'Merek']);
    });

    it('Satuan & barcode: barcode duplikat antar-satuan, format barcode, satuan kembar, konversi', () => {
        const dua = Konteks({
            Satuan: [
                { ...BarisDasar, Barcode: ['111222', '899'] },
                {
                    Uuid: null,
                    UuidSatuan: 'SAT-PAK',
                    KonversiKeDasar: '10',
                    DefaultJual: false,
                    DefaultBeli: false,
                    Barcode: ['899', 'ab'],
                    HargaAwal: [],
                },
            ],
        });
        const galat = PeriksaLangkahProduk('Satuan', dua);

        expect(galat['Satuan.0.Barcode.1']).toContain('sudah dipakai');
        expect(galat['Satuan.1.Barcode.0']).toContain('sudah dipakai');
        expect(galat['Satuan.1.Barcode.1']).toContain('3–64 karakter');
        expect(galat['Satuan.0.Barcode.0']).toBeUndefined();

        const kembar = Konteks({
            Satuan: [BarisDasar, { ...BarisDasar, KonversiKeDasar: '0', DefaultJual: false, DefaultBeli: false }],
        });
        expect(PeriksaLangkahProduk('Satuan', kembar)).toEqual({
            'Satuan.1.UuidSatuan': 'Satuan ini sudah dipakai di baris lain.',
            'Satuan.1.KonversiKeDasar': 'Isi jumlah lebih dari 0, maksimal 4 angka desimal.',
        });
        expect(PeriksaLangkahProduk('Satuan', Konteks({ UuidSatuanDasar: '' }))).toHaveProperty('UuidSatuanDasar');
        expect(PeriksaLangkahProduk('Satuan', Konteks())).toEqual({});
    });

    it('Harga & stok: harga jual wajib (0 boleh) kecuali tanpa izin harga atau harga diketik kasir', () => {
        const KonteksDenganHarga = (harga: { JumlahMinimum: string; Harga: string }[]) =>
            Konteks({ Satuan: [{ ...BarisDasar, HargaAwal: harga }] });

        expect(PeriksaLangkahProduk('HargaStok', Konteks())).toEqual({
            'Satuan.0.HargaAwal': 'Isi harga jual. Tulis 0 bila gratis.',
        });
        expect(PeriksaLangkahProduk('HargaStok', KonteksDenganHarga([{ JumlahMinimum: '1', Harga: '0' }]))).toEqual({});
        expect(PeriksaLangkahProduk('HargaStok', Konteks({}, { izinUbahHarga: false }))).toEqual({});
        expect(PeriksaLangkahProduk('HargaStok', Konteks({ HargaTerbuka: true }))).toEqual({});
        expect(PeriksaLangkahProduk('HargaStok', KonteksDenganHarga([{ JumlahMinimum: '1', Harga: '' }]))).toEqual({
            'Satuan.0.HargaAwal.0.Harga': 'Isi harga. Tulis 0 bila gratis.',
        });
        expect(PeriksaLangkahProduk('HargaStok', KonteksDenganHarga([{ JumlahMinimum: '12', Harga: '4500' }]))).toEqual(
            {
                'Satuan.0.HargaAwal': 'Harga jual untuk jumlah minimum 1 wajib diisi.',
            },
        );
        expect(
            PeriksaLangkahProduk('HargaStok', KonteksDenganHarga([{ JumlahMinimum: '1,5', Harga: '4500' }])),
        ).toHaveProperty(['Satuan.0.HargaAwal.0.JumlahMinimum']);
        // Bahan baku tidak dijual: tanpa harga.
        expect(PeriksaLangkahProduk('HargaStok', Konteks({ Jenis: 'BahanBaku' }))).toEqual({});
    });

    it('Harga & stok: paket sesi, nomor seri, dan stok sekarang', () => {
        const KonteksSesi = (JumlahSesi: string, MasaBerlakuHari = '') =>
            Konteks(
                {
                    Jenis: 'Jasa',
                    HargaTerbuka: true,
                    PaketSesi: { JumlahSesi, MasaBerlakuHari },
                },
                { bolehPaketSesi: true },
            );

        expect(PeriksaLangkahProduk('HargaStok', KonteksSesi(''))).toEqual({
            'PaketSesi.JumlahSesi': 'Isi jumlah sesi, misal 10.',
        });
        expect(PeriksaLangkahProduk('HargaStok', KonteksSesi('10', '180'))).toEqual({});
        expect(PeriksaLangkahProduk('HargaStok', KonteksSesi('10', '9999'))).toHaveProperty([
            'PaketSesi.MasaBerlakuHari',
        ]);

        const KonteksSeri = (opsi: Partial<FormProduk>) => Konteks({ HargaTerbuka: true, Pelacakan: 'Seri', ...opsi });

        expect(PeriksaLangkahProduk('HargaStok', KonteksSeri({ UuidSatuanDasar: 'SAT-KG' }))).toHaveProperty(
            'Pelacakan',
        );
        expect(PeriksaLangkahProduk('HargaStok', KonteksSeri({ MasaGaransiBulan: 300 }))).toHaveProperty(
            'MasaGaransiBulan',
        );
        expect(PeriksaLangkahProduk('HargaStok', KonteksSeri({ MasaGaransiBulan: 12 }))).toEqual({});

        const KonteksStok = (Jumlah: string, UuidGudang: string) =>
            Konteks({ HargaTerbuka: true, StokAwal: { Jumlah, HargaBeli: '', UuidGudang } }, { bolehStokAwal: true });

        expect(PeriksaLangkahProduk('HargaStok', KonteksStok('', ''))).toEqual({});
        expect(PeriksaLangkahProduk('HargaStok', KonteksStok('24', 'G-1'))).toEqual({});
        expect(PeriksaLangkahProduk('HargaStok', KonteksStok('24', ''))).toEqual({
            'StokAwal.UuidGudang': 'Pilih lokasi stok.',
        });
        expect(PeriksaLangkahProduk('HargaStok', KonteksStok('1.23456', 'G-1'))).toHaveProperty(['StokAwal.Jumlah']);
    });

    it('Varian: nama atribut wajib dan unik, nilai minimal satu', () => {
        const KonteksVarian = (AtributVarian: FormProduk['AtributVarian']) =>
            Konteks({ Jenis: 'IndukVarian', AtributVarian });

        expect(PeriksaLangkahProduk('Varian', KonteksVarian([]))).toEqual({});
        expect(PeriksaLangkahProduk('Varian', KonteksVarian([{ Nama: 'Ukuran', Nilai: ['M', 'L'] }]))).toEqual({});
        expect(PeriksaLangkahProduk('Varian', KonteksVarian([{ Nama: '', Nilai: [] }]))).toEqual({
            'AtributVarian.0.Nama': 'Isi nama atribut, misal Ukuran.',
            'AtributVarian.0.Nilai': 'Isi minimal 1 nilai, misal M atau L.',
        });
        expect(
            PeriksaLangkahProduk(
                'Varian',
                KonteksVarian([
                    { Nama: 'Ukuran', Nilai: ['M'] },
                    { Nama: 'ukuran', Nilai: [' '] },
                ]),
            ),
        ).toEqual({
            'AtributVarian.1.Nama': 'Nama atribut tidak boleh sama.',
            'AtributVarian.1.Nilai': 'Isi minimal 1 nilai, misal M atau L.',
        });
    });

    it('Pajak & tampilan: kelompok pajak wajib untuk produk yang dijual, kode Coretax berpola, durasi 5–720 menit', () => {
        expect(PeriksaLangkahProduk('Pajak', Konteks({ UuidKelompokPajak: null }))).toHaveProperty('UuidKelompokPajak');
        expect(PeriksaLangkahProduk('Pajak', Konteks({ Jenis: 'BahanBaku', UuidKelompokPajak: null }))).toEqual({});
        expect(
            PeriksaLangkahProduk('Pajak', Konteks({ Jenis: 'IndukVarian', UuidKelompokPajak: null })),
        ).toHaveProperty('UuidKelompokPajak');
        expect(
            Object.keys(
                PeriksaLangkahProduk('Pajak', Konteks({ KodeBarangJasaCoretax: '12', KodeUnitCoretax: 'UM21' })),
            ),
        ).toEqual(['KodeBarangJasaCoretax', 'KodeUnitCoretax']);
        expect(
            PeriksaLangkahProduk('Pajak', Konteks({ KodeBarangJasaCoretax: '123456', KodeUnitCoretax: 'UM.0021' })),
        ).toEqual({});
        expect(PeriksaLangkahProduk('Pajak', Konteks({ Jenis: 'Jasa', DurasiMenit: 3 }))).toHaveProperty('DurasiMenit');
        expect(PeriksaLangkahProduk('Pajak', Konteks({ Jenis: 'Jasa', DurasiMenit: 45 }))).toEqual({});
    });

    it('Tinjau tidak punya isian sendiri', () => {
        expect(PeriksaLangkahProduk('Tinjau', Konteks())).toEqual({});
    });
});

describe('SiapkanIsianProdukBaru', () => {
    it('membuang harga sisa untuk produk yang tidak dijual dan stok sekarang yang tidak berlaku', () => {
        const isi: FormProduk = {
            ...produkKosong,
            Satuan: [{ ...BarisDasar, HargaAwal: [{ JumlahMinimum: '1', Harga: '5000' }] }],
            StokAwal: { Jumlah: '24', HargaBeli: '100', UuidGudang: 'G-1' },
        };

        expect(SiapkanIsianProdukBaru(isi, { dijual: false, bolehStokAwal: false })).toMatchObject({
            Satuan: [{ HargaAwal: [] }],
            StokAwal: null,
        });
        expect(SiapkanIsianProdukBaru(isi, { dijual: true, bolehStokAwal: true })).toMatchObject({
            Satuan: [{ HargaAwal: [{ JumlahMinimum: '1', Harga: '5000' }] }],
            StokAwal: { Jumlah: '24' },
        });
        expect(
            SiapkanIsianProdukBaru(
                { ...isi, StokAwal: { Jumlah: ' ', HargaBeli: '', UuidGudang: 'G-1' } },
                { dijual: true, bolehStokAwal: true },
            ).StokAwal,
        ).toBeNull();
    });
});

function PropsForm(perubahan: Partial<PropsFormProduk> = {}): PropsFormProduk {
    return {
        Mode: 'Buat',
        Produk: { ...produkKosong, Satuan: [] },
        Kepala: null,
        Kategori: OpsiKategoriUji,
        Satuan: OpsiSatuanUji,
        KelompokPajak: OpsiKelompokPajakUji,
        Jenis: AturanJenis,
        JenisTerkunci: false,
        BatasSku: { Batas: null, Terpakai: 5 },
        Pengaturan: { HargaTermasukPajakOutlet: 'harga sudah termasuk pajak', StokBolehMinus: false },
        Izin: IzinPenuh,
        ...perubahan,
    };
}

function Lanjut() {
    fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
}

function IsiNama(nama: string) {
    fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: nama } });
}

function IsiHargaDasar(harga: string) {
    fireEvent.click(screen.getByRole('button', { name: 'Isi harga jual' }));
    fireEvent.change(screen.getByLabelText('Harga baris 1'), { target: { value: harga } });
}

function LabelLangkahAktif(): string {
    return screen.getByRole('heading', { level: 2 }).textContent;
}

/** Tombol di penunjuk langkah (bukan tombol "Ubah ..." di ringkasan). */
function TombolLangkah(nama: RegExp | string): HTMLButtonElement {
    return within(screen.getByRole('navigation', { name: 'Langkah tambah produk' })).getByRole<HTMLButtonElement>(
        'button',
        { name: nama },
    );
}

describe('Wizard tambah produk (D-128)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('langkah 1 tidak bisa dilanjutkan tanpa nama: masalah tampil, tetap di langkah 1, isian bermasalah difokuskan', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        Lanjut();

        expect(LabelLangkahAktif()).toBe('Jenis & nama');
        expect(screen.getByText('Isi nama produk dulu.')).toBeTruthy();
        expect(screen.getByLabelText('Nama produk')).toBe(document.activeElement);
        expect(
            screen.getByText('Ada 1 isian yang perlu diperbaiki. Lihat pesan di bawah isian tersebut.'),
        ).toBeTruthy();

        IsiNama('Sabun');
        expect(screen.queryByText('Isi nama produk dulu.')).toBeNull();
        Lanjut();
        expect(LabelLangkahAktif()).toBe('Satuan & barcode');
    });

    it('barcode yang diketik lalu langsung Lanjut ikut tersimpan; yang tidak valid menahan Lanjut', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Air Mineral 600 ml');
        Lanjut();
        expect(LabelLangkahAktif()).toBe('Satuan & barcode');

        const isian = screen.getByPlaceholderText<HTMLInputElement>('Pindai atau ketik barcode');

        // Ketik lalu klik Lanjut tanpa Enter/Tambah: peramban memicu blur lebih dulu, barcode ikut ditambahkan.
        fireEvent.change(isian, { target: { value: '8993175530021' } });
        fireEvent.blur(isian);
        Lanjut();
        expect(LabelLangkahAktif()).not.toBe('Satuan & barcode');
        fireEvent.click(TombolLangkah(/Satuan & barcode/));
        expect(screen.getByText('8993175530021')).toBeTruthy();

        // Ketikan tidak valid: tetap di langkah ini, isian difokuskan, pesannya terlihat.
        const lagi = screen.getByPlaceholderText<HTMLInputElement>('Pindai atau ketik barcode');

        fireEvent.change(lagi, { target: { value: 'ab' } });
        fireEvent.blur(lagi);
        Lanjut();
        expect(LabelLangkahAktif()).toBe('Satuan & barcode');
        expect(document.activeElement).toBe(lagi);
        expect(screen.getByText('Barcode 3–64 karakter: huruf, angka, titik, atau strip.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        expect(LabelLangkahAktif()).toBe('Satuan & barcode');

        fireEvent.change(lagi, { target: { value: '' } });
        Lanjut();
        expect(LabelLangkahAktif()).not.toBe('Satuan & barcode');
    });

    it('penunjuk langkah: langkah aktif aria-current="step", langkah di depan terkunci dan klik tidak berpindah', () => {
        render(<HalamanFormProduk {...PropsForm()} />);

        expect(screen.getByText('Langkah 1 dari 5')).toBeTruthy();
        expect(TombolLangkah(/Jenis & nama/).getAttribute('aria-current')).toBe('step');
        for (const nama of [/Satuan & barcode/, /Harga & stok/, /Pajak & tampilan/, /Tinjau & simpan/]) {
            const tombol = TombolLangkah(nama);

            expect(tombol.disabled).toBe(true);
            expect(tombol.getAttribute('aria-disabled')).toBe('true');
            expect(tombol.textContent).toContain('terkunci');
        }

        fireEvent.click(TombolLangkah(/Tinjau & simpan/));
        expect(LabelLangkahAktif()).toBe('Jenis & nama');
        expect(kirimanForm).toHaveLength(0);
    });

    it('Lanjut dan Kembali berpindah langkah, data langkah sebelumnya tetap, fokus pindah ke judul langkah', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        expect(screen.queryByRole('button', { name: 'Kembali' })).toBeNull();

        IsiNama('Teh Botol Sosro 450 ml');
        fireEvent.change(screen.getByLabelText('Merek (opsional)'), { target: { value: 'Sosro' } });
        Lanjut();
        expect(LabelLangkahAktif()).toBe('Satuan & barcode');
        expect(screen.getByRole('heading', { level: 2 })).toBe(document.activeElement);

        Lanjut();
        expect(LabelLangkahAktif()).toBe('Harga & stok');
        IsiHargaDasar('6.500');

        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        fireEvent.click(screen.getByRole('button', { name: 'Kembali' }));
        expect(LabelLangkahAktif()).toBe('Jenis & nama');
        expect(screen.getByLabelText<HTMLInputElement>('Nama produk').value).toBe('Teh Botol Sosro 450 ml');
        expect(screen.getByLabelText<HTMLInputElement>('Merek (opsional)').value).toBe('Sosro');

        // Langkah yang sudah dicapai boleh dibuka lagi lewat penunjuk; harga yang diisi masih ada.
        fireEvent.click(TombolLangkah(/Harga & stok/));
        expect(LabelLangkahAktif()).toBe('Harga & stok');
        expect(screen.getByLabelText<HTMLInputElement>('Harga baris 1').value).toContain('6.500');
        expect(TombolLangkah(/Pajak & tampilan/).disabled).toBe(true);
    });

    it('membuka lagi langkah yang sudah dicapai memeriksa langkah di antaranya: berhenti di yang tidak valid', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        IsiHargaDasar('5.000');
        Lanjut();
        Lanjut();
        expect(LabelLangkahAktif()).toBe('Tinjau & simpan');

        fireEvent.click(TombolLangkah(/Jenis & nama/));
        IsiNama('');
        fireEvent.click(TombolLangkah(/Tinjau & simpan/));

        expect(LabelLangkahAktif()).toBe('Jenis & nama');
        expect(screen.getByText('Isi nama produk dulu.')).toBeTruthy();
        expect(kirimanForm).toHaveLength(0);

        IsiNama('Sabun Cair');
        // Dari mana pun, "Kembali ke tinjauan" melompat langsung ke Tinjau bila langkah di antaranya valid.
        fireEvent.click(screen.getByRole('button', { name: 'Kembali ke tinjauan' }));
        expect(LabelLangkahAktif()).toBe('Tinjau & simpan');
        expect(screen.getByText('Sabun Cair')).toBeTruthy();
    });

    it('Enter di isian = Lanjut dan tidak menyimpan; hanya langkah Tinjau yang menyimpan', () => {
        const { container } = render(<HalamanFormProduk {...PropsForm()} />);
        const form = container.querySelector('form');

        if (!form) {
            throw new Error('form tidak ditemukan');
        }

        IsiNama('Sabun');
        fireEvent.submit(form);
        expect(LabelLangkahAktif()).toBe('Satuan & barcode');
        fireEvent.submit(form);
        expect(LabelLangkahAktif()).toBe('Harga & stok');
        IsiHargaDasar('5.000');
        fireEvent.submit(form);
        fireEvent.submit(form);
        expect(LabelLangkahAktif()).toBe('Tinjau & simpan');
        expect(kirimanForm).toHaveLength(0);

        fireEvent.submit(form);
        expect(kirimanForm).toHaveLength(1);
        expect(kirimanForm[0]?.url).toBe('/kelola/produk');
    });

    it('ganti jenis di langkah 1 menghitung ulang langkah: Jasa melewatkan satuan & barcode, induk varian menambah Varian', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Potong rambut');
        expect(screen.getByText('Langkah 1 dari 5')).toBeTruthy();

        fireEvent.click(screen.getByRole('radio', { name: /Jasa/ }));
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Satuan & barcode/ })).toBeNull();
        expect(TombolLangkah(/Jenis & nama/).getAttribute('aria-current')).toBe('step');

        fireEvent.click(
            screen.getByRole('radio', { name: /Produk bervarian|IndukVarian|Satu produk dengan banyak pilihan/ }),
        );
        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(TombolLangkah(/Varian/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Harga/ })).toBeNull();

        fireEvent.click(screen.getByRole('radio', { name: /Barang yang dihitung stoknya/ }));
        expect(screen.getByText('Langkah 1 dari 5')).toBeTruthy();
    });

    it('ganti jenis menyesuaikan isian yang tidak berlaku (favorit & harga terbuka), tetapi mempertahankan yang lain', () => {
        render(
            <HalamanFormProduk
                {...PropsForm({ Produk: { ...produkKosong, Satuan: [], Favorit: true, HargaTerbuka: true } })}
            />,
        );
        IsiNama('Bahan kopi');
        fireEvent.click(screen.getByRole('radio', { name: /Bahan untuk resep atau produksi/ }));
        Lanjut();
        Lanjut();
        // Bahan baku tidak dijual: langkah "Stok" tanpa harga jual.
        expect(LabelLangkahAktif()).toBe('Stok');
        Lanjut();
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.Jenis).toBe('BahanBaku');
        expect(body.Nama).toBe('Bahan kopi');
        expect(body.Favorit).toBe(false);
        expect(body.TampilDiPos).toBe(false);
        expect(body.HargaTerbuka).toBe(false);
    });

    it('Tinjau menampilkan ringkasan isian, tombol Ubah kembali ke langkahnya, dan Simpan mengirim StokAwal dan Favorit', async () => {
        const opsiStok = {
            Lokasi: [{ Uuid: '01K5GDG0000000000000000001', Nama: 'Gudang Toko', NamaOutlet: 'Toko Pusat' }],
        };
        render(<HalamanFormProduk {...PropsForm({ StokAwal: opsiStok })} />);
        IsiNama('Kopi Arabika 250 g');
        fireEvent.click(screen.getByRole('combobox', { name: 'Kategori' }));
        fireEvent.click(await screen.findByRole('option', { name: /Minuman › Kopi/ }));
        Lanjut();
        Lanjut();
        IsiHargaDasar('45.000');
        fireEvent.change(screen.getByLabelText(/Jumlah stok/), { target: { value: '12' } });
        fireEvent.change(screen.getByLabelText('Harga beli per satuan'), { target: { value: '30.000' } });
        Lanjut();
        fireEvent.click(screen.getByRole('switch', { name: 'Favorit di kasir' }));
        Lanjut();

        expect(LabelLangkahAktif()).toBe('Tinjau & simpan');
        expect(screen.getByText('Kopi Arabika 250 g')).toBeTruthy();
        expect(screen.getByText('Barang stok')).toBeTruthy();
        expect(screen.getByText('Dibuat otomatis')).toBeTruthy();
        expect(screen.getByText('Rp 45.000')).toBeTruthy();
        expect(screen.getByText('12 pcs')).toBeTruthy();
        expect(screen.getByText(/Harga beli Rp 30\.000 per satuan/)).toBeTruthy();
        expect(screen.getByText('Pieces (pcs)')).toBeTruthy();
        expect(screen.getByText('Makan & minum | Kena PBJT')).toBeTruthy();
        // Satu bagian per langkah isian, masing-masing dengan tombol Ubah.
        for (const nama of ['Jenis & nama', 'Satuan & barcode', 'Harga & stok', 'Pajak & tampilan']) {
            expect(screen.getByRole('button', { name: `Ubah ${nama}` })).toBeTruthy();
        }

        fireEvent.click(screen.getByRole('button', { name: 'Ubah Harga & stok' }));
        expect(LabelLangkahAktif()).toBe('Harga & stok');
        expect(screen.getByRole('button', { name: 'Kembali ke tinjauan' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Kembali ke tinjauan' }));
        expect(LabelLangkahAktif()).toBe('Tinjau & simpan');

        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));
        expect(kirimanForm).toHaveLength(1);
        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.Nama).toBe('Kopi Arabika 250 g');
        expect(body.UuidKategori).toBe('KAT-KOPI');
        expect(body.Favorit).toBe(true);
        expect(body.StokAwal).toEqual({ Jumlah: '12', HargaBeli: '30000', UuidGudang: '01K5GDG0000000000000000001' });
        expect(body.Satuan[0]?.HargaAwal).toEqual([{ JumlahMinimum: '1', Harga: '45000' }]);
    });

    it('masalah server di langkah yang biasanya dilewati membuka langkah itu; masalah harga membuka langkah harga', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkKosong, Satuan: [], Jenis: 'Jasa' } })} />);
        IsiNama('Potong rambut');
        Lanjut();
        IsiHargaDasar('50.000');
        Lanjut();
        Lanjut();
        expect(screen.getByText('Langkah 4 dari 4')).toBeTruthy();

        AturGalatBalasanUji({ UuidSatuanDasar: 'Satuan tidak ditemukan. Muat ulang halaman lalu pilih lagi.' });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        expect(LabelLangkahAktif()).toBe('Satuan & barcode');
        expect(screen.getByText('Langkah 2 dari 5')).toBeTruthy();
        expect(screen.getByText('Satuan tidak ditemukan. Muat ulang halaman lalu pilih lagi.')).toBeTruthy();
        expect(screen.getAllByText('Perlu diperbaiki')).toHaveLength(1);
        cleanup();

        AturHalamanUji();
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        IsiHargaDasar('5.000');
        Lanjut();
        Lanjut();
        AturGalatBalasanUji({
            'Satuan.0.HargaAwal.0.Harga': 'Harga berupa angka tanpa titik ribuan.',
            Nama: 'Nama sudah dipakai.',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        // Langkah pertama (urutan baku) yang memuat galat: Jenis & nama; harga ikut ditandai.
        expect(LabelLangkahAktif()).toBe('Jenis & nama');
        expect(screen.getByText('Nama sudah dipakai.')).toBeTruthy();
        expect(screen.getAllByText('Perlu diperbaiki')).toHaveLength(2);
    });

    it('mengosongkan nama sesudah melewati langkah 1 menahan Lanjut lagi dan tidak ada yang terkirim', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        IsiHargaDasar('5.000');
        Lanjut();
        Lanjut();
        fireEvent.click(TombolLangkah(/Jenis & nama/));
        IsiNama('');
        fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
        expect(kirimanForm).toHaveLength(0);
        expect(LabelLangkahAktif()).toBe('Jenis & nama');
    });

    it('tanpa izin kelola produk: wizard tetap terbaca tetapi tombol Simpan tidak ada di langkah terakhir', () => {
        render(<HalamanFormProduk {...PropsForm({ Izin: { ...IzinPenuh, Kelola: false, UbahHarga: false } })} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        Lanjut();
        Lanjut();

        expect(LabelLangkahAktif()).toBe('Tinjau & simpan');
        expect(screen.queryByRole('button', { name: 'Simpan produk' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Kembali' })).toBeTruthy();
    });

    it('batas produk paket penuh: Simpan produk nonaktif di langkah Tinjau', () => {
        render(<HalamanFormProduk {...PropsForm({ BatasSku: { Batas: 100, Terpakai: 100 } })} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        IsiHargaDasar('5.000');
        Lanjut();
        Lanjut();

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan produk' }).disabled).toBe(true);
    });

    it('rapi di 360px: penunjuk ringkas di HP (bilah kemajuan) dan daftar langkah baru bila wadahnya cukup lebar, tanpa lebar tetap', () => {
        const { container } = render(<HalamanFormProduk {...PropsForm()} />);
        const navigasi = screen.getByRole('navigation', { name: 'Langkah tambah produk' });

        // Daftar penuh hanya bila wadahnya cukup lebar (container query: sidebar terbuka memakan lebar layar).
        expect(navigasi.className).toContain('@container');
        expect(navigasi.querySelector('ol')?.className).toMatch(/\bhidden\b.*@\w+:grid\b/);
        expect(navigasi.querySelector('[role="progressbar"]')?.className).toMatch(/@\w+:hidden\b/);
        // Bilah aksi menempel di bawah di HP dan memakai ruang aman iOS.
        const bilah = screen.getByRole('button', { name: 'Lanjut' }).parentElement;
        expect(bilah?.className).toContain('sticky bottom-0');
        expect(bilah?.className).toContain('tepi-bawah-aman');
        // Tidak ada lebar piksel tetap yang bisa melewati 360px pada isi wizard.
        const dilarang = Array.from(container.querySelectorAll('form *')).filter((el) =>
            /(^|\s)(w|min-w)-\[\d+px\]/.test(el.getAttribute('class') ?? ''),
        );
        expect(dilarang).toHaveLength(0);
    });
});
