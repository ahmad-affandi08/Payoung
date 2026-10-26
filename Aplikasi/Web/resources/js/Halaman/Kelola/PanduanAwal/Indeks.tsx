import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import IndikatorLangkah, { teksStatusLangkah } from '@/Komponen/PanduanAwal/IndikatorLangkah';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import { Separator } from '@/Komponen/Ui/separator';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPanduanAwal from '@/TataLetak/TataLetakPanduanAwal';
import { AlamatPanduan, type PropsIndeksPanduan, type StatusLangkahPanduan } from '@/Tipe/PanduanAwal';

const jenisLabel: Record<StatusLangkahPanduan, 'sukses' | 'peringatan' | 'netral'> = {
    Selesai: 'sukses',
    Dilewati: 'peringatan',
    Belum: 'netral',
};

/** Ringkasan panduan awal (F-01): 6 langkah, status masing-masing, lanjutkan dari langkah yang belum. */
export default function HalamanIndeksPanduanAwal({ Progres }: PropsIndeksPanduan) {
    const [memproses, AturMemproses] = useState(false);
    const belumSelesai = Progres.Langkah.filter((item) => item.Status !== 'Selesai');
    const langkahBerikutnya = Progres.Langkah.find((item) => item.Status === 'Belum') ?? belumSelesai[0];
    const jumlahDilewati = Progres.Langkah.filter((item) => item.Status === 'Dilewati').length;
    const wajib = Progres.Wajib === true;
    const kurangWajib = Progres.Langkah.filter((item) => (Progres.WajibBelumSelesai ?? []).includes(item.Kunci));

    const Selesaikan = () =>
        router.post(
            AlamatPanduan.Selesai,
            {},
            { onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <TataLetakPanduanAwal judul="Panduan awal" wajib={wajib}>
            <IndikatorLangkah langkah={Progres.Langkah} aktif={null} />
            <p className="text-isi text-teks-sekunder">
                Siapkan outlet <span className="font-semibold text-teks-utama">{Progres.Outlet.Nama}</span>{' '}
                <span className="font-mono text-label">({Progres.Outlet.Kode})</span> sampai siap berjualan: profil
                usaha, jenis usaha, pajak, produk, metode pembayaran, dan perangkat kasir.{' '}
                {wajib
                    ? 'Lima langkah pertama wajib diselesaikan sebelum membuka menu lain. Perangkat kasir boleh diatur nanti.'
                    : 'Langkah yang dilewati bisa dikerjakan nanti.'}
            </p>

            {Progres.SelesaiPada ? (
                <Pemberitahuan jenis="sukses" judul="Panduan awal sudah selesai">
                    Diselesaikan {FormatTanggalWaktu(Progres.SelesaiPada)}. Anda tetap bisa membuka dan mengubah setiap
                    langkah di bawah.
                </Pemberitahuan>
            ) : null}

            <Card className="gap-0 py-0 rounded-panel shadow-none">
                <ol aria-label="Status setiap langkah" className="divide-y divide-garis">
                    {Progres.Langkah.map((item, indeks) => (
                        <li
                            key={item.Kunci}
                            className="flex flex-col gap-2 px-4 py-3 text-isi sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span className="text-teks-utama">
                                    {String(indeks + 1)}. {item.Judul}
                                </span>
                                <LabelStatus jenis={jenisLabel[item.Status]} teks={teksStatusLangkah[item.Status]} />
                            </span>
                            <Button
                                asChild
                                variant="link"
                                size="sm"
                                className="h-11 self-start px-0 font-semibold underline sm:h-8 sm:self-auto"
                            >
                                <Link href={item.Tautan}>
                                    {item.Status === 'Selesai' ? `Ubah ${item.Judul}` : `Buka ${item.Judul}`}
                                </Link>
                            </Button>
                        </li>
                    ))}
                </ol>
            </Card>

            <Separator />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-isi text-teks-sekunder">
                    {belumSelesai.length === 0
                        ? 'Semua langkah selesai.'
                        : `${String(belumSelesai.length)} langkah belum selesai${
                              jumlahDilewati > 0 ? `, ${String(jumlahDilewati)} di antaranya dilewati` : ''
                          }. ${wajib ? 'Selesaikan dulu langkah yang wajib.' : 'Langkah yang belum selesai muncul di Kotak Tindakan.'}`}
                </p>
                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                    <Tombol
                        varian={langkahBerikutnya ? 'sekunder' : 'utama'}
                        onClick={Selesaikan}
                        memproses={memproses}
                        disabled={kurangWajib.length > 0}
                    >
                        Selesaikan panduan
                    </Tombol>
                    {langkahBerikutnya ? (
                        <Button asChild>
                            <Link href={langkahBerikutnya.Tautan}>Lanjutkan: {langkahBerikutnya.Judul}</Link>
                        </Button>
                    ) : null}
                </div>
            </div>
        </TataLetakPanduanAwal>
    );
}
