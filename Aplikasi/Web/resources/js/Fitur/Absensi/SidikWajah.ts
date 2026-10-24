/**
 * Fungsi murni absensi web (F-18 bagian 4, D-37) yang bisa diuji tanpa kamera & model wajah.
 *
 * Server mencocokkan sidik wajah sebagai bilangan bulat (deskriptor × 10.000) supaya hitungannya tetap desimal tanpa
 * pecahan biner (`PencocokWajah`). Nilai dibatasi ±100.000 sesuai validasi server.
 */
export const SKALA_SIDIK = 10_000;
export const BATAS_NILAI_SIDIK = 100_000;

/** Ambang skor model di HP sebelum wajah dianggap hidup (bukan foto/layar). Keputusan cocok tetap di server. */
export const AMBANG_WAJAH_ASLI = 0.5;
export const AMBANG_WAJAH_HIDUP = 0.5;

export function KeSidikWajah(deskriptor: readonly number[]): number[] {
    return deskriptor.map((nilai) => {
        const bulat = Math.round(nilai * SKALA_SIDIK);

        return Math.max(-BATAS_NILAI_SIDIK, Math.min(BATAS_NILAI_SIDIK, Number.isFinite(bulat) ? bulat : 0));
    });
}

/** Koordinat Geolocation API → teks derajat desimal 7 angka (format yang diterima server). */
export function KeTeksKoordinat(derajat: number): string {
    return derajat.toFixed(7);
}

/** Akurasi GPS dibulatkan ke atas ke meter (server menolak akurasi lebih buruk dari radius outlet). */
export function KeAkurasiMeter(akurasi: number): number {
    return Math.max(0, Math.ceil(akurasi));
}

export type AlasanWajahGagal =
    | 'TidakAdaWajah'
    | 'BanyakWajah'
    | 'TerlaluJauh'
    | 'TerlaluDekat'
    | 'TidakDiTengah'
    | 'BukanWajahAsli'
    | 'SidikKosong';

export type PenilaianWajah = { Lolos: true } | { Lolos: false; Alasan: AlasanWajahGagal };

/** Kotak wajah `[x, y, lebar, tinggi]` dalam piksel video, seperti hasil deteksi Human. */
export type KotakWajah = readonly [number, number, number, number];

/** Wajah dianggap terlalu jauh bila lebarnya kurang dari bagian ini dari sisi pendek video (yang tampak di lingkaran). */
export const BATAS_WAJAH_JAUH = 0.32;
/** ... terlalu dekat bila lebih dari ini. */
export const BATAS_WAJAH_DEKAT = 0.9;
/** Pusat wajah boleh bergeser sejauh ini (bagian dari sisi pendek video) dari pusat lingkaran. */
export const BATAS_GESER_PUSAT = 0.22;

/**
 * Posisi wajah terhadap lingkaran kamera: lingkaran menampilkan persegi tengah video, jadi ukuran & pusat wajah
 * dinilai terhadap sisi pendek video. Null = posisinya pas.
 */
export function NilaiPosisiWajah(
    kotak: KotakWajah,
    lebarVideo: number,
    tinggiVideo: number,
): 'TerlaluJauh' | 'TerlaluDekat' | 'TidakDiTengah' | null {
    const sisi = Math.min(lebarVideo, tinggiVideo);

    if (sisi <= 0) {
        return null;
    }

    const [x, y, lebar, tinggi] = kotak;
    const ukuran = Math.max(lebar, tinggi) / sisi;

    if (ukuran < BATAS_WAJAH_JAUH) {
        return 'TerlaluJauh';
    }

    if (ukuran > BATAS_WAJAH_DEKAT) {
        return 'TerlaluDekat';
    }

    const geserX = Math.abs(x + lebar / 2 - lebarVideo / 2) / sisi;
    const geserY = Math.abs(y + tinggi / 2 - tinggiVideo / 2) / sisi;

    return geserX > BATAS_GESER_PUSAT || geserY > BATAS_GESER_PUSAT ? 'TidakDiTengah' : null;
}

export type OpsiNilaiWajah = {
    /** Ukuran video untuk menilai posisi; tanpa ini posisi tidak dinilai. */
    ukuranVideo?: { lebar: number; tinggi: number };
    /** Wajib ada sidik wajah (model deskriptor sedang dijalankan). Bawaan true. */
    butuhSidik?: boolean;
};

/**
 * Menilai satu hasil deteksi: tepat satu wajah, posisinya pas di lingkaran, skor wajah asli & hidup cukup, dan (bila
 * diminta) model menghasilkan sidik wajah. Tidak ada syarat kedip: kecocokan wajah ditentukan server, dan keaslian
 * dijaga skor model anti-tipu & kehidupan (D-186).
 */
export function NilaiWajah(
    wajah: readonly { real?: number; live?: number; embedding?: number[]; box?: KotakWajah }[],
    opsi: OpsiNilaiWajah = {},
): PenilaianWajah {
    if (wajah.length === 0) {
        return { Lolos: false, Alasan: 'TidakAdaWajah' };
    }

    if (wajah.length > 1) {
        return { Lolos: false, Alasan: 'BanyakWajah' };
    }

    const satu = wajah[0];

    if (satu?.box !== undefined && opsi.ukuranVideo !== undefined) {
        const posisi = NilaiPosisiWajah(satu.box, opsi.ukuranVideo.lebar, opsi.ukuranVideo.tinggi);

        if (posisi !== null) {
            return { Lolos: false, Alasan: posisi };
        }
    }

    if ((satu?.real ?? 0) < AMBANG_WAJAH_ASLI || (satu?.live ?? 0) < AMBANG_WAJAH_HIDUP) {
        return { Lolos: false, Alasan: 'BukanWajahAsli' };
    }

    if ((opsi.butuhSidik ?? true) && (satu?.embedding?.length ?? 0) === 0) {
        return { Lolos: false, Alasan: 'SidikKosong' };
    }

    return { Lolos: true };
}

