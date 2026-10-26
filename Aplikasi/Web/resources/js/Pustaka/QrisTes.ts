import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { HitungCrc16, PeriksaQris, UraiQris } from '@/Pustaka/Qris';

/*
 * F-08 (D-189): pemeriksaan QRIS di peramban memakai vektor yang sama dengan PHP dan Dart
 * (`Spesifikasi/VektorUjiQris/QrisDinamis.json`), jadi kode galat dan keputusan sah/tidak selalu seragam.
 */
type Vektor = {
    Kasus: { Nama: string; Statis: string; Dinamis: string }[];
    TidakSah: { Nama: string; Teks: string; Galat: string }[];
};

const vektor = JSON.parse(
    readFileSync(resolve(process.cwd(), '../../Spesifikasi/VektorUjiQris/QrisDinamis.json'), 'utf8'),
) as Vektor;

describe('PeriksaQris', () => {
    it('CRC16/CCITT-FALSE: nilai acuan standar "123456789" = 29B1', () => {
        expect(HitungCrc16('123456789')).toBe('29B1');
    });

    it.each(vektor.Kasus.map((k) => [k.Nama, k] as const))('QRIS statis sah: %s', (_nama, kasus) => {
        const hasil = PeriksaQris(kasus.Statis);

        expect(hasil.Sah).toBe(true);
        // CRC hasil QRIS bernominal juga sah, tetapi ditolak sebagai sumber karena bukan statis lagi.
        expect(HitungCrc16(kasus.Dinamis.slice(0, -4))).toBe(kasus.Dinamis.slice(-4));
        expect(PeriksaQris(kasus.Dinamis)).toMatchObject({ Sah: false, Galat: 'BukanStatis' });
    });

    it.each(vektor.TidakSah.map((k) => [k.Nama, k] as const))('tidak sah: %s', (_nama, kasus) => {
        const hasil = PeriksaQris(kasus.Teks);

        expect(hasil).toMatchObject({ Sah: false, Galat: kasus.Galat });
        expect(hasil.Sah === false && hasil.Pesan.length > 0).toBe(true);
    });

    it('memberi nama merchant dan kota. Spasi di tepi diabaikan', () => {
        expect(PeriksaQris(`  ${vektor.Kasus[0]?.Statis ?? ''}\n`)).toEqual({
            Sah: true,
            NamaMerchant: 'TOKO SEMBAKO MAJU JAYA',
            Kota: 'SURAKARTA',
        });
    });

    it('UraiQris menolak teks terpotong', () => {
        expect(UraiQris('00020101021126')).toBeNull();
        expect(UraiQris(vektor.Kasus[0]?.Statis ?? '')?.at(-1)?.[0]).toBe('63');
    });
});
