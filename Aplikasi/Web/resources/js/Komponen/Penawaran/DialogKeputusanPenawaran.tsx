import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { KeputusanPenawaran } from '@/Tipe/Penawaran';

import { AlamatPenawaran } from './BagianPenawaran';

/**
 * Mencatat keputusan pelanggan yang diterima lewat jalur lain (telepon, email, tatap muka), F-21 bagian 3. Nama penyetuju
 * bersifat opsional di sini (di halaman publik wajib), karena penagih sendiri yang bertanggung jawab atas catatannya.
 */
export default function DialogKeputusanPenawaran({
    uuidPenawaran,
    keputusan,
    saatTutup,
}: {
    uuidPenawaran: string;
    keputusan: KeputusanPenawaran;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors as Record<string, string | undefined>;
    const [nama, AturNama] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const terima = keputusan === 'Terima';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${AlamatPenawaran}/${uuidPenawaran}/putuskan`,
            {
                Keputusan: keputusan,
                Nama: nama.trim() === '' ? null : nama.trim(),
                Catatan: catatan.trim() === '' ? null : catatan.trim(),
            },
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
            judul={terima ? 'Tandai penawaran diterima' : 'Tandai penawaran ditolak'}
            keterangan={
                terima
                    ? 'Catat bahwa pelanggan menyetujui penawaran ini (misalnya lewat telepon). Setelah itu penawaran bisa dijadikan invoice.'
                    : 'Catat bahwa pelanggan menolak penawaran ini. Penawaran yang ditolak tidak bisa dijadikan invoice.'
            }
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Catat keputusan penawaran">
                <BidangTeks
                    label="Nama penyetuju"
                    keterangan="Siapa di pihak pelanggan yang memutuskan. Boleh dikosongkan."
                    nilai={nama}
                    saatBerubah={AturNama}
                    maxLength={100}
                    galat={galat.Nama}
                />
                <BidangTeksPanjang
                    label="Catatan"
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    maksimal={500}
                    galat={galat.Catatan}
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" varian={terima ? 'utama' : 'bahaya'} memproses={memproses}>
                        {terima ? 'Tandai diterima' : 'Tandai ditolak'}
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
