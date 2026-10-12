import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarProduk from '@/Halaman/Kelola/Produk/Daftar';
import HalamanDetailProduk from '@/Halaman/Kelola/Produk/Detail';
import HalamanFormProduk from '@/Halaman/Kelola/Produk/Form';
import HalamanHargaProduk from '@/Halaman/Kelola/Produk/Harga';
import HalamanKomponenProduk, { PeriksaAlokasiHarga } from '@/Halaman/Kelola/Produk/Komponen';
import HalamanPilihanProduk from '@/Halaman/Kelola/Produk/Pilihan';
import HalamanResepProduk, { TeksRumusSusut } from '@/Halaman/Kelola/Produk/Resep';
import type {
    FormProduk,
    PropsDaftarProduk,
    PropsDetailProduk,
    PropsFormProduk,
    PropsHargaProduk,
    PropsResepProduk,
} from '@/Tipe/Katalog';

import {
    AturanJenis,
    BuatBarisProduk,
    BuatHasilTabel,
    BuatKepala,
    HargaEkstrem,
    IzinLihat,
    IzinPenuh,
    NamaPanjang,
    OpsiKategoriUji,
    OpsiKelompokPajakUji,
    OpsiSatuanUji,
} from './DataUjiKatalog';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from './TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('./TiruanInertia')).TiruanInertia);

function PropsDaftar(perubahan: Partial<PropsDaftarProduk> = {}): PropsDaftarProduk {
    return {
        Produk: BuatHasilTabel([]),
        Kategori: OpsiKategoriUji,
        Jenis: AturanJenis,
        BatasSku: { Batas: 100, Terpakai: 12 },
        Izin: IzinPenuh,
        ...perubahan,
    };
}

