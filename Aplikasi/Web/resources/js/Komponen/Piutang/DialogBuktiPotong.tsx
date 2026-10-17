import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/**
 * Catat atau perbarui nomor bukti potong PPh yang diterima belakangan dari pelanggan (F-21 bagian 5). Hanya keterangan
 * administratif: jumlah dan jurnal pelunasan tidak berubah.
 */
export default function DialogBuktiPotong({
    nomorPelunasan,
    alamat,
    nomorAwal,
    saatTutup,
}: {
    nomorPelunasan: string;
    alamat: string;
    nomorAwal: string | null;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [nomor, AturNomor] = useState(nomorAwal ?? '');
    const [memproses, AturMemproses] = useState(false);
    const judul = `Bukti potong ${nomorPelunasan}`;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            alamat,
            { NomorBuktiPotong: nomor },
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
            judul={judul}
            keterangan="Isi nomor bukti potong (mis. PPh 23) yang diberikan pelanggan. Jumlah dan jurnal pelunasan tidak berubah."
            galatUmum={props.errors.Umum}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <BidangTeks
                    label="Nomor bukti potong"
                    nilai={nomor}
                    saatBerubah={AturNomor}
                    galat={props.errors.NomorBuktiPotong}
                    maxLength={40}
                    kode
                    autoFocus
                    required
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" varian="utama" memproses={memproses} disabled={nomor.trim() === ''}>
                        Simpan nomor
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
