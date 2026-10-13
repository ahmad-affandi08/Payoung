import { describe, expect, it } from 'vitest';

import {
    HitungLangkahTimBergalat,
    PetakanGalatTimKeLangkah,
    PeriksaLangkahTim,
    PeriksaPinKasir,
    SusunLangkahTim,
    type IsianTim,
} from './LangkahTim';

const isian: IsianTim = {
    Akses: 'Kasir',
    Nama: 'Budi',
    Jabatan: '',
    Email: '',
    NoHp: '',
    KataSandi: '',
    Pin: '482915',
    Peran: 'R-KASIR',
    UuidOutlet: '',
    LevelStaf: '',
    GajiPokok: '',
    TarifLemburPerJam: '',
    PotonganTerlambatPerMenit: '',
    PotonganTidakMasukPerHari: '',
};
const konteks = { isian, aksesTersedia: ['Kasir', 'Dashboard', 'Tanpa'] as IsianTim['Akses'][], outletKurang: false };

describe('SusunLangkahTim', () => {
    it('akun melewati Akun masuk lalu Tugas & outlet; tanpa akun hanya memilih outlet utama', () => {
        expect(SusunLangkahTim({ akses: 'Kasir', punyaKaryawan: true, aturGaji: false }).map((l) => l.Kunci)).toEqual([
            'Siapa',
            'Akun',
            'Peran',
            'Tinjau',
        ]);
        expect(SusunLangkahTim({ akses: 'Tanpa', punyaKaryawan: true, aturGaji: false }).map((l) => l.Kunci)).toEqual([
            'Siapa',
            'Outlet',
            'Tinjau',
        ]);
    });

    it('langkah Gaji hanya muncul bila diminta dan orangnya dicatat sebagai karyawan', () => {
        const Kunci = (punyaKaryawan: boolean, aturGaji: boolean) =>
            SusunLangkahTim({ akses: 'Dashboard', punyaKaryawan, aturGaji }).map((l) => l.Kunci);

        expect(Kunci(true, true)).toEqual(['Siapa', 'Akun', 'Peran', 'Gaji', 'Tinjau']);
        expect(Kunci(true, false)).not.toContain('Gaji');
        expect(Kunci(false, true)).not.toContain('Gaji');
    });
});

describe('PeriksaPinKasir (cermin PenjagaPin)', () => {
    it('menolak bukan 6 angka, angka sama semua, dan deret naik/turun', () => {
        expect(PeriksaPinKasir('12345')).toBe('PIN harus 6 angka.');
        expect(PeriksaPinKasir('12a456')).toBe('PIN harus 6 angka.');
        expect(PeriksaPinKasir('111111')).toContain('sama semua');
        expect(PeriksaPinKasir('123456')).toContain('berurutan');
        expect(PeriksaPinKasir('654321')).toContain('berurutan');
        expect(PeriksaPinKasir('012345')).toContain('berurutan');
        expect(PeriksaPinKasir('482915')).toBeNull();
        expect(PeriksaPinKasir('123450')).toBeNull();
    });
});

describe('PeriksaLangkahTim', () => {
    it('Siapa: nama wajib, jenis akses harus tersedia', () => {
        expect(PeriksaLangkahTim('Siapa', konteks)).toEqual({});
        expect(PeriksaLangkahTim('Siapa', { ...konteks, isian: { ...isian, Nama: ' ' } }).Nama).toBe('Isi nama dulu.');
        expect(PeriksaLangkahTim('Siapa', { ...konteks, aksesTersedia: ['Dashboard'] }).Akses).toContain(
            'Pilih apakah ia masuk',
        );
    });

    it('Akun: kasir wajib PIN kuat; dashboard wajib email dan kata sandi huruf+angka, PIN opsional tetapi harus kuat', () => {
        expect(PeriksaLangkahTim('Akun', konteks)).toEqual({});
        expect(PeriksaLangkahTim('Akun', { ...konteks, isian: { ...isian, Pin: '' } }).Pin).toContain(
            'wajib diberi PIN',
        );

        const dashboard = { ...isian, Akses: 'Dashboard' as const, Pin: '' };
        const hasil = PeriksaLangkahTim('Akun', { ...konteks, isian: dashboard });
        expect(Object.keys(hasil).sort()).toEqual(['Email', 'KataSandi']);
        expect(
            PeriksaLangkahTim('Akun', {
                ...konteks,
                isian: { ...dashboard, Email: 'budi@usaha.id', KataSandi: 'rahasia123' },
            }),
        ).toEqual({});
        expect(
            PeriksaLangkahTim('Akun', {
                ...konteks,
                isian: { ...dashboard, Email: 'budi@usaha.id', KataSandi: 'hurufsaja' },
            }).KataSandi,
        ).toContain('huruf dan angka');
        expect(
            PeriksaLangkahTim('Akun', {
                ...konteks,
                isian: { ...dashboard, Email: 'budi@usaha.id', KataSandi: 'abc12345', Pin: '111111' },
            }).Pin,
        ).toContain('sama semua');
        expect(PeriksaLangkahTim('Akun', { ...konteks, isian: { ...isian, NoHp: '12' } }).NoHp).toContain('8–20 angka');
    });

    it('Peran: peran wajib; peran bercakupan terpilih butuh minimal satu outlet; Gaji memeriksa angka', () => {
        expect(PeriksaLangkahTim('Peran', konteks)).toEqual({});
        expect(PeriksaLangkahTim('Peran', { ...konteks, isian: { ...isian, Peran: '' } }).Peran).toBe('Pilih peran.');
        expect(PeriksaLangkahTim('Peran', { ...konteks, outletKurang: true }).Outlet).toContain('minimal satu outlet');
        expect(
            PeriksaLangkahTim('Gaji', { ...konteks, isian: { ...isian, GajiPokok: '2.500.000' } }).GajiPokok,
        ).toContain('pemisah desimal titik');
        expect(PeriksaLangkahTim('Gaji', { ...konteks, isian: { ...isian, GajiPokok: '2500000.50' } })).toEqual({});
        expect(PeriksaLangkahTim('Tinjau', { ...konteks, isian: { ...isian, Nama: '' } })).toEqual({});
    });
});

describe('PetakanGalatTimKeLangkah', () => {
    it('kunci galat server menuju langkahnya; Outlet bergantung cara masuk', () => {
        expect(PetakanGalatTimKeLangkah('Nama', 'Kasir')).toBe('Siapa');
        expect(PetakanGalatTimKeLangkah('Pin', 'Kasir')).toBe('Akun');
        expect(PetakanGalatTimKeLangkah('Email', 'Dashboard')).toBe('Akun');
        expect(PetakanGalatTimKeLangkah('Peran', 'Kasir')).toBe('Peran');
        expect(PetakanGalatTimKeLangkah('Outlet.0', 'Kasir')).toBe('Peran');
        expect(PetakanGalatTimKeLangkah('UuidOutlet', 'Tanpa')).toBe('Outlet');
        expect(PetakanGalatTimKeLangkah('GajiPokok', 'Kasir')).toBe('Gaji');
        expect(PetakanGalatTimKeLangkah('Umum', 'Kasir')).toBeNull();
        expect(HitungLangkahTimBergalat({ Pin: 'x', Nama: 'y', Umum: 'z' }, 'Kasir')).toEqual(['Siapa', 'Akun']);
    });
});
