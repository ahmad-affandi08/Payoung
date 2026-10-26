import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { BukaPilihan } from '@/Pengujian/InteraksiPilihan';
import type { BarisHarga } from '@/Tipe/Katalog';

import { AmbilGalatBerawalan, HitungKombinasiVarian, PindahkanItem } from './BantuanKatalog';
import { TahanBilaBarcodeTertunda } from './BantuanKatalog';
import BidangBarcode from './BidangBarcode';
import BidangJumlah from './BidangJumlah';
import PemilihProduk, { BuatUrlCariProduk } from './PemilihProduk';
import TabelHargaBertingkat, { PeriksaBarisHarga, RingkasBarisHarga, UrutkanBarisHarga } from './TabelHargaBertingkat';
import { RenderUji } from './TiruanInertia';

vi.mock('@inertiajs/react', async () => (await import('./TiruanInertia')).TiruanInertia);

function JumlahUji({
    awal = '',
    desimal,
    saatBerubah,
}: {
    awal?: string;
    desimal?: number;
    saatBerubah?: (n: string) => void;
}) {
    const [nilai, AturNilai] = useState(awal);

    return (
        <BidangJumlah
            label="Jumlah"
            nilai={nilai}
            {...(desimal === undefined ? {} : { desimal })}
            akhiran="kg"
            saatBerubah={(baru) => {
                AturNilai(baru);
                saatBerubah?.(baru);
            }}
        />
    );
}

