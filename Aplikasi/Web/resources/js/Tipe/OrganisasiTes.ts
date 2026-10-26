import { describe, expect, it } from 'vitest';

import { CekBatasPenuh, FormatBatas, IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';

describe('Batas paket di layar F-02 (BR-02.1)', () => {
    it('menampilkan pemakaian terhadap batas, termasuk paket tanpa batas', () => {
        expect(FormatBatas({ Batas: 3, Terpakai: 2 }, 'outlet')).toBe('2 dari 3 outlet');
        expect(FormatBatas({ Batas: null, Terpakai: 12 }, 'pengguna')).toBe('12 pengguna (tanpa batas)');
    });

    it('menandai penuh hanya bila batas terisi habis', () => {
        expect(CekBatasPenuh({ Batas: 1, Terpakai: 1 })).toBe(true);
        expect(CekBatasPenuh({ Batas: 3, Terpakai: 2 })).toBe(false);
        expect(CekBatasPenuh({ Batas: null, Terpakai: 1000 })).toBe(false);
    });
});

describe('Izin tenant untuk menu (UX saja, server tetap penentu)', () => {
    it('Pemilik selalu punya izin. Anggota lain sesuai daftar izin, tanpa akses berarti tanpa izin', () => {
        expect(PunyaIzinTenant({ Pemilik: true, Izin: [] }, IzinTenant.AuditLihat)).toBe(true);
        expect(PunyaIzinTenant({ Pemilik: false, Izin: ['outlet.lihat'] }, IzinTenant.OutletLihat)).toBe(true);
        expect(PunyaIzinTenant({ Pemilik: false, Izin: ['outlet.lihat'] }, IzinTenant.OutletKelola)).toBe(false);
        expect(PunyaIzinTenant(null, IzinTenant.OutletLihat)).toBe(false);
    });

    it('D-35: izin nonaktif edisi Lisensi (langganan, bantuan) mati juga untuk Pemilik', () => {
        const akses = { Pemilik: true, Izin: [], IzinNonaktif: ['langganan.kelola', 'bantuan.tiket.lihat'] };

        expect(PunyaIzinTenant(akses, IzinTenant.LanggananKelola)).toBe(false);
        expect(PunyaIzinTenant(akses, IzinTenant.BantuanTiketLihat)).toBe(false);
        expect(PunyaIzinTenant(akses, IzinTenant.OutletKelola)).toBe(true);
    });
});

describe('Izin panduan awal F-01 (sama dengan IzinTenant::PanduanAwalKelola di Backend)', () => {
    it('memakai kunci panduan-awal.kelola', () => {
        expect(IzinTenant.PanduanAwalKelola).toBe('panduan-awal.kelola');
        expect(PunyaIzinTenant({ Pemilik: false, Izin: ['panduan-awal.kelola'] }, IzinTenant.PanduanAwalKelola)).toBe(
            true,
        );
    });
});
