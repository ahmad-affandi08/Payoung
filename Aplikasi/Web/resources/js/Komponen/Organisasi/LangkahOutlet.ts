/**
 * Logika murni wizard "Tambah outlet" (F-02 langkah 1, BR-02.1, BR-02.2): daftar langkah, pemetaan kunci galat server
 * ke langkah, dan validasi klien per langkah. Aturan mencerminkan `SimpanOutletPermintaan`; server tetap penentu
 * (termasuk keunikan kode di tenant, yang tidak diketahui halaman ini).
 */
import type { LangkahWizard } from '@/Komponen/Formulir/Wizard/TipeWizard';

export type KunciLangkahOutlet = 'Identitas' | 'Lokasi' | 'Pajak' | 'Kanvas' | 'Tinjau';
export type LangkahOutlet = LangkahWizard<KunciLangkahOutlet>;

export const urutanLangkahOutlet: KunciLangkahOutlet[] = ['Identitas', 'Lokasi', 'Pajak', 'Kanvas', 'Tinjau'];

const keteranganLangkah: Record<KunciLangkahOutlet, { Label: string; Keterangan: string }> = {
    Identitas: {
        Label: 'Nama & kode',
        Keterangan:
            'Nama yang dikenal pelanggan, dan kode singkat untuk nomor dokumen dan perangkat kasir. Kode tidak bisa diubah setelah outlet bertransaksi.',
    },
    Lokasi: {
        Label: 'Lokasi & jam',
        Keterangan: 'Alamat, kota, zona waktu, dan jam pergantian hari buku untuk laporan.',
    },
    Pajak: {
        Label: 'Pajak',
        Keterangan: 'Status pajak dasar outlet. Tarif mengikuti data resmi, bukan diisi di sini.',
    },
    Kanvas: {
        Label: 'Kanvas',
        Keterangan: 'Hanya bila outlet ini kendaraan salesman yang berkeliling menjual barang.',
    },
    Tinjau: {
        Label: 'Tinjau & tambah',
        Keterangan: 'Periksa ringkasan di bawah. Ubah bagian yang perlu, lalu tambahkan outlet.',
    },
};

/** Langkah Kanvas hanya untuk usaha grosir/distribusi (D-48); selainnya dilewati. */
export function SusunLangkahOutlet(tampilKanvas: boolean): LangkahOutlet[] {
    return urutanLangkahOutlet
        .filter((kunci) => kunci !== 'Kanvas' || tampilKanvas)
        .map((kunci) => ({ Kunci: kunci, ...keteranganLangkah[kunci] }));
}

const kunciIdentitas = ['Nama', 'Kode', 'Merek'];
const kunciLokasi = ['Alamat', 'KodeKota', 'ZonaWaktu', 'JamTutupBuku'];
const kunciPajak = ['Pkp', 'Nitku', 'PungutPbjt'];
const kunciKanvas = ['Kanvas', 'NomorKendaraan'];

export function PetakanGalatOutletKeLangkah(kunci: string): KunciLangkahOutlet | null {
    if (kunciIdentitas.includes(kunci)) {
        return 'Identitas';
    }

    if (kunciLokasi.includes(kunci)) {
        return 'Lokasi';
    }

    if (kunciPajak.includes(kunci)) {
        return 'Pajak';
    }

    return kunciKanvas.includes(kunci) ? 'Kanvas' : null;
}

export function HitungLangkahOutletBergalat(galat: Record<string, string | undefined>): KunciLangkahOutlet[] {
    const ada = new Set<KunciLangkahOutlet>();

    for (const [kunci, pesan] of Object.entries(galat)) {
        const langkah = pesan ? PetakanGalatOutletKeLangkah(kunci) : null;

        if (langkah !== null) {
            ada.add(langkah);
        }
    }

    return urutanLangkahOutlet.filter((kunci) => ada.has(kunci));
}

export function AmbilGalatLangkahOutlet(
    galat: Record<string, string | undefined>,
    kunci: KunciLangkahOutlet,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(galat).filter(([isi, pesan]) => Boolean(pesan) && PetakanGalatOutletKeLangkah(isi) === kunci),
    );
}

export type IsianPeriksaOutlet = {
    Nama: string;
    Kode: string;
    Merek: string;
    ZonaWaktu: string;
    JamTutupBuku: string;
    Pkp: boolean;
    Nitku: string;
    Kanvas: boolean;
    NomorKendaraan: string;
};

/** BR-02.2: kode 3–5 karakter, diawali huruf, hanya huruf dan angka. */
export const polaKodeOutlet = /^[A-Za-z][A-Za-z0-9]{2,4}$/;
const polaNomorKendaraan = /^[A-Za-z0-9][A-Za-z0-9 -]*$/;

/** Galat klien satu langkah, berkunci sama dengan galat server. Kosong = langkah boleh dilanjutkan. */
export function PeriksaLangkahOutlet(
    kunci: KunciLangkahOutlet,
    isian: IsianPeriksaOutlet,
    perluMerek: boolean,
): Record<string, string> {
    const galat: Record<string, string> = {};

    if (kunci === 'Identitas') {
        if (isian.Nama.trim() === '') {
            galat.Nama = 'Isi nama outlet dulu.';
        } else if (isian.Nama.trim().length > 150) {
            galat.Nama = 'Nama outlet maksimal 150 karakter.';
        }

        if (isian.Kode.trim() === '') {
            galat.Kode = 'Isi kode outlet dulu.';
        } else if (!polaKodeOutlet.test(isian.Kode.trim())) {
            galat.Kode = 'Kode 3–5 karakter, diawali huruf, hanya huruf dan angka. Misal: RSY atau JKT1.';
        }

        if (perluMerek && isian.Merek === '') {
            galat.Merek = 'Pilih merek.';
        }
    }

    if (kunci === 'Lokasi') {
        if (isian.ZonaWaktu === '') {
            galat.ZonaWaktu = 'Pilih zona waktu.';
        }

        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(isian.JamTutupBuku)) {
            galat.JamTutupBuku = 'Jam tutup buku memakai format JJ:MM, misal 04:00.';
        }
    }

    if (kunci === 'Pajak' && isian.Pkp && isian.Nitku !== '' && !/^\d{22}$/.test(isian.Nitku)) {
        galat.Nitku = 'NITKU terdiri dari 22 angka.';
    }

    if (kunci === 'Kanvas' && isian.Kanvas && isian.NomorKendaraan.trim() !== '') {
        if (isian.NomorKendaraan.trim().length > 20) {
            galat.NomorKendaraan = 'Nomor kendaraan paling banyak 20 karakter.';
        } else if (!polaNomorKendaraan.test(isian.NomorKendaraan.trim())) {
            galat.NomorKendaraan = 'Nomor kendaraan hanya huruf, angka, dan spasi. Misal: AD 1234 XY.';
        }
    }

    return galat;
}
