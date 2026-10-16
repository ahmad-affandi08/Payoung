import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { StatusTampilPenawaran } from '@/Tipe/Penawaran';

/** Akar rute back-office penawaran (F-21 bagian 3). */
export const AlamatPenawaran = '/kelola/penawaran';

/** Warna hanya penguat: teks status selalu tampil. Diterima = sukses, menunggu = peringatan, ditolak/batal/kedaluwarsa = bahaya. */
export function AmbilJenisStatusPenawaran(
    status: StatusTampilPenawaran,
): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    switch (status) {
        case 'Diterima':
        case 'DiInvoicekan':
            return 'sukses';
        case 'MenungguKeputusan':
            return 'peringatan';
        case 'Ditolak':
        case 'Dibatalkan':
        case 'Kedaluwarsa':
            return 'bahaya';
        default:
            return 'netral';
    }
}

export function LabelStatusPenawaran({ status, label }: { status: StatusTampilPenawaran; label: string }) {
    return <LabelStatus jenis={AmbilJenisStatusPenawaran(status)} teks={label} />;
}
