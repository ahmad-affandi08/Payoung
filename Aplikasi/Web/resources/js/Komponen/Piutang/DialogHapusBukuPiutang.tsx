import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { FormatRupiah } from '@/Pustaka/Format';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisPiutang } from '@/Tipe/Piutang';

import { AlamatPiutang } from './BagianPiutang';

/**
 * E8: hapus-buku sisa piutang yang tidak akan tertagih. Seluruh sisa dibukukan sebagai beban (tidak bisa sebagian),
 * jadi dialog hanya meminta tanggal pembukuan dan alasan.
 */
export default function DialogHapusBukuPiutang({
    piutang,
    hariIni,
    saatTutup,
}: {
    piutang: BarisPiutang;
    hariIni: string;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [tanggal, AturTanggal] = useState(hariIni);
    const [alasan, AturAlasan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const galat = props.errors as Record<string, string | undefined>;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${AlamatPiutang}/${piutang.Uuid}/hapus-buku`,
            { Tanggal: tanggal, Alasan: alasan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );
    };

    return (
        <DialogFormulir
            judul={`Hapus-buku piutang ${piutang.Nomor}?`}
            keterangan={`Sisa ${FormatRupiah(piutang.Sisa)} dicatat sebagai beban piutang tak tertagih dan piutangnya tidak muncul lagi di daftar tagihan. Bila pelanggan ternyata membayar, batalkan hapus-bukunya lebih dulu.`}
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Hapus-buku piutang">
                <PemilihTanggal
                    id="tanggal-hapus-buku"
                    label="Tanggal pembukuan"
                    nilai={tanggal}
                    max={hariIni}
                    required
                    saatBerubah={AturTanggal}
                    galat={galat.Tanggal}
                />
                <BidangTeksPanjang
                    label="Alasan"
                    nilai={alasan}
                    saatBerubah={AturAlasan}
                    galat={galat.Alasan}
                    maksimal={255}
                    required
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" varian="bahaya" memproses={memproses} disabled={alasan.trim().length < 5}>
                        Hapus-buku piutang
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
