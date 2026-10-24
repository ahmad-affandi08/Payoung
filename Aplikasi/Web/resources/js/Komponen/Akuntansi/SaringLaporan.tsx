import { router } from '@inertiajs/react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import type { OpsiPilihan } from '@/Komponen/Formulir/PilihanCari';
import TombolEkspor from '@/Komponen/Laporan/TombolEkspor';
import PemilihRentangTanggal from '@/Komponen/Tanggal/PemilihRentangTanggal';
import { GabungRentang, PecahRentang } from '@/Pustaka/Tanggal';
import type { SaringLaporanKeuangan } from '@/Tipe/Akuntansi';

type SaringLengkap = SaringLaporanKeuangan & { Akun?: string };

/** Query laporan keuangan (`dari`, `sampai`, `outlet`, `akun`); nilai kosong tidak ditulis. */
export function BuatQueryLaporan(saring: SaringLengkap): string {
    const parameter = new URLSearchParams();

    if (saring.Akun) {
        parameter.set('akun', saring.Akun);
    }

    parameter.set('dari', saring.Dari);
    parameter.set('sampai', saring.Sampai);

    if (saring.Outlet !== '') {
        parameter.set('outlet', saring.Outlet);
    }

    return parameter.toString();
}

type PropsSaringLaporan = {
    alamat: string;
    saring: SaringLengkap;
    opsiOutlet: { Uuid: string; Nama: string }[];
    /** Buku besar: pilihan akun (wajib dipilih sebelum mutasi tampil). */
    opsiAkun?: OpsiPilihan[];
    /** Tautan ekspor (alamat + query saringan) untuk tombol Excel/CSV/cetak; null = belum bisa diekspor. */
    ekspor?: string | null;
    /** D-124: tawarkan "Ekspor besar" (rute ekspor memasang `EksporDiLatarBelakang`; buku besar). */
    eksporBesar?: boolean;
};

/**
 * Saringan laporan keuangan F-13a: periode (`PemilihRentangTanggal`), outlet dan akun (`BidangPilihan` dengan kotak
 * cari). Setiap perubahan memuat ulang laporan lewat Inertia dengan saringan di URL (bisa dibagikan/di-bookmark).
 */
export default function SaringLaporan({
    alamat,
    saring,
    opsiOutlet,
    opsiAkun,
    ekspor,
    eksporBesar = false,
}: PropsSaringLaporan) {
    const Terapkan = (ubah: Partial<SaringLengkap>) => {
        const baru = { ...saring, ...ubah };
        router.get(`${alamat}?${BuatQueryLaporan(baru)}`, {}, { preserveScroll: true, preserveState: true });
    };

    const UbahRentang = (nilai: string) => {
        const [dari, sampai] = PecahRentang(nilai);

        // Rentang dikosongkan = kembali ke bawaan server (bulan berjalan).
        if (dari === '' && sampai === '') {
            router.get(alamat, saring.Akun ? { akun: saring.Akun } : {}, { preserveScroll: true, preserveState: true });

            return;
        }

        Terapkan({ Dari: dari === '' ? sampai : dari, Sampai: sampai === '' ? dari : sampai });
    };

    return (
        <section
            aria-label="Filter laporan"
            className="grid gap-3 rounded-panel border border-garis bg-permukaan p-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end"
        >
            {opsiAkun ? (
                <BidangPilihan
                    label="Akun"
                    nilai={saring.Akun ?? ''}
                    opsi={opsiAkun}
                    kosong="Pilih akun"
                    saatBerubah={(nilai) => Terapkan({ Akun: nilai })}
                />
            ) : null}
            <PemilihRentangTanggal
                label="Periode"
                nilai={GabungRentang(saring.Dari, saring.Sampai)}
                saatBerubah={UbahRentang}
                kosong="Bulan ini"
            />
            <BidangPilihan
                label="Outlet"
                nilai={saring.Outlet}
                opsi={opsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                kosong="Semua outlet"
                saatBerubah={(nilai) => Terapkan({ Outlet: nilai })}
            />
            {ekspor !== undefined ? (
                <div className="flex lg:justify-end">
                    {ekspor === null ? (
                        <TombolEkspor alamat="" nonaktif className="w-full sm:w-auto" />
                    ) : (
                        <TombolEkspor
                            alamat={ekspor.split('?')[0] ?? ekspor}
                            query={ekspor.includes('?') ? ekspor.slice(ekspor.indexOf('?') + 1) : ''}
                            eksporBesar={eksporBesar}
                            className="w-full sm:w-auto"
                        />
                    )}
                </div>
            ) : null}
        </section>
    );
}
