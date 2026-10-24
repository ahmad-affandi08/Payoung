import { router } from '@inertiajs/react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import { Label } from '@/Komponen/Ui/label';
import PemilihRentangTanggal from '@/Komponen/Tanggal/PemilihRentangTanggal';
import { GabungRentang, PecahRentang } from '@/Pustaka/Tanggal';
import type { OpsiLaporan } from '@/Tipe/Laporan';

export type DefinisiPilihanLaporan = {
    kunci: string;
    label: string;
    kosong: string;
    opsi: OpsiLaporan[];
};

type PropsSaringLaporan = {
    /** URL halaman laporan (tanpa query). */
    alamat: string;
    /** Semua parameter query halaman saat ini (tab, dari, sampai, outlet, ...). */
    query: Record<string, string>;
    /** Batas hari periode (keterangan di bawah isian). */
    maksHari?: number;
    pilihan: DefinisiPilihanLaporan[];
};

/**
 * Saring laporan dalam satu baris (§17.6.5): periode (`PemilihRentangTanggal`) dan pilihan ber-kotak cari
 * (`PilihanCari`). Setiap perubahan memuat ulang laporan lewat Inertia dengan keadaan di URL (bisa dibagikan).
 */
export default function SaringLaporan({ alamat, query, maksHari, pilihan }: PropsSaringLaporan) {
    const Terapkan = (ubah: Record<string, string>) => {
        const baru: Record<string, string> = {};

        for (const [kunci, nilai] of Object.entries({ ...query, ...ubah })) {
            if (nilai !== '') {
                baru[kunci] = nilai;
            }
        }

        router.get(alamat, baru, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="group" aria-label="Filter laporan">
            <PemilihRentangTanggal
                label="Periode"
                nilai={GabungRentang(query.dari ?? '', query.sampai ?? '')}
                keterangan={maksHari ? `Paling panjang ${String(maksHari)} hari.` : undefined}
                saatBerubah={(nilai) => {
                    const [dari, sampai] = PecahRentang(nilai);
                    Terapkan({ dari, sampai: sampai || dari });
                }}
            />
            {pilihan.map((p) => (
                <div key={p.kunci} className="flex flex-col gap-1">
                    <Label htmlFor={`saring-laporan-${p.kunci}`} className="text-label font-semibold text-teks-utama">
                        {p.label}
                    </Label>
                    <PilihanCari
                        id={`saring-laporan-${p.kunci}`}
                        label={p.label}
                        nilai={query[p.kunci] ?? ''}
                        opsi={p.opsi}
                        kosong={p.kosong}
                        saatBerubah={(nilai) => Terapkan({ [p.kunci]: nilai })}
                    />
                </div>
            ))}
        </div>
    );
}
