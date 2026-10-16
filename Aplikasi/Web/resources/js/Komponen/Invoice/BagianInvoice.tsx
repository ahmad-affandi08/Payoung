import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import type { StatusBayarInvoice } from '@/Tipe/Invoice';

/** Akar rute back-office invoice (F-21). */
export const AlamatInvoice = '/kelola/invoice';

/** Warna hanya penguat: teks status selalu tampil. Lunas = sukses, lewat tempo/batal = bahaya, sebagian = peringatan. */
export function AmbilJenisStatusBayar(status: StatusBayarInvoice): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    switch (status) {
        case 'Lunas':
            return 'sukses';
        case 'DibayarSebagian':
            return 'peringatan';
        case 'LewatTempo':
        case 'Dibatalkan':
            return 'bahaya';
        default:
            return 'netral';
    }
}

export function LabelStatusBayarInvoice({ status, label }: { status: StatusBayarInvoice; label: string }) {
    return <LabelStatus jenis={AmbilJenisStatusBayar(status)} teks={label} />;
}

/** Termin pembayaran dalam kata ("Bayar langsung" atau "14 hari"). */
export function KeteranganTermin(hari: number): string {
    return hari === 0 ? 'Bayar langsung' : `${String(hari)} hari`;
}

/** "10.0000" → "10", "1.5000" → "1,5", "100" → "100" (koma desimal Indonesia; angka bulat tidak disentuh). */
export function FormatJumlahInvoice(jumlah: string): string {
    if (!jumlah.includes('.')) {
        return jumlah;
    }

    const tanpaNol = jumlah.replace(/0+$/, '').replace(/\.$/, '');

    return tanpaNol.replace('.', ',');
}
