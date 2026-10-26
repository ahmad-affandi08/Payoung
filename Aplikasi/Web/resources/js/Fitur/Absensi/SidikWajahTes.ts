import { describe, expect, it } from 'vitest';

import {
    AmbilPetunjukPosisi,
    AmbilTampilanKeadaan,
    BolehAmbilFoto,
    KeAkurasiMeter,
    KeSidikWajah,
    KeTeksKoordinat,
    NilaiPosisiWajah,
    NilaiWajah,
    PenstabilKeadaan,
    TentukanKeadaan,
} from '@/Fitur/Absensi/SidikWajah';

/*
 * F-18 bagian 4 (D-37, D-186): sidik wajah dikirim sebagai bilangan bulat (deskriptor × 10.000, dibatasi ±100.000);
 * penilaian wajah di HP mensyaratkan satu wajah asli & hidup yang posisinya pas di lingkaran. Cocok-tidaknya wajah
 * ditentukan server, jadi di sini hanya keadaan lingkaran (hijau/merah) dan syarat tombol Ambil foto.
 */
describe('Sidik wajah absensi web', () => {
    it('deskriptor dikali 10.000, dibulatkan, dibatasi, dan nilai tak hingga jadi 0', () => {
        expect(KeSidikWajah([0.12345, -0.5, 20, Number.NaN])).toEqual([1235, -5000, 100000, 0]);
    });

    it('koordinat 7 angka desimal. Akurasi dibulatkan ke atas', () => {
        expect(KeTeksKoordinat(-7.556)).toBe('-7.5560000');
        expect(KeAkurasiMeter(12.2)).toBe(13);
        expect(KeAkurasiMeter(-1)).toBe(0);
    });
});

describe('Penilaian wajah', () => {
    const asli = { real: 0.9, live: 0.8, embedding: [0.1, 0.2] };

    it('kosong, ganda, palsu, tanpa sidik, lalu lolos. Tidak ada syarat kedip lagi', () => {
        expect(NilaiWajah([])).toEqual({ Lolos: false, Alasan: 'TidakAdaWajah' });
        expect(NilaiWajah([asli, asli])).toEqual({ Lolos: false, Alasan: 'BanyakWajah' });
        expect(NilaiWajah([{ ...asli, real: 0.2 }])).toEqual({ Lolos: false, Alasan: 'BukanWajahAsli' });
        expect(NilaiWajah([{ real: 0.9, embedding: [0.1] }])).toEqual({ Lolos: false, Alasan: 'BukanWajahAsli' });
        expect(NilaiWajah([{ ...asli, embedding: [] }])).toEqual({ Lolos: false, Alasan: 'SidikKosong' });
        expect(NilaiWajah([asli])).toEqual({ Lolos: true });
    });

    it('bingkai tanpa model deskriptor tidak butuh sidik', () => {
        const tanpaSidik = { real: 0.9, live: 0.8 };

        expect(NilaiWajah([tanpaSidik], { butuhSidik: false })).toEqual({ Lolos: true });
        expect(NilaiWajah([tanpaSidik])).toEqual({ Lolos: false, Alasan: 'SidikKosong' });
    });

    it('posisi wajah dinilai terhadap sisi pendek video: jauh, dekat, geser, lalu pas', () => {
        // Video 640x480: sisi pendek 480. Pusat video (320, 240).
        expect(NilaiPosisiWajah([270, 190, 100, 100], 640, 480)).toBe('TerlaluJauh');
        expect(NilaiPosisiWajah([100, 10, 470, 460], 640, 480)).toBe('TerlaluDekat');
        expect(NilaiPosisiWajah([20, 140, 220, 220], 640, 480)).toBe('TidakDiTengah');
        expect(NilaiPosisiWajah([210, 130, 220, 220], 640, 480)).toBeNull();
        expect(NilaiPosisiWajah([0, 0, 10, 10], 0, 0)).toBeNull();

        const pas = { ...asli, box: [210, 130, 220, 220] as const };
        const jauh = { ...asli, box: [270, 190, 100, 100] as const };

        expect(NilaiWajah([pas], { ukuranVideo: { lebar: 640, tinggi: 480 } })).toEqual({ Lolos: true });
        expect(NilaiWajah([jauh], { ukuranVideo: { lebar: 640, tinggi: 480 } })).toEqual({
            Lolos: false,
            Alasan: 'TerlaluJauh',
        });
    });
});

