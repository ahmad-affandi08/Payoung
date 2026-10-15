import { describe, expect, it } from 'vitest';

import {
    AmbilPenanda,
    CekBisaKirim,
    FormatLamaMenunggu,
    GabungPesan,
    HitungMenunggu,
    SusunJudulTab,
    type PesanObrolan,
} from '@/Pustaka/Obrolan';

const BuatPesan = (uuid: string, isi = 'Halo'): PesanObrolan => ({
    Uuid: uuid,
    JenisPengirim: 'Pengguna',
    NamaPengirim: 'Rina Wulandari',
    Isi: isi,
    DibuatPada: '2026-10-15T02:00:00+00:00',
});

describe('PLT-12 logika obrolan langsung', () => {
    it('GabungPesan menambah hanya pesan baru, menjaga urutan, dan mengembalikan daftar lama bila tidak ada yang baru', () => {
        const lama = [BuatPesan('A'), BuatPesan('B')];

        expect(GabungPesan(lama, [BuatPesan('B'), BuatPesan('C')]).map((p) => p.Uuid)).toEqual(['A', 'B', 'C']);
        expect(GabungPesan(lama, [BuatPesan('A')])).toBe(lama);
        expect(GabungPesan([], [BuatPesan('A')]).map((p) => p.Uuid)).toEqual(['A']);
    });

    it('AmbilPenanda memakai Uuid pesan terakhir, null bila kosong', () => {
        expect(AmbilPenanda([BuatPesan('A'), BuatPesan('B')])).toBe('B');
        expect(AmbilPenanda([])).toBeNull();
    });

    it('CekBisaKirim menolak pesan kosong/spasi saja dan yang melewati batas', () => {
        expect(CekBisaKirim('   ', 100)).toBe(false);
        expect(CekBisaKirim('Halo', 100)).toBe(true);
        expect(CekBisaKirim('a'.repeat(101), 100)).toBe(false);
        expect(CekBisaKirim(` ${'a'.repeat(100)} `, 100)).toBe(true);
    });

    it('HitungMenunggu dan SusunJudulTab menandai tab hanya bila ada yang menunggu', () => {
        const menunggu = HitungMenunggu([
            { MenungguBalasan: true },
            { MenungguBalasan: false },
            { MenungguBalasan: true },
        ]);

        expect(menunggu).toBe(2);
        expect(SusunJudulTab('Obrolan langsung', menunggu)).toBe('(2) Obrolan langsung');
        expect(SusunJudulTab('Obrolan langsung', 0)).toBe('Obrolan langsung');
    });

    it('FormatLamaMenunggu: baru saja, menit, jam, hari', () => {
        const sekarang = new Date('2026-10-15T10:00:00Z').getTime();

        expect(FormatLamaMenunggu('2026-10-15T09:59:40Z', sekarang)).toBe('baru saja');
        expect(FormatLamaMenunggu('2026-10-15T09:55:00Z', sekarang)).toBe('5 mnt');
        expect(FormatLamaMenunggu('2026-10-15T07:00:00Z', sekarang)).toBe('3 jam');
        expect(FormatLamaMenunggu('2026-10-12T09:00:00Z', sekarang)).toBe('3 hari');
    });
});
