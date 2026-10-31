/**
 * POST JSON ke endpoint back-office dari luar alur Inertia, untuk hal yang jawabannya memang data — misal token
 * transaksi Snap (BR-P08.11) yang dipakai skrip gerbang, bukan halaman baru.
 *
 * Inertia mengirim CSRF lewat axios bawaannya, yang membaca cookie `XSRF-TOKEN` dan memasangnya sebagai header
 * `X-XSRF-TOKEN`. `fetch` tidak melakukannya sendiri, jadi dikerjakan di sini.
 */

/** Token CSRF Laravel dari string cookie. String kosong bila cookienya tidak ada. */
export function AmbilTokenXsrf(kuki: string): string {
    const cocok = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(kuki);

    return cocok === null ? '' : decodeURIComponent(cocok[1] ?? '');
}

/** Pesan galat dari format galat API Payoung (`{"Galat": {"Kode", "Pesan"}}`, §16); null bila bukan bentuk itu. */
export function AmbilPesanGalat(isi: unknown): string | null {
    if (typeof isi !== 'object' || isi === null || !('Galat' in isi)) {
        return null;
    }

    const galat = (isi as { Galat: unknown }).Galat;

    if (typeof galat !== 'object' || galat === null || !('Pesan' in galat)) {
        return null;
    }

    const pesan = (galat as { Pesan: unknown }).Pesan;

    return typeof pesan === 'string' && pesan !== '' ? pesan : null;
}

/**
 * Pesan galat validasi bawaan Laravel (`{"errors": {"Kode": ["…"]}}`, status 422): pesan pertama yang ada.
 * Dipakai agar dialog dan isian cepat bisa menampilkan penyebab sebenarnya, bukan pesan umum.
 */
export function AmbilPesanValidasi(isi: unknown): string | null {
    if (typeof isi !== 'object' || isi === null || !('errors' in isi)) {
        return null;
    }

    const galat = (isi as { errors: unknown }).errors;

    if (typeof galat !== 'object' || galat === null) {
        return null;
    }

    for (const pesan of Object.values(galat as Record<string, unknown>)) {
        const pertama: unknown = Array.isArray(pesan) ? pesan[0] : pesan;

        if (typeof pertama === 'string' && pertama !== '') {
            return pertama;
        }
    }

    return null;
}

/** Kode galat API Payoung (`Galat.Kode`); null bila bukan bentuk itu. */
export function AmbilKodeGalat(isi: unknown): string | null {
    if (typeof isi !== 'object' || isi === null || !('Galat' in isi)) {
        return null;
    }

    const galat = (isi as { Galat: unknown }).Galat;
    const kode =
        typeof galat === 'object' && galat !== null && 'Kode' in galat ? (galat as { Kode: unknown }).Kode : null;

    return typeof kode === 'string' && kode !== '' ? kode : null;
}

/** Galat dari `KirimJson`: pesan untuk pengguna, plus status HTTP & kode galat untuk percabangan di halaman. */
export class GalatPermintaan extends Error {
    constructor(
        pesan: string,
        readonly status: number,
        readonly kode: string | null,
    ) {
        super(pesan);
        this.name = 'GalatPermintaan';
    }
}

/** Pesan cadangan per status HTTP bila jawaban server tidak membawa pesan galat Payoung (§17.6.7). */
export function PesanStatusHttp(status: number): string {
    if (status === 419) {
        return 'Sesi halaman kedaluwarsa. Muat ulang halaman, lalu coba lagi.';
    }

    if (status === 429) {
        return 'Terlalu banyak percobaan. Tunggu satu menit, lalu coba lagi.';
    }

    if (status === 422) {
        return 'Data yang dikirim tidak lengkap atau tidak sah. Muat ulang halaman, lalu coba lagi.';
    }

    if (status === 404) {
        return 'Halaman tidak ditemukan atau tautannya sudah tidak berlaku.';
    }

    return status >= 500
        ? 'Server sedang bermasalah. Coba lagi beberapa saat lagi.'
        : `Permintaan gagal (${String(status)}).`;
}

export async function KirimJson<T>(alamat: string, data: unknown = {}, metode: 'POST' | 'PUT' = 'POST'): Promise<T> {
    const respons = await fetch(alamat, {
        method: metode,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': AmbilTokenXsrf(document.cookie),
        },
        credentials: 'same-origin',
        body: JSON.stringify(data),
    });
    const isi: unknown = await respons.json().catch(() => null);

    if (!respons.ok) {
        throw new GalatPermintaan(
            AmbilPesanGalat(isi) ??
                (respons.status === 422 ? AmbilPesanValidasi(isi) : null) ??
                PesanStatusHttp(respons.status),
            respons.status,
            AmbilKodeGalat(isi),
        );
    }

    return isi as T;
}

/** GET JSON dengan galat yang sama bentuknya dengan `KirimJson` (pesan Payoung, status, kode). */
export async function AmbilJson<T>(alamat: string, sinyal?: AbortSignal): Promise<T> {
    const respons = await fetch(alamat, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        ...(sinyal === undefined ? {} : { signal: sinyal }),
    });
    const isi: unknown = await respons.json().catch(() => null);

    if (!respons.ok) {
        throw new GalatPermintaan(
            AmbilPesanGalat(isi) ?? PesanStatusHttp(respons.status),
            respons.status,
            AmbilKodeGalat(isi),
        );
    }

    return isi as T;
}
