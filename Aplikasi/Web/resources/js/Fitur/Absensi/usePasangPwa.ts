import { useCallback, useEffect, useState } from 'react';

interface PemicuPasang extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export interface KeadaanPasangPwa {
    /** Halaman sudah dibuka dari layar utama (mode aplikasi) atau baru saja dipasang. */
    terpasang: boolean;
    /** Peramban menawarkan jendela pasang sendiri (Chrome/Edge Android dan desktop). */
    bisaPasang: boolean;
    /** iPhone/iPad Safari tidak punya jendela pasang; pengguna harus memakai menu Bagikan. */
    petunjukIos: boolean;
    Pasang: () => Promise<void>;
}

/** Jalur (tanpa asal) dari alamat dasar halaman Absen, mis. `/kelola/absen` atau `/{slug}/absen/{token}`. */
export function JalurHalamanAbsen(alamatDasar: string): string {
    return new URL(alamatDasar, window.location.href).pathname.replace(/\/+$/, '');
}

function SudahMandiri(): boolean {
    return (
        window.matchMedia?.('(display-mode: standalone)').matches === true ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

function PerangkatIos(): boolean {
    return (
        /iphone|ipad|ipod/i.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

/**
 * PWA halaman Absen (F-18 bagian 4, D-211): mendaftarkan service worker di jalur halaman itu sendiri dan
 * mengurus pemasangan ke layar utama HP, supaya karyawan tidak perlu membuka tautan terus-menerus.
 */
export function usePasangPwa(alamatDasar: string): KeadaanPasangPwa {
    const [pemicu, AturPemicu] = useState<PemicuPasang | null>(null);
    const [terpasang, AturTerpasang] = useState(SudahMandiri);

    useEffect(() => {
        const jalur = JalurHalamanAbsen(alamatDasar);

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(`${jalur}/pekerja-layanan`, { scope: jalur }).catch(() => undefined);
        }

        const SaatSiapPasang = (peristiwa: Event) => {
            peristiwa.preventDefault();
            AturPemicu(peristiwa as PemicuPasang);
        };
        const SaatTerpasang = () => {
            AturTerpasang(true);
            AturPemicu(null);
        };

        window.addEventListener('beforeinstallprompt', SaatSiapPasang);
        window.addEventListener('appinstalled', SaatTerpasang);

        return () => {
            window.removeEventListener('beforeinstallprompt', SaatSiapPasang);
            window.removeEventListener('appinstalled', SaatTerpasang);
        };
    }, [alamatDasar]);

    const Pasang = useCallback(async () => {
        if (pemicu === null) {
            return;
        }

        await pemicu.prompt();
        const pilihan = await pemicu.userChoice;
        AturPemicu(null);

        if (pilihan.outcome === 'accepted') {
            AturTerpasang(true);
        }
    }, [pemicu]);

    return {
        terpasang,
        bisaPasang: pemicu !== null && !terpasang,
        petunjukIos: !terpasang && pemicu === null && PerangkatIos(),
        Pasang,
    };
}
