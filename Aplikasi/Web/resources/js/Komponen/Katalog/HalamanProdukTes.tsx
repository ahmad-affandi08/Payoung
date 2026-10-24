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
import { AturGalatBalasanUji, AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from './TiruanInertia';

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
        expect(screen.getByText('12 / 100')).toBeTruthy();
    });

    it('kosong karena pencarian: menawarkan hapus pencarian & filter', () => {
        window.history.replaceState({}, '', '/kelola/produk?cari=teh');
        RenderUji(<HalamanDaftarProduk {...PropsDaftar()} />);

        expect(screen.getByText('Tidak ada hasil untuk pencarian atau filter ini.')).toBeTruthy();
        expect(screen.getAllByRole('button', { name: 'Hapus pencarian & filter' }).length).toBeGreaterThan(0);
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
        expect(screen.getByText('Penuh')).toBeTruthy();
        expect(screen.getByRole('progressbar', { name: 'Pemakaian kuota produk' }).getAttribute('aria-valuenow')).toBe(
            '100',
        );
        expect(screen.getByRole('link', { name: 'tingkatkan paket' })).toBeTruthy();
    });

    it('filter status bawaan Aktif, ekspor mengikuti pencarian & filter aktif di URL', () => {
        window.history.replaceState(
            {},
            '',
            '/kelola/produk?cari=kopi&urut=-DiubahPada&saring%5BJenis%5D=Resep&saring%5BStatus%5D=Semua',
        );
        RenderUji(<HalamanDaftarProduk {...PropsDaftar({ Produk: BuatHasilTabel([BuatBarisProduk(1)]) })} />);

        expect(screen.getByRole('link', { name: 'Ekspor ke Excel' }).getAttribute('href')).toBe(
            '/kelola/produk/ekspor?cari=kopi&urut=-DiubahPada&saring%5BJenis%5D=Resep&saring%5BStatus%5D=Semua',
        );
        const chip = screen.getByLabelText('Filter aktif');
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

/** Ketuk "Lanjut" di wizard tambah produk. */
function Lanjut() {
    fireEvent.click(screen.getByRole('button', { name: 'Lanjut' }));
}

function IsiNama(nama: string) {
    fireEvent.change(screen.getByLabelText('Nama produk'), { target: { value: nama } });
}

/** Langkah harga: buka baris harga dasar lalu isi harga per satuan dasar (tulis "5.000" seperti diketik pengguna). */
function IsiHargaDasar(harga: string) {
    fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
    fireEvent.change(screen.getByLabelText('Harga baris 1'), { target: { value: harga } });
}

function LangkahAktif(): string {
    return screen.getByRole('heading', {
        level: 2,
        name: /^(Jenis & nama|Satuan & barcode|Harga|Stok|Harga & stok|Varian|Pajak & tampilan|Tinjau & simpan)$/,
    }).textContent;
}

describe('Kelola/Produk/Form tambah produk bertahap (DesainF03 E.3, D-128)', () => {
    // Menggantikan test formulir Lengkap (tab bebas) dan Sederhana (D-23 B) untuk TAMBAH produk.
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

    it('Buat: satuan dasar otomatis, harga dasar + bertingkat, lalu POST body = FormProduk', () => {
        render(<HalamanFormProduk {...PropsForm()} />);

        IsiNama('Sabun Mandi 90 g');
        Lanjut();
        expect(LangkahAktif()).toBe('Satuan & barcode');
        Lanjut();
        expect(LangkahAktif()).toBe('Harga & stok');
        IsiHargaDasar('5.000');
        fireEvent.click(screen.getByRole('button', { name: 'Tambah harga bertingkat' }));
        fireEvent.change(screen.getByLabelText('Mulai jumlah baris 2'), { target: { value: '12' } });
        fireEvent.change(screen.getByLabelText('Harga baris 2'), { target: { value: '4.500' } });
        Lanjut();
        expect(LangkahAktif()).toBe('Pajak & tampilan');
        Lanjut();
        expect(LangkahAktif()).toBe('Tinjau & simpan');
        expect(kirimanForm).toHaveLength(0);
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

    it('bawaan wizard: langkah 1 menawarkan semua jenis (yang umum di atas) dengan contoh; langkah di depan terkunci', () => {
        render(<HalamanFormProduk {...PropsForm()} />);

        expect(screen.getByText('Langkah 1 dari 5')).toBeTruthy();
        expect(screen.queryByRole('tab')).toBeNull();
        expect(screen.getAllByRole('radio').map((r) => r.getAttribute('value'))).toEqual([
            'Stok',
            'Resep',
            'Jasa',
            'NonStok',
            'IndukVarian',
            'Produksi',
            'Paket',
            'BahanBaku',
            'Konsinyasi',
        ]);
        expect(screen.getByText('Barang yang dihitung stoknya, misal sabun atau minuman botol.')).toBeTruthy();
    });

    it('harga bertingkat tidak valid ditahan di peramban: tetap di langkah harga dengan galat di barisnya', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        Lanjut();

        expect(LangkahAktif()).toBe('Harga & stok');
        expect(screen.getByText('Isi harga. Tulis 0 bila gratis.')).toBeTruthy();
        expect(kirimanForm).toHaveLength(0);
    });

    it('harga jual kosong ditahan di peramban dengan pesan jelas; harga 0 (gratis) boleh', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        Lanjut();

        expect(LangkahAktif()).toBe('Harga & stok');
        expect(screen.getByText('Isi harga jual. Tulis 0 bila gratis.')).toBeTruthy();

        IsiHargaDasar('0');
        Lanjut();
        expect(LangkahAktif()).toBe('Pajak & tampilan');
    });

    it('galat server bersarang (Satuan.0.Barcode.1) tampil di isiannya dan langkahnya ditandai', () => {
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

        expect(screen.getAllByText('Perlu diperbaiki')).toHaveLength(2);
        expect(screen.getByText('SKU sudah dipakai produk lain.')).toBeTruthy();
        expect(
            screen.getByText('Ada 1 isian yang perlu diperbaiki. Lihat pesan di bawah isian tersebut.'),
        ).toBeTruthy();

        IsiNama('Teh Botol');
        Lanjut();
        expect(LangkahAktif()).toBe('Satuan & barcode');
        expect(screen.getByText('Barcode 899 sudah dipakai Teh Botol.')).toBeTruthy();
    });

    it('produk bervarian: langkah Varian muncul, langkah harga hilang, harga dijelaskan per varian', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkBaru, Jenis: 'IndukVarian' } })} />);

        expect(screen.getByText('Langkah 1 dari 4')).toBeTruthy();
        expect(screen.getByRole('button', { name: /Varian/ })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Harga/ })).toBeNull();
        IsiNama('Kaos Polos');
        Lanjut();
        expect(LangkahAktif()).toBe('Varian');
        expect(screen.getByText(/harga diisi per varian/)).toBeTruthy();
    });

    it('tanpa izin harga: tabel harga awal nonaktif, alasannya tertulis, dan harga tidak diwajibkan', () => {
        render(<HalamanFormProduk {...PropsForm({ Izin: { ...IzinPenuh, UbahHarga: false } })} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();

        expect(screen.getByText(/produk\.harga\.ubah/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Isi harga dasar' })).toBeNull();
        Lanjut();
        expect(LangkahAktif()).toBe('Pajak & tampilan');
    });

    it('Jasa + "Jual sebagai paket sesi" mengirim PaketSesi; jumlah sesi wajib; langkah satuan dilewati', () => {
        render(<HalamanFormProduk {...PropsForm({ FiturPaketSesi: true })} />);
        IsiNama('Paket Creambath 10x');
        fireEvent.click(screen.getByRole('radio', { name: /Jasa/ }));
        Lanjut();
        expect(LangkahAktif()).toBe('Harga');
        expect(screen.getByText('Langkah 2 dari 4')).toBeTruthy();

        fireEvent.click(screen.getByRole('checkbox', { name: 'Jual sebagai paket sesi' }));
        IsiHargaDasar('450.000');
        Lanjut();
        expect(LangkahAktif()).toBe('Harga');
        expect(screen.getByText('Isi jumlah sesi, misal 10.')).toBeTruthy();
        expect(kirimanForm).toHaveLength(0);

        fireEvent.change(screen.getByLabelText('Jumlah sesi'), { target: { value: '10' } });
        fireEvent.change(screen.getByLabelText('Masa berlaku (hari, opsional)'), { target: { value: '180' } });
        Lanjut();
        Lanjut();
        expect(screen.getByText('10 sesi, berlaku 180 hari')).toBeTruthy();
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

        IsiNama('Sabun Cuci Piring 780 ml');
        Lanjut();
        Lanjut();
        expect(screen.getByText('Stok sekarang (opsional)')).toBeTruthy();
        expect(screen.queryByLabelText('Lokasi stok')).toBeNull();
        IsiHargaDasar('15.000');
        fireEvent.change(screen.getByLabelText(/Jumlah stok/), { target: { value: '24' } });
        fireEvent.change(screen.getByLabelText('Harga beli per satuan'), { target: { value: '12.500' } });
        Lanjut();
        Lanjut();
        expect(screen.getByText('24 pcs')).toBeTruthy();
        expect(screen.getByText(/di Toko Pusat \| Gudang Toko/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.StokAwal).toEqual({ Jumlah: '24', HargaBeli: '12500', UuidGudang: '01K5GDG0000000000000000001' });
        cleanup();

        render(<HalamanFormProduk {...PropsForm({ StokAwal: opsiStok, Produk: { ...produkBaru, Jenis: 'Jasa' } })} />);
        IsiNama('Potong rambut');
        Lanjut();
        expect(screen.queryByText('Stok sekarang (opsional)')).toBeNull();
        cleanup();

        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        expect(screen.queryByText('Stok sekarang (opsional)')).toBeNull();
    });

    it('stok sekarang: banyak lokasi wajib dipilih bila jumlah diisi', () => {
        const opsiStok = {
            Lokasi: [
                { Uuid: '01K5GDG0000000000000000001', Nama: 'Gudang Toko', NamaOutlet: 'Toko Pusat' },
                { Uuid: '01K5GDG0000000000000000002', Nama: 'Gudang Belakang', NamaOutlet: 'Toko Pusat' },
            ],
        };
        render(<HalamanFormProduk {...PropsForm({ StokAwal: opsiStok, Izin: { ...IzinPenuh, UbahHarga: false } })} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        fireEvent.change(screen.getByLabelText(/Jumlah stok/), { target: { value: '24' } });
        Lanjut();

        expect(LangkahAktif()).toBe('Harga & stok');
        expect(screen.getByText('Pilih lokasi stok.')).toBeTruthy();
    });

    it('fitur paket sesi di luar paket langganan: pilihan tidak tampil', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkBaru, Jenis: 'Jasa' } })} />);
        IsiNama('Potong rambut');
        Lanjut();

        expect(screen.queryByText('Jual sebagai paket sesi')).toBeNull();
    });

    it('galat server membuka langkah pertama yang memuatnya dan menampilkan galatnya', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        IsiHargaDasar('5.000');
        Lanjut();
        Lanjut();
        expect(LangkahAktif()).toBe('Tinjau & simpan');

        AturGalatBalasanUji({ Sku: 'SKU sudah dipakai produk lain.', KodeUnitCoretax: 'Kode satuan tidak dikenal.' });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        expect(kirimanForm).toHaveLength(1);
        expect(LangkahAktif()).toBe('Jenis & nama');
        expect(screen.getByText('SKU sudah dipakai produk lain.')).toBeTruthy();
        expect(screen.getAllByText('Perlu diperbaiki')).toHaveLength(2);
    });

    it('F-07 mode service: produk Jasa punya isian durasi layanan (langkah pajak & tampilan) yang ikut dikirim; jenis lain tanpa isian', () => {
        render(<HalamanFormProduk {...PropsForm({ Produk: { ...produkBaru, Jenis: 'Jasa' } })} />);
        IsiNama('Potong rambut');
        Lanjut();
        IsiHargaDasar('50.000');
        Lanjut();
        fireEvent.change(screen.getByLabelText(/Durasi layanan/), { target: { value: '45 menit' } });
        expect((screen.getByLabelText(/Durasi layanan/) as HTMLInputElement).value).toBe('45');
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));
        expect((kirimanForm[0]?.data as FormProduk).DurasiMenit).toBe(45);
        cleanup();

        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun');
        Lanjut();
        Lanjut();
        IsiHargaDasar('5.000');
        Lanjut();
        expect(LangkahAktif()).toBe('Pajak & tampilan');
        expect(screen.queryByLabelText(/Durasi layanan/)).toBeNull();
    });
});

