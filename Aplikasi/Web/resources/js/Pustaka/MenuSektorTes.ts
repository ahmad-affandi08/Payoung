import { describe, expect, it } from 'vitest';

import { daftarPengaturan } from '@/Pustaka/DaftarPengaturan';
import { BandingkanMenuSektor, KumpulkanButirBersektor, TentukanMenuSektor } from '@/Pustaka/MenuSektor';
import { daftarMenu } from '@/TataLetak/TataLetakAplikasi';

const butir = KumpulkanButirBersektor(
    daftarMenu,
    daftarPengaturan.flatMap((grup) => grup.butir),
);
const Label = (daftar: { Label: string }[]) => daftar.map((satu) => satu.Label).sort();

describe('Pustaka/MenuSektor (audit #33: ringkasan menu menurut jenis usaha)', () => {
    it('mengumpulkan butir menu samping & Pengaturan yang bergantung sektor, tanpa duplikat', () => {
        const kunci = butir.map((satu) => `${satu.Lokasi}|${satu.Label}`);

        expect(new Set(kunci).size).toBe(kunci.length);
        expect(butir.some((satu) => satu.Label === 'Produksi' && satu.Lokasi === 'Menu samping')).toBe(true);
        expect(butir.some((satu) => satu.Label === 'Laporan apotek' && satu.Lokasi === 'Pengaturan')).toBe(true);
        expect(butir.some((satu) => satu.Label === 'Stasiun dapur' && satu.Lokasi === 'Pengaturan')).toBe(true);
        // Butir umum (tanpa sektor) tidak ikut dihitung.
        expect(butir.some((satu) => satu.Label === 'Produk')).toBe(false);
    });

    it('sektor kosong (belum diketahui) menampilkan semua, sama dengan sidebar', () => {
        const hasil = TentukanMenuSektor(butir, []);

        expect(hasil.Disembunyikan).toEqual([]);
        expect(hasil.Tampil.length).toBe(butir.length);
    });

    it('FnB ke Apotek: Reservasi & Produksi hilang, Stasiun dapur hilang, Laporan apotek muncul', () => {
        const hasil = BandingkanMenuSektor(butir, ['FNB-CAF'], ['RTL-PHR']);

        expect(Label(hasil.Hilang)).toEqual(expect.arrayContaining(['Produksi', 'Stasiun dapur']));
        expect(Label(hasil.Hilang).some((nama) => nama.startsWith('Reservasi'))).toBe(true);
        expect(Label(hasil.Muncul)).toEqual(['Laporan apotek']);
        // Bahan terbuang dipakai FnB dan apotek: tidak berubah.
        expect(Label(hasil.Hilang)).not.toContain('Bahan terbuang');
        expect(Label(hasil.Muncul)).not.toContain('Bahan terbuang');
    });

    it('sektor yang sama tidak menghasilkan perubahan; sektor campuran menggabungkan menu keduanya', () => {
        const sama = BandingkanMenuSektor(butir, ['FNB-CAF'], ['FNB-CAF']);

        expect(sama).toEqual({ Muncul: [], Hilang: [] });

        const campuran = TentukanMenuSektor(butir, ['FNB-CAF', 'RTL-PHR']);

        expect(Label(campuran.Tampil)).toEqual(expect.arrayContaining(['Produksi', 'Laporan apotek']));
    });

    it('sektor Perusahaan (BIZ) menyembunyikan menu kasir lewat kecualiSektor', () => {
        const hasil = TentukanMenuSektor(butir, ['BIZ-JSA']);

        expect(Label(hasil.Disembunyikan)).toEqual(expect.arrayContaining(['Promo', 'Penjualan & kasir']));
    });
});
