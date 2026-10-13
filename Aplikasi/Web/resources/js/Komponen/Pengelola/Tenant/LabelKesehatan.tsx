import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { KategoriKesehatan } from '@/Tipe/TenantPengelola';

const jenisKategori = { Sehat: 'sukses', PerluPerhatian: 'peringatan', Berisiko: 'bahaya' } as const;

/** Kategori skor kesehatan tenant (P-07 PGL-09). Teks selalu tampil; warna hanya penguat. */
export default function LabelKesehatan({
    kategori,
    teks,
    skor,
}: {
    kategori: KategoriKesehatan | null;
    teks?: string | null | undefined;
    skor?: number | null | undefined;
}) {
    if (kategori === null) {
        return <LabelStatus jenis="netral" teks="Belum dinilai" />;
    }

    return (
        <LabelStatus
            jenis={jenisKategori[kategori]}
            teks={`${teks ?? kategori}${skor == null ? '' : ` | ${String(skor)}`}`}
        />
    );
}