describe('Kelola/Produk/Form ubah produk (tab bebas, tidak berubah)', () => {
    beforeEach(() => AturHalamanUji());
    afterEach(() => cleanup());

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

    it('mode Ubah: barcode yang diketik lalu Simpan ikut ditambahkan; yang tidak valid menahan simpan', () => {
        render(
            <HalamanFormProduk
                {...PropsForm({
                    Mode: 'Ubah',
                    Kepala: BuatKepala(),
                    Produk: {
                        ...produkBaru,
                        Nama: 'Es Kopi',
                        Satuan: [
                            {
                                Uuid: null,
                                UuidSatuan: 'SAT-PCS',
                                KonversiKeDasar: '1',
                                DefaultJual: true,
                                DefaultBeli: true,
                                Barcode: [],
                                HargaAwal: [],
                            },
                        ],
                    },
                })}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: /Satuan & barcode/ }));
        const isian = screen.getByPlaceholderText<HTMLInputElement>('Pindai atau ketik barcode');

        fireEvent.change(isian, { target: { value: 'ab' } });
        fireEvent.blur(isian);
        const sebelum = kirimanForm.length;

        fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan' }));
        expect(kirimanForm.length).toBe(sebelum);
        expect(screen.getByText('Barcode 3–64 karakter: huruf, angka, titik, atau strip.')).toBeTruthy();

        fireEvent.change(isian, { target: { value: '8993175530021' } });
        fireEvent.blur(isian);
        fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan' }));
        expect(kirimanForm.at(-1)?.metode).toBe('put');
        expect(JSON.stringify(kirimanForm.at(-1)?.data)).toContain('8993175530021');
    });

    it('mode Ubah selalu tab bebas tanpa wizard, penunjuk langkah, atau sakelar mode', () => {
        render(<HalamanFormProduk {...PropsForm({ Mode: 'Ubah', Kepala: BuatKepala() })} />);
        expect(screen.queryByRole('button', { name: 'Formulir sederhana' })).toBeNull();
        expect(screen.queryByText(/^Langkah \d dari \d$/)).toBeNull();
        expect(screen.queryByRole('button', { name: 'Lanjut' })).toBeNull();
        expect(screen.getByRole('tab', { name: 'Umum' })).toBeTruthy();
        // Tab bebas: Harga bisa dibuka langsung tanpa melewati langkah lain.
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        expect(screen.getByRole('tab', { name: 'Harga' }).getAttribute('aria-selected')).toBe('true');
    });

    it('galat server bersarang (Satuan.0.Barcode.1) tampil di isian dan tab ditandai', () => {
        AturHalamanUji({
            Sku: 'SKU sudah dipakai produk lain.',
            'Satuan.0.Barcode.1': 'Barcode 899 sudah dipakai Teh Botol.',
        });
        render(
            <HalamanFormProduk
                {...PropsForm({
                    Mode: 'Ubah',
                    Kepala: BuatKepala(),
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

    it('harga bertingkat tidak valid ditahan di peramban dan tab Harga dibuka (produk lama dengan satuan baru)', () => {
        render(<HalamanFormProduk {...PropsForm({ Mode: 'Ubah', Kepala: BuatKepala() })} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.click(screen.getByRole('tab', { name: 'Umum' }));
        fireEvent.click(screen.getByRole('button', { name: 'Simpan perubahan' }));

        expect(kirimanForm).toHaveLength(0);
        expect(screen.getByRole('tab', { name: 'Harga' }).getAttribute('aria-selected')).toBe('true');
        expect(screen.getByText('Isi harga. Tulis 0 bila gratis.')).toBeTruthy();
    });

    it('tanpa izin harga: tabel harga awal nonaktif dan alasannya tertulis', () => {
        render(
            <HalamanFormProduk
                {...PropsForm({ Mode: 'Ubah', Kepala: BuatKepala(), Izin: { ...IzinPenuh, UbahHarga: false } })}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));

        expect(screen.getByText(/produk\.harga\.ubah/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Isi harga dasar' })).toBeNull();
    });

    it('produk bervarian: tab Varian muncul, harga dijelaskan per varian', () => {
        render(
            <HalamanFormProduk
                {...PropsForm({ Mode: 'Ubah', Kepala: BuatKepala(), Produk: { ...produkBaru, Jenis: 'IndukVarian' } })}
            />,
        );

        expect(screen.getByRole('tab', { name: 'Varian' })).toBeTruthy();
        fireEvent.click(screen.getByRole('tab', { name: 'Harga' }));
        expect(screen.getByText(/Harga diisi per varian/)).toBeTruthy();
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
        StokVarian: {},
        StokAwalVarian: null,
        BatasStok: [
            { UuidGudang: 'G-1', NamaGudang: 'Gudang utama', NamaOutlet: 'Solo', StokMinimum: '10', StokMaksimum: '' },
        ],
        Ketersediaan: [
            {
                UuidOutlet: 'O-1',
                NamaOutlet: 'Outlet Solo Baru',
                Habis: false,
                Kanal: { Kasir: false, TokoOnline: false, SelfOrder: false },
            },
            {
                UuidOutlet: 'O-2',
                NamaOutlet: 'Outlet Yogyakarta',
                Habis: true,
                Kanal: { Kasir: true, TokoOnline: true, SelfOrder: true },
            },
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

    it('BR-03.1: barcode diketik di detail (Enter atau tombol), format & duplikat ditolak sebelum dikirim, galat server tampil', () => {
        RenderUji(<HalamanDetailProduk {...PropsDetail()} />);
        const isian = screen.getByLabelText('Barcode baru dus');
        const tombolTambah = within(isian.parentElement as HTMLElement).getByRole('button', { name: 'Tambah barcode' });

        expect((tombolTambah as HTMLButtonElement).disabled).toBe(true);

        fireEvent.change(isian, { target: { value: 'a b' } });
        fireEvent.keyDown(isian, { key: 'Enter' });
        expect(screen.getByText('Barcode 3–64 karakter: huruf, angka, titik, atau strip.')).toBeTruthy();

        // Duplikat dicek terhadap semua satuan produk, tanpa beda huruf besar/kecil.
        fireEvent.change(isian, { target: { value: '8991234567890' } });
        fireEvent.keyDown(isian, { key: 'Enter' });
        expect(screen.getByText('Barcode 8991234567890 sudah dipakai di produk ini.')).toBeTruthy();
        expect(tiruanRouter.post).not.toHaveBeenCalled();

        fireEvent.change(isian, { target: { value: ' 8991234500017 ' } });
        fireEvent.keyDown(isian, { key: 'Enter' });
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/satuan/PS-2/barcode',
            { Barcode: '8991234500017' },
            expect.objectContaining({ preserveScroll: true }),
        );

        tiruanRouter.post.mockImplementationOnce(
            (_url: string, _data: unknown, opsi: { onError?: (g: Record<string, string>) => void }) =>
                opsi.onError?.({ Barcode: 'Barcode 8991234500017 sudah dipakai produk Teh Botol.' }),
        );
        fireEvent.click(tombolTambah);
        expect(screen.getByText('Barcode 8991234500017 sudah dipakai produk Teh Botol.')).toBeTruthy();
    });

    it('BR-03.1: barcode di detail bisa dihapus setelah konfirmasi; tanpa izin kelola tidak ada isian maupun tombol hapus', () => {
        RenderUji(<HalamanDetailProduk {...PropsDetail()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Hapus barcode 8991234567890' }));
        expect(tiruanRouter.delete).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Ya, hapus barcode' }));
        expect(tiruanRouter.delete).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/barcode/B-1',
            expect.objectContaining({ preserveScroll: true }),
        );
        cleanup();

        RenderUji(<HalamanDetailProduk {...PropsDetail({ Izin: { ...IzinPenuh, Kelola: false } })} />);
        expect(screen.getByText('8991234567890')).toBeTruthy();
        expect(screen.queryByLabelText('Barcode baru dus')).toBeNull();
        expect(screen.queryByRole('button', { name: /Hapus barcode/ })).toBeNull();
        expect(screen.queryByRole('button', { name: /Pindai kamera/ })).toBeNull();
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

    it('PRD-11: tandai habis per kanal mengirim Kanal; habis sebagian diberi label sendiri; Semua tidak mengirim Kanal', () => {
        RenderUji(
            <HalamanDetailProduk
                {...PropsDetail({
                    Ketersediaan: [
                        {
                            UuidOutlet: 'O-1',
                            NamaOutlet: 'Outlet Solo Baru',
                            Habis: false,
                            Kanal: { Kasir: false, TokoOnline: true, SelfOrder: false },
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Habis sebagian')).toBeTruthy();
        const kanal = screen.getByRole('list', { name: 'Kanal di Outlet Solo Baru' });
        expect(within(kanal).getByText('Toko online | habis')).toBeTruthy();
        expect(within(kanal).getByText('Kasir | ada')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Tandai habis di kanal Kasir, Outlet Solo Baru' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/habis',
            { UuidOutlet: 'O-1', Habis: true, Kanal: 'Kasir' },
            expect.anything(),
        );
        cleanup();

        RenderUji(
            <HalamanDetailProduk
                {...PropsDetail({
                    Ketersediaan: [
                        {
                            UuidOutlet: 'O-1',
                            NamaOutlet: 'Outlet Solo Baru',
                            Habis: false,
                            Kanal: { Kasir: false, TokoOnline: true, SelfOrder: false },
                        },
                    ],
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Tersedia lagi di kanal Toko online, Outlet Solo Baru' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/habis',
            { UuidOutlet: 'O-1', Habis: false, Kanal: 'TokoOnline' },
            expect.anything(),
        );
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

    it('induk varian 2 atribut: grid ukuran × warna menyimpan harga yang berubah dan mencatat stok awal sekaligus', () => {
        const BuatVarian = (uuid: string, ukuran: string, warna: string, harga: string) => ({
            Uuid: uuid,
            Nama: `Kaos ${ukuran} ${warna}`,
            Sku: null,
            Atribut: [
                { Nama: 'Ukuran', Nilai: ukuran },
                { Nama: 'Warna', Nilai: warna },
            ],
            HargaDasar: harga,
            Status: 'Aktif' as const,
        });
        const props = PropsDetail({
            Kepala: BuatKepala({ Jenis: 'IndukVarian' }),
            Produk: {
                ...PropsDetail().Produk,
                Jenis: 'IndukVarian',
                AtributVarian: [
                    { Nama: 'Ukuran', Nilai: ['S', 'M'] },
                    { Nama: 'Warna', Nilai: ['Hitam', 'Putih'] },
                ],
            },
            Varian: [
                BuatVarian('V-S-H', 'S', 'Hitam', '75000.00'),
                BuatVarian('V-S-P', 'S', 'Putih', '75000.00'),
                BuatVarian('V-M-H', 'M', 'Hitam', '75000.00'),
            ],
            StokVarian: { 'V-M-H': '6.0000' },
            StokAwalVarian: { Lokasi: [{ Uuid: 'G-1', Nama: 'Gudang utama', NamaOutlet: 'Solo' }] },
            BatasStok: null,
        });
        RenderUji(<HalamanDetailProduk {...props} />);

        expect(screen.getByRole('heading', { name: /Grid ukuran × warna/ })).toBeTruthy();
        // Kombinasi M/Putih belum dibuat → tanda hubung, bukan kolom isian.
        expect(screen.getAllByLabelText('Belum dibuat').length).toBeGreaterThan(0);

        fireEvent.change(
            within(screen.getByRole('region', { name: 'Grid harga jual varian' })).getByRole('textbox', {
                name: 'Harga Kaos S Hitam',
            }),
            { target: { value: '80000' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Simpan harga 1 varian' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/varian',
            { Baris: [{ Uuid: 'V-S-H', Harga: '80000', Barcode: null }] },
            expect.anything(),
        );

        fireEvent.click(screen.getByRole('tab', { name: 'Stok awal' }));
        // Varian yang sudah punya stok hanya ditampilkan; tombol catat nonaktif sampai ada isian.
        expect(screen.getByText('Stok 6')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Catat stok awal varian' }).disabled).toBe(true);
        fireEvent.change(screen.getByRole('textbox', { name: 'Stok awal Kaos S Putih' }), { target: { value: '12' } });
        fireEvent.click(screen.getByRole('button', { name: 'Catat stok awal 1 varian' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/produk/01J9PRODUK00000000000000001/varian/stok-awal',
            { UuidGudang: 'G-1', Baris: [{ Uuid: 'V-S-P', Jumlah: '12', HargaBeli: '' }] },
            expect.anything(),
        );
    });

    it('induk varian 1 atribut: tidak ada grid (tetap tabel daftar)', () => {
        const props = PropsDetail({
            Kepala: BuatKepala({ Jenis: 'IndukVarian' }),
            Produk: {
                ...PropsDetail().Produk,
                Jenis: 'IndukVarian',
                AtributVarian: [{ Nama: 'Ukuran', Nilai: ['M'] }],
            },
            Varian: [
                {
                    Uuid: 'V-M',
                    Nama: 'Kaos M',
                    Sku: null,
                    Atribut: [{ Nama: 'Ukuran', Nilai: 'M' }],
                    HargaDasar: null,
                    Status: 'Aktif',
                },
            ],
            BatasStok: null,
        });
        RenderUji(<HalamanDetailProduk {...props} />);

        expect(screen.queryByRole('heading', { name: /Grid ukuran/ })).toBeNull();
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
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Sabun cair');
        Lanjut();
        Lanjut();
        IsiHargaDasar('10.000');
        Lanjut();

        const pos = screen.getByRole('switch', { name: 'Tampil di kasir (POS)' });
        const online = screen.getByRole('switch', { name: 'Tampil di toko online' });
        expect(pos.getAttribute('aria-checked')).toBe('true');
        expect(online.getAttribute('aria-checked')).toBe('false');

        fireEvent.click(pos);
        fireEvent.click(online);
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.TampilDiPos).toBe(false);
        expect(body.TampilOnline).toBe(true);
    });

    it('K-16: sakelar Favorit di kasir mengirim Favorit di body FormProduk dan hilang saat produk disembunyikan dari kasir', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Kopi susu gula aren');
        Lanjut();
        Lanjut();
        IsiHargaDasar('10.000');
        Lanjut();

        const favorit = screen.getByRole('switch', { name: 'Favorit di kasir' });
        expect(favorit.getAttribute('aria-checked')).toBe('false');
        fireEvent.click(favorit);
        expect(screen.getByRole('switch', { name: 'Favorit di kasir' }).getAttribute('aria-checked')).toBe('true');

        Lanjut();
        expect(screen.getByText('Favorit di kasir').nextElementSibling?.textContent).toBe('Ya');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));
        expect((kirimanForm[0]?.data as FormProduk).Favorit).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Ubah Pajak & tampilan' }));
        fireEvent.click(screen.getByRole('switch', { name: 'Tampil di kasir (POS)' }));
        expect(screen.queryByRole('switch', { name: 'Favorit di kasir' })).toBeNull();
    });

    it('K-25: sakelar harga terbuka (langkah harga) mengisi HargaTerbuka di body dan membuat harga tidak wajib', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Barang lain-lain');
        Lanjut();
        Lanjut();

        // Harga kosong ditahan sampai kasir diizinkan mengetik harga sendiri.
        Lanjut();
        expect(screen.getByText('Isi harga jual. Tulis 0 bila gratis.')).toBeTruthy();
        fireEvent.click(screen.getByRole('switch', { name: /Harga diketik kasir/ }));
        Lanjut();
        expect(screen.queryByText('Isi harga jual. Tulis 0 bila gratis.')).toBeNull();
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.HargaTerbuka).toBe(true);
    });

    it('Apotek (§9.5): bagian golongan obat hanya untuk barang ber-batch; OWA untuk obat keras; HNA + margin mengisi harga', () => {
        render(<HalamanFormProduk {...PropsForm()} />);
        IsiNama('Parasetamol');
        Lanjut();
        Lanjut();
        expect(screen.queryByText('Golongan obat (apotek)')).toBeNull();
        cleanup();

        render(
            <HalamanFormProduk
                {...PropsForm({
                    Produk: { ...produkBaru, Pelacakan: 'Batch', GolonganObat: 'Keras', ObatWajibApotek: false },
                })}
            />,
        );
        IsiNama('Asam Mefenamat 500 mg Tablet');
        Lanjut();
        Lanjut();
        expect(screen.getByText('Golongan obat (apotek)')).toBeTruthy();
        expect(screen.getByText(/Wajib resep dokter dan hanya bisa dijual apoteker/)).toBeTruthy();
        fireEvent.click(screen.getByRole('checkbox', { name: /Obat Wajib Apotek/ }));
        expect(screen.getByText(/Dijual tanpa resep oleh apoteker/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Isi harga dasar' }));
        fireEvent.change(screen.getByLabelText(/HNA per/), { target: { value: '650' } });
        fireEvent.change(screen.getByLabelText('Margin (%)'), { target: { value: '25' } });
        fireEvent.click(screen.getByRole('button', { name: 'Pakai sebagai harga' }));
        Lanjut();
        Lanjut();
        fireEvent.click(screen.getByRole('button', { name: 'Simpan produk' }));

        const body = kirimanForm[0]?.data as FormProduk;
        expect(body.GolonganObat).toBe('Keras');
        expect(body.ObatWajibApotek).toBe(true);
        expect(body.Satuan[0]?.HargaAwal[0]?.Harga).toBe('800.00');
    });
});
