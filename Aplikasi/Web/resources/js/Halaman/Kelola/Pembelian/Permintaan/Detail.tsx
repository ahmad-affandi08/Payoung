import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Panel from '@/Komponen/Kelola/Panel';
import {
    AlamatPembelian,
    DaftarRiwayatDokumen,
    DialogAlasan,
    KartuKeterangan,
    Keterangan,
    LabelStatusPembelian,
} from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailPermintaan, PropsDetailPermintaan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/permintaan`;

const kolomBaris: KolomTabel<BarisDetailPermintaan>[] = [
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
        header: 'Diminta',
        enableSorting: false,
        meta: { label: 'Jumlah diminta', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, b.SimbolSatuan),
    },
    {
        id: 'HargaPerkiraan',
        header: 'Perkiraan harga',
        enableSorting: false,
        meta: { label: 'Perkiraan harga per satuan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.HargaPerkiraan),
    },
];

type Dialog = 'Tolak' | 'Batalkan' | 'JadikanPo' | null;

/** F-04 PR: detail permintaan pembelian (ajukan, setujui/tolak, jadikan PO, batalkan). */
export default function HalamanDetailPermintaan({
    Permintaan,
    Baris,
    Riwayat,
    OpsiPemasok,
    Tindakan,
}: PropsDetailPermintaan) {
    const [dialog, AturDialog] = useState<Dialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const [pemasok, AturPemasok] = useState(Permintaan.Pemasok?.Uuid ?? '');
    const Kirim = (aksi: string, data: Record<string, string | null> = {}) =>
        router.post(`${alamat}/${Permintaan.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => {
                AturMemproses(false);
                AturDialog(null);
            },
        });

    return (
        <TataLetakAplikasi
            judul={`Permintaan ${Permintaan.Nomor}`}
            jejak={[{ label: 'Permintaan pembelian', href: alamat }]}
        >
            <div className="flex flex-wrap items-center gap-2">
                <LabelStatusPembelian status={Permintaan.Status} label={Permintaan.LabelStatus} />
                <span className="text-isi text-teks-sekunder">
                    {Permintaan.NamaGudang}
                    {Permintaan.NamaOutlet ? ` | ${Permintaan.NamaOutlet}` : ''}
                </span>
            </div>

            {Permintaan.AlasanDitolak && Permintaan.Status === 'Draf' ? (
                <Pemberitahuan jenis="peringatan" judul="Permintaan ditolak">
                    {Permintaan.AlasanDitolak}. Perbaiki lalu ajukan lagi.
                </Pemberitahuan>
            ) : null}
            {Permintaan.AlasanBatal ? (
                <Pemberitahuan jenis="bahaya" judul="Permintaan dibatalkan">
                    {Permintaan.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {Permintaan.Status === 'Diajukan' && !Tindakan.Putuskan ? (
                <Pemberitahuan jenis="info" judul="Menunggu persetujuan">
                    Permintaan ini disetujui oleh pengguna lain yang berizin menyetujui pesanan pembelian (bukan pembuat
                    atau pengajunya).
                </Pemberitahuan>
            ) : null}
            {Permintaan.Pesanan ? (
                <Pemberitahuan jenis="sukses" judul="Sudah dijadikan pesanan pembelian">
                    <Link
                        href={`${AlamatPembelian}/pesanan/${Permintaan.Pesanan.Uuid}`}
                        className="font-mono underline"
                    >
                        {Permintaan.Pesanan.Nomor}
                    </Link>
                </Pemberitahuan>
            ) : null}

            <div className="flex flex-wrap gap-2">
                {Tindakan.Ubah ? (
                    <Button asChild variant="outline">
                        <Link href={`${alamat}/${Permintaan.Uuid}/ubah`}>Ubah draf</Link>
                    </Button>
                ) : null}
                {Tindakan.Ajukan ? (
                    <Button onClick={() => Kirim('ajukan')} disabled={memproses}>
                        Ajukan permintaan
                    </Button>
                ) : null}
                {Tindakan.Putuskan ? (
                    <>
                        <Button onClick={() => Kirim('setujui')} disabled={memproses}>
                            Setujui permintaan
                        </Button>
                        <Button variant="outline" onClick={() => AturDialog('Tolak')}>
                            Tolak
                        </Button>
                    </>
                ) : null}
                {Tindakan.JadikanPo ? (
                    <Button onClick={() => AturDialog('JadikanPo')}>Jadikan pesanan pembelian</Button>
                ) : null}
                {Tindakan.Batalkan ? (
                    <Button variant="destructive" onClick={() => AturDialog('Batalkan')}>
                        Batalkan permintaan
                    </Button>
                ) : null}
            </div>

            <KartuKeterangan>
                <Keterangan label="Nomor">
                    <span className="font-mono">{Permintaan.Nomor}</span>
                </Keterangan>
                <Keterangan label="Tanggal">{FormatTanggal(Permintaan.Tanggal)}</Keterangan>
                <Keterangan label="Dibutuhkan pada">
                    {Permintaan.DibutuhkanPada ? FormatTanggal(Permintaan.DibutuhkanPada) : '—'}
                </Keterangan>
                <Keterangan label="Pemasok usulan">
                    {Permintaan.Pemasok
                        ? `${Permintaan.Pemasok.Nama} (${Permintaan.Pemasok.Kode})`
                        : 'Belum ditentukan'}
                </Keterangan>
                <Keterangan label="Dibuat oleh">{Permintaan.DibuatOleh ?? '—'}</Keterangan>
                {Permintaan.DiputuskanOleh ? (
                    <Keterangan label="Disetujui">
                        {Permintaan.DiputuskanOleh}
                        {Permintaan.DiputuskanPada ? ` | ${FormatTanggalWaktu(Permintaan.DiputuskanPada)}` : ''}
                    </Keterangan>
                ) : null}
                {Permintaan.Catatan ? <Keterangan label="Catatan">{Permintaan.Catatan}</Keterangan> : null}
            </KartuKeterangan>

            <Panel judul="Barang" idJudul="judul-barang-permintaan">
                <TabelData
                    id="pembelian-permintaan-baris"
                    label={`Barang diminta, ${String(Baris.length)} baris`}
                    kolom={kolomBaris}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Id)}
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Permintaan ini belum berisi barang.' }}
                />
            </Panel>

            <DaftarRiwayatDokumen riwayat={Riwayat} />

            {dialog === 'Tolak' ? (
                <DialogAlasan
                    judul={`Tolak permintaan ${Permintaan.Nomor}?`}
                    keterangan="Permintaan kembali ke draf agar pembuat bisa memperbaikinya."
                    labelAksi="Tolak permintaan"
                    alamat={`${alamat}/${Permintaan.Uuid}/tolak`}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Batalkan' ? (
                <DialogAlasan
                    judul={`Batalkan permintaan ${Permintaan.Nomor}?`}
                    keterangan="Permintaan yang dibatalkan tidak bisa dijadikan pesanan pembelian."
                    labelAksi="Batalkan permintaan"
                    alamat={`${alamat}/${Permintaan.Uuid}/batalkan`}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'JadikanPo' ? (
                <DialogKonfirmasi
                    judul={`Jadikan ${Permintaan.Nomor} pesanan pembelian?`}
                    labelAksi="Buat draf pesanan"
                    varian="utama"
                    memproses={memproses}
                    nonaktif={pemasok === ''}
                    saatBatal={() => AturDialog(null)}
                    saatKonfirmasi={() => Kirim('jadikan-po', { UuidPemasok: pemasok === '' ? null : pemasok })}
                >
                    <div className="flex flex-col gap-3">
                        <p>
                            Semua barang disalin ke draf pesanan pembelian, dengan perkiraan harga sebagai harga awal.
                            Periksa dan ajukan pesanan itu setelahnya.
                        </p>
                        <BidangPilihan
                            label="Pemasok"
                            nilai={pemasok}
                            kosong="Pilih pemasok"
                            opsi={OpsiPemasok.filter((p) => p.Aktif || p.Uuid === pemasok).map((p) => ({
                                Nilai: p.Uuid,
                                Label: p.Nama,
                                Keterangan: p.Kode,
                            }))}
                            saatBerubah={AturPemasok}
                            required
                        />
                    </div>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
