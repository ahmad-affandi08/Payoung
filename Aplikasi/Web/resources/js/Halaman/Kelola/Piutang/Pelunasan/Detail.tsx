import { Link } from '@inertiajs/react';
import { useState } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import {
    DaftarJurnalDokumen,
    DaftarRiwayatDokumen,
    DialogAlasan,
    KartuKeterangan,
    Keterangan,
} from '@/Komponen/Pembelian/BagianDokumenPembelian';
import { AlamatPiutang, LabelStatusPiutang } from '@/Komponen/Piutang/BagianPiutang';
import DialogBuktiPotong from '@/Komponen/Piutang/DialogBuktiPotong';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsDetailPelunasan } from '@/Tipe/Piutang';

const alamat = `${AlamatPiutang}/pelunasan`;

/** F-12: detail pelunasan piutang (alokasi per penjualan, jurnal, batalkan). */
export default function HalamanDetailPelunasan({
    Pelunasan: p,
    Alokasi,
    Jurnal,
    Riwayat,
    Izin,
    Tindakan,
}: PropsDetailPelunasan) {
    const [batalkan, AturBatalkan] = useState(false);
    const [buktiPotong, AturBuktiPotong] = useState(false);
    const adaPph = AmbilTandaDesimal(p.PotonganPph) > 0;

    return (
        <TataLetakAplikasi judul={`Pelunasan ${p.Nomor}`}>
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatusPiutang status={p.Status} label={p.LabelStatus} />
            </div>
            {p.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Pelunasan dibatalkan">
                    {p.AlasanBatal}. Sisa piutang sudah dikembalikan.
                </Pemberitahuan>
            ) : null}
            {Tindakan.Batalkan || Tindakan.CatatBuktiPotong ? (
                <div className="flex flex-wrap gap-2">
                    {Tindakan.CatatBuktiPotong ? (
                        <Button variant="outline" onClick={() => AturBuktiPotong(true)}>
                            {p.NomorBuktiPotong ? 'Ubah nomor bukti potong' : 'Catat bukti potong'}
                        </Button>
                    ) : null}
                    {Tindakan.Batalkan ? (
                        <Button variant="destructive" onClick={() => AturBatalkan(true)}>
                            Batalkan pelunasan
                        </Button>
                    ) : null}
                </div>
            ) : null}

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{p.Nomor}</span>
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(p.Tanggal)}</Keterangan>
                <Keterangan label="Pelanggan">
                    {p.Pelanggan ? (
                        <Link href={`/kelola/pelanggan/${p.Pelanggan.Uuid}`} className="text-brand underline">
                            {p.Pelanggan.Nama}
                        </Link>
                    ) : (
                        '—'
                    )}
                </Keterangan>
                <Keterangan label="Diterima di">{p.Akun ?? '—'}</Keterangan>
                <Keterangan label="Jumlah">
                    <span className="font-semibold tabular-nums">{FormatRupiah(p.Jumlah)}</span>
                </Keterangan>
                <Keterangan label="Dicatat oleh">{p.DibuatOleh ?? '—'}</Keterangan>
                {adaPph ? (
                    <Keterangan label="PPh dipotong pelanggan">
                        <span className="tabular-nums">{FormatRupiah(p.PotonganPph)}</span>
                    </Keterangan>
                ) : null}
                {adaPph ? (
                    <Keterangan label="Nomor bukti potong">
                        {p.NomorBuktiPotong ? (
                            <span className="font-mono break-all">{p.NomorBuktiPotong}</span>
                        ) : (
                            'Belum diterima dari pelanggan'
                        )}
                    </Keterangan>
                ) : null}
                {p.Catatan ? <Keterangan label="Catatan">{p.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Penjualan yang dilunasi" idJudul="judul-alokasi-pelunasan">
                <ul className="flex flex-col gap-2">
                    {Alokasi.map((a, i) => (
                        <li
                            key={a.Nomor ?? String(i)}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-panel border border-garis px-3 py-2"
                        >
                            <span className="flex flex-col">
                                <span className="font-mono font-semibold break-all">{a.Nomor ?? '—'}</span>
                                <span className="text-keterangan text-teks-sekunder">
                                    {a.JatuhTempo ? `Jatuh tempo ${FormatTanggal(a.JatuhTempo)}` : ''}
                                    {a.Sisa ? ` | sisa sekarang ${FormatRupiah(a.Sisa)}` : ''}
                                </span>
                            </span>
                            <span className="tabular-nums">{FormatRupiah(a.Jumlah)}</span>
                        </li>
                    ))}
                </ul>
            </Panel>

            <DaftarJurnalDokumen jurnal={Jurnal} bolehLihat={Izin.LihatJurnal} />
            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {buktiPotong ? (
                <DialogBuktiPotong
                    nomorPelunasan={p.Nomor}
                    alamat={`${alamat}/${p.Uuid}/bukti-potong`}
                    nomorAwal={p.NomorBuktiPotong}
                    saatTutup={() => AturBuktiPotong(false)}
                />
            ) : null}
            {batalkan ? (
                <DialogAlasan
                    judul={`Batalkan pelunasan ${p.Nomor}?`}
                    keterangan="Jurnal pelunasan dibalik dan sisa piutang pelanggan dikembalikan."
                    labelAksi="Batalkan pelunasan"
                    alamat={`${alamat}/${p.Uuid}/batalkan`}
                    saatTutup={() => AturBatalkan(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
