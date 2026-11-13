import { usePage } from '@inertiajs/react';

import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/**
 * D-299: true bila layanan WhatsApp tersedia bagi tenant ini, yaitu konsol sudah mengaktifkannya dan paket tenant
 * memuatnya. Semua tombol, menu, dan isian yang berkaitan dengan WhatsApp wajib memeriksa ini dan hilang bila false
 * (default false, jadi halaman yang belum memuat props tidak menampilkan WhatsApp).
 */
export function useWhatsappTersedia(): boolean {
    const { props } = usePage<PropsBersamaAplikasi>();

    return props.WhatsappTersedia === true;
}

/** Kunci fitur paket yang menandai butir menu atau pengaturan khusus WhatsApp. */
export const KUNCI_FITUR_WHATSAPP = 'integrasi.whatsapp';

/**
 * D-299: butir menu atau pengaturan WhatsApp disembunyikan penuh (tanpa gembok) bila konsol belum mengaktifkan layanan.
 * Bila konsol aktif tetapi paket tidak memuatnya, butir tetap tampil dengan gembok paket (ajakan naik paket).
 */
export function CekButirWhatsappTampil(fitur: string | undefined, platformAktif: boolean): boolean {
    return fitur !== KUNCI_FITUR_WHATSAPP || platformAktif;
}

/** True bila konsol sudah mengaktifkan layanan WhatsApp platform (tanpa memandang paket). */
export function useWhatsappPlatformAktif(): boolean {
    const { props } = usePage<PropsBersamaAplikasi>();

    return props.WhatsappPlatformAktif === true;
}

/** Label dari server yang menyebut WhatsApp ("Terbitkan dan kirim WhatsApp") dinetralkan bila layanan tidak tersedia. */
export function LabelTanpaWhatsapp(label: string, tersedia: boolean): string {
    return tersedia
        ? label
        : label
              .replace(/\s+dan kirim WhatsApp/i, '')
              .replace(/\s*WhatsApp\s*/i, ' ')
              .trim();
}
