import { describe, expect, it } from 'vitest';

import { BacaHargaTeks, UraiDaftarTempel } from './UraiDaftarTempel';

const kategori = [
    { Uuid: 'KAT-MINUM', Nama: 'Minuman' },
    { Uuid: 'KAT-MAKAN', Nama: 'Makanan' },
];

describe('UraiDaftarTempel (D-23 A: tempel daftar produk)', () => {
    it('harga teks Indonesia: titik ribuan, Rp, ,-, rb/k, desimal', () => {
        expect(BacaHargaTeks('15.000')).toBe('15000');
        expect(BacaHargaTeks('Rp1.250.000,-')).toBe('1250000');
        expect(BacaHargaTeks('15000')).toBe('15000');
        expect(BacaHargaTeks('12', 'rb')).toBe('12000');
        expect(BacaHargaTeks('2,5', 'k')).toBe('2500');
        expect(BacaHargaTeks('4200,50')).toBe('4200.50');
        expect(BacaHargaTeks('0')).toBe('0');
        expect(BacaHargaTeks('lima ribu')).toBeNull();
    });

    it('salinan Excel (Tab) dengan judul kolom dan kategori. Kategori tak dikenal dilaporkan', () => {
        const hasil = UraiDaftarTempel(
            'Nama\tHarga\tKategori\nKopi Susu Gula Aren\t18.000\tminuman\nNasi Goreng Kampung\t25000\tMakanan\nDonat\t8000\tKue',
            kategori,
        );

        expect(hasil.Baris).toEqual([
            { Nama: 'Kopi Susu Gula Aren', Harga: '18000', Kategori: 'KAT-MINUM' },
            { Nama: 'Nasi Goreng Kampung', Harga: '25000', Kategori: 'KAT-MAKAN' },
            { Nama: 'Donat', Harga: '8000', Kategori: '' },
        ]);
        expect(hasil.KategoriTakDikenal).toEqual(['Kue']);
        expect(hasil.Dilewati).toEqual([]);
    });

    it('pesan WhatsApp bebas: nomor/poin di depan dibuang, harga di akhir. Baris tanpa harga dilewati', () => {
        const hasil = UraiDaftarTempel(
            '1. Es Teh Manis - Rp5.000,-\n• Roti Bakar Coklat Keju: 12rb\n\nMie Ayam Bakso 2,5k\nMenu spesial hari ini\nKopi 3 in 1 = 4.000',
            kategori,
        );

        expect(hasil.Baris).toEqual([
            { Nama: 'Es Teh Manis', Harga: '5000', Kategori: '' },
            { Nama: 'Roti Bakar Coklat Keju', Harga: '12000', Kategori: '' },
            { Nama: 'Mie Ayam Bakso', Harga: '2500', Kategori: '' },
            { Nama: 'Kopi 3 in 1', Harga: '4000', Kategori: '' },
        ]);
        expect(hasil.Dilewati).toEqual([5]);
    });
});