describe('Keadaan lingkaran kamera', () => {
    it('mode daftar: wajah lolos = siap (hijau). Mode absen: hijau hanya bila server bilang cocok', () => {
        const lolos = { Lolos: true } as const;

        expect(TentukanKeadaan(lolos, null)).toBe('siap');
        expect(TentukanKeadaan(lolos, 'belum')).toBe('memeriksa');
        expect(TentukanKeadaan(lolos, 'cocok')).toBe('cocok');
        expect(TentukanKeadaan(lolos, 'tidak-cocok')).toBe('tidak-cocok');
    });

    it('wajah yang tidak lolos tidak pernah hijau, apa pun jawaban kecocokan terakhir', () => {
        expect(TentukanKeadaan({ Lolos: false, Alasan: 'TidakAdaWajah' }, 'cocok')).toBe('tidak-ada-wajah');
        expect(TentukanKeadaan({ Lolos: false, Alasan: 'BukanWajahAsli' }, 'cocok')).toBe('tidak-asli');
        expect(TentukanKeadaan({ Lolos: false, Alasan: 'BanyakWajah' }, 'cocok')).toBe('atur-posisi');
        expect(TentukanKeadaan({ Lolos: false, Alasan: 'TerlaluJauh' }, 'cocok')).toBe('atur-posisi');
        expect(TentukanKeadaan({ Lolos: false, Alasan: 'SidikKosong' }, null)).toBe('memeriksa');
    });

    it('tombol Ambil foto hanya aktif pada keadaan hijau', () => {
        const semua = [
            'menyiapkan',
            'tidak-ada-wajah',
            'atur-posisi',
            'memeriksa',
            'cocok',
            'tidak-cocok',
            'tidak-asli',
            'siap',
        ] as const;

        expect(semua.filter((k) => BolehAmbilFoto(k))).toEqual(['cocok', 'siap']);
    });

    it('warna: hijau cocok/siap, merah tidak cocok/tidak asli, kuning periksa/atur posisi, netral tanpa wajah', () => {
        expect(AmbilTampilanKeadaan('cocok', 'absen').nada).toBe('sukses');
        expect(AmbilTampilanKeadaan('siap', 'daftar').nada).toBe('sukses');
        expect(AmbilTampilanKeadaan('tidak-cocok', 'absen').nada).toBe('bahaya');
        expect(AmbilTampilanKeadaan('tidak-asli', 'absen').nada).toBe('bahaya');
        expect(AmbilTampilanKeadaan('memeriksa', 'absen').nada).toBe('peringatan');
        expect(AmbilTampilanKeadaan('atur-posisi', 'absen').nada).toBe('peringatan');
        expect(AmbilTampilanKeadaan('tidak-ada-wajah', 'absen').nada).toBe('netral');
        expect(AmbilTampilanKeadaan('cocok', 'absen').teks).toContain('Ambil foto');
        expect(AmbilPetunjukPosisi('TerlaluJauh')).toContain('Dekatkan');
        expect(AmbilPetunjukPosisi('TerlaluDekat')).toContain('Jauhkan');
        expect(AmbilPetunjukPosisi('TidakDiTengah')).toContain('tengah');
    });
});

describe('Peredam kedip warna', () => {
    it('keadaan baru dipakai setelah dua pemindaian berturut-turut. Keluar dari hijau seketika', () => {
        const p = new PenstabilKeadaan();
        p.Atur('tidak-ada-wajah');

        expect(p.Masukkan('memeriksa')).toBe('tidak-ada-wajah');
        expect(p.Masukkan('memeriksa')).toBe('memeriksa');
        expect(p.Masukkan('cocok')).toBe('memeriksa');
        expect(p.Masukkan('cocok')).toBe('cocok');

        // Satu bingkai buruk saja sudah mematikan tombol: wajah yang bergeser tidak boleh difoto.
        expect(p.Masukkan('atur-posisi')).toBe('atur-posisi');
        // Kembali ke hijau butuh dua bingkai lagi, bukan satu.
        expect(p.Masukkan('cocok')).toBe('atur-posisi');
        expect(p.Masukkan('cocok')).toBe('cocok');
    });

    it('bingkai selang-seling tidak mengganti keadaan', () => {
        const p = new PenstabilKeadaan();
        p.Atur('tidak-ada-wajah');

        for (const mentah of ['memeriksa', 'tidak-ada-wajah', 'memeriksa', 'tidak-ada-wajah'] as const) {
            expect(p.Masukkan(mentah)).toBe('tidak-ada-wajah');
        }
    });
});
