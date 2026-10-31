import { beforeEach, describe, expect, it } from 'vitest';

import { BacaDraf, BuatKunciDraf, HapusDraf, MasaBerlakuDrafMs, TulisDraf } from '@/Pustaka/DrafLokal';

/* Draf isian form di peramban: tulis, baca, kedaluwarsa 7 hari, dan tahan terhadap penyimpanan rusak atau terblokir. */
describe('DrafLokal', () => {
    const kunci = BuatKunciDraf('toko-roti', 'pengguna-1', 'transfer-stok');

    beforeEach(() => window.localStorage.clear());

    it('kunci memisahkan tenant, pengguna, dan nama form', () => {
        expect(kunci).toBe('payoung:draf:toko-roti:pengguna-1:transfer-stok');
        expect(BuatKunciDraf('toko-lain', 'pengguna-1', 'transfer-stok')).not.toBe(kunci);
    });

    it('menulis lalu membaca isi draf beserta waktu simpan', () => {
        const waktu = new Date('2026-10-31T03:00:00Z');

        TulisDraf(kunci, { catatan: 'Kirim Senin', daftar: [{ Jumlah: '5' }] }, waktu);

        expect(BacaDraf<{ catatan: string }>(kunci, waktu)).toEqual({
            Versi: 1,
            Disimpan: '2026-10-31T03:00:00.000Z',
            Data: { catatan: 'Kirim Senin', daftar: [{ Jumlah: '5' }] },
        });
    });

    it('draf lebih tua dari tujuh hari dibuang', () => {
        const waktu = new Date('2026-10-01T00:00:00Z');

        TulisDraf(kunci, { a: 1 }, waktu);

        expect(BacaDraf(kunci, new Date(waktu.getTime() + MasaBerlakuDrafMs - 1000))).not.toBeNull();
        expect(BacaDraf(kunci, new Date(waktu.getTime() + MasaBerlakuDrafMs + 1000))).toBeNull();
        expect(window.localStorage.getItem(kunci)).toBeNull();
    });

    it('isi yang rusak atau berbentuk lain diabaikan dan dibersihkan', () => {
        window.localStorage.setItem(kunci, '{bukan json');
        expect(BacaDraf(kunci)).toBeNull();

        window.localStorage.setItem(kunci, JSON.stringify({ Versi: 2, Disimpan: '2026-10-31T00:00:00Z', Data: {} }));
        expect(BacaDraf(kunci)).toBeNull();
        expect(window.localStorage.getItem(kunci)).toBeNull();
    });

    it('hapus menghilangkan draf', () => {
        TulisDraf(kunci, { a: 1 });
        HapusDraf(kunci);

        expect(BacaDraf(kunci)).toBeNull();
    });
});
