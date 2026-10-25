import TampilanAbsen from '@/Komponen/Absensi/TampilanAbsen';
import type { PropsAbsenSaya } from '@/Tipe/Karyawan';

/** Halaman Absen berlogin `/kelola/absen` (D-180). Tampilannya dipakai bersama tautan pribadi (D-198). */
export default function HalamanAbsenSaya(props: PropsAbsenSaya) {
    return <TampilanAbsen {...props} />;
}
