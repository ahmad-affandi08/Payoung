import { describe, expect, it } from 'vitest';

import { AmbilKodeGalat, AmbilPesanGalat, AmbilPesanValidasi, AmbilTokenXsrf, PesanStatusHttp } from './PermintaanJson';

describe('AmbilTokenXsrf', () => {
    it('mengambil token walau cookie lain mendahuluinya', () => {
        expect(AmbilTokenXsrf('payoung_session=abc; XSRF-TOKEN=tok123; lain=1')).toBe('tok123');
    });

    it('mengambil token saat berada di awal', () => {
        expect(AmbilTokenXsrf('XSRF-TOKEN=tok123')).toBe('tok123');
    });

    it('mengurai persen-encoding, karena Laravel meng-URL-encode nilai cookienya', () => {
        expect(AmbilTokenXsrf('XSRF-TOKEN=a%3Db%3D')).toBe('a=b=');
    });

    it('tidak tertipu cookie yang namanya berakhiran sama', () => {
        expect(AmbilTokenXsrf('LAIN-XSRF-TOKEN=salah')).toBe('');
    });

    it('kosong bila cookienya tidak ada', () => {
        expect(AmbilTokenXsrf('')).toBe('');
    });
});

describe('AmbilPesanGalat', () => {
    it('mengambil pesan dari format masalah API', () => {
        expect(AmbilPesanGalat({ Galat: { Kode: 'GerbangMenolak', Pesan: 'Gerbang menolak.' } })).toBe(
            'Gerbang menolak.',
        );
    });

    it('null untuk bentuk lain, termasuk null dan pesan kosong', () => {
        expect(AmbilPesanGalat(null)).toBeNull();
        expect(AmbilPesanGalat({ message: 'Server Error' })).toBeNull();
        expect(AmbilPesanGalat({ Galat: { Pesan: '' } })).toBeNull();
    });
});

describe('PesanStatusHttp', () => {
    it('memberi pesan Indonesia untuk sesi kedaluwarsa, batas percobaan, dan data tidak sah', () => {
        expect(PesanStatusHttp(419)).toMatch(/Sesi halaman kedaluwarsa/);
        expect(PesanStatusHttp(429)).toMatch(/Terlalu banyak percobaan/);
        expect(PesanStatusHttp(422)).toMatch(/tidak sah/);
        expect(PesanStatusHttp(503)).toMatch(/Server sedang bermasalah/);
        expect(PesanStatusHttp(409)).toBe('Permintaan gagal (409).');
    });
});

describe('AmbilKodeGalat', () => {
    it('mengambil Galat.Kode untuk percabangan halaman (misal KodeQrWajib)', () => {
        expect(AmbilKodeGalat({ Galat: { Kode: 'KodeQrWajib', Pesan: 'Pindai QR' } })).toBe('KodeQrWajib');
        expect(AmbilKodeGalat({ message: 'x' })).toBeNull();
    });
});

describe('AmbilPesanValidasi', () => {
    it('mengambil pesan pertama dari galat validasi Laravel', () => {
        expect(
            AmbilPesanValidasi({
                message: 'x',
                errors: { Kode: ['Kode sudah dipakai.', 'lain'], Nama: ['Nama wajib.'] },
            }),
        ).toBe('Kode sudah dipakai.');
    });

    it('null bila tidak ada pesan yang bisa dipakai', () => {
        expect(AmbilPesanValidasi(null)).toBeNull();
        expect(AmbilPesanValidasi({ errors: {} })).toBeNull();
        expect(AmbilPesanValidasi({ errors: { Kode: [] } })).toBeNull();
        expect(AmbilPesanValidasi({ message: 'Server Error' })).toBeNull();
    });
});
