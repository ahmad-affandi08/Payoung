import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import KerangkaPelanggan from '@/Komponen/Publik/KerangkaPelanggan';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatWaktuReservasi } from '@/Halaman/Kelola/Reservasi/Daftar';
import type { StatusReservasi } from '@/Tipe/Reservasi';

type PropsStatusReservasi = {
    Ditemukan: boolean;
    Slug: string;
    Kode: string;
    Toko?: { Nama: string };
    Reservasi?: {
        Nomor: string;
        MulaiPada: string;
        SelesaiPada: string;
        Layanan: string;
        Staf: string | null;
        Outlet: string;
        NamaPelanggan: string;
        Status: StatusReservasi;
        LabelStatus: string;
        BolehBatal: boolean;
    };
};

const JenisStatus: Record<StatusReservasi, 'netral' | 'sukses' | 'peringatan' | 'bahaya'> = {
    Menunggu: 'peringatan',
    Dikonfirmasi: 'sukses',
    Hadir: 'sukses',
    Selesai: 'sukses',
    Batal: 'bahaya',
    TidakDatang: 'bahaya',
};

/** F-07 mode service: status reservasi pelanggan (dari tautan setelah memesan) dengan tombol batal. */
export default function HalamanStatusReservasi({ Ditemukan, Slug, Kode, Toko, Reservasi: r }: PropsStatusReservasi) {
    const { props } = usePage<{ Kilat?: string | null; errors: Record<string, string> }>();
    const [memproses, AturMemproses] = useState(false);

    return (
        <KerangkaPelanggan toko={Toko?.Nama}>
            <Head title="Status reservasi" />
            {!Ditemukan || !r ? (
                <>
                    <JudulHalaman>Reservasi tidak ditemukan</JudulHalaman>
                    <p className="text-teks-sekunder">Periksa kembali tautan dari toko.</p>
                </>
            ) : (
                <>
                    <header className="flex flex-col gap-1 border-b border-garis pb-3">
                        <JudulHalaman>Reservasi {r.Nomor}</JudulHalaman>
                    </header>
                    {props.Kilat ? <Pemberitahuan jenis="sukses">{props.Kilat}</Pemberitahuan> : null}
                    {props.errors.Umum ? <Pemberitahuan jenis="bahaya">{props.errors.Umum}</Pemberitahuan> : null}
                    <LabelStatus jenis={JenisStatus[r.Status]} teks={r.LabelStatus} />
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                        <dt className="text-teks-sekunder">Waktu</dt>
                        <dd className="tabular-nums">{FormatWaktuReservasi(r.MulaiPada, r.SelesaiPada)}</dd>
                        <dt className="text-teks-sekunder">Layanan</dt>
                        <dd className="break-words">{r.Layanan}</dd>
                        <dt className="text-teks-sekunder">Staf</dt>
                        <dd>{r.Staf ?? 'Ditentukan toko'}</dd>
                        <dt className="text-teks-sekunder">Outlet</dt>
                        <dd className="break-words">{r.Outlet}</dd>
                        <dt className="text-teks-sekunder">Atas nama</dt>
                        <dd className="break-words">{r.NamaPelanggan}</dd>
                    </dl>
                    <p className="text-keterangan text-teks-sekunder">
                        Simpan tautan ini untuk melihat atau membatalkan reservasi. Kode:{' '}
                        <span className="font-mono">{Kode}</span>
                    </p>
                    {r.BolehBatal ? (
                        <Tombol
                            varian="bahaya"
                            memproses={memproses}
                            onClick={() =>
                                router.post(
                                    `/${Slug}/reservasi/${Kode}/batal`,
                                    {},
                                    { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
                                )
                            }
                        >
                            Batalkan reservasi
                        </Tombol>
                    ) : null}
                </>
            )}
        </KerangkaPelanggan>
    );
}
