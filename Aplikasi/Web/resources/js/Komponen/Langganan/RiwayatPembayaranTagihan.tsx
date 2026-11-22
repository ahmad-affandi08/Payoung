import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/Komponen/Ui/accordion';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { KeadaanBayar, PembayaranLangganan } from '@/Tipe/TagihanLangganan';

type TampilanKeadaan = {
    jenis: 'sukses' | 'peringatan' | 'bahaya' | 'netral';
    label: string;
    /** Kalimat penjelas untuk pembayaran lewat gerbang. Bukan kode gerbang. */
    catatan: string | null;
};

const tampilanKeadaan: Record<KeadaanBayar, TampilanKeadaan> = {
    Berhasil: { jenis: 'sukses', label: 'Berhasil', catatan: null },
    Menunggu: { jenis: 'peringatan', label: 'Menunggu', catatan: null },
    TidakPasti: {
        jenis: 'netral',
        label: 'Belum pasti',
        catatan: 'Kami belum bisa memastikan pembayaran ini. Uang yang masuk belum kami catat.',
    },
    Kedaluwarsa: {
        jenis: 'netral',
        label: 'Kedaluwarsa',
        catatan: 'Batas bayar lewat. Kalau sudah membayar, hubungi Bantuan.',
    },
    Gagal: {
        jenis: 'netral',
        label: 'Tidak selesai',
        catatan: 'Pembayaran tidak selesai. Kalau sudah membayar, hubungi Bantuan.',
    },
    Ditolak: { jenis: 'bahaya', label: 'Ditolak', catatan: null },
};

/**
 * D-305: riwayat percobaan bayar satu tagihan, diringkas dan tertutup bila pembayaran terakhir sudah berhasil. Satu
 * tagihan hanya punya beberapa percobaan, jadi ini daftar biasa (bukan `TabelData` dengan saring dan urut). Statusnya
 * bahasa layar dari server (`Keadaan`), bukan status mentah atau kode gerbang.
 */
export default function RiwayatPembayaranTagihan({
    pembayaran,
    terbukaAwal = false,
}: {
    pembayaran: PembayaranLangganan[];
    terbukaAwal?: boolean;
}) {
    if (pembayaran.length === 0) {
        return null;
    }

    return (
        <Accordion
            type="single"
            collapsible
            {...(terbukaAwal ? { defaultValue: 'riwayat' } : {})}
            className="rounded-panel border border-garis bg-permukaan px-4"
        >
            <AccordionItem value="riwayat" className="border-b-0">
                <AccordionTrigger className="text-subjudul font-semibold text-teks-utama">
                    <span className="flex flex-col items-start gap-0.5 text-left">
                        Riwayat pembayaran
                        <span className="text-keterangan font-normal text-teks-sekunder">
                            {pembayaran.length} percobaan untuk tagihan ini
                        </span>
                    </span>
                </AccordionTrigger>
                <AccordionContent>
                    <ul className="flex flex-col divide-y divide-garis">
                        {pembayaran.map((baris) => {
                            const tampilan = tampilanKeadaan[baris.Keadaan];
                            const catatan =
                                baris.Keadaan === 'Ditolak' && baris.AlasanTolak
                                    ? `Alasan: ${baris.AlasanTolak}`
                                    : tampilan.catatan;

                            return (
                                <li key={baris.Uuid} className="flex flex-col gap-1 py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-isi font-semibold text-teks-utama">
                                            {baris.LabelMetode} | {FormatRupiah(baris.Jumlah)}
                                        </span>
                                        <LabelStatus jenis={tampilan.jenis} teks={tampilan.label} />
                                    </div>
                                    <span className="text-keterangan text-teks-sekunder">
                                        {FormatTanggalWaktu(baris.DiunggahPada)} | {baris.KodeReferensi}
                                    </span>
                                    {catatan ? (
                                        <span className="text-keterangan text-teks-sekunder">{catatan}</span>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                </AccordionContent>
            </AccordionItem>
        </Accordion>
    );
}
