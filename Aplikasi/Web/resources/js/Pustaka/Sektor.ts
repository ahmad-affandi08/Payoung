import { usePage } from '@inertiajs/react';

import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/**
 * Audit kemudahan pakai #33 (D-38, D-48): fitur khusus sektor tampil bila salah satu outlet (atau jenis usaha tambahan)
 * memakai sektor berawalan itu; `kecualiSektor` menyembunyikannya bila semua sektor usaha ada di daftar itu (D-167).
 * `sektor` kosong = fitur umum; daftar sektor outlet kosong = sektor belum diketahui, semua tampil. Awalan cocok per
 * segmen: `SVC` mencakup `SVC-WRK`, `SVC-WRK` hanya Bengkel.
 */
export function CekSesuaiSektor(
    menu: { sektor?: string[] | undefined; kecualiSektor?: string[] | undefined },
    sektorOutlet: string[],
): boolean {
    if (sektorOutlet.length === 0) {
        return true;
    }

    const Cocok = (awalan: string, kode: string) => kode === awalan || kode.startsWith(`${awalan}-`);

    // D-167: butir yang tidak bermakna bagi usaha tanpa kasir (kasir, shift, promo toko) disembunyikan hanya bila SEMUA
    // sektor usaha termasuk daftar pengecualian; usaha campuran (mis. konsultan yang juga punya toko) tetap melihatnya.
    if (
        menu.kecualiSektor !== undefined &&
        sektorOutlet.every((kode) => menu.kecualiSektor?.some((a) => Cocok(a, kode)))
    ) {
        return false;
    }

    if (menu.sektor === undefined) {
        return true;
    }

    return menu.sektor.some((awalan) => sektorOutlet.some((kode) => Cocok(awalan, kode)));
}

/** Hook: apakah tenant ini punya usaha bersektor salah satu awalan di `sektor`. */
export function PakaiSektor(sektor: string[]): boolean {
    const { props } = usePage<PropsBersamaAplikasi>();

    return CekSesuaiSektor({ sektor }, props.SektorOutlet ?? []);
}
