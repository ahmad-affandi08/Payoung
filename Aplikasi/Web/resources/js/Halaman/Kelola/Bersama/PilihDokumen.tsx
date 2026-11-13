import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Panel from '@/Komponen/Kelola/Panel';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { ButirPilihDokumen, PropsPilihDokumen } from '@/Tipe/PilihDokumen';

/** Butir yang cocok dengan kata cari (nomor, pihak, atau rincian), tanpa membedakan huruf besar kecil. */
export function CariButirPilihDokumen(daftar: ButirPilihDokumen[], kata: string): ButirPilihDokumen[] {
    const kunci = kata.trim().toLowerCase();

    if (kunci === '') {
        return daftar;
    }

    return daftar.filter((b) => `${b.Nomor} ${b.Pihak} ${b.Rincian}`.toLowerCase().includes(kunci));
}

/**
 * Langkah pertama dokumen turunan yang dibuka tanpa dokumen asal (D-287): pilih dokumen hulunya dari daftar yang nyata,
 * satu ketukan membuka form yang sudah terisi. Dipakai bersama oleh retur pembelian, nota debit, biaya tambahan, dan
 * faktur pembelian, jadi tidak ada lagi jalan buntu "buka dulu dokumennya, lalu tekan tombol".
 */
export default function HalamanPilihDokumen({ Teks, Butir, Alternatif }: PropsPilihDokumen) {
    const [kata, AturKata] = useState('');
    const tampil = useMemo(() => CariButirPilihDokumen(Butir, kata), [Butir, kata]);

    return (
        <TataLetakAplikasi judul={Teks.Judul}>
            <p className="max-w-3xl text-isi text-teks-sekunder">{Teks.Petunjuk}</p>
            <Panel judul={Teks.JudulDaftar}>
                {Butir.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">{Teks.Kosong}</p>
                ) : (
                    <>
                        <BidangTeks
                            label={Teks.LabelCari}
                            nilai={kata}
                            saatBerubah={AturKata}
                            keterangan="Ketik nomor, nama, atau lokasi."
                        />
                        {tampil.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">Tidak ada yang cocok dengan pencarian.</p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-garis" aria-label={Teks.JudulDaftar}>
                                {tampil.map((b) => (
                                    <li
                                        key={b.Uuid}
                                        className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono font-semibold break-words">{b.Nomor}</span>
                                                {b.Lencana ? (
                                                    <LabelStatus jenis={b.Lencana.Jenis} teks={b.Lencana.Teks} />
                                                ) : null}
                                            </span>
                                            <span className="break-words">{b.Pihak}</span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                {FormatTanggal(b.Tanggal)} | {b.Rincian}
                                            </span>
                                        </div>
                                        <Button asChild variant="outline" className="h-8 shrink-0 pointer-coarse:h-11">
                                            <Link href={b.Tautan}>{Teks.LabelAksi}</Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </Panel>
            {Alternatif ? (
                <Panel judul={Alternatif.Judul}>
                    <p className="text-isi text-teks-sekunder">{Alternatif.Teks}</p>
                    <div className="flex flex-wrap gap-2">
                        {Alternatif.Tautan.map((t) => (
                            <Button key={t.Href} asChild variant="outline" className="h-8 pointer-coarse:h-11">
                                <Link href={t.Href}>{t.Teks}</Link>
                            </Button>
                        ))}
                    </div>
                </Panel>
            ) : null}
        </TataLetakAplikasi>
    );
}
