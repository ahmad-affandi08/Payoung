import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';

/**
 * Tawaran melanjutkan draf yang tersimpan di peramban (lihat `useDrafLokal`). Muncul di atas form yang masih kosong.
 */
export default function TawaranDraf({
    disimpan,
    saatLanjutkan,
    saatBuang,
}: {
    /** Waktu draf disimpan (ISO). */
    disimpan: string;
    saatLanjutkan: () => void;
    saatBuang: () => void;
}) {
    return (
        <Pemberitahuan jenis="info" judul="Ada isian yang belum selesai">
            Disimpan otomatis di peramban ini pada {FormatTanggalWaktu(disimpan)}.
            <span className="mt-2 flex flex-wrap gap-2">
                <Tombol varian="sekunder" onClick={saatLanjutkan}>
                    Lanjutkan isian
                </Tombol>
                <Tombol varian="sekunder" onClick={saatBuang}>
                    Buang
                </Tombol>
            </span>
        </Pemberitahuan>
    );
}