/** Keadaan lingkaran kamera yang dilihat karyawan. */
export type KeadaanPemindai =
    'menyiapkan' | 'tidak-ada-wajah' | 'atur-posisi' | 'memeriksa' | 'cocok' | 'tidak-cocok' | 'tidak-asli' | 'siap';

/** Keadaan mentah satu pemindaian: gabungan penilaian wajah dan hasil cek kecocokan server terbaru (bila ada). */
export function TentukanKeadaan(
    penilaian: PenilaianWajah,
    kecocokan: 'belum' | 'cocok' | 'tidak-cocok' | null,
): KeadaanPemindai {
    if (!penilaian.Lolos) {
        switch (penilaian.Alasan) {
            case 'TidakAdaWajah':
                return 'tidak-ada-wajah';
            case 'BukanWajahAsli':
                return 'tidak-asli';
            // Sidik kosong = model deskriptor belum jalan pada bingkai ini; tampilkan seperti sedang memeriksa.
            case 'SidikKosong':
                return 'memeriksa';
            default:
                return 'atur-posisi';
        }
    }

    // `null` = mode daftar (tidak ada wajah acuan): kualitas yang baik cukup untuk memotret.
    if (kecocokan === null) {
        return 'siap';
    }

    return kecocokan === 'cocok' ? 'cocok' : kecocokan === 'tidak-cocok' ? 'tidak-cocok' : 'memeriksa';
}

/** Keadaan yang membolehkan tombol "Ambil foto". */
export function BolehAmbilFoto(keadaan: KeadaanPemindai): boolean {
    return keadaan === 'cocok' || keadaan === 'siap';
}

/**
 * Peredam kedip warna: keadaan baru baru dipakai setelah muncul berturut-turut [syarat] kali pemindaian. Keluar dari
 * keadaan "boleh foto" lebih cepat ([syaratKeluar]) supaya tombol tidak aktif untuk wajah yang sudah bergeser.
 */
export class PenstabilKeadaan {
    private stabil: KeadaanPemindai = 'menyiapkan';
    private calon: KeadaanPemindai | null = null;
    private hitung = 0;

    constructor(
        private readonly syarat = 2,
        private readonly syaratKeluar = 1,
    ) {}

    Ambil(): KeadaanPemindai {
        return this.stabil;
    }

    Atur(keadaan: KeadaanPemindai): KeadaanPemindai {
        this.stabil = keadaan;
        this.calon = null;
        this.hitung = 0;

        return keadaan;
    }

    Masukkan(mentah: KeadaanPemindai): KeadaanPemindai {
        if (mentah === this.stabil) {
            this.calon = null;
            this.hitung = 0;

            return this.stabil;
        }

        this.hitung = this.calon === mentah ? this.hitung + 1 : 1;
        this.calon = mentah;
        const perlu = BolehAmbilFoto(this.stabil) ? this.syaratKeluar : this.syarat;

        if (this.hitung >= perlu) {
            return this.Atur(mentah);
        }

        return this.stabil;
    }
}

export type TampilanKeadaan = {
    /** Petunjuk singkat berteks di bawah lingkaran. */
    teks: string;
    /** Warna semantik lingkaran & petunjuk. */
    nada: 'netral' | 'sukses' | 'bahaya' | 'peringatan';
};

/** Teks & warna per keadaan; [mode] `daftar` memakai kalimat pendaftaran. */
export function AmbilTampilanKeadaan(keadaan: KeadaanPemindai, mode: 'daftar' | 'absen'): TampilanKeadaan {
    switch (keadaan) {
        case 'menyiapkan':
            return { teks: 'Menyiapkan kamera…', nada: 'netral' };
        case 'tidak-ada-wajah':
            return { teks: 'Arahkan wajah ke dalam lingkaran, di tempat terang.', nada: 'netral' };
        case 'atur-posisi':
            return { teks: 'Atur posisi wajah.', nada: 'peringatan' };
        case 'memeriksa':
            return { teks: 'Memeriksa wajah…', nada: 'peringatan' };
        case 'tidak-asli':
            return {
                teks: 'Wajah belum terbaca jelas. Lepas masker atau kacamata gelap, hadapkan wajah lurus.',
                nada: 'bahaya',
            };
        case 'tidak-cocok':
            return { teks: 'Wajah tidak cocok dengan wajah terdaftar. Coba lagi di tempat terang.', nada: 'bahaya' };
        case 'cocok':
            return { teks: 'Wajah cocok. Ketuk Ambil foto.', nada: 'sukses' };
        case 'siap':
            return { teks: mode === 'daftar' ? 'Wajah terbaca. Ketuk Ambil foto.' : 'Wajah terbaca.', nada: 'sukses' };
    }
}

/** Petunjuk posisi yang spesifik saat keadaan `atur-posisi` (jauh, dekat, geser, lebih dari satu wajah). */
export function AmbilPetunjukPosisi(alasan: AlasanWajahGagal): string {
    switch (alasan) {
        case 'BanyakWajah':
            return 'Pastikan hanya wajah Anda yang terlihat di kamera.';
        case 'TerlaluJauh':
            return 'Terlalu jauh. Dekatkan wajah ke kamera.';
        case 'TerlaluDekat':
            return 'Terlalu dekat. Jauhkan sedikit wajah dari kamera.';
        case 'TidakDiTengah':
            return 'Geser wajah ke tengah lingkaran.';
        default:
            return 'Atur posisi wajah.';
    }
}
