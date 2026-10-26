import { afterEach, describe, expect, it } from 'vitest';

import {
    AlihkanPilihan,
    BacaKeranjang,
    BacaRiwayatPesanan,
    BATAS_BARIS,
    CatatRiwayatPesanan,
    HitungJumlahItem,
    PeriksaPilihan,
    SimpanKeranjang,
    TambahKeKeranjang,
    UbahJumlah,
    type BarisKeranjang,
    type KelompokPilihanMenu,
} from '@/Fitur/PesanSendiri/Keranjang';

const gula: KelompokPilihanMenu = {
    Uuid: 'K-GULA',
    Nama: 'Level Gula',
    MinimalPilih: 1,
    MaksimalPilih: 1,
    Pilihan: [
        { Uuid: 'P-NORMAL', Nama: 'Normal', Harga: '0.00' },
        { Uuid: 'P-SEDIKIT', Nama: 'Sedikit gula', Harga: '0.00' },
    ],
};
const topping: KelompokPilihanMenu = {
    Uuid: 'K-TOPPING',
    Nama: 'Topping',
    MinimalPilih: 0,
    MaksimalPilih: 2,
    Pilihan: [
        { Uuid: 'P-BOBA', Nama: 'Boba', Harga: '5000.00' },
        { Uuid: 'P-KEJU', Nama: 'Keju', Harga: '6000.00' },
        { Uuid: 'P-JELI', Nama: 'Jeli', Harga: '4000.00' },
    ],
};

function Baris(uuid: string, pilihan: string[] = [], catatan = '', jumlah = 1): BarisKeranjang {
    return { Uuid: uuid, UuidProduk: 'KOPI', Jumlah: jumlah, Pilihan: pilihan, Catatan: catatan };
}

describe('Keranjang pesan sendiri (F-17)', () => {
    afterEach(() => window.localStorage.clear());

    it('menggabung produk & pilihan sama tanpa catatan. Catatan berbeda jadi baris baru. Jumlah maks. 50', () => {
        let keranjang = TambahKeKeranjang([], Baris('A', ['P-NORMAL', 'P-BOBA']));
        keranjang = TambahKeKeranjang(keranjang, Baris('B', ['P-BOBA', 'P-NORMAL'], '', 49));
        expect(keranjang).toHaveLength(1);
        expect(keranjang[0]?.Jumlah).toBe(50);

        keranjang = TambahKeKeranjang(keranjang, Baris('C', ['P-NORMAL', 'P-BOBA'], 'Es dipisah'));
        keranjang = TambahKeKeranjang(keranjang, Baris('D', ['P-SEDIKIT']));
        expect(keranjang.map((b) => b.Uuid)).toEqual(['A', 'C', 'D']);
        expect(HitungJumlahItem(keranjang)).toBe(52);

        keranjang = UbahJumlah(keranjang, 'C', 3);
        keranjang = UbahJumlah(keranjang, 'D', 0);
        expect(keranjang.map((b) => [b.Uuid, b.Jumlah])).toEqual([
            ['A', 50],
            ['C', 3],
        ]);
    });

    it(`paling banyak ${String(BATAS_BARIS)} baris`, () => {
        let keranjang: BarisKeranjang[] = [];

        for (let i = 0; i < BATAS_BARIS + 3; i++) {
            keranjang = TambahKeKeranjang(keranjang, Baris(`B${String(i)}`, [], `catatan ${String(i)}`));
        }

        expect(keranjang).toHaveLength(BATAS_BARIS);
    });

    it('pilihan: wajib, maksimal, dan radio untuk kelompok maksimal 1', () => {
        expect(PeriksaPilihan([gula, topping], [])).toBe('Pilih Level Gula dulu.');
        expect(PeriksaPilihan([gula, topping], ['P-NORMAL', 'P-BOBA', 'P-KEJU'])).toBeNull();
        expect(PeriksaPilihan([gula, topping], ['P-NORMAL', 'P-BOBA', 'P-KEJU', 'P-JELI'])).toBe(
            'Pilih paling banyak 2 Topping.',
        );

        expect(AlihkanPilihan(gula, ['P-NORMAL', 'P-BOBA'], 'P-SEDIKIT')).toEqual(['P-BOBA', 'P-SEDIKIT']);
        expect(AlihkanPilihan(topping, ['P-BOBA', 'P-KEJU'], 'P-JELI')).toEqual(['P-BOBA', 'P-KEJU']);
        expect(AlihkanPilihan(topping, ['P-BOBA', 'P-KEJU'], 'P-KEJU')).toEqual(['P-BOBA']);
    });

    it('disimpan per token meja. Isi rusak diabaikan. Riwayat pesanan terbaru di depan (maks. 5)', () => {
        SimpanKeranjang('TOKEN-7', [Baris('A', ['P-NORMAL'], '', 2)]);
        expect(BacaKeranjang('TOKEN-7')).toEqual([Baris('A', ['P-NORMAL'], '', 2)]);
        expect(BacaKeranjang('TOKEN-9')).toEqual([]);

        window.localStorage.setItem('pesan-sendiri:TOKEN-7:keranjang', '[{"Uuid":"X","Jumlah":1.5},"rusak"]');
        expect(BacaKeranjang('TOKEN-7')).toEqual([]);
        window.localStorage.setItem('pesan-sendiri:TOKEN-7:keranjang', '{bukan json');
        expect(BacaKeranjang('TOKEN-7')).toEqual([]);

        ['U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'U2'].forEach((u) => CatatRiwayatPesanan('TOKEN-7', u));
        expect(BacaRiwayatPesanan('TOKEN-7')).toEqual(['U2', 'U6', 'U5', 'U4', 'U3']);
    });
});
