import { Link } from '@inertiajs/react';

import { AlamatInvoice } from '@/Komponen/Invoice/BagianInvoice';
import Panel from '@/Komponen/Kelola/Panel';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import type { RingkasanPenagihan } from '@/Tipe/Invoice';

/**
 * Ringkasan penagihan invoice bulan berjalan (F-21): ditagihkan, diterima, piutang beredar, dan yang lewat jatuh tempo.
 * Dipakai di Beranda (bagi usaha yang menagih lewat invoice) dan di atas daftar Invoice. Status lewat tempo tidak hanya
 * berwarna: angkanya disertai teks jumlah invoice.
 */
export default function RingkasanPenagihanKartu({
    ringkasan,
    sembunyiBilaKosong = false,
}: {
    ringkasan: RingkasanPenagihan;
    /** Beranda: tanpa invoice sama sekali, kartu berisi empat "Rp 0" tidak ditampilkan. */
    sembunyiBilaKosong?: boolean;
}) {
    const adaLewat = ringkasan.LewatTempo.Jumlah > 0;
    const kosong =
        AmbilTandaDesimal(ringkasan.Ditagih) === 0 &&
        AmbilTandaDesimal(ringkasan.Diterima) === 0 &&
        ringkasan.Beredar.Jumlah === 0 &&
        ringkasan.Draf === 0;

    if (sembunyiBilaKosong && kosong) {
        return null;
    }

    return (
        <Panel
            tingkat="h2"
            judul="Penagihan invoice"
            keterangan={`${FormatTanggal(ringkasan.Dari)} sampai ${FormatTanggal(ringkasan.Sampai)}`}
        >
            <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <Kartu judul="Ditagihkan bulan ini" nilai={ringkasan.Ditagih} />
                <Kartu judul="Diterima bulan ini" nilai={ringkasan.Diterima} />
                <Kartu
                    judul="Belum dibayar"
                    nilai={ringkasan.Beredar.Sisa}
                    keterangan={`${ringkasan.Beredar.Jumlah.toLocaleString('id-ID')} invoice`}
                    tautan={`${AlamatInvoice}?saring[Pembayaran]=Terbuka`}
                />
                <Kartu
                    judul="Lewat jatuh tempo"
                    nilai={ringkasan.LewatTempo.Sisa}
                    keterangan={
                        adaLewat
                            ? `${ringkasan.LewatTempo.Jumlah.toLocaleString('id-ID')} invoice perlu ditagih`
                            : 'Tidak ada'
                    }
                    bahaya={adaLewat && AmbilTandaDesimal(ringkasan.LewatTempo.Sisa) > 0}
                    tautan={adaLewat ? `${AlamatInvoice}?saring[Pembayaran]=LewatTempo` : undefined}
                />
            </div>
            {ringkasan.Draf > 0 ? (
                <p className="text-keterangan text-teks-sekunder">
                    {ringkasan.Draf.toLocaleString('id-ID')} draf belum diterbitkan.{' '}
                    <Link href={`${AlamatInvoice}?saring[Status]=Draf`} className="text-brand underline">
                        Lihat draf
                    </Link>
                </p>
            ) : null}
        </Panel>
    );
}

function Kartu({
    judul,
    nilai,
    keterangan,
    bahaya = false,
    tautan,
}: {
    judul: string;
    nilai: string;
    keterangan?: string | undefined;
    bahaya?: boolean;
    tautan?: string | undefined;
}) {
    const isi = (
        <>
            <p className="text-label text-teks-sekunder">{judul}</p>
            <p
                className={`text-subjudul font-semibold tabular-nums wrap-anywhere ${bahaya ? 'text-bahaya' : 'text-teks-utama'}`}
            >
                {FormatRupiah(nilai)}
            </p>
            {keterangan !== undefined ? <p className="text-keterangan text-teks-sekunder">{keterangan}</p> : null}
        </>
    );
    const kelas = 'min-w-0 rounded-panel border border-garis bg-permukaan px-3 py-2';

    return tautan === undefined ? (
        <div className={kelas}>{isi}</div>
    ) : (
        <Link href={tautan} className={`${kelas} block hover:bg-permukaan-redup`}>
            {isi}
        </Link>
    );
}
