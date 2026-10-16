import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { KeputusanPenawaran } from '@/Tipe/Penawaran';

/**
 * Formulir pelanggan di halaman publik penawaran (F-21 bagian 3): menerima atau menolak. Nama penyetuju wajib karena
 * tanpa login itulah satu-satunya jejak siapa yang memutuskan. Dikirim ke `/p/{kode}/putuskan`; server menolak bila masa
 * berlaku sudah lewat atau keputusan sudah diambil (pesannya tampil di sini).
 */
export default function KeputusanPenawaranPublik({ kode }: { kode: string }) {
    const { props } = usePage<{ errors: Record<string, string | undefined> }>();
    const galat = props.errors;
    const [nama, AturNama] = useState('');
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState<KeputusanPenawaran | null>(null);

    const Kirim = (peristiwa: FormEvent, keputusan: KeputusanPenawaran) => {
        peristiwa.preventDefault();
        router.post(
            `/p/${kode}/putuskan`,
            { Keputusan: keputusan, Nama: nama.trim(), Catatan: catatan.trim() === '' ? null : catatan.trim() },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(keputusan),
                onFinish: () => AturMemproses(null),
            },
        );
    };

    return (
        <form
            noValidate
            onSubmit={(e) => Kirim(e, 'Terima')}
            className="flex flex-col gap-4 rounded-panel border border-garis bg-permukaan p-4 print:hidden"
            aria-label="Tanggapi penawaran"
        >
            <h2 className="text-subjudul font-semibold text-teks-utama">Tanggapi penawaran ini</h2>
            {galat.Umum ? (
                <Pemberitahuan jenis="bahaya" judul="Belum bisa diproses">
                    {galat.Umum}
                </Pemberitahuan>
            ) : null}
            <BidangTeks
                label="Nama Anda"
                keterangan="Nama penyetuju dari pihak Anda. Ditampilkan kepada penjual."
                nilai={nama}
                saatBerubah={AturNama}
                maxLength={100}
                galat={galat.Nama}
                required
            />
            <BidangTeksPanjang
                label="Catatan untuk penjual"
                nilai={catatan}
                saatBerubah={AturCatatan}
                maksimal={500}
                galat={galat.Catatan}
            />
            <div className="flex flex-wrap gap-2">
                <Tombol
                    type="submit"
                    memproses={memproses === 'Terima'}
                    disabled={nama.trim().length < 2 || memproses !== null}
                >
                    Terima penawaran
                </Tombol>
                <Tombol
                    type="button"
                    varian="sekunder"
                    memproses={memproses === 'Tolak'}
                    disabled={nama.trim().length < 2 || memproses !== null}
                    onClick={(e) => Kirim(e, 'Tolak')}
                >
                    Tolak penawaran
                </Tombol>
            </div>
        </form>
    );
}
