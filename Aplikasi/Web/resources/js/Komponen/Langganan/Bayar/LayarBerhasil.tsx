import { Link } from '@inertiajs/react';

import IlustrasiBayar from '@/Komponen/Langganan/Bayar/IlustrasiBayar';
import { Card } from '@/Komponen/Ui/card';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { PembayaranLangganan, TagihanLangganan } from '@/Tipe/TagihanLangganan';

type PropsLayarBerhasil = { tagihan: TagihanLangganan; pembayaran: PembayaranLangganan | null };

/** D-305: layar tagihan lunas dengan centang beranimasi, ringkasan, dan dua jalan keluar. */
export default function LayarBerhasil({ tagihan, pembayaran }: PropsLayarBerhasil) {
    return (
        <Card
            role="region"
            aria-label="Pembayaran berhasil"
            data-hasil="Berhasil"
            className="items-center gap-4 rounded-panel px-4 py-8 text-center shadow-none"
        >
            <IlustrasiBayar jenis="berhasil" ukuran={128} />
            <div className="flex flex-col gap-1">
                <h2 className="text-judul font-semibold text-teks-utama">Pembayaran berhasil</h2>
                <p className="text-isi text-teks-sekunder" role="status">
                    {tagihan.PeriodeSelesai
                        ? `Langganan ${tagihan.NamaPaket} aktif sampai ${FormatTanggalWaktu(tagihan.PeriodeSelesai)}. Terima kasih sudah memakai Payoung.`
                        : `Langganan ${tagihan.NamaPaket} Anda sudah aktif. Terima kasih sudah memakai Payoung.`}
                </p>
            </div>
            <dl className="grid w-full max-w-md grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-panel bg-permukaan-redup px-4 py-3 text-left text-isi">
                <dt className="text-teks-sekunder">Dibayar</dt>
                <dd className="text-right font-semibold tabular-nums">{FormatRupiah(tagihan.Total)}</dd>
                {pembayaran ? (
                    <>
                        <dt className="text-teks-sekunder">Cara bayar</dt>
                        <dd className="text-right font-semibold">{pembayaran.LabelMetode}</dd>
                    </>
                ) : null}
                {tagihan.DibayarPada ? (
                    <>
                        <dt className="text-teks-sekunder">Waktu</dt>
                        <dd className="text-right font-semibold">{FormatTanggalWaktu(tagihan.DibayarPada)}</dd>
                    </>
                ) : null}
                <dt className="text-teks-sekunder">Nomor tagihan</dt>
                <dd className="text-right font-semibold">{tagihan.Nomor}</dd>
            </dl>
            <div className="flex flex-wrap justify-center gap-2">
                <Link
                    href="/kelola"
                    className="inline-flex h-8 items-center rounded-kontrol bg-brand px-4 text-label font-semibold text-brand-teks hover:bg-brand-gelap pointer-coarse:h-11"
                >
                    Kembali ke Beranda
                </Link>
                <Link
                    href="/kelola/langganan"
                    className="inline-flex h-8 items-center rounded-kontrol border border-brand bg-permukaan px-4 text-label font-semibold text-brand hover:bg-brand-lembut pointer-coarse:h-11"
                >
                    Lihat langganan
                </Link>
            </div>
        </Card>
    );
}
