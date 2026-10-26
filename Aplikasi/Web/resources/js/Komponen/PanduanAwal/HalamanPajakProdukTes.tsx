import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanMetodePembayaranPanduan from '@/Halaman/Kelola/PanduanAwal/MetodePembayaran';
import HalamanPajak from '@/Halaman/Kelola/PanduanAwal/Pajak';
import HalamanProdukPanduan from '@/Halaman/Kelola/PanduanAwal/Produk';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { PropsPajak, PropsProdukPanduan } from '@/Tipe/PanduanAwal';

import { BuatProgresContoh } from './DataUjiPanduan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function BuatPropsPajak(ubah: Partial<PropsPajak> = {}): PropsPajak {
    return {
        Progres: BuatProgresContoh({ ProfilUsaha: 'Selesai', Sektor: 'Selesai' }),
        Pkp: false,
        Kota: { Kode: '3404', Nama: 'Kab. Sleman' },
        Nilai: { PungutPbjt: true, BiayaLayananAktif: false, PersenBiayaLayanan: '0', HargaTermasukPajak: true },
        SudahDikonfirmasi: false,
        TarifPbjt: null,
        TarifPpn: null,
        KelompokPajak: [],
        AlasanUsulan: [],
        ...ubah,
    };
}

function BuatPropsProduk(ubah: Partial<PropsProdukPanduan> = {}): PropsProdukPanduan {
    return {
        Progres: BuatProgresContoh({ ProfilUsaha: 'Selesai', Sektor: 'Selesai', Pajak: 'Selesai' }),
        AdaTemplate: true,
        ProdukContoh: [
            { Nama: 'Kopi susu', NamaKategori: 'Kopi', Harga: '18000.00', KodeSatuan: 'C62', SudahAda: false },
            { Nama: 'Teh manis', NamaKategori: 'Non-kopi', Harga: '8000.00', KodeSatuan: 'C62', SudahAda: false },
            { Nama: 'Americano', NamaKategori: 'Kopi', Harga: '15000.00', KodeSatuan: 'C62', SudahAda: true },
        ],
        Kategori: [],
        Produk: [],
        JumlahProduk: 0,
        BatasSku: { Batas: null, Terpakai: 1 },
        ...ubah,
    };
}

describe('Langkah 3 Pajak (F-01): pilihan harga termasuk pajak memakai RadioGroup', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/panduan-awal/pajak'));
    afterEach(() => cleanup());

    it('radio "Belum termasuk pajak" mengubah HargaTermasukPajak yang dikirim', () => {
        RenderUji(<HalamanPajak {...BuatPropsPajak()} />);

        const termasuk = screen.getByRole('radio', { name: /Sudah termasuk pajak/ });
        const belum = screen.getByRole('radio', { name: /Belum termasuk pajak/ });
        expect(termasuk.getAttribute('aria-checked')).toBe('true');
        expect(screen.getByRole('radiogroup', { name: 'Harga jual di menu' })).toBeTruthy();

        fireEvent.click(belum);
        expect(belum.getAttribute('aria-checked')).toBe('true');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pengaturan pajak' }));

        expect(kirimanForm[0]).toEqual({
            metode: 'post',
            url: '/kelola/panduan-awal/pajak',
            data: { PungutPbjt: true, BiayaLayananAktif: false, PersenBiayaLayanan: '0', HargaTermasukPajak: false },
        });
    });
});

