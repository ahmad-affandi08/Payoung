import { Link, usePage } from '@inertiajs/react';
import { ClockIcon } from 'lucide-react';

import Panel from '@/Komponen/Kelola/Panel';
import { ALAMAT_ABSEN } from '@/Fitur/Absensi/Alamat';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/**
 * Pintasan Beranda ke halaman Absen (D-180) untuk karyawan yang wajib absen. Tidak muncul bagi yang tidak ikut
 * absensi (manajer, supervisor, pemilik) atau yang akunnya belum ditautkan ke data karyawan.
 */
export default function KartuAbsenSaya() {
    const { props } = usePage<PropsBersamaAplikasi>();

    if (props.AbsenSaya !== true) {
        return null;
    }

    return (
        <Panel judul="Absen" tingkat="h2" keterangan="Catat jam masuk dan pulang Anda dari HP.">
            <Link
                href={ALAMAT_ABSEN}
                className="inline-flex h-8 items-center gap-2 rounded-md bg-brand px-4 text-label font-semibold text-brand-teks pointer-coarse:h-11"
            >
                <ClockIcon aria-hidden="true" className="size-4" />
                Buka halaman Absen
            </Link>
        </Panel>
    );
}
