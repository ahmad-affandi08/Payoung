import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { JurnalDokumenPersediaan, RiwayatDokumenPersediaan } from '@/Tipe/DokumenPersediaan';

type JenisLabel = 'sukses' | 'peringatan' | 'bahaya' | 'netral';

const petaJenis: Record<string, JenisLabel> = {
    Draf: 'netral',
    Dikirim: 'peringatan',
    DiterimaSebagian: 'peringatan',
    Berlangsung: 'peringatan',
    Ditinjau: 'peringatan',
    MenungguPersetujuan: 'peringatan',
    Diterima: 'sukses',
    Disetujui: 'sukses',
    Diposting: 'sukses',
    Dibatalkan: 'bahaya',
    // D-105 permintaan stok.
    Diajukan: 'peringatan',
    DiprosesSebagian: 'peringatan',
    Selesai: 'sukses',
    Ditutup: 'netral',
    Ditolak: 'bahaya',
};

/** Warna label status dokumen persediaan F-05b; teks tetap dari server (`LabelStatus`), warna hanya penguat. */
export function AmbilJenisLabelDokumen(status: string): JenisLabel {
    return petaJenis[status] ?? 'netral';
}

export function LabelStatusDokumen({ status, label }: { status: string; label: string }) {
    return <LabelStatus jenis={AmbilJenisLabelDokumen(status)} teks={label} />;
}

/** Alasan tindakan 5–255 karakter (`AlasanDokumenPersediaanPermintaan`). */
export function PeriksaAlasan(alasan: string): string | null {
    const panjang = alasan.trim().length;

    if (panjang < 5) {
        return 'Tulis alasan minimal 5 karakter.';
    }

    return panjang > 255 ? 'Alasan paling panjang 255 karakter.' : null;
}

export function Keterangan({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-label font-semibold text-teks-sekunder">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** Riwayat status dokumen (urut waktu), dengan pelaku dan alasan bila ada. */
export function PanelRiwayatDokumen({ riwayat, id }: { riwayat: RiwayatDokumenPersediaan[]; id: string }) {
    return (
        <Panel judul="Riwayat status" idJudul={id}>
            {riwayat.length === 0 ? (
                <p className="text-isi text-teks-sekunder">Belum ada perubahan status.</p>
            ) : (
                <ol className="flex flex-col gap-2">
                    {riwayat.map((r, indeks) => (
                        <li
                            key={`${r.Pada}-${String(indeks)}`}
                            className="flex flex-col gap-0.5 border-l-2 border-garis pl-3"
                        >
                            <span className="text-isi font-semibold text-teks-utama">{r.LabelStatusKe}</span>
                            <span className="text-keterangan text-teks-sekunder">
                                {FormatTanggalWaktu(r.Pada)}
                                {r.Oleh ? ` | ${r.Oleh}` : ''}
                            </span>
                            {r.Alasan ? (
                                <span className="text-keterangan text-teks-sekunder">Alasan: {r.Alasan}</span>
                            ) : null}
                        </li>
                    ))}
                </ol>
            )}
        </Panel>
    );
}

/** Jurnal otomatis dokumen (TabelData lokal, tautan ke detail jurnal bila berizin laporan.keuangan.lihat). */
export function PanelJurnalDokumen({
    id,
    jurnal,
    lihatJurnal,
    keterangan,
    kosong,
}: {
    id: string;
    jurnal: JurnalDokumenPersediaan[];
    lihatJurnal: boolean;
    keterangan: string;
    kosong: string;
}) {
    return (
        <Panel judul="Jurnal" idJudul={`judul-${id}`} keterangan={keterangan}>
            <TabelData
                id={id}
                label="Jurnal dokumen"
                kolom={[
                    {
                        id: 'Nomor',
                        accessorKey: 'Nomor',
                        header: 'Nomor jurnal',
                        meta: { label: 'Nomor jurnal', prioritas: 'utama', wajib: true },
                        cell: ({ row: { original: j } }) =>
                            lihatJurnal ? (
                                <Link
                                    href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                                    className="font-mono font-semibold text-brand underline"
                                >
                                    {j.Nomor}
                                </Link>
                            ) : (
                                <span className="font-mono">{j.Nomor}</span>
                            ),
                    },
                    {
                        id: 'Tanggal',
                        accessorKey: 'Tanggal',
                        header: 'Tanggal',
                        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
                        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
                    },
                    {
                        id: 'Keterangan',
                        accessorKey: 'Keterangan',
                        header: 'Keterangan',
                        enableSorting: false,
                        meta: { label: 'Keterangan', prioritas: 'rendah' },
                    },
                    {
                        id: 'TotalDebit',
                        header: 'Total debit',
                        enableSorting: false,
                        meta: { label: 'Total debit', angka: true, prioritas: 'penting' },
                        cell: ({ row }) => FormatNilai(row.original.TotalDebit),
                    },
                ]}
                sumber={{ mode: 'lokal', data: jurnal }}
                ambilIdBaris={(j) => j.Uuid}
                kosong={{ judul: kosong }}
            />
        </Panel>
    );
}

/** Dialog tindakan beralasan (batal, tutup, tolak, kembalikan): alasan 5–255 karakter lalu kirim. */
export function DialogAlasan({
    judul,
    keterangan,
    labelAksi,
    labelAlasan = 'Alasan',
    varian = 'bahaya',
    memproses,
    galat,
    saatKirim,
    saatTutup,
}: {
    judul: string;
    keterangan: ReactNode;
    labelAksi: string;
    labelAlasan?: string;
    varian?: 'utama' | 'bahaya';
    memproses: boolean;
    galat: Record<string, string>;
    saatKirim: (alasan: string) => void;
    saatTutup: () => void;
}) {
    const [alasan, AturAlasan] = useState('');
    const [periksa, AturPeriksa] = useState(false);
    const galatAlasan = galat.Alasan ?? (periksa ? (PeriksaAlasan(alasan) ?? undefined) : undefined);

    const Kirim = () => {
        AturPeriksa(true);

        if (PeriksaAlasan(alasan) === null) {
            saatKirim(alasan.trim());
        }
    };

    return (
        <DialogFormulir
            jenis="konfirmasi"
            judul={judul}
            keterangan={keterangan}
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <div className="flex flex-col gap-3">
                <DaftarGalatServer galat={galat} kecuali={['Alasan', 'Umum']} />
                <BidangTeksPanjang
                    label={labelAlasan}
                    nilai={alasan}
                    saatBerubah={AturAlasan}
                    galat={galatAlasan}
                    keterangan="5–255 karakter. Tercatat di riwayat dan log audit."
                    baris={3}
                    maksimal={255}
                    required
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol varian="sekunder" onClick={saatTutup} disabled={memproses}>
                        Kembali
                    </Tombol>
                    <Tombol varian={varian} memproses={memproses} onClick={Kirim}>
                        {labelAksi}
                    </Tombol>
                </div>
            </div>
        </DialogFormulir>
    );
}
