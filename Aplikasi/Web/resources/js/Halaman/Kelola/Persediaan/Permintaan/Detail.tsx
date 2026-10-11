import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import {
    DialogAlasan,
    Keterangan,
    LabelStatusDokumen,
    PanelRiwayatDokumen,
} from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDetailPermintaanStok, PropsDetailPermintaanStok } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/permintaan';

type JenisDialog = 'Tolak' | 'Batalkan' | 'Tutup' | null;

const kolomBaris: KolomTabel<BarisDetailPermintaanStok>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
            </>
        ),
    },
    {
        id: 'JumlahDiminta',
        header: 'Diminta',
        enableSorting: false,
        meta: { label: 'Diminta', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahDiminta, row.original.SimbolSatuan),
    },
    {
        id: 'JumlahDikirim',
        header: 'Sudah dikirim',
        enableSorting: false,
        meta: { label: 'Sudah dikirim', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahDikirim, row.original.SimbolSatuan),
    },
    {
        id: 'JumlahSisa',
        header: 'Belum dikirim',
        enableSorting: false,
        meta: { label: 'Belum dikirim', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.JumlahSisa, row.original.SimbolSatuan),
    },
];

/** F-05b, D-105: detail permintaan stok. Pengirim memenuhi lewat transfer atau menolak; peminta membatalkan; keduanya bisa menutup sisa. */
export default function HalamanDetailPermintaanStok({
    Permintaan,
    Baris,
    Transfer,
    Riwayat,
    Tindakan,
}: PropsDetailPermintaanStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState<JenisDialog>(null);
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (aksi: string, data: Parameters<typeof router.post>[1] = {}) =>
        router.post(`${alamat}/${Permintaan.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturDialog(null),
        });

    return (
        <TataLetakAplikasi judul={`Permintaan ${Permintaan.Nomor}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-subjudul font-semibold text-teks-utama">{Permintaan.Nomor}</span>
                    <LabelStatusDokumen status={Permintaan.Status} label={Permintaan.LabelStatus} />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={alamat}>Kembali ke daftar</Link>
                    </Button>
                    {Tindakan.Batalkan ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('Batalkan')}>
                            Batalkan permintaan
                        </Tombol>
                    ) : null}
                    {Tindakan.Tutup ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('Tutup')}>
                            Tutup sisa
                        </Tombol>
                    ) : null}
                    {Tindakan.Tolak ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('Tolak')}>
                            Tolak permintaan
                        </Tombol>
                    ) : null}
                    {Tindakan.Penuhi ? (
                        <Button asChild className="h-8 pointer-coarse:h-11">
                            <Link href={`/kelola/persediaan/transfer/buat?permintaan=${Permintaan.Uuid}`}>
                                Penuhi dengan transfer
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>

            {Permintaan.Status === 'Ditolak' ||
            Permintaan.Status === 'Dibatalkan' ||
            Permintaan.Status === 'Ditutup' ? (
                <Pemberitahuan jenis="info" judul={`Permintaan ${Permintaan.LabelStatus.toLowerCase()}`}>
                    {Permintaan.Alasan ? `Alasan: ${Permintaan.Alasan}` : 'Permintaan ini sudah berakhir.'}
                </Pemberitahuan>
            ) : null}
            {Permintaan.Status === 'Diajukan' && !Tindakan.Penuhi ? (
                <Pemberitahuan jenis="info" judul="Menunggu pengirim">
                    Pengirim di {Permintaan.NamaOutletAsal ?? Permintaan.NamaGudangAsal} akan memenuhinya lewat transfer
                    stok. Anda akan melihat jumlah yang sudah dikirim di halaman ini.
                </Pemberitahuan>
            ) : null}
            {dialog === null ? <DaftarGalatServer galat={galat} /> : null}

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-permintaan">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Diminta oleh (penerima barang)">
                        {Permintaan.NamaGudangTujuan}
                        {Permintaan.NamaOutletTujuan ? (
                            <span className="block text-keterangan text-teks-sekunder">
                                {Permintaan.NamaOutletTujuan}
                            </span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Diminta dari (pengirim)">
                        {Permintaan.NamaGudangAsal}
                        {Permintaan.NamaOutletAsal ? (
                            <span className="block text-keterangan text-teks-sekunder">
                                {Permintaan.NamaOutletAsal}
                            </span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Tanggal">{FormatTanggal(Permintaan.Tanggal)}</Keterangan>
                    <Keterangan label="Diajukan">
                        {FormatTanggalWaktu(Permintaan.DibuatPada)}
                        {Permintaan.DibuatOleh ? ` oleh ${Permintaan.DibuatOleh}` : ''}
                    </Keterangan>
                    {Permintaan.Catatan ? <Keterangan label="Catatan">{Permintaan.Catatan}</Keterangan> : null}
                </dl>
            </Panel>

            <Panel judul="Barang yang diminta" idJudul="judul-barang-permintaan">
                <TabelData
                    id="persediaan-permintaan-baris"
                    label={`Barang permintaan, ${String(Baris.length)} baris`}
                    kolom={kolomBaris}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Permintaan ini tidak berisi barang.' }}
                />
            </Panel>

            <Panel
                judul="Transfer yang menjawab permintaan ini"
                idJudul="judul-transfer-permintaan"
                keterangan="Jumlah baru terhitung terkirim saat transfer dikirim, bukan saat masih draf."
            >
                {Transfer.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada transfer untuk permintaan ini.</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-garis">
                        {Transfer.map((t) => (
                            <li key={t.Uuid} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <Link
                                    href={`/kelola/persediaan/transfer/${t.Uuid}`}
                                    className="font-mono font-semibold break-all text-brand underline"
                                >
                                    {t.Nomor ?? 'Draf tanpa nomor'}
                                </Link>
                                <span className="flex items-center gap-2 text-keterangan text-teks-sekunder">
                                    {FormatTanggal(t.Tanggal)} | {t.JumlahBaris.toLocaleString('id-ID')} baris
                                    <LabelStatusDokumen status={t.Status} label={t.LabelStatus} />
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <PanelRiwayatDokumen riwayat={Riwayat} id="judul-riwayat-permintaan" />

            {dialog === 'Batalkan' ? (
                <DialogAlasan
                    judul="Batalkan permintaan?"
                    keterangan={
                        <p>Pengirim tidak akan melihat permintaan ini lagi sebagai permintaan yang perlu dikirim.</p>
                    }
                    labelAksi="Batalkan permintaan"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('batalkan', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Tolak' ? (
                <DialogAlasan
                    judul="Tolak permintaan?"
                    keterangan={<p>Peminta akan melihat alasan Anda. Stok tidak berubah.</p>}
                    labelAksi="Tolak permintaan"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('tolak', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Tutup' ? (
                <DialogAlasan
                    judul="Tutup sisa permintaan?"
                    keterangan={<p>Barang yang belum dikirim tidak akan dikirim lagi untuk permintaan ini.</p>}
                    labelAksi="Tutup sisa"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('tutup', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
