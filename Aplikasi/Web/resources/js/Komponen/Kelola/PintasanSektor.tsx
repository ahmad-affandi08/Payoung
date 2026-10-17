import { Link, usePage } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';

import Panel from '@/Komponen/Kelola/Panel';
import { CekButirSesuaiEdisi, daftarPengaturan, type ButirPengaturan } from '@/Pustaka/DaftarPengaturan';
import { CekSesuaiSektor } from '@/Pustaka/Sektor';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { PunyaIzinTenant } from '@/Tipe/Organisasi';

/**
 * Pintasan Beranda untuk fitur khusus jenis usaha yang rumahnya Pengaturan (mis. Laporan apotek), supaya tidak
 * terkubur. Butir ditandai `pintasanBeranda` di `Pustaka/DaftarPengaturan`; hanya tampil bila sektor outlet sudah
 * diketahui dan cocok (sektor kosong tidak menampilkan apa pun, agar Beranda tenant baru tidak ramai), pengguna
 * berizin, dan fiturnya ada di paket. Tidak muncul sama sekali bila tidak ada butir yang berlaku.
 */
export default function PintasanSektor() {
    const { props } = usePage<PropsBersamaAplikasi>();
    const sektor = props.SektorOutlet ?? [];
    const terkunci = props.FiturPaket?.Terkunci ?? {};
    const butir = daftarPengaturan
        .flatMap((grup) => grup.butir)
        .filter(
            (satu: ButirPengaturan) =>
                satu.pintasanBeranda === true &&
                sektor.length > 0 &&
                CekSesuaiSektor(satu, sektor) &&
                CekButirSesuaiEdisi(satu, props.Edisi) &&
                (satu.izin === null ||
                    PunyaIzinTenant(props.Akses, satu.izin) ||
                    (satu.atau !== undefined && PunyaIzinTenant(props.Akses, satu.atau))) &&
                (satu.fitur === undefined || terkunci[satu.fitur] === undefined),
        );

    if (butir.length === 0) {
        return null;
    }

    return (
        <Panel judul="Untuk jenis usaha Anda" tingkat="h2">
            <ul className="grid gap-2 sm:grid-cols-2" aria-label="Pintasan jenis usaha">
                {butir.map((satu) => (
                    <li key={satu.href}>
                        <Link
                            href={satu.href}
                            className="flex min-h-11 items-start justify-between gap-3 rounded-panel border border-garis bg-permukaan px-4 py-3 hover:bg-permukaan-redup focus-visible:bg-permukaan-redup"
                        >
                            <span className="flex flex-col gap-1">
                                <span className="text-label font-semibold text-teks-utama">{satu.label}</span>
                                <span className="text-keterangan text-teks-sekunder">{satu.keterangan}</span>
                            </span>
                            <ChevronRightIcon className="mt-0.5 size-4 shrink-0 text-teks-sekunder" aria-hidden />
                        </Link>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}
