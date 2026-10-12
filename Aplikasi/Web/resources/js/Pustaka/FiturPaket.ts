import { usePage } from '@inertiajs/react';

import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/**
 * D-111: apakah fitur paket ini terkunci untuk tenant aktif (di luar paket, add-on, dan override). Server tetap penentu
 * (`WajibFiturPaket`); hook ini hanya menyembunyikan tab/aksi yang pasti ditolak. Tanpa data paket (halaman tanpa tenant)
 * fitur dianggap tidak terkunci.
 */
export function PakaiFiturTerkunci(kunci: string): boolean {
    const { props } = usePage<PropsBersamaAplikasi>();

    return kunci in (props.FiturPaket?.Terkunci ?? {});
}
