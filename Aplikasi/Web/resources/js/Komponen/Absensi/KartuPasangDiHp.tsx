import { ShareIcon, SmartphoneIcon } from 'lucide-react';

import Tombol from '@/Komponen/Formulir/Tombol';
import type { KeadaanPasangPwa } from '@/Fitur/Absensi/usePasangPwa';

/**
 * Ajakan memasang halaman Absen ke layar utama HP (F-18 bagian 4, D-211). Tombol hanya muncul bila peramban
 * menawarkan pemasangan; di iPhone/iPad berupa petunjuk Bagikan → Tambahkan ke Layar Utama. Sudah terpasang atau
 * peramban tidak mendukung = tidak tampil sama sekali.
 */
export default function KartuPasangDiHp({ keadaan }: { keadaan: KeadaanPasangPwa }) {
    if (keadaan.bisaPasang) {
        return (
            <section
                className="flex items-center gap-3 rounded-panel border border-garis bg-permukaan p-4"
                aria-label="Pasang di HP"
            >
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-info-lembut text-info">
                    <SmartphoneIcon aria-hidden="true" className="size-5" />
                </span>
                <p className="min-w-0 flex-1 text-label text-teks-utama">
                    Pasang di layar utama HP agar absen tinggal ketuk ikonnya.
                </p>
                <Tombol onClick={() => void keadaan.Pasang()}>Pasang di HP</Tombol>
            </section>
        );
    }

    if (keadaan.petunjukIos) {
        return (
            <section
                className="flex items-start gap-3 rounded-panel border border-garis bg-permukaan p-4"
                aria-label="Pasang di HP"
            >
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-info-lembut text-info">
                    <ShareIcon aria-hidden="true" className="size-5" />
                </span>
                <p className="min-w-0 flex-1 text-label text-teks-utama">
                    Pasang di layar utama iPhone: buka lewat Safari, ketuk tombol Bagikan, lalu pilih “Tambahkan ke
                    Layar Utama”.
                </p>
            </section>
        );
    }

    return null;
}
