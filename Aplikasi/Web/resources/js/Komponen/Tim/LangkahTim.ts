/**
 * Logika murni wizard "Tambah anggota tim" (D-101): daftar langkah menurut cara masuk yang dipilih, pemetaan kunci
 * galat server ke langkah, dan validasi klien per langkah. Aturan mencerminkan `TambahTimPermintaan` dan `PenjagaPin`;
 * server tetap penentu.
 */
import type { LangkahWizard } from '@/Komponen/Formulir/Wizard/TipeWizard';

export type AksesTim = 'Kasir' | 'Dashboard' | 'Tanpa';

export type KunciLangkahTim = 'Siapa' | 'Akun' | 'Peran' | 'Outlet' | 'Gaji' | 'Tinjau';
export type LangkahTim = LangkahWizard<KunciLangkahTim>;

/** Urutan baku semua langkah; daftar langkah aktif selalu subhimpunan berurutan dari sini. */
export const urutanLangkahTim: KunciLangkahTim[] = ['Siapa', 'Akun', 'Peran', 'Outlet', 'Gaji', 'Tinjau'];

export type KonteksLangkahTim = {
    akses: AksesTim;
    /** Pengguna boleh mencatat gaji (punya izin karyawan) dan orang ini dicatat sebagai karyawan. */
    punyaKaryawan: boolean;
    /** Pengguna memilih mengatur gaji & komisi sekarang; bawaannya nanti saja. */
    aturGaji: boolean;
};

const keteranganLangkah: Record<KunciLangkahTim, string> = {
    Siapa: 'Siapa yang ditambahkan, dan bagaimana ia masuk.',
    Akun: 'Data untuk masuk. Berikan ke orangnya setelah disimpan.',
    Peran: 'Tugasnya menentukan menu yang bisa dibuka, dan outlet mana yang boleh ia akses.',
    Outlet: 'Orang ini tidak punya akun masuk, jadi hanya dicatat untuk jadwal, absensi, dan gaji.',
    Gaji: 'Dipakai rekap gaji dan aturan komisi. Semua boleh dikosongkan dan diisi nanti di menu Tim.',
    Tinjau: 'Periksa ringkasan di bawah. Ubah bagian yang perlu, lalu tambahkan ke tim.',
};

/**
 * Langkah menurut cara masuk: akun (Kasir/Dashboard) melewati Akun lalu Peran; "Tanpa akun" hanya memilih outlet.
 * Gaji & komisi hanya muncul bila diminta, supaya menambah kasir tetap singkat.
 */
export function SusunLangkahTim({ akses, punyaKaryawan, aturGaji }: KonteksLangkahTim): LangkahTim[] {
    const berakun = akses !== 'Tanpa';
    const kunci: KunciLangkahTim[] = [
        'Siapa',
        ...(berakun ? (['Akun', 'Peran'] as const) : (['Outlet'] as const)),
        ...(punyaKaryawan && aturGaji ? (['Gaji'] as const) : []),
        'Tinjau',
    ];
    const label: Record<KunciLangkahTim, string> = {
        Siapa: 'Siapa & cara masuk',
        Akun: 'Akun masuk',
        Peran: 'Tugas & outlet',
        Outlet: 'Outlet utama',
        Gaji: 'Gaji & komisi',
        Tinjau: 'Tinjau & tambah',
    };

    return kunci.map((item) => ({ Kunci: item, Label: label[item], Keterangan: keteranganLangkah[item] }));
}

const kunciSiapa = ['Akses', 'Nama', 'Jabatan'];
const kunciAkun = ['Email', 'KataSandi', 'Pin', 'NoHp'];
const kunciPeran = ['Peran', 'SemuaOutlet', 'Outlet', 'JugaKaryawan', 'UuidKaryawan', 'IkutAbsensi'];
const kunciGaji = [
    'LevelStaf',
    'GajiPokok',
    'TarifLemburPerJam',
    'PotonganTerlambatPerMenit',
    'PotonganTidakMasukPerHari',
];

/** Langkah yang memuat isian dari kunci galat server. `Outlet` (array) dan `UuidOutlet` bergantung cara masuk. */
export function PetakanGalatTimKeLangkah(kunci: string, akses: AksesTim): KunciLangkahTim | null {
    if (kunci === 'UuidOutlet') {
        return 'Outlet';
    }

    if (kunciSiapa.includes(kunci)) {
        return 'Siapa';
    }

    if (kunciAkun.includes(kunci)) {
        return 'Akun';
    }

    if (kunciPeran.includes(kunci) || kunci.startsWith('Outlet.')) {
        return akses === 'Tanpa' ? 'Outlet' : 'Peran';
    }

    return kunciGaji.includes(kunci) ? 'Gaji' : null;
}

export function HitungLangkahTimBergalat(
    galat: Record<string, string | undefined>,
    akses: AksesTim,
): KunciLangkahTim[] {
    const ada = new Set<KunciLangkahTim>();

    for (const [kunci, pesan] of Object.entries(galat)) {
        const langkah = pesan ? PetakanGalatTimKeLangkah(kunci, akses) : null;

        if (langkah !== null) {
            ada.add(langkah);
        }
    }

    return urutanLangkahTim.filter((kunci) => ada.has(kunci));
}

