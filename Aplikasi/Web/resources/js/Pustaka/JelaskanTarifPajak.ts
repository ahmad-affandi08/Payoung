import { FormatPersen } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { TarifTampil } from '@/Tipe/PanduanAwal';

/** "10.000000" berlaku "2024-01-01" menjadi "10% berlaku mulai 1 Jan 2024 (Perda No. 1 Tahun 2024)". */
export function JelaskanTarifPajak(tarif: TarifTampil): string {
    const tanggal = /^\d{4}-\d{2}-\d{2}/.exec(tarif.BerlakuMulai)?.[0];
    const berlaku = tanggal ? ` berlaku mulai ${FormatTanggal(tanggal)}` : '';
    const dasarHukum = tarif.NomorDasarHukum ? ` (${tarif.NomorDasarHukum})` : '';

    return `${FormatPersen(tarif.Tarif)}%${berlaku}${dasarHukum}`;
}