describe('BidangJumlah (kuantitas string, maks 4 desimal)', () => {
    afterEach(() => cleanup());

    it('menampilkan format Indonesia rata kanan dan mengirim string desimal polos', () => {
        const SaatBerubah = vi.fn();
        render(<JumlahUji awal="1250.5000" saatBerubah={SaatBerubah} />);
        const input = screen.getByLabelText<HTMLInputElement>('Jumlah');

        expect(input.value).toBe('1.250,5');
        expect(input.className).toContain('text-right');
        expect(input.className).toContain('tabular-nums');

        fireEvent.change(input, { target: { value: '0,' } });
        expect(input.value).toBe('0,');
        expect(SaatBerubah).toHaveBeenLastCalledWith('0');

        fireEvent.change(input, { target: { value: '0,25' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('0.25');

        fireEvent.change(input, { target: { value: '0,12345' } });
        expect(SaatBerubah).toHaveBeenLastCalledWith('0.25');

        fireEvent.blur(input);
        expect(input.value).toBe('0,25');
    });

    it('satuan tanpa desimal menolak koma', () => {
        const SaatBerubah = vi.fn();
        render(<JumlahUji desimal={0} saatBerubah={SaatBerubah} />);
        const input = screen.getByLabelText<HTMLInputElement>('Jumlah');

        fireEvent.change(input, { target: { value: '12,5' } });
        expect(SaatBerubah).not.toHaveBeenCalled();
        expect(input.getAttribute('inputmode')).toBe('numeric');
    });
});

function BarcodeUji({ awal = [] as string[] }) {
    const [nilai, AturNilai] = useState(awal);

    return <BidangBarcode label="Barcode pcs" nilai={nilai} saatBerubah={AturNilai} barcodeLain={['8990001']} />;
}

describe('BidangBarcode (BR-03.1)', () => {
    afterEach(() => cleanup());

    it('Enter dari pemindai menambah barcode tanpa mengirim formulir; duplikat ditolak', () => {
        render(<BarcodeUji awal={['8991234567890']} />);
        const input = screen.getByLabelText<HTMLInputElement>('Barcode pcs');

        fireEvent.change(input, { target: { value: '8997000111222' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(screen.getByText('8997000111222').className).toContain('font-mono');
        expect(input.value).toBe('');

        fireEvent.change(input, { target: { value: '8990001' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(screen.getByText('Barcode 8990001 sudah dipakai di produk ini.')).toBeTruthy();

        fireEvent.change(input, { target: { value: 'ab' } });
        fireEvent.click(screen.getByRole('button', { name: 'Tambah barcode' }));
        expect(screen.getByText('Barcode 3–64 karakter: huruf, angka, titik, atau strip.')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Hapus barcode 8991234567890' }));
        expect(screen.queryByText('8991234567890')).toBeNull();
    });
});

describe('BidangBarcode: ketikan yang ditinggalkan tidak hilang diam-diam', () => {
    afterEach(() => cleanup());

    it('meninggalkan isian menambahkan barcode yang valid; tombol Tambah sesudahnya tidak menggandakan', () => {
        render(<BarcodeUji />);
        const input = screen.getByLabelText<HTMLInputElement>('Barcode pcs');

        fireEvent.change(input, { target: { value: '8997000111222' } });
        expect(input.getAttribute('data-barcode-tertunda')).toBe('true');

        fireEvent.blur(input);
        expect(screen.getAllByText('8997000111222')).toHaveLength(1);
        expect(input.value).toBe('');
        expect(input.getAttribute('data-barcode-tertunda')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Tambah barcode' }));
        expect(screen.getAllByText('8997000111222')).toHaveLength(1);
    });

    it('ketikan tidak valid atau duplikat tetap di isian dengan pesannya, dan Lanjut/Simpan ditahan', () => {
        render(
            <div>
                <BarcodeUji />
            </div>,
        );
        const input = screen.getByLabelText<HTMLInputElement>('Barcode pcs');

        fireEvent.change(input, { target: { value: '8990001' } });
        fireEvent.blur(input);
        expect(input.value).toBe('8990001');
        expect(screen.getByText('Barcode 8990001 sudah dipakai di produk ini.')).toBeTruthy();
        expect(input.getAttribute('data-barcode-tertunda')).toBe('true');

        expect(TahanBilaBarcodeTertunda(document.body)).toBe(true);
        expect(document.activeElement).toBe(input);

        fireEvent.change(input, { target: { value: '' } });
        expect(TahanBilaBarcodeTertunda(document.body)).toBe(false);
    });

    it('penahan menjelaskan apa yang harus dilakukan bila ketikan valid belum sempat ditambahkan', () => {
        render(<BarcodeUji />);
        const input = screen.getByLabelText<HTMLInputElement>('Barcode pcs');

        fireEvent.change(input, { target: { value: '8997000111222' } });
        act(() => {
            expect(TahanBilaBarcodeTertunda(document.body)).toBe(true);
        });
        expect(screen.getByText('Barcode ini belum ditambahkan. Tekan Enter atau tombol Tambah barcode.')).toBeTruthy();
    });
});

describe('TabelHargaBertingkat (harga dasar + bertingkat, string saja)', () => {
    afterEach(() => cleanup());

    it('memvalidasi baris tanpa number: dasar wajib, duplikat, pecahan pada satuan bulat', () => {
        const baris: BarisHarga[] = [
            { JumlahMinimum: '12', Harga: '4500' },
            { JumlahMinimum: '12.0000', Harga: '' },
            { JumlahMinimum: '2.5', Harga: '4000' },
            { JumlahMinimum: '0', Harga: '1' },
        ];
        const { perBaris, umum } = PeriksaBarisHarga(baris, { wajibDasar: true, bolehDesimal: false });

        expect(umum).toBe('Harga jual untuk jumlah minimum 1 wajib diisi.');
        expect(perBaris[0]).toBeUndefined();
        expect(perBaris[1]).toEqual({
            JumlahMinimum: 'Jumlah minimum ini sudah ada di baris lain.',
            Harga: 'Isi harga. Tulis 0 bila gratis.',
        });
        expect(perBaris[2]?.JumlahMinimum).toBe('Satuan ini tidak boleh pecahan. Isi bilangan bulat.');
        expect(perBaris[3]?.JumlahMinimum).toBe('Isi jumlah minimum lebih dari 0.');
        expect(
            PeriksaBarisHarga([{ JumlahMinimum: '12', Harga: '0' }], { wajibDasar: false, bolehDesimal: false }),
        ).toEqual({ perBaris: {}, umum: null });
    });

    it('mengurutkan dengan perbandingan desimal string (9 < 12 < 100)', () => {
        const hasil = UrutkanBarisHarga([
            { JumlahMinimum: '100', Harga: '1' },
            { JumlahMinimum: '9', Harga: '1' },
            { JumlahMinimum: '', Harga: '1' },
            { JumlahMinimum: '12', Harga: '1' },
        ]);

        expect(hasil.map((item) => item.JumlahMinimum)).toEqual(['9', '12', '100', '']);
        expect(RingkasBarisHarga({ JumlahMinimum: '12.0000', Harga: '4500.00' }, 'pcs')).toBe('12+ pcs: Rp 4.500');
    });

    it('baris dasar terkunci, tingkat baru bisa ditambah dan dihapus', () => {
        function Uji() {
            const [baris, AturBaris] = useState<BarisHarga[]>([{ JumlahMinimum: '1.0000', Harga: '5000.00' }]);

            return (
                <TabelHargaBertingkat
                    judul="Harga per pcs"
                    baris={baris}
                    saatBerubah={AturBaris}
                    simbolSatuan="pcs"
                    bolehDesimal={false}
                    wajibDasar
                />
            );
        }

        render(<Uji />);

        expect(screen.getByLabelText<HTMLInputElement>('Mulai jumlah baris 1').disabled).toBe(true);
        expect(screen.getByLabelText<HTMLInputElement>('Harga baris 1').value).toBe('5.000');

        fireEvent.click(screen.getByRole('button', { name: 'Tambah harga bertingkat' }));
        fireEvent.change(screen.getByLabelText('Mulai jumlah baris 2'), { target: { value: '12' } });
        fireEvent.change(screen.getByLabelText('Harga baris 2'), { target: { value: '4.500' } });
        expect(screen.getByLabelText<HTMLInputElement>('Harga baris 2').value).toBe('4.500');

        fireEvent.click(screen.getByRole('button', { name: 'Hapus tingkat harga baris 2' }));
        expect(screen.queryByLabelText('Harga baris 2')).toBeNull();
    });

    it('tanpa baris: menjelaskan dengan bahasa biasa bahwa satuan itu belum bisa dipilih di kasir', () => {
        render(
            <TabelHargaBertingkat
                judul="Harga per dus"
                baris={[]}
                saatBerubah={vi.fn()}
                simbolSatuan="dus"
                bolehDesimal={false}
                wajibDasar
            />,
        );

        expect(
            screen.getByText(
                'Belum ada harga jual per dus. Selama kosong, dus belum bisa dipilih di kasir (masih bisa dipakai saat belanja stok).',
            ),
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Isi harga jual' })).toBeTruthy();
    });
});

describe('PemilihProduk (TanStack Query, KunciKueri.Produk.Cari)', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('membangun URL pencarian dengan jenis[]', () => {
        expect(BuatUrlCariProduk('susu uht', ['BahanBaku', 'Stok'])).toBe(
            '/kelola/produk/cari?kata=susu+uht&jenis%5B%5D=BahanBaku&jenis%5B%5D=Stok&batas=20',
        );
    });

    it('mencari, menampilkan hasil, dan memilih dengan keyboard; produk yang dikecualikan disembunyikan', async () => {
        const Ambil = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    Data: [
                        {
                            Uuid: 'P-SUSU',
                            Nama: 'Susu UHT 1 L',
                            Sku: 'PRD-000012',
                            Jenis: 'BahanBaku',
                            UuidSatuanDasar: 'SAT-ML',
                            Satuan: [
                                {
                                    Uuid: 'SAT-ML',
                                    Nama: 'Mililiter',
                                    Simbol: 'ml',
                                    BolehDesimal: true,
                                    KonversiKeDasar: '1.0000',
                                },
                            ],
                        },
                        {
                            Uuid: 'P-DIRI',
                            Nama: 'Susu diri sendiri',
                            Sku: null,
                            Jenis: 'Stok',
                            UuidSatuanDasar: 'SAT-PCS',
                            Satuan: [],
                        },
                    ],
                }),
        });
        vi.stubGlobal('fetch', Ambil);
        const SaatPilih = vi.fn();

        RenderUji(
            <PemilihProduk
                label="Cari bahan"
                jenis={['BahanBaku', 'Stok']}
                kecuali={['P-DIRI']}
                saatPilih={SaatPilih}
            />,
        );
        // Pola PilihanCari: tombol pemicu membuka popover, kotak carinya di dalam popover.
        BukaPilihan(screen.getByRole('combobox', { name: 'Cari bahan' }));
        const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
        fireEvent.change(cari, { target: { value: 'su' } });

        await waitFor(() => expect(screen.getByRole('option', { name: /Susu UHT 1 L/ })).toBeTruthy());
        expect(screen.queryByRole('option', { name: /diri sendiri/ })).toBeNull();
        // Membuka daftar memicu permintaan awal (kata kosong) lebih dulu, jadi URL ketikan ditunggu.
        await waitFor(() =>
            expect(Ambil.mock.calls.map((panggilan) => panggilan[0])).toContain(
                '/kelola/produk/cari?kata=su&jenis%5B%5D=BahanBaku&jenis%5B%5D=Stok&batas=20',
            ),
        );

        fireEvent.keyDown(cari, { key: 'Enter' });
        expect(SaatPilih).toHaveBeenCalledWith(expect.objectContaining({ Uuid: 'P-SUSU' }));
    });

    it('membuka bidang langsung menampilkan daftar produk tanpa perlu mengetik', async () => {
        const Ambil = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    Data: Array.from({ length: 20 }, (_, i) => ({
                        Uuid: `P-${String(i)}`,
                        Nama: `Kopi Arabika ${String(i)}`,
                        Sku: `SKU-${String(i)}`,
                        Jenis: 'Stok',
                        UuidSatuanDasar: 'SAT-PCS',
                        Satuan: [],
                    })),
                }),
        });
        vi.stubGlobal('fetch', Ambil);
        RenderUji(<PemilihProduk label="Cari bahan" jenis={['Stok']} saatPilih={vi.fn()} />);

        BukaPilihan(screen.getByRole('combobox', { name: 'Cari bahan' }));

        // Pengguna yang belum hafal nama/SKU/barcode tetap bisa melihat produk yang ada.
        await waitFor(() => expect(screen.getByRole('option', { name: /Kopi Arabika 0/ })).toBeTruthy());
        expect(Ambil.mock.calls[0]?.[0]).toBe('/kelola/produk/cari?kata=&jenis%5B%5D=Stok&batas=20');

        // Daftar awal dipotong server, jadi harus dikatakan supaya tidak dikira produknya cuma segitu.
        expect(screen.getByText('Menampilkan 20 produk pertama. Ketik untuk mencari yang lain.')).toBeTruthy();
    });

    it('daftar produk dibatasi tinggi layar yang tersedia dan tetap bisa digulir (regresi layar HP)', () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ Data: [] }) }));
        RenderUji(<PemilihProduk label="Cari bahan" jenis={['Stok']} saatPilih={vi.fn()} />);

        BukaPilihan(screen.getByRole('combobox', { name: 'Cari bahan' }));

        // Tanpa batas ini popover lebih tinggi dari layar HP, terpotong viewport, dan tidak ada yang bisa digulir.
        const isi = document.querySelector('[data-slot="popover-content"]');
        expect(isi?.className).toContain('max-h-(--radix-popover-content-available-height)');

        const daftar = document.querySelector('[data-slot="command-list"]');
        expect(daftar?.className).toContain('overflow-y-auto');
        expect(daftar?.className).toContain('flex-1');
    });

    it('masalah jaringan dan hasil kosong ditulis di area aria-live', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        RenderUji(<PemilihProduk label="Cari komponen" jenis={['Stok']} saatPilih={vi.fn()} />);
        BukaPilihan(screen.getByRole('combobox', { name: 'Cari komponen' }));
        const input = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');

        fireEvent.change(input, { target: { value: 'x' } });
        // Satu huruf pun sudah dicari; batas 2 huruf dihapus agar daftar bisa dibuka tanpa mengetik.
        expect(screen.queryByText('Ketik minimal 2 huruf.')).toBeNull();

        await act(async () => {
            fireEvent.change(input, { target: { value: 'xyz' } });
            await Promise.resolve();
        });
        await waitFor(() =>
            expect(screen.getByText('Pencarian gagal. Periksa koneksi lalu ketik ulang.')).toBeTruthy(),
        );
    });
});

describe('BantuanKatalog', () => {
    it('memetakan masalah server bersarang ke kunci relatif', () => {
        expect(
            AmbilGalatBerawalan(
                { 'Satuan.0.Barcode.1': 'Barcode sudah dipakai', 'Satuan.1.Harga': 'x', Sku: 'SKU sudah dipakai' },
                'Satuan.0',
            ),
        ).toEqual({ 'Barcode.1': 'Barcode sudah dipakai' });
    });

    it('menghitung kombinasi varian kartesius dan memindahkan urutan', () => {
        expect(
            HitungKombinasiVarian([
                { Nama: 'Ukuran', Nilai: ['M', 'L'] },
                { Nama: 'Warna', Nilai: ['Merah', 'Biru'] },
                { Nama: 'Kosong', Nilai: [] },
            ]),
        ).toEqual([
            ['M', 'Merah'],
            ['M', 'Biru'],
            ['L', 'Merah'],
            ['L', 'Biru'],
        ]);
        expect(HitungKombinasiVarian([])).toEqual([]);
        expect(PindahkanItem(['a', 'b', 'c'], 2, 0)).toEqual(['c', 'a', 'b']);
        expect(PindahkanItem(['a', 'b'], 0, 5)).toEqual(['a', 'b']);
    });
});