describe('Langkah 4 Produk (F-01): centang produk contoh memakai Checkbox', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/panduan-awal/produk'));
    afterEach(() => cleanup());

    it('"Pilih semua" menjadi indeterminate saat sebagian dipilih. Produk yang sudah ada tidak bisa dicentang', () => {
        RenderUji(<HalamanProdukPanduan {...BuatPropsProduk()} />);

        const semua = screen.getByRole('checkbox', { name: 'Pilih semua' });
        expect(semua.getAttribute('aria-checked')).toBe('true');
        expect(screen.queryByRole('checkbox', { name: 'Pilih Americano' })).toBeNull();
        expect(screen.getByText('2 dari 2 produk contoh dipilih.')).toBeTruthy();

        fireEvent.click(screen.getByRole('checkbox', { name: 'Pilih Teh manis' }));
        expect(semua.getAttribute('aria-checked')).toBe('mixed');
        expect(screen.getByText('1 dari 2 produk contoh dipilih.')).toBeTruthy();

        fireEvent.click(semua);
        expect(semua.getAttribute('aria-checked')).toBe('true');
        fireEvent.click(semua);
        expect(semua.getAttribute('aria-checked')).toBe('false');
        expect((screen.getByRole('button', { name: 'Tambahkan produk contoh' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
    });

    it('kuota paket terlampaui: peringatan tampil dan tombol tambah nonaktif', () => {
        RenderUji(<HalamanProdukPanduan {...BuatPropsProduk({ BatasSku: { Batas: 2, Terpakai: 1 } })} />);

        expect(screen.getByText(/Sisa kuota paket 1 SKU/)).toBeTruthy();
        expect((screen.getByRole('button', { name: 'Tambahkan produk contoh' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
    });
});

describe('Langkah 4 Produk (D-23 A): tempel daftar dari Excel/WhatsApp', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/panduan-awal/produk'));
    afterEach(() => cleanup());

    it('tempelan diurai ke baris, baris tak terbaca dilaporkan, lalu dikirim sebagai Produk', () => {
        RenderUji(<HalamanProdukPanduan {...BuatPropsProduk({ Kategori: [{ Uuid: 'KAT-KOPI', Nama: 'Kopi' }] })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Tempel daftar dari Excel atau WhatsApp' }));
        fireEvent.change(screen.getByLabelText('Daftar produk'), {
            target: { value: 'Kopi Susu Aren\t18.000\tKopi\nEs Teh Manis 5rb\nMenu baru' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Masukkan ke daftar' }));

        expect(screen.getByText(/2 produk dimasukkan ke daftar\./)).toBeTruthy();
        expect(screen.getByText(/Baris 3 dilewati/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Tambah produk' }));

        expect(kirimanForm[0]?.url).toBe('/kelola/panduan-awal/produk');
        expect(kirimanForm[0]?.data).toEqual({
            Produk: [
                { Nama: 'Kopi Susu Aren', Harga: '18000', Kategori: 'KAT-KOPI' },
                { Nama: 'Es Teh Manis', Harga: '5000', Kategori: '' },
            ],
        });
    });
});

describe('Langkah 5 Metode pembayaran (F-01): daftar TabelData & nonaktifkan lewat konfirmasi', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/panduan-awal/metode-pembayaran'));
    afterEach(() => cleanup());

    it('tunai selalu tersedia; QRIS dinonaktifkan setelah konfirmasi', () => {
        const dasar = {
            NamaBank: null,
            NomorRekening: null,
            NamaPemilikRekening: null,
            PersenBiaya: '0',
            TautanGambarQris: null,
            Aktif: true,
        };
        RenderUji(
            <HalamanMetodePembayaranPanduan
                Progres={BuatProgresContoh({ ProfilUsaha: 'Selesai', Sektor: 'Selesai', Pajak: 'Selesai' })}
                MetodePembayaran={[
                    { ...dasar, Uuid: 'M1', Jenis: 'Tunai', LabelJenis: 'Tunai', Nama: 'Tunai', Wajib: true },
                    {
                        ...dasar,
                        Uuid: 'M2',
                        Jenis: 'QrisStatis',
                        LabelJenis: 'QRIS statis',
                        Nama: 'QRIS toko',
                        PersenBiaya: '0.7',
                        Wajib: false,
                    },
                ]}
                JenisTersedia={[]}
                Bank={[]}
                BatasGambarQris={{ UkuranMaksimalKb: 2048, Ekstensi: ['png'] }}
                GerbangPembayaran={{ Aktif: false, Penyedia: null }}
            />,
        );

        expect(screen.getByText('Selalu tersedia')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan QRIS toko' }));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan QRIS toko' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/panduan-awal/metode-pembayaran/M2/nonaktifkan',
            {},
            expect.anything(),
        );
    });

    it('F-08 QRIS dinamis: petunjuk gerbang aktif/belum aktif tanpa isian gambar QRIS', () => {
        const props = {
            Progres: BuatProgresContoh({ ProfilUsaha: 'Selesai', Sektor: 'Selesai', Pajak: 'Selesai' }),
            MetodePembayaran: [],
            JenisTersedia: [{ Nilai: 'QrisDinamis', Label: 'QRIS dinamis' }],
            Bank: [],
            BatasGambarQris: { UkuranMaksimalKb: 2048, Ekstensi: ['png'] },
        };
        const { unmount: Lepas } = RenderUji(
            <HalamanMetodePembayaranPanduan {...props} GerbangPembayaran={{ Aktif: false, Penyedia: null }} />,
        );

        expect(screen.getByText('Gerbang pembayaran belum aktif')).toBeTruthy();
        expect(screen.queryByText('Gambar QRIS')).toBeNull();
        Lepas();

        RenderUji(<HalamanMetodePembayaranPanduan {...props} GerbangPembayaran={{ Aktif: true, Penyedia: 'DOKU' }} />);
        expect(screen.getByText('Gerbang pembayaran aktif: DOKU')).toBeTruthy();
    });
    it('F-08: batas hari menunggu pencairan tampil hanya untuk metode berpencairan; Atur mengirim angka (kosong = bawaan)', () => {
        const dasar = {
            NamaBank: null,
            NomorRekening: null,
            NamaPemilikRekening: null,
            PersenBiaya: '0',
            TautanGambarQris: null,
            Aktif: true,
        };
        RenderUji(
            <HalamanMetodePembayaranPanduan
                Progres={BuatProgresContoh({ ProfilUsaha: 'Selesai', Sektor: 'Selesai', Pajak: 'Selesai' })}
                MetodePembayaran={[
                    { ...dasar, Uuid: 'M1', Jenis: 'Tunai', LabelJenis: 'Tunai', Nama: 'Tunai', Wajib: true },
                    {
                        ...dasar,
                        Uuid: 'M2',
                        Jenis: 'Marketplace',
                        LabelJenis: 'Platform ojol / marketplace',
                        Nama: 'GoFood',
                        Wajib: false,
                        BatasHariMenunggu: 10,
                        BatasHariKustom: null,
                    },
                ]}
                JenisTersedia={[]}
                Bank={[]}
                BatasGambarQris={{ UkuranMaksimalKb: 2048, Ekstensi: ['png'] }}
                GerbangPembayaran={{ Aktif: false, Penyedia: null }}
            />,
        );

        expect(screen.getAllByText(/Wajar menunggu pencairan/)).toHaveLength(1);
        expect(screen.getByText(/Wajar menunggu pencairan 10 hari \(bawaan\)/)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Atur' }));
        fireEvent.change(screen.getByLabelText('Batas menunggu (hari)'), { target: { value: '14' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan batas' }));
        expect(kirimanForm.at(-1)).toMatchObject({
            metode: 'post',
            url: '/kelola/panduan-awal/metode-pembayaran/M2/batas-hari-menunggu',
            data: { BatasHariMenunggu: '14' },
        });
    });

    it('X8 platform ojol: isian Platform, petunjuk pencatatan manual, komisi sampai 40 persen, kanal tampil di tabel', () => {
        RenderUji(
            <HalamanMetodePembayaranPanduan
                Progres={BuatProgresContoh({ ProfilUsaha: 'Selesai', Sektor: 'Selesai', Pajak: 'Selesai' })}
                MetodePembayaran={[
                    {
                        Uuid: 'M3',
                        Jenis: 'Marketplace',
                        LabelJenis: 'Platform ojol / marketplace',
                        Nama: 'GoFood',
                        NamaBank: null,
                        NomorRekening: null,
                        NamaPemilikRekening: null,
                        Kanal: 'GoFood',
                        LabelKanal: 'GoFood',
                        PersenBiaya: '20',
                        TautanGambarQris: null,
                        Aktif: true,
                        Wajib: false,
                    },
                ]}
                JenisTersedia={[{ Nilai: 'Marketplace', Label: 'Platform ojol / marketplace' }]}
                KanalPlatform={[
                    { Nilai: 'GoFood', Label: 'GoFood' },
                    { Nilai: 'GrabFood', Label: 'GrabFood' },
                ]}
                PersenBiayaMaksimal={{ Umum: '10', Platform: '40' }}
                Bank={[]}
                BatasGambarQris={{ UkuranMaksimalKb: 2048, Ekstensi: ['png'] }}
                GerbangPembayaran={{ Aktif: false, Penyedia: null }}
            />,
        );

        expect(screen.getByText('Pesanan GoFood')).toBeTruthy();
        expect(screen.getByText('Pesanan ojol dicatat manual di kasir')).toBeTruthy();
        expect(screen.getByText('Platform')).toBeTruthy();
        expect(screen.getByText('Komisi platform (persen, opsional)')).toBeTruthy();
        expect(screen.getByText(/0 sampai 40 persen/)).toBeTruthy();
    });
});
