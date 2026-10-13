import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import Panel from '@/Komponen/Kelola/Panel';
import {
    AlamatPembelian,
    DaftarDokumenTerkait,
    DaftarRiwayatDokumen,
    DialogAlasan,
    KartuKeterangan,
    Keterangan,
    LabelStatusPembelian,
    RingkasanNilai,
} from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailPesanan, PropsDetailPesanan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/pesanan`;

export const kolomBarisPesanan: KolomTabel<BarisDetailPesanan>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Dipesan',
        enableSorting: false,
        meta: { label: 'Jumlah dipesan', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, b.SimbolSatuan),
    },
    {
        id: 'Harga',
        header: 'Harga',
        enableSorting: false,
        meta: { label: 'Harga per satuan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Harga),
    },
    {
        id: 'Diskon',
        header: 'Diskon',
        enableSorting: false,
        meta: { label: 'Diskon', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Diskon),
    },
    {
        id: 'Subtotal',
        header: 'Subtotal',
        enableSorting: false,
        meta: { label: 'Subtotal', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Subtotal),
    },
    {
        id: 'Diterima',
        header: 'Diterima',
        enableSorting: false,
        meta: { label: 'Sudah diterima', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.JumlahDiterima, b.SimbolSatuan),
    },
    {
        id: 'Sisa',
        header: 'Sisa',
        enableSorting: false,
        meta: { label: 'Sisa belum diterima', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Sisa, b.SimbolSatuan),
    },
];

type Dialog = 'Tolak' | 'Batalkan' | 'Tutup' | null;

/** F-04 fase 1: detail pesanan pembelian (ajukan, setujui/tolak, terima barang, batalkan, tutup, cetak). */
export default function HalamanDetailPesanan({
    Pesanan,
    Baris,
    Penerimaan,
    Pengiriman,
    Riwayat,
    Tindakan,
}: PropsDetailPesanan) {
    const [dialog, AturDialog] = useState<Dialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const Kirim = (aksi: string) =>
        router.post(
            `${alamat}/${Pesanan.Uuid}/${aksi}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => {
                    AturMemproses(false);
                    AturDialog(null);
                },
            },
        );

    return (
        <TataLetakAplikasi judul={`Pesanan ${Pesanan.Nomor}`}>
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatusPembelian status={Pesanan.Status} label={Pesanan.LabelStatus} />
                <span className="text-isi text-teks-sekunder">{Pesanan.Pemasok.Nama}</span>
            </div>

            {Pesanan.AlasanDitolak && Pesanan.Status === 'Draf' ? (
                <Pemberitahuan jenis="peringatan" judul="Pesanan ditolak">
                    {Pesanan.AlasanDitolak}. Perbaiki lalu ajukan lagi.
                </Pemberitahuan>
            ) : null}
            {Pesanan.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Pesanan dibatalkan">
                    {Pesanan.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {Pesanan.Status === 'MenungguPersetujuan' && !Tindakan.Setujui ? (
                <Pemberitahuan jenis="info" judul="Menunggu persetujuan">
                    Pesanan ini disetujui oleh pengguna lain yang berizin menyetujui pesanan pembelian (bukan
                    pembuatnya).
                </Pemberitahuan>
            ) : null}

            <div className="flex flex-wrap gap-2">
                {Tindakan.Ubah ? (
                    <Button asChild variant="outline">
                        <Link href={`${alamat}/${Pesanan.Uuid}/ubah`}>Ubah draf</Link>
                    </Button>
                ) : null}
                {Tindakan.Ajukan ? (
                    <Button onClick={() => Kirim('ajukan')} disabled={memproses}>
                        Ajukan pesanan
                    </Button>
                ) : null}
                {Tindakan.Setujui ? (
                    <>
                        <Button onClick={() => Kirim('setujui')} disabled={memproses}>
                            Setujui pesanan
                        </Button>
                        <Button variant="outline" onClick={() => AturDialog('Tolak')}>
                            Tolak
                        </Button>
                    </>
                ) : null}
                {Tindakan.Terima ? (
                    <Button asChild>
                        <Link href={`${AlamatPembelian}/penerimaan/buat?pesanan=${Pesanan.Uuid}`}>Terima barang</Link>
                    </Button>
                ) : null}
                {Tindakan.KirimWhatsapp ? (
                    <Button variant="outline" onClick={() => Kirim('kirim-whatsapp')} disabled={memproses}>
                        Kirim ke WhatsApp pemasok
                    </Button>
                ) : null}
                {Tindakan.Tutup ? (
                    <Button variant="outline" onClick={() => AturDialog('Tutup')}>
                        Tutup pesanan
                    </Button>
                ) : null}
                <Button asChild variant="outline">
                    <a href={`${alamat}/${Pesanan.Uuid}/cetak`} target="_blank" rel="noreferrer">
                        Cetak pesanan
                    </a>
                </Button>
                {Tindakan.Batalkan ? (
                    <Button variant="destructive" onClick={() => AturDialog('Batalkan')}>
                        Batalkan pesanan
                    </Button>
                ) : null}
            </div>

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{Pesanan.Nomor}</span>
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(Pesanan.Tanggal)}</Keterangan>
                <Keterangan label="Perkiraan tiba">
                    {Pesanan.PerkiraanTiba ? FormatTanggal(Pesanan.PerkiraanTiba) : '—'}
                </Keterangan>
                <Keterangan label="Pemasok">
                    {Pesanan.Pemasok.Nama} ({Pesanan.Pemasok.Kode}){Pesanan.Pemasok.Pkp ? ' | PKP' : ''}
                </Keterangan>
                <Keterangan label="Lokasi tujuan">
                    {Pesanan.NamaGudang}
                    {Pesanan.NamaOutlet ? ` | ${Pesanan.NamaOutlet}` : ''}
                </Keterangan>
                <Keterangan label="Termin">
                    {Pesanan.TerminHari === 0 ? 'Tunai' : `Tempo ${String(Pesanan.TerminHari)} hari`}
                </Keterangan>
                <Keterangan label="Dibuat oleh">{Pesanan.DibuatOleh ?? '—'}</Keterangan>
                {Pesanan.DisetujuiOleh ? (
                    <Keterangan label="Disetujui">
                        {Pesanan.DisetujuiOleh}
                        {Pesanan.DisetujuiPada ? ` | ${FormatTanggalWaktu(Pesanan.DisetujuiPada)}` : ''}
                    </Keterangan>
                ) : null}
                {Pesanan.Catatan ? <Keterangan label="Catatan">{Pesanan.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Barang" idJudul="judul-barang-pesanan">
                <TabelData
                    id="pembelian-pesanan-baris"
                    label={`Barang pesanan, ${String(Baris.length)} baris`}
                    kolom={kolomBarisPesanan}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Id)}
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Pesanan ini belum berisi barang.' }}
                />
                <RingkasanNilai
                    baris={[
                        { label: 'Subtotal', nilai: Pesanan.Subtotal },
                        { label: 'Diskon', nilai: Pesanan.Diskon },
                        {
                            label: Pesanan.TarifPpn ? `PPN masukan ${FormatPersen(Pesanan.TarifPpn)}` : 'PPN masukan',
                            nilai: Pesanan.Pajak,
                        },
                        { label: 'Ongkos kirim', nilai: Pesanan.Ongkir },
                        { label: 'Total', nilai: Pesanan.Total, tebal: true },
                    ]}
                />
            </Panel>

            <DaftarDokumenTerkait
                judul="Penerimaan barang"
                alamat={`${AlamatPembelian}/penerimaan`}
                dokumen={Penerimaan.map((p) => ({ ...p, Nilai: p.TotalNilai }))}
                kosong="Belum ada barang diterima untuk pesanan ini."
            />
            {Pengiriman.length > 0 ? (
                <Panel judul="Pengiriman ke pemasok" idJudul="judul-pengiriman-pesanan">
                    <ul aria-label="Riwayat pengiriman ke pemasok" className="flex flex-col divide-y divide-garis">
                        {Pengiriman.map((p) => (
                            <li key={p.Uuid} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-isi">
                                <LabelStatus
                                    jenis={
                                        p.Status === 'Terkirim'
                                            ? 'sukses'
                                            : p.Status === 'Gagal'
                                              ? 'bahaya'
                                              : p.Status === 'Dibatalkan'
                                                ? 'netral'
                                                : 'peringatan'
                                    }
                                    teks={p.LabelStatus}
                                />
                                <span className="text-teks-sekunder">
                                    WhatsApp | {FormatTanggalWaktu(p.TerkirimPada ?? p.DibuatPada)}
                                </span>
                                {p.PesanGalat ? (
                                    <span className="text-keterangan text-bahaya wrap-anywhere">{p.PesanGalat}</span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}
            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {dialog === 'Tolak' ? (
                <DialogAlasan
                    judul={`Tolak pesanan ${Pesanan.Nomor}?`}
                    keterangan="Pesanan kembali ke draf agar pembuat bisa memperbaikinya."
                    labelAksi="Tolak pesanan"
                    alamat={`${alamat}/${Pesanan.Uuid}/tolak`}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Batalkan' ? (
                <DialogAlasan
                    judul={`Batalkan pesanan ${Pesanan.Nomor}?`}
                    keterangan="Pesanan yang dibatalkan tidak bisa diterima lagi. Hanya bisa bila belum ada barang diterima."
                    labelAksi="Batalkan pesanan"
                    alamat={`${alamat}/${Pesanan.Uuid}/batalkan`}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Tutup' ? (
                <DialogKonfirmasi
                    judul={`Tutup pesanan ${Pesanan.Nomor}?`}
                    labelAksi="Tutup pesanan"
                    varian="utama"
                    memproses={memproses}
                    saatBatal={() => AturDialog(null)}
                    saatKonfirmasi={() => Kirim('tutup')}
                >
                    <p>Sisa barang yang belum diterima tidak ditunggu lagi. Penerimaan yang sudah ada tetap berlaku.</p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
