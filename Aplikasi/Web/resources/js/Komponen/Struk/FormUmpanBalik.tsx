import { useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import type { FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { cn } from '@/Komponen/Ui/utils';

export type UmpanBalikStruk = { Diminta: boolean; Nilai: number | null; Komentar: string | null };

const labelNilai = ['Sangat buruk', 'Kurang baik', 'Cukup', 'Baik', 'Sangat baik'];

function Bintang({ nilai, isi }: { nilai: number; isi: boolean }) {
    return (
        <Star
            aria-hidden="true"
            className={cn('size-8', nilai <= 0 ? '' : isi ? 'fill-current text-brand' : 'text-garis')}
        />
    );
}

/**
 * F7 (CRM-08): penilaian 1–5 bintang dan komentar opsional di struk digital. Setelah terkirim, halaman menampilkan
 * penilaian yang tersimpan (tidak bisa diubah). Bintang adalah pilihan radio asli, jadi bisa dipakai dengan keyboard.
 */
export default function FormUmpanBalik({ umpanBalik, alamat }: { umpanBalik: UmpanBalikStruk; alamat: string }) {
    const formulir = useForm<{ Nilai: number; Komentar: string }>({ Nilai: 0, Komentar: '' });
    // Galat umum (batas waktu lewat, transaksi dibatalkan) tidak terikat ke bidang formulir.
    const galatUmum = (formulir.errors as Record<string, string | undefined>).Umum;

    if (!umpanBalik.Diminta) {
        return (
            <section
                aria-label="Penilaian Anda"
                className="flex flex-col items-center gap-2 rounded-kontrol border border-garis bg-permukaan p-4 text-center"
            >
                <p className="text-isi font-semibold text-teks-utama">Terima kasih atas penilaian Anda</p>
                <div role="img" aria-label={`${String(umpanBalik.Nilai ?? 0)} dari 5 bintang`} className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((n) => (
                        <Bintang key={n} nilai={n} isi={n <= (umpanBalik.Nilai ?? 0)} />
                    ))}
                </div>
                {umpanBalik.Komentar ? (
                    <p className="text-keterangan text-teks-sekunder">{umpanBalik.Komentar}</p>
                ) : null}
            </section>
        );
    }

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (formulir.data.Nilai >= 1) {
            formulir.post(alamat);
        }
    };

    return (
        <form
            onSubmit={Kirim}
            noValidate
            aria-label="Penilaian transaksi"
            className="flex flex-col gap-3 rounded-kontrol border border-garis bg-permukaan p-4"
        >
            <fieldset className="flex flex-col items-center gap-2">
                <legend className="text-isi font-semibold text-teks-utama">Bagaimana pengalaman Anda?</legend>
                <div className="flex gap-1">
                    {[1, 2, 3, 4, 5].map((n) => (
                        <label
                            key={n}
                            className="cursor-pointer p-1 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand"
                        >
                            <input
                                type="radio"
                                name="Nilai"
                                value={n}
                                checked={formulir.data.Nilai === n}
                                onChange={() => formulir.setData('Nilai', n)}
                                className="sr-only"
                            />
                            <span className="sr-only">{`${String(n)} dari 5 bintang, ${labelNilai[n - 1] ?? ''}`}</span>
                            <Bintang nilai={n} isi={n <= formulir.data.Nilai} />
                        </label>
                    ))}
                </div>
                {formulir.data.Nilai >= 1 ? (
                    <p className="text-keterangan text-teks-sekunder">{labelNilai[formulir.data.Nilai - 1]}</p>
                ) : null}
            </fieldset>
            <BidangTeksPanjang
                label="Komentar (opsional)"
                nilai={formulir.data.Komentar}
                saatBerubah={(nilai) => formulir.setData('Komentar', nilai)}
                baris={3}
                maksimal={500}
                galat={formulir.errors.Komentar}
            />
            {galatUmum ? (
                <p role="alert" className="text-keterangan text-bahaya">
                    {galatUmum}
                </p>
            ) : null}
            {formulir.errors.Nilai ? (
                <p role="alert" className="text-keterangan text-bahaya">
                    {formulir.errors.Nilai}
                </p>
            ) : null}
            <Tombol type="submit" memproses={formulir.processing} disabled={formulir.data.Nilai < 1}>
                Kirim penilaian
            </Tombol>
        </form>
    );
}
