import { BandingkanDesimal, CekDesimalValid, JumlahkanDesimal } from '@/Pustaka/HitungDesimal';

export const NamaBulan = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
] as const;

/** Tiga huruf pertama untuk kepala kolom sempit: "Jan", "Feb", ... */
export function SingkatBulan(indeks: number): string {
    return (NamaBulan[indeks] ?? '').slice(0, 3);
}

/** Jumlah angka bulanan yang terisi (kosong atau bukan desimal dihitung nol); string desimal tanpa float. */
export function HitungTotalSetahun(perBulan: readonly string[]): string {
    return JumlahkanDesimal(perBulan.filter((nilai) => nilai !== '' && CekDesimalValid(nilai)));
}

/** True bila ada minimal satu bulan yang berisi angka lebih dari nol. */
export function AdaAngkaAnggaran(perBulan: readonly string[]): boolean {
    return perBulan.some((nilai) => nilai !== '' && CekDesimalValid(nilai) && /[1-9]/.test(nilai));
}

/** Dua daftar angka bulanan sama nilainya ("12" = "12.00"; kosong dianggap nol). */
export function SamaAnggaran(a: readonly string[], b: readonly string[]): boolean {
    const Angka = (nilai: string | undefined) =>
        nilai === undefined || nilai === '' || !CekDesimalValid(nilai) ? '0' : nilai;

    return a.length === b.length && a.every((nilai, indeks) => BandingkanDesimal(Angka(nilai), Angka(b[indeks])) === 0);
}