export function AmbilGalatLangkahTim(
    galat: Record<string, string | undefined>,
    kunci: KunciLangkahTim,
    akses: AksesTim,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(galat).filter(
            ([isi, pesan]) => Boolean(pesan) && PetakanGalatTimKeLangkah(isi, akses) === kunci,
        ),
    );
}

/** Aturan `PenjagaPin`: 6 angka, bukan angka yang sama semua, bukan deret naik/turun. Null = PIN sah. */
export function PeriksaPinKasir(pin: string): string | null {
    if (!/^\d{6}$/.test(pin)) {
        return 'PIN harus 6 angka.';
    }

    if (new Set(pin).size === 1) {
        return 'PIN terlalu mudah ditebak: jangan pakai angka yang sama semua, misal 111111.';
    }

    if ('0123456789'.includes(pin) || '9876543210'.includes(pin)) {
        return 'PIN terlalu mudah ditebak: jangan pakai angka berurutan, misal 123456 atau 654321.';
    }

    return null;
}

export type IsianTim = {
    Akses: AksesTim;
    Nama: string;
    Jabatan: string;
    Email: string;
    NoHp: string;
    KataSandi: string;
    Pin: string;
    Peran: string;
    UuidOutlet: string;
    LevelStaf: string;
    GajiPokok: string;
    TarifLemburPerJam: string;
    PotonganTerlambatPerMenit: string;
    PotonganTidakMasukPerHari: string;
};

export type KonteksPeriksaTim = {
    isian: IsianTim;
    /** Jenis akses yang boleh dipilih pengguna ini. */
    aksesTersedia: AksesTim[];
    /** Cakupan outlet peran terpilih kosong padahal peran butuh outlet terpilih. */
    outletKurang: boolean;
};

const polaUang = /^\d{1,16}(\.\d{1,2})?$/;
const polaEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Galat klien satu langkah, berkunci sama dengan galat server. Kosong = langkah boleh dilanjutkan. */
export function PeriksaLangkahTim(
    kunci: KunciLangkahTim,
    { isian, aksesTersedia, outletKurang }: KonteksPeriksaTim,
): Record<string, string> {
    const galat: Record<string, string> = {};

    if (kunci === 'Siapa') {
        if (isian.Nama.trim() === '') {
            galat.Nama = 'Isi nama dulu.';
        } else if (isian.Nama.trim().length > 150) {
            galat.Nama = 'Nama maksimal 150 karakter.';
        }

        if (isian.Jabatan.trim().length > 80) {
            galat.Jabatan = 'Jabatan maksimal 80 karakter.';
        }

        if (!aksesTersedia.includes(isian.Akses)) {
            galat.Akses = 'Pilih apakah ia masuk lewat kasir, dashboard, atau tanpa akun.';
        }
    }

    if (kunci === 'Akun') {
        if (isian.Akses === 'Dashboard') {
            if (isian.Email.trim() === '') {
                galat.Email = 'Isi email untuk masuk dashboard.';
            } else if (!polaEmail.test(isian.Email.trim()) || isian.Email.trim().length > 191) {
                galat.Email = 'Isi email yang benar, misal nama@usaha.id.';
            }

            if (isian.KataSandi === '') {
                galat.KataSandi = 'Isi kata sandi awal untuk pengguna yang memakai email.';
            } else if (isian.KataSandi.length < 8 || !/[A-Za-z]/.test(isian.KataSandi) || !/\d/.test(isian.KataSandi)) {
                galat.KataSandi = 'Kata sandi minimal 8 karakter, berisi huruf dan angka.';
            }
        }

        if (isian.Akses === 'Kasir' || isian.Pin !== '') {
            const pesanPin =
                isian.Pin === ''
                    ? 'Karyawan tanpa email wajib diberi PIN untuk masuk aplikasi kasir.'
                    : PeriksaPinKasir(isian.Pin);

            if (pesanPin !== null) {
                galat.Pin = pesanPin;
            }
        }

        if (isian.NoHp.trim() !== '' && !/^\+?[0-9\- ]{8,20}$/.test(isian.NoHp.trim())) {
            galat.NoHp = 'Nomor WhatsApp berisi 8–20 angka, misal 081234567890.';
        }
    }

    if (kunci === 'Peran') {
        if (isian.Peran === '') {
            galat.Peran = 'Pilih peran.';
        } else if (outletKurang) {
            galat.Outlet = 'Pilih minimal satu outlet untuk peran ini.';
        }
    }

    if (kunci === 'Gaji') {
        const angka: [keyof IsianTim, string][] = [
            ['GajiPokok', 'Gaji pokok'],
            ['TarifLemburPerJam', 'Tarif lembur'],
            ['PotonganTerlambatPerMenit', 'Potongan terlambat'],
            ['PotonganTidakMasukPerHari', 'Potongan tidak masuk'],
        ];

        for (const [isi, nama] of angka) {
            const nilai = isian[isi];

            if (nilai !== '' && !polaUang.test(nilai)) {
                galat[isi] = `${nama} harus angka dengan pemisah desimal titik (maks. 2 desimal).`;
            }
        }

        if (isian.LevelStaf.trim().length > 40) {
            galat.LevelStaf = 'Level staf maksimal 40 karakter.';
        }
    }

    return galat;
}
