import { TulisTanggal } from '@/Pustaka/Tanggal';

/** Pilihan cepat periode laporan Pemasukan & Pengeluaran (D-255). Semua berakhir hari ini, kecuali Bulan lalu. */
export type KunciPeriode = 'HariIni' | 'MingguIni' | 'BulanIni' | 'BulanLalu' | 'TahunIni';

export const DaftarPeriode: { kunci: KunciPeriode; label: string }[] = [
    { kunci: 'HariIni', label: 'Hari ini' },
    { kunci: 'MingguIni', label: 'Minggu ini' },
    { kunci: 'BulanIni', label: 'Bulan ini' },
    { kunci: 'BulanLalu', label: 'Bulan lalu' },
    { kunci: 'TahunIni', label: 'Tahun ini' },
];

/** Rentang `TTTT-BB-HH` untuk pilihan cepat. Minggu dimulai hari Senin. */
export function HitungPeriode(kunci: KunciPeriode, hariIni: Date = new Date()): { Dari: string; Sampai: string } {
    const tahun = hariIni.getFullYear();
    const bulan = hariIni.getMonth();
    const tanggal = hariIni.getDate();
    // getDay: 0 = Minggu. Selisih ke Senin: Senin 0, Selasa 1, ..., Minggu 6.
    const selisihSenin = (hariIni.getDay() + 6) % 7;

    switch (kunci) {
        case 'HariIni':
            return { Dari: TulisTanggal(hariIni), Sampai: TulisTanggal(hariIni) };
        case 'MingguIni':
            return {
                Dari: TulisTanggal(new Date(tahun, bulan, tanggal - selisihSenin)),
                Sampai: TulisTanggal(hariIni),
            };
        case 'BulanIni':
            return { Dari: TulisTanggal(new Date(tahun, bulan, 1)), Sampai: TulisTanggal(hariIni) };
        case 'BulanLalu':
            return {
                Dari: TulisTanggal(new Date(tahun, bulan - 1, 1)),
                Sampai: TulisTanggal(new Date(tahun, bulan, 0)),
            };
        case 'TahunIni':
            return { Dari: TulisTanggal(new Date(tahun, 0, 1)), Sampai: TulisTanggal(hariIni) };
    }
}

/** Pilihan cepat yang rentangnya sama dengan saringan aktif, atau null bila rentangnya dipilih sendiri. */
export function CariPeriodeAktif(dari: string, sampai: string, hariIni: Date = new Date()): KunciPeriode | null {
    for (const { kunci } of DaftarPeriode) {
        const rentang = HitungPeriode(kunci, hariIni);

        if (rentang.Dari === dari && rentang.Sampai === sampai) {
            return kunci;
        }
    }

    return null;
}

/** Frasa pembanding setelah angka persen: "naik 4,2% dari bulan lalu". */
export function FrasaPembanding(jenis: string): string {
    switch (jenis) {
        case 'Kemarin':
            return 'dari kemarin';
        case 'MingguLalu':
            return 'dari minggu lalu';
        case 'BulanLalu':
            return 'dari bulan lalu';
        case 'TahunLalu':
            return 'dari tahun lalu';
        default:
            return 'dari periode sebelumnya';
    }
}
