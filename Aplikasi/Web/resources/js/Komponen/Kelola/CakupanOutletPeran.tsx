import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import type { CakupanOutlet, OpsiOutletPengguna, OpsiPeranPengguna } from '@/Tipe/Organisasi';

/*
 * D-104: cakupan outlet mengikuti peran, bukan saklar terpisah.
 * - Semua    : peran tingkat usaha (Pemilik, Admin, Akuntan) — tanpa pilihan, selalu semua outlet.
 * - Terpilih : peran operasional (Manajer Outlet, Staf Gudang, Kasir, ...) — hanya pilihan outlet, wajib minimal satu.
 * - Bebas    : peran kustom — pembuat akses memilih sendiri semua outlet atau outlet tertentu.
 */

export type IsianCakupan = { SemuaOutlet: boolean; Outlet: string[] };

/** Cakupan peran yang dipilih; peran belum dipilih diperlakukan Bebas supaya tidak ada pilihan yang hilang. */
export function AmbilCakupan(peran: OpsiPeranPengguna | undefined): CakupanOutlet {
    if (peran === undefined) {
        return 'Bebas';
    }

    return peran.Pemilik ? 'Semua' : peran.Cakupan;
}

/**
 * Isian cakupan yang konsisten dengan peran (dipakai saat peran berganti dan untuk isian awal formulir ubah). Peran Semua mengosongkan outlet; peran Terpilih mempertahankan outlet yang
 * sudah dipilih, dan bila usaha hanya punya satu outlet langsung memilihnya (aturan outlet diisi otomatis, v3.23).
 */
export function TerapkanCakupanPeran(
    peran: OpsiPeranPengguna | undefined,
    outlet: OpsiOutletPengguna[],
    sekarang: IsianCakupan,
): IsianCakupan {
    const cakupan = AmbilCakupan(peran);

    if (cakupan === 'Semua') {
        return { SemuaOutlet: true, Outlet: [] };
    }

    if (cakupan === 'Terpilih') {
        const [satuSatunya] = outlet;
        const pilihan =
            sekarang.Outlet.length > 0 ? sekarang.Outlet : outlet.length === 1 && satuSatunya ? [satuSatunya.Uuid] : [];

        return { SemuaOutlet: false, Outlet: pilihan };
    }

    return { SemuaOutlet: sekarang.SemuaOutlet, Outlet: sekarang.Outlet };
}

type PropsCakupanOutletPeran = {
    peran: OpsiPeranPengguna | undefined;
    outlet: OpsiOutletPengguna[];
    nilai: IsianCakupan;
    saatBerubah: (isian: IsianCakupan) => void;
    galatSemuaOutlet?: string | undefined;
    galatOutlet?: string | undefined;
};

function PilihanOutlet({
    outlet,
    nilai,
    saatBerubah,
    galat,
}: {
    outlet: OpsiOutletPengguna[];
    nilai: IsianCakupan;
    saatBerubah: (isian: IsianCakupan) => void;
    galat?: string | undefined;
}) {
    return (
        <GrupCentang
            legenda="Outlet yang ditugaskan"
            opsi={outlet.map((baris) => ({ nilai: baris.Uuid, label: `${baris.Kode} | ${baris.Nama}` }))}
            terpilih={nilai.Outlet}
            saatBerubah={(terpilih) => saatBerubah({ SemuaOutlet: false, Outlet: terpilih })}
            galat={galat}
            required
        />
    );
}

/** Bagian "outlet" pada formulir akses anggota: isinya berubah menurut cakupan peran yang dipilih. */
export default function CakupanOutletPeran({
    peran,
    outlet,
    nilai,
    saatBerubah,
    galatSemuaOutlet,
    galatOutlet,
}: PropsCakupanOutletPeran) {
    if (peran === undefined) {
        return null;
    }

    const cakupan = AmbilCakupan(peran);

    if (cakupan === 'Semua') {
        return (
            <p className="text-isi text-teks-sekunder" role="status">
                <span className="font-semibold text-teks-utama">Akses: semua outlet.</span> Peran {peran.Nama} otomatis
                mencakup seluruh outlet, termasuk outlet baru. Untuk orang yang hanya mengurus satu cabang, pilih
                Manajer Outlet atau Staf Gudang.
            </p>
        );
    }

    if (cakupan === 'Terpilih') {
        return <PilihanOutlet outlet={outlet} nilai={nilai} saatBerubah={saatBerubah} galat={galatOutlet} />;
    }

    return (
        <>
            <KotakCentang
                label="Semua outlet, termasuk outlet baru"
                nilai={nilai.SemuaOutlet}
                saatBerubah={(semua) => saatBerubah({ ...nilai, SemuaOutlet: semua })}
            />
            {galatSemuaOutlet ? <p className="text-keterangan font-semibold text-bahaya">{galatSemuaOutlet}</p> : null}
            {nilai.SemuaOutlet ? null : (
                <PilihanOutlet outlet={outlet} nilai={nilai} saatBerubah={saatBerubah} galat={galatOutlet} />
            )}
        </>
    );
}