describe('Kelola/Produk/Daftar (DesainF03 E.2, TabelData D-16)', () => {
    beforeEach(() => {
        AturHalamanUji();
        window.history.replaceState({}, '', '/kelola/produk');
    });
    afterEach(() => cleanup());

    it('keadaan kosong memakai microcopy desain dan dua ajakan', () => {
        RenderUji(<HalamanDaftarProduk {...PropsDaftar()} />);

        expect(screen.getByText('Belum ada produk. Impor dari Excel atau Tambah produk')).toBeTruthy();
        expect(screen.getAllByRole('link', { name: 'Impor dari Excel' }).length).toBeGreaterThan(0);
        expect(screen.getByText('12 dari 100 produk')).toBeTruthy();
    });

    it('kosong karena pencarian: menawarkan hapus pencarian & saring', () => {
        window.history.replaceState({}, '', '/kelola/produk?cari=teh');
        RenderUji(<HalamanDaftarProduk {...PropsDaftar()} />);

        expect(screen.getByText('Tidak ada hasil untuk pencarian atau saring ini.')).toBeTruthy();
        expect(screen.getAllByRole('button', { name: 'Hapus pencarian & saring' }).length).toBeGreaterThan(0);
    });

    it('data ekstrem: 2.000 baris, nama 60 karakter, Rp 1.250.000.000 rata kanan tabular; SKU Mono', () => {
        const baris = Array.from({ length: 2000 }, (_, i) =>
            BuatBarisProduk(i + 1, i === 0 ? { Nama: NamaPanjang, HargaDasar: HargaEkstrem } : {}),
        );
        const { container } = RenderUji(<HalamanDaftarProduk {...PropsDaftar({ Produk: BuatHasilTabel(baris) })} />);

        // Kueri DOM langsung: kueri berbasis peran untuk 2.000 baris terlalu lambat di jsdom.
        expect(container.querySelectorAll('tbody tr')).toHaveLength(2000);
        const pertama = container.querySelector('tbody tr');
        expect(pertama?.querySelector('a')?.textContent).toBe(NamaPanjang);
        const selHarga = Array.from(pertama?.querySelectorAll('td') ?? []).find((sel) =>
            sel.textContent.includes('Rp 1.250.000.000'),
        );
        expect(selHarga?.className).toContain('text-right');
        expect(selHarga?.className).toContain('tabular-nums');
        expect(pertama?.querySelector('td.font-mono')?.textContent).toBe('PRD-000001');
    }, 30_000);

    it('arsipkan mengirim POST; tanpa izin kelola aksi disembunyikan dan alasannya tertulis', () => {
        RenderUji(<HalamanDaftarProduk {...PropsDaftar({ Produk: BuatHasilTabel([BuatBarisProduk(1)]) })} />);
        // Aksi baris ada di DropdownMenu (Radix): dibuka dengan keyboard, lalu item "Arsipkan" dipilih.
        fireEvent.keyDown(screen.getByRole('button', { name: 'Aksi baris' }), { key: 'Enter' });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Arsipkan' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/produk/${BuatBarisProduk(1).Uuid}/arsipkan`,
            {},
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDaftarProduk {...PropsDaftar({ Produk: BuatHasilTabel([BuatBarisProduk(1)]), Izin: IzinLihat })} />,
        );
        expect(screen.queryByRole('button', { name: 'Aksi baris' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Tambah produk' })).toBeNull();
        expect(screen.getByText('Hanya bisa melihat')).toBeTruthy();
    });

    it('batas SKU penuh: tombol tambah nonaktif dengan penjelasan', () => {
        RenderUji(<HalamanDaftarProduk {...PropsDaftar({ BatasSku: { Batas: 100, Terpakai: 100 } })} />);

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Tambah produk' }).disabled).toBe(true);
        expect(screen.getByText('Batas produk paket sudah tercapai')).toBeTruthy();
    });

    it('saring status bawaan Aktif, ekspor mengikuti pencarian & saring aktif di URL', () => {
        window.history.replaceState(
            {},
            '',
            '/kelola/produk?cari=kopi&urut=-DiubahPada&saring%5BJenis%5D=Resep&saring%5BStatus%5D=Semua',
        );
        RenderUji(<HalamanDaftarProduk {...PropsDaftar({ Produk: BuatHasilTabel([BuatBarisProduk(1)]) })} />);

        expect(screen.getByRole('link', { name: 'Ekspor ke Excel' }).getAttribute('href')).toBe(
            '/kelola/produk/ekspor?cari=kopi&urut=-DiubahPada&saring%5BJenis%5D=Resep&saring%5BStatus%5D=Semua',
        );
        const chip = screen.getByLabelText('Saring aktif');
        expect(within(chip).getByText('Status: Semua status')).toBeTruthy();
        expect(within(chip).getByText('Jenis: Menu resep')).toBeTruthy();
    });
});

const produkBaru: FormProduk = {
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
    Satuan: [],
    AtributVarian: [],
};

function PropsForm(perubahan: Partial<PropsFormProduk> = {}): PropsFormProduk {
    return {
        Mode: 'Buat',
        Produk: produkBaru,
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

describe('Kelola/Produk/Form (DesainF03 E.3)', () => {
    // Test di blok ini menguji formulir lengkap (tab); mode Sederhana (D-23 B) diuji di blok berikutnya.
    beforeEach(() => {
        AturHalamanUji();
        window.localStorage.setItem('Katalog.FormProduk.Mode', 'Lengkap');
    });
    afterEach(() => {
        cleanup();
        window.localStorage.clear();
    });

    it('Buat: satuan dasar otomatis, harga dasar + bertingkat, lalu POST body = FormProduk', () => {
        render(<HalamanFormProduk {...PropsForm()} />);

        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Sabun Mandi 90 g' } });
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.change(screen.getByLabelText('Harga baris 1'), { target: { value: '5.000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tambah harga bertingkat' }));
        fireEvent.change(screen.getByLabelText('Mulai jumlah baris 2'), { target: { value: '12' } });
        fireEvent.change(screen.getByLabelText('Harga baris 2'), { target: { value: '4.500' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        expect(kirimanForm).toHaveLength(1);
        expect(kirimanForm[0]?.metode).toBe('post');
        expect(kirimanForm[0]?.url).toBe('/kelola/produk');
        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.Uuid).toBe('01J9PRODUKBARU000000000000A');
        expect(body.Nama).toBe('Sabun Mandi 90 g');
        expect(body.Satuan).toEqual([
            {
                Uuid: null,
                UuidSatuan: 'SAT-PCS',
                KonversiKeDasar: '1',
                DefaultJual: true,
                DefaultBeli: true,
                Barcode: [],
                HargaAwal: [
                    { JumlahMinimum: '1', Harga: '5000' },
                    { JumlahMinimum: '12', Harga: '4500' },
                ],
            },
        ]);
    });

    it('harga bertingkat tidak valid ditahan di peramban dan tab Harga dibuka', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.click(screen.getByRole('tab', { name: 'Umum' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        expect(kirimanForm).toHaveLength(0);
        expect(screen.getByRole('tab', { name: 'Harga' }).getAttribute('aria-selected')).toBe('true');
        expect(screen.getByText('Isi harga. Tulis 0 bila gratis.')).toBeTruthy();
    });

    it('galat server bersarang (Satuan.0.Barcode.1) tampil di isian dan tab ditandai', () => {
        AturHalamanUji({
            Sku: 'SKU sudah dipakai produk lain.',
            'Satuan.0.Barcode.1': 'Barcode 899 sudah dipakai Teh Botol.',
        });
        render(
            <HalamanFormProduk
                {...PropsForm({
                    Produk: {
                        ...produkBaru,
                        Satuan: [
                            {
                                Uuid: null,
                                UuidSatuan: 'SAT-PCS',
                                KonversiKeDasar: '1',
                                DefaultJual: true,
                                DefaultBeli: true,
                                Barcode: ['111', '899'],
                                HargaAwal: [],
                            },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByRole('tab', { name: /Umum.*perlu diperbaiki/ })).toBeTruthy();
        expect(screen.getByRole('tab', { name: /Satuan & barcode.*perlu diperbaiki/ })).toBeTruthy();
        expect(screen.getByText('SKU sudah dipakai produk lain.')).toBeTruthy();
        expect(screen.getByText('Barcode 899 sudah dipakai Teh Botol.')).toBeTruthy();
        expect(
            screen.getByText('Ada 2 isian yang perlu diperbaiki. Lihat pesan di bawah masing-masing isian.'),
        ).toBeTruthy();
    });

    it('produk bervarian: tab Varian muncul, harga dijelaskan per varian', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkBaru, Jenis: 'IndukVarian' } })} />);

        expect(screen.getByRole('tab', { name: 'Varian' })).toBeTruthy();
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        expect(screen.getByText(/Harga diisi per varian/)).toBeTruthy();
    });

    it('Ubah dengan jenis terkunci: jenis hanya dibaca, PUT ke URL produk', () => {
        render(
            <HalamanFormProduk
                {...PropsForm({
                    Mode: 'Ubah',
                    JenisTerkunci: true,
                    Kepala: BuatKepala(),
                    Produk: { ...produkBaru, Nama: 'Es Kopi' },
                })}
            />,
        );

        expect(screen.getByLabelText<HTMLInputElement>('Jenis produk').disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan' }));
        expect(kirimanForm[0]?.metode).toBe('put');
        expect(kirimanForm[0]?.url).toBe('/kelola/produk/01J9PRODUKBARU000000000000A');
    });

    it('tanpa izin harga: tabel harga awal nonaktif dan alasannya tertulis', () => {
        render(<HalamanFormProduk {...PropsForm({ Izin: { ...IzinPenuh, UbahHarga: false } })} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));

        expect(screen.getByText(/produk\.harga\.ubah/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Isi harga dasar' })).toBeNull();
    });
});

describe('Kelola/Produk/Form mode Sederhana (D-23 B)', () => {
    beforeEach(() => {
        AturHalamanUji();
        window.localStorage.clear();
    });
    afterEach(() => cleanup());

    it('bawaan Sederhana: nama, jenis, harga, kategori di satu layar; POST memakai harga dasar satuan dasar', () => {
        render(<HalamanFormProduk {...PropsForm()} />);

        expect(screen.queryByRole('tab', { name: 'Harga' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Formulir sederhana' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByText(/Otomatis: satuan Pieces \(pcs\), pajak Makan & minum, tampil di kasir/)).toBeTruthy();
        // Hanya jenis yang umum ditawarkan.
        expect(screen.getAllByRole('radio').map((r) => r.getAttribute('value'))).toEqual([
            'Stok',
            'Resep',
            'Jasa',
            'NonStok',
        ]);

        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Teh Botol Sosro 450 ml' } });
        fireEvent.change(screen.getByLabelText('Harga jual'), { target: { value: '6.500' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.Nama).toBe('Teh Botol Sosro 450 ml');
        expect(body.Satuan[0]?.HargaAwal).toEqual([{ JumlahMinimum: '1', Harga: '6500' }]);
        expect(body.PaketSesi).toBeNull();
    });

    it('harga jual kosong ditahan di peramban; pilihan Lengkap diingat di peramban', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Sabun' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        expect(kirimanForm).toHaveLength(0);
        expect(screen.getByText('Isi harga jual. Tulis 0 bila gratis.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Formulir lengkap' }));
        expect(screen.getByRole('tab', { name: 'Harga' })).toBeTruthy();
        expect(window.localStorage.getItem('Katalog.FormProduk.Mode')).toBe('Lengkap');
    });

    it('Jasa + "Jual sebagai paket sesi" mengirim PaketSesi; jumlah sesi wajib', () => {
        render(<HalamanFormProduk {...PropsForm({ FiturPaketSesi: true })} />);
        expect(screen.queryByText('Jual sebagai paket sesi')).toBeNull();

        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Paket Creambath 10x' } });
        fireEvent.click(screen.getByRole('radio', { name: /Jasa/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Jual sebagai paket sesi' }));
        fireEvent.change(screen.getByLabelText('Harga paket'), { target: { value: '450.000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));
        expect(kirimanForm).toHaveLength(0);
        expect(screen.getByText('Isi jumlah sesi, misal 10.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Jumlah sesi'), { target: { value: '10' } });
        fireEvent.change(screen.getByLabelText('Masa berlaku (hari, opsional)'), { target: { value: '180' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.Jenis).toBe('Jasa');
        expect(body.PaketSesi).toEqual({ JumlahSesi: '10', MasaBerlakuHari: '180' });
        expect(body.Satuan[0]?.HargaAwal).toEqual([{ JumlahMinimum: '1', Harga: '450000' }]);
    });

    it('audit kemudahan pakai #11: stok sekarang + harga beli ikut terkirim, lokasi tunggal terpilih otomatis; Jasa tanpa isian', () => {
        const opsiStok = {
            Lokasi: [{ Uuid: '01K5GDG0000000000000000001', Nama: 'Gudang Toko', NamaOutlet: 'Toko Pusat' }],
        };
        render(<HalamanFormProduk {...PropsForm({ StokAwal: opsiStok })} />);

        expect(screen.getByText('Stok sekarang (opsional)')).toBeTruthy();
        expect(screen.queryByLabelText('Lokasi stok')).toBeNull();
        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Sabun Cuci Piring 780 ml' } });
        fireEvent.change(screen.getByLabelText('Harga jual'), { target: { value: '15.000' } });
        fireEvent.change(screen.getByLabelText(/Jumlah stok/), { target: { value: '24' } });
        fireEvent.change(screen.getByLabelText('Harga beli per satuan'), { target: { value: '12.500' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.StokAwal).toEqual({ Jumlah: '24', HargaBeli: '12500', UuidGudang: '01K5GDG0000000000000000001' });

        fireEvent.click(screen.getByRole('radio', { name: /Jasa/ }));
        expect(screen.queryByText('Stok sekarang (opsional)')).toBeNull();
        cleanup();

        render(<HalamanFormProduk {...PropsForm()} />);
        expect(screen.queryByText('Stok sekarang (opsional)')).toBeNull();
    });

    it('fitur paket sesi di luar paket langganan: pilihan tidak tampil; galat isian lanjutan membuka formulir lengkap', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkBaru, Jenis: 'Jasa' } })} />);
        expect(screen.queryByText('Jual sebagai paket sesi')).toBeNull();
        cleanup();

        AturHalamanUji({ Sku: 'SKU sudah dipakai produk lain.' });
        render(<HalamanFormProduk {...PropsForm()} />);
        expect(screen.getByRole('tab', { name: /Umum.*perlu diperbaiki/ })).toBeTruthy();
        expect(screen.getByText('Perbaiki isian yang ditandai di formulir lengkap.')).toBeTruthy();
    });

    it('F-07 mode service: produk Jasa punya isian durasi layanan yang ikut dikirim; jenis lain tanpa isian', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkBaru, Jenis: 'Jasa' } })} />);
        fireEvent.change(screen.getByLabelText(/Durasi layanan/), { target: { value: '45 menit' } });
        expect((screen.getByLabelText(/Durasi layanan/) as HTMLInputElement).value).toBe('45');
        cleanup();

        render(<HalamanFormProduk {...PropsForm()} />);
        expect(screen.queryByLabelText(/Durasi layanan/)).toBeNull();
    });

    it('mode Ubah selalu formulir lengkap tanpa sakelar', () => {
        render(<HalamanFormProduk {...PropsForm({ Mode: 'Ubah', Kepala: BuatKepala() })} />);
        expect(screen.queryByRole('button', { name: 'Formulir sederhana' })).toBeNull();
        expect(screen.getByRole('tab', { name: 'Umum' })).toBeTruthy();
    });
});

function PropsDetail(perubahan: Partial<PropsDetailProduk> = {}): PropsDetailProduk {
    return {
        Kepala: BuatKepala({ Jenis: 'Stok', LabelJenis: 'Barang stok' }),
        Produk: {
            Uuid: '01J9PRODUK00000000000000001',
            Nama: 'Susu UHT 1 L',
            NamaStruk: null,
            Sku: 'PRD-000012',
            Jenis: 'Stok',
            LabelJenis: 'Barang stok',
            NamaKategori: 'Bahan',
            Merek: null,
            SatuanDasar: OpsiSatuanUji[0] ?? { Uuid: 'SAT-PCS', Nama: 'Pieces', Simbol: 'pcs', BolehDesimal: false },
            Pelacakan: 'Tidak',
            LabelPelacakan: 'Tidak dilacak',
            KelompokPajak: OpsiKelompokPajakUji[0] ?? null,
            HargaTermasukPajak: 'Ikut',
            BolehMinus: 'Tidak',
            TampilDiPos: true,
            TampilOnline: false,
            Favorit: false,
            UrlGambar: null,
            UrlGambarKecil: null,
            Satuan: [
                {
                    Uuid: 'PS-1',
                    Nama: 'Pieces',
                    Simbol: 'pcs',
                    KonversiKeDasar: '1.0000',
                    DefaultJual: true,
                    DefaultBeli: false,
                    BisaDijual: true,
                    Barcode: [{ Uuid: 'B-1', Barcode: '8991234567890' }],
                },
                {
                    Uuid: 'PS-2',
                    Nama: 'Dus',
                    Simbol: 'dus',
                    KonversiKeDasar: '24.0000',
                    DefaultJual: false,
                    DefaultBeli: true,
                    BisaDijual: false,
                    Barcode: [],
                },
            ],
            AtributVarian: [],
            AlasanTidakBisaDihapus: 'dipakai sebagai bahan di resep Es Kopi Susu versi 3',
            DibuatPada: '2026-09-20T03:00:00Z',
            DiubahPada: '2026-09-21T03:00:00Z',
            DiarsipkanPada: null,
        },
        Varian: [],
        BatasStok: [
            { UuidGudang: 'G-1', NamaGudang: 'Gudang utama', NamaOutlet: 'Solo', StokMinimum: '10', StokMaksimum: '' },
        ],
        Ketersediaan: [
            { UuidOutlet: 'O-1', NamaOutlet: 'Outlet Solo Baru', Habis: false },
            { UuidOutlet: 'O-2', NamaOutlet: 'Outlet Yogyakarta', Habis: true },
        ],
        Riwayat: [],
        Jenis: AturanJenis,
        BatasSku: { Batas: 100, Terpakai: 12 },
        Izin: IzinPenuh,
        ...perubahan,
    };
}

describe('Kelola/Produk/Detail (DesainF03 E.4)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('K-16: detail produk menampilkan status Favorit di kasir (Ya/Tidak)', () => {
        const { unmount: Bongkar } = RenderUji(<HalamanDetailProduk {...PropsDetail()} />);
        expect(screen.getByText('Favorit di kasir').nextElementSibling?.textContent).toBe('Tidak');
        Bongkar();

        RenderUji(<HalamanDetailProduk {...PropsDetail({ Produk: { ...PropsDetail().Produk, Favorit: true } })} />);
        expect(screen.getByText('Favorit di kasir').nextElementSibling?.textContent).toBe('Ya');
    });

    it('BR-03.2: produk terpakai tidak menawarkan hapus, alasan ditulis; arsip tetap bisa', () => {
        RenderUji(<HalamanDetailProduk {...PropsDetail()} />);

        expect(screen.queryByRole('button', { name: 'Hapus produk' })).toBeNull();
        expect(
            screen.getByText(/tidak bisa dihapus: dipakai sebagai bahan di resep Es Kopi Susu versi 3/),
        ).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Arsipkan produk' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/arsipkan',
            {},
            expect.anything(),
        );
    });

    it('satuan & barcode: isi konversi, barcode Mono, barcode internal lewat POST', () => {
        RenderUji(<HalamanDetailProduk {...PropsDetail()} />);

        expect(screen.getByText('24 pcs')).toBeTruthy();
        expect(screen.getByText('8991234567890').className).toContain('font-mono');
        expect(screen.getByText('Hanya untuk pembelian (belum ada harga dasar)')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Buat barcode internal dus' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/satuan/PS-2/barcode-internal',
            {},
            expect.anything(),
        );
    });

    it('F-17 BR-17.2: tandai habis & tersedia lagi per outlet lewat POST; tanpa izin hanya baca; induk varian tanpa panel', () => {
        RenderUji(<HalamanDetailProduk {...PropsDetail()} />);

        expect(screen.getByText('Tersedia')).toBeTruthy();
        expect(screen.getByText('Habis')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Tandai habis di Outlet Solo Baru' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/habis',
            { UuidOutlet: 'O-1', Habis: true },
            expect.anything(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Tersedia lagi di Outlet Yogyakarta' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/habis',
            { UuidOutlet: 'O-2', Habis: false },
            expect.anything(),
        );
        cleanup();

        RenderUji(<HalamanDetailProduk {...PropsDetail({ Izin: { ...IzinPenuh, Kelola: false } })} />);
        expect(screen.queryByRole('button', { name: /Tandai habis di/ })).toBeNull();
        expect(screen.getByText('Habis')).toBeTruthy();
        cleanup();

        RenderUji(<HalamanDetailProduk {...PropsDetail({ Ketersediaan: null })} />);
        expect(screen.queryByText('Ketersediaan per outlet')).toBeNull();
    });

    it('batas stok: min > maks ditolak di peramban; tanpa izin persediaan hanya baca', () => {
        RenderUji(<HalamanDetailProduk {...PropsDetail()} />);
        fireEvent.change(screen.getByLabelText('Stok maksimum Gudang utama'), { target: { value: '5' } });

        expect(screen.getByText('Stok minimum tidak boleh lebih besar dari stok maksimum.')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Simpan batas stok' }).disabled).toBe(true);

        fireEvent.change(screen.getByLabelText('Stok maksimum Gudang utama'), { target: { value: '50' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan batas stok' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/batas-stok',
            { Baris: [{ UuidGudang: 'G-1', StokMinimum: '10', StokMaksimum: '50' }] },
            expect.anything(),
        );
        cleanup();

        RenderUji(<HalamanDetailProduk {...PropsDetail({ Izin: { ...IzinPenuh, KelolaPersediaan: false } })} />);
        expect(screen.queryByRole('button', { name: 'Simpan batas stok' })).toBeNull();
    });

    it('induk varian: pratinjau kombinasi baru dan kuota paket semua-atau-tidak', () => {
        const props = PropsDetail({
            Kepala: BuatKepala({ Jenis: 'IndukVarian' }),
            Produk: {
                ...PropsDetail().Produk,
                Jenis: 'IndukVarian',
                AtributVarian: [{ Nama: 'Ukuran', Nilai: ['M', 'L'] }],
            },
            Varian: [
                {
                    Uuid: 'V-M',
                    Nama: 'Kaos M',
                    Sku: 'PRD-000012-01',
                    Atribut: [{ Nama: 'Ukuran', Nilai: 'M' }],
                    HargaDasar: null,
                    Status: 'Aktif',
                },
            ],
            BatasStok: null,
            BatasSku: { Batas: 100, Terpakai: 100 },
        });
        RenderUji(<HalamanDetailProduk {...props} />);

        expect(screen.getByText(/1 varian baru akan dibuat: L\./)).toBeTruthy();
        expect(screen.getByText(/Sisa kuota paket 0 produk/)).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Buat 1 varian' }).disabled).toBe(true);
    });
});

describe('Kelola/Produk/Harga, Pilihan, Resep, Komponen (E.6, E.9)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('harga dasar: PUT per satuan; riwayat menampilkan lama → baru', () => {
        const props: PropsHargaProduk = {
            Kepala: BuatKepala(),
            Satuan: [
                {
                    UuidProdukSatuan: 'PS-1',
                    Nama: 'Pieces',
                    Simbol: 'pcs',
                    KonversiKeDasar: '1.0000',
                    BolehDesimal: false,
                    HargaDasar: [{ JumlahMinimum: '1.0000', Harga: '5000.00' }],
                },
            ],
            DaftarHarga: [],
            Riwayat: BuatHasilTabel([
                {
                    DibuatPada: '2026-09-20T03:00:00Z',
                    NamaSatuan: 'pcs',
                    NamaDaftarHarga: null,
                    JumlahMinimum: '1.0000',
                    HargaLama: '4500.00',
                    HargaBaru: '5000.00',
                    NamaPengubah: 'Rina',
                    Sumber: 'Manual',
                    LabelSumber: 'Manual',
                },
            ]),
            OpsiSumberRiwayat: [{ Nilai: 'Manual', Label: 'Diubah manual' }],
            LabelHargaTermasukPajak: 'sudah termasuk pajak (ikut outlet)',
            Izin: IzinPenuh,
        };
        window.history.replaceState({}, '', '/kelola/produk/01J9PRODUK00000000000000001/harga');
        RenderUji(<HalamanHargaProduk {...props} />);

        expect(screen.getByText('Rp 4.500')).toBeTruthy();
        expect(screen.getAllByText('Rp 5.000').length).toBeGreaterThan(0);
        expect(screen.getByText(/Belum ada daftar harga/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan harga dasar' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/harga',
            { Satuan: [{ UuidProdukSatuan: 'PS-1', Harga: [{ JumlahMinimum: '1.0000', Harga: '5000.00' }] }] },
            expect.anything(),
        );
    });

    it('pilihan produk: pasang, urutkan, simpan berurutan; varian anak hanya baca', () => {
        render(
            <HalamanPilihanProduk
                Kepala={BuatKepala()}
                Terpasang={[{ Uuid: 'KP-GULA', Nama: 'Level gula', Ringkasan: 'Wajib pilih 1' }]}
                Tersedia={[{ Uuid: 'KP-TOP', Nama: 'Topping', Ringkasan: 'Opsional' }]}
                DariInduk={false}
                Izin={IzinPenuh}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Pasang Topping' }));
        fireEvent.click(screen.getByRole('button', { name: 'Naikkan Topping' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pilihan produk' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/pilihan',
            { KelompokPilihan: ['KP-TOP', 'KP-GULA'] },
            expect.anything(),
        );
        cleanup();

        render(
            <HalamanPilihanProduk
                Kepala={BuatKepala({ UuidInduk: 'P-INDUK', NamaInduk: 'Kaos' })}
                Terpasang={[]}
                Tersedia={[{ Uuid: 'KP-TOP', Nama: 'Topping', Ringkasan: 'Opsional' }]}
                DariInduk
                Izin={IzinPenuh}
            />,
        );
        expect(screen.getByRole('link', { name: 'Ubah pilihan di Kaos' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Pasang Topping' })).toBeNull();
    });

    it('resep: rumus susut ÷ (1 − s) tertulis, pratinjau jumlah kotor, HPP belum tersedia', () => {
        const props: PropsResepProduk = {
            Kepala: BuatKepala(),
            Resep: {
                Versi: 3,
                JumlahHasil: '1.0000',
                SimbolSatuanHasil: 'porsi',
                Catatan: null,
                DibuatPada: '2026-09-20T03:00:00Z',
                NamaPembuat: 'Rina',
                Bahan: [
                    {
                        UuidProdukBahan: 'P-SUSU',
                        NamaBahan: 'Susu UHT',
                        Sku: 'PRD-000012',
                        Jumlah: '150.0000',
                        UuidSatuan: 'SAT-ML',
                        SimbolSatuan: 'ml',
                        JumlahDasar: '150.0000',
                        SimbolSatuanDasar: 'ml',
                        PersenSusut: '10.000000',
                    },
                ],
            },
            VersiTerbaru: 3,
            DaftarVersi: [
                { Versi: 3, DibuatPada: '2026-09-20T03:00:00Z', NamaPembuat: 'Rina' },
                { Versi: 2, DibuatPada: '2026-09-10T03:00:00Z', NamaPembuat: 'Rina' },
            ],
            Hpp: { Status: 'BelumTersedia', HppSatuan: null, Baris: [] },
            Izin: IzinPenuh,
        };
        RenderUji(<HalamanResepProduk {...props} />);

        expect(TeksRumusSusut).toContain('÷ (1 − susut/100)');
        expect(screen.getByText(TeksRumusSusut)).toBeTruthy();
        expect(screen.getByText('166,6667 ml')).toBeTruthy();
        expect(screen.getByText('HPP belum tersedia. HPP bahan muncul setelah stok awal diisi.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Versi 2' }).getAttribute('href')).toBe(
            '/kelola/produk/01J9PRODUK00000000000000001/resep?versi=2',
        );

        fireEvent.change(screen.getByLabelText('Susut Susu UHT'), { target: { value: '100' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan sebagai versi baru' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(screen.getByText('Susut harus 0 sampai kurang dari 100 %.')).toBeTruthy();
    });

    it('resep versi lama hanya dibaca', () => {
        RenderUji(
            <HalamanResepProduk
                Kepala={BuatKepala()}
                Resep={{
                    Versi: 1,
                    JumlahHasil: '20',
                    SimbolSatuanHasil: 'pcs',
                    Catatan: 'Adonan roti',
                    DibuatPada: '2026-09-01T03:00:00Z',
                    NamaPembuat: null,
                    Bahan: [],
                }}
                VersiTerbaru={3}
                DaftarVersi={[]}
                Hpp={{ Status: 'Tersedia', HppSatuan: '7800.125000', Baris: [] }}
                Izin={IzinPenuh}
            />,
        );

        expect(screen.getByText('Anda melihat versi 1 (bukan yang terbaru)')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Simpan sebagai versi baru' })).toBeNull();
        expect(screen.getByText('Rp 7.800,13')).toBeTruthy();
    });

    it('isi paket: alokasi harga semua kosong atau total tepat 100 %', () => {
        const komponen = [
            {
                UuidProdukKomponen: 'A',
                Nama: 'Burger',
                Sku: null,
                Jumlah: '1',
                SimbolSatuan: 'pcs',
                AlokasiHarga: '33.333333',
            },
            {
                UuidProdukKomponen: 'B',
                Nama: 'Kentang',
                Sku: null,
                Jumlah: '1',
                SimbolSatuan: 'pcs',
                AlokasiHarga: '33.333333',
            },
            {
                UuidProdukKomponen: 'C',
                Nama: 'Minum',
                Sku: null,
                Jumlah: '1',
                SimbolSatuan: 'pcs',
                AlokasiHarga: '33.333334',
            },
        ];

        expect(PeriksaAlokasiHarga(komponen)).toEqual({ total: '100.000000', pesan: null });
        expect(PeriksaAlokasiHarga(komponen.map((item) => ({ ...item, AlokasiHarga: '' })))).toEqual({
            total: null,
            pesan: null,
        });
        expect(
            PeriksaAlokasiHarga(komponen.slice(0, 2).map((item, i) => ({ ...item, AlokasiHarga: i === 0 ? '60' : '' })))
                .pesan,
        ).toBe('Isi alokasi semua komponen, atau kosongkan semuanya agar dibagi otomatis.');

        RenderUji(
            <HalamanKomponenProduk
                Kepala={BuatKepala({ Jenis: 'Paket' })}
                Komponen={komponen.slice(0, 2)}
                Izin={IzinPenuh}
            />,
        );
        expect(screen.getByText('Total alokasi harus tepat 100 %, sekarang 66,666666 %.')).toBeTruthy();
    });
});

describe('Kelola/Produk: dialog & sakelar shadcn/ui', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('hapus produk memakai AlertDialog: Batal menutup tanpa kirim, konfirmasi mengirim DELETE', async () => {
        RenderUji(
            <HalamanDetailProduk
                {...PropsDetail({ Produk: { ...PropsDetail().Produk, AlasanTidakBisaDihapus: null } })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Hapus produk' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Hapus Susu UHT 1 L?' });
        expect(dialog.textContent).toContain('dihapus permanen');
        fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
        await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
        expect(tiruanRouter.delete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Hapus produk' }));
        fireEvent.click(screen.getByRole('button', { name: 'Ya, hapus produk' }));
        expect(tiruanRouter.delete).toHaveBeenCalledWith('/kelola/produk/01J9PRODUK00000000000000001');
    });

    it('sakelar tampil (Switch) mengubah TampilDiPos/TampilOnline di body FormProduk', () => {
        window.localStorage.setItem('Katalog.FormProduk.Mode', 'Lengkap');
        render(<HalamanFormProduk {...PropsForm()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Pajak & tampilan' }));

        const pos = screen.getByRole('switch', { name: 'Tampil di kasir (POS)' });
        const online = screen.getByRole('switch', { name: 'Tampil di toko online' });
        expect(pos.getAttribute('aria-checked')).toBe('true');
        expect(online.getAttribute('aria-checked')).toBe('false');

        fireEvent.click(pos);
        fireEvent.click(online);
        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Sabun cair' } });
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.change(screen.getByLabelText('Harga baris 1'), { target: { value: '10.000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.TampilDiPos).toBe(false);
        expect(body.TampilOnline).toBe(true);
        window.localStorage.clear();
    });

    it('K-16: sakelar Favorit di kasir mengirim Favorit di body FormProduk dan hilang saat produk disembunyikan dari kasir', () => {
        window.localStorage.setItem('Katalog.FormProduk.Mode', 'Lengkap');
        render(<HalamanFormProduk {...PropsForm()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Pajak & tampilan' }));

        const favorit = screen.getByRole('switch', { name: 'Favorit di kasir' });
        expect(favorit.getAttribute('aria-checked')).toBe('false');
        fireEvent.click(favorit);
        expect(screen.getByRole('switch', { name: 'Favorit di kasir' }).getAttribute('aria-checked')).toBe('true');

        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Kopi susu gula aren' } });
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.change(screen.getByLabelText('Harga baris 1'), { target: { value: '10.000' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));
        expect((kirimanForm[0]?.data as FormProduk).Favorit).toBe(true);

        fireEvent.click(screen.getByRole('tab', { name: 'Pajak & tampilan' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Tampil di kasir (POS)' }));
        expect(screen.queryByRole('switch', { name: 'Favorit di kasir' })).toBeNull();
        window.localStorage.clear();
    });

    it('K-25: sakelar harga terbuka mengisi HargaTerbuka di body FormProduk', () => {
        window.localStorage.setItem('Katalog.FormProduk.Mode', 'Lengkap');
        render(<HalamanFormProduk {...PropsForm()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Pajak & tampilan' }));

        fireEvent.click(screen.getByRole('switch', { name: /Harga diketik kasir/ }));
        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Barang lain-lain' } });
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.change(screen.getByLabelText('Harga baris 1'), { target: { value: '0' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        expect((kirimanForm[0]?.data as FormProduk).HargaTerbuka).toBe(true);
        window.localStorage.clear();
    });

    it('Apotek (§9.5): bagian golongan obat hanya untuk barang ber-batch; OWA untuk obat keras; HNA + margin mengisi harga', () => {
        window.localStorage.setItem('Katalog.FormProduk.Mode', 'Lengkap');
        render(<HalamanFormProduk {...PropsForm()} />);
        expect(screen.queryByText('Golongan obat (apotek)')).toBeNull();
        cleanup();

        render(
            <HalamanFormProduk
                {...PropsForm({
                    Produk: { ...produkBaru, Pelacakan: 'Batch', GolonganObat: 'Keras', ObatWajibApotek: false },
                })}
            />,
        );
        expect(screen.getByText('Golongan obat (apotek)')).toBeTruthy();
        expect(screen.getByText(/Wajib resep dokter dan hanya bisa dijual apoteker/)).toBeTruthy();
        fireEvent.click(screen.getByRole('checkbox', { name: /Obat Wajib Apotek/ }));
        expect(screen.getByText(/Dijual tanpa resep oleh apoteker/)).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: 'Asam Mefenamat 500 mg Tablet' } });
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.change(screen.getByLabelText(/HNA per/), { target: { value: '650' } });
        fireEvent.change(screen.getByLabelText('Margin (%)'), { target: { value: '25' } });
        fireEvent.click(screen.getByRole('button', { name: 'Pakai sebagai harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.GolonganObat).toBe('Keras');
        expect(body.ObatWajibApotek).toBe(true);
        expect(body.Satuan[0]?.HargaAwal[0]?.Harga).toBe('800.00');
        window.localStorage.clear();
    });
});
