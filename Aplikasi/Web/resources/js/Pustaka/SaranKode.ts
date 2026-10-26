/** Kata yang menjelaskan jenis/badan usaha, bukan namanya (sama dengan `PembuatKodeOutlet` di server). */
const KATA_ABAIKAN = ['PT', 'CV', 'UD', 'PD', 'TOKO', 'WARUNG', 'KEDAI', 'USAHA', 'DAN', 'DI', 'THE', 'AND'];
const VOKAL = ['A', 'I', 'U', 'E', 'O'];

function AmbilKata(nama: string): string[] {
    const kata = nama
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toUpperCase()
        .split(/[^A-Z0-9]+/)
        .filter((k) => k !== '');
    const inti = kata.filter((k) => !KATA_ABAIKAN.includes(k));

    return inti.length === 0 ? kata : inti;
}

/**
 * Saran kode outlet dari nama (3–5 karakter, diawali huruf): satu kata → empat huruf pertama; beberapa kata → huruf
 * pertama tiap kata, dilengkapi konsonan kata terakhir sampai tiga huruf (Raja Sayur → RSY). Aturannya sama dengan kode
 * outlet pertama yang dibuat server; kosong bila nama belum cukup untuk disarankan.
 */
export function SaranKodeOutlet(nama: string): string {
    const kata = AmbilKata(nama);

    if (kata.length === 0) {
        return '';
    }

    let kode: string;

    if (kata.length === 1) {
        kode = (kata[0] ?? '').slice(0, 4);
    } else {
        const dipakai = kata.slice(0, 5);
        const terakhir = dipakai[dipakai.length - 1] ?? '';
        kode = dipakai.map((k) => k.charAt(0)).join('');

        for (const hanyaKonsonan of [true, false]) {
            for (let i = 1; i < terakhir.length && kode.length < 3; i++) {
                const huruf = terakhir.charAt(i);

                if (!hanyaKonsonan || !VOKAL.includes(huruf)) {
                    kode += huruf;
                }
            }
        }
    }

    return /^[A-Z][A-Z0-9]{2,4}$/.test(kode) ? kode : '';
}

/** Saran kode pemasok dari nama: kata inti digabung "-", huruf besar, maksimal 30 karakter (CV Maju Jaya → MAJU-JAYA). */
export function SaranKodePemasok(nama: string): string {
    const gabung = AmbilKata(nama).join('-');

    return gabung.length <= 30
        ? gabung
        : gabung
              .slice(0, 30)
              .replace(/-[^-]*$/, '')
              .replace(/-$/, '');
}
