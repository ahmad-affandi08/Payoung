import TampilanAbsen from '@/Komponen/Absensi/TampilanAbsen';
import type { PropsAbsenSaya } from '@/Tipe/Karyawan';

/**
 * Halaman Absen lewat tautan pribadi `/{slug}/absen/{token}` (F-18 bagian 4, D-198): tanpa login, tampilan dan alurnya
 * sama dengan halaman berlogin.
 */
export default function HalamanAbsenTautan(props: PropsAbsenSaya) {
    return <TampilanAbsen {...props} />;
}
