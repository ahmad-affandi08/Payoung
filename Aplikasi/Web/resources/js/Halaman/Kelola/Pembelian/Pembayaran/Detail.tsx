import { Link } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import {
    AlamatPembelian,
    DaftarJurnalDokumen,
    DaftarRiwayatDokumen,
    DialogAlasan,
    KartuKeterangan,
    Keterangan,
    LabelStatusPembelian,
} from '@/Komponen/Pembelian/BagianDokumenPembelian';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailPembayaran } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/pembayaran`;

/** F-04 fase 1: detail pembayaran hutang (alokasi per faktur, jurnal, batalkan). */
export default function HalamanDetailPembayaran({
    Pembayaran: p,
    Alokasi,
    Jurnal,
    Riwayat,
    Izin,
    Tindakan,
}: PropsDetailPembayaran) {
    const [batalkan, AturBatalkan] = useState(false);

    return (
        <TataLetakAplikasi judul={`Pembayaran ${p.Nomor}`}>
            {p.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Pembayaran dibatalkan">
                    {p.AlasanBatal}. Sisa hutang faktur sudah dikembalikan.
                </Pemberitahuan>
            ) : null}
            <AksiHalaman
                keterangan={
                    <>
                        <LabelStatusPembelian status={p.Status} label={p.LabelStatus} />
                        {p.BelanjaStok ? <span className="text-isi text-teks-sekunder">Dari belanja stok</span> : null}
                        {p.Kompensasi ? (
                            <span className="text-isi text-teks-sekunder">Potong klaim promo pemasok (tanpa kas)</span>
                        ) : null}
                    </>
                }
                langkah={[]}
                lainnya={
                    Tindakan.Batalkan
                        ? [{ label: 'Batalkan pembayaran', saatKlik: () => AturBatalkan(true), bahaya: true }]
                        : []
                }
            />

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{p.Nomor}</span>
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(p.Tanggal)}</Keterangan>
                <Keterangan label="Pemasok">{p.Pemasok ? `${p.Pemasok.Nama} (${p.Pemasok.Kode})` : '—'}</Keterangan>
                <Keterangan label="Dibayar dari">{p.Akun ?? '—'}</Keterangan>
                <Keterangan label="Jumlah">
                    <span className="font-semibold tabular-nums">{FormatRupiah(p.Jumlah)}</span>
                </Keterangan>
                <Keterangan label="Dicatat oleh">{p.DibuatOleh ?? '—'}</Keterangan>
                {p.Lampiran ? (
                    <Keterangan label="Lampiran">
                        <a href={`${alamat}/${p.Uuid}/lampiran`} className="text-brand underline">
                            {p.Lampiran.Nama}
                        </a>
                    </Keterangan>
                ) : null}
                {p.Catatan ? <Keterangan label="Catatan">{p.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Faktur yang dibayar" idJudul="judul-alokasi-pembayaran">
                <ul className="flex flex-col gap-2">
                    {Alokasi.map((a, i) => (
                        <li
                            key={a.UuidFaktur ?? String(i)}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis px-3 py-2"
                        >
                            <span className="flex flex-col">
                                {a.UuidFaktur ? (
                                    <Link
                                        href={`${AlamatPembelian}/faktur/${a.UuidFaktur}`}
                                        className="font-mono font-semibold text-brand underline"
                                    >
                                        {a.NomorFaktur}
                                    </Link>
                                ) : (
                                    <span className="font-mono">{a.NomorFaktur ?? '—'}</span>
                                )}
                                <span className="text-keterangan text-teks-sekunder">
                                    {a.NomorFakturPemasok ? `No. pemasok ${a.NomorFakturPemasok}` : ''}
                                    {a.JatuhTempo ? ` | jatuh tempo ${FormatTanggal(a.JatuhTempo)}` : ''}
                                </span>
                            </span>
                            <span className="tabular-nums">{FormatRupiah(a.Jumlah)}</span>
                        </li>
                    ))}
                </ul>
            </Panel>

            <DaftarJurnalDokumen jurnal={Jurnal} bolehLihat={Izin.LihatJurnal} />
            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {batalkan ? (
                <DialogAlasan
                    judul={`Batalkan pembayaran ${p.Nomor}?`}
                    keterangan="Jurnal pembayaran dibalik dan sisa hutang faktur dikembalikan."
                    labelAksi="Batalkan pembayaran"
                    alamat={`${alamat}/${p.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
