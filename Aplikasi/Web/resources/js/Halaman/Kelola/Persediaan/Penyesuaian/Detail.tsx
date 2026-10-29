import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import {
    DialogAlasan,
    Keterangan,
    LabelStatusDokumen,
    PanelJurnalDokumen,
    PanelRiwayatDokumen,
} from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatHppSatuan, FormatJumlahStok, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { BandingkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDetailPenyesuaianStok, PropsDetailPenyesuaianStok } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/penyesuaian';

type JenisDialog = 'Ajukan' | 'Setujui' | 'Tolak' | 'Batalkan' | null;

const kolom: KolomTabel<BarisDetailPenyesuaianStok>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                    {b.NomorBatch ? ` | batch ${b.NomorBatch}` : ''}
                    {b.NomorSeri ? ` | ${b.NomorSeri}` : ''}
                </span>
            </>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah (+ masuk, − keluar)', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Jumlah, row.original.SimbolSatuan),
    },
    {
        id: 'HppSatuan',
        header: 'Harga modal',
        enableSorting: false,
        meta: { label: 'Harga modal per satuan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => (row.original.HppSatuan === null ? 'HPP berjalan' : FormatHppSatuan(row.original.HppSatuan)),
    },
    {
        id: 'Nilai',
        header: 'Nilai',
        enableSorting: false,
        meta: { label: 'Nilai tercatat', angka: true, prioritas: 'penting' },
        cell: ({ row }) => (row.original.Nilai === null ? 'Saat dicatat' : FormatNilai(row.original.Nilai)),
    },
];

/** F-05b: detail penyesuaian stok: ajukan (posting langsung atau menunggu persetujuan), setujui/tolak, batal draf. */
export default function HalamanDetailPenyesuaianStok({
    Penyesuaian,
    Baris,
    Jurnal,
    Riwayat,
    Tindakan,
    Izin,
    BatasPersetujuan,
}: PropsDetailPenyesuaianStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState<JenisDialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const nomor = Penyesuaian.Nomor ?? 'Draf tanpa nomor';
    const diAtasBatas = BandingkanDesimal(Penyesuaian.NilaiPerkiraan, BatasPersetujuan) > 0;

    const Kirim = (aksi: string, data: Parameters<typeof router.post>[1] = {}) =>
        router.post(`${alamat}/${Penyesuaian.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturDialog(null),
        });

    return (
        <TataLetakAplikasi judul={`Penyesuaian ${nomor}`}>
            <AksiHalaman
                keterangan={<LabelStatusDokumen status={Penyesuaian.Status} label={Penyesuaian.LabelStatus} />}
                langkah={[
                    ...(Tindakan.Ajukan ? [{ label: 'Ajukan penyesuaian', saatKlik: () => AturDialog('Ajukan') }] : []),
                    ...(Tindakan.Setujui ? [{ label: 'Setujui & catat', saatKlik: () => AturDialog('Setujui') }] : []),
                ]}
                lainnya={[
                    ...(Tindakan.Ubah ? [{ label: 'Ubah draf', href: `${alamat}/${Penyesuaian.Uuid}/ubah` }] : []),
                    ...(Tindakan.Tolak ? [{ label: 'Tolak', saatKlik: () => AturDialog('Tolak') }] : []),
                    ...(Tindakan.Batalkan
                        ? [{ label: 'Batalkan draf', saatKlik: () => AturDialog('Batalkan'), bahaya: true }]
                        : []),
                ]}
            />

            {Penyesuaian.Status === 'MenungguPersetujuan' ? (
                <Pemberitahuan jenis="peringatan" judul="Butuh persetujuan">
                    Nilai {FormatNilai(Penyesuaian.NilaiPerkiraan)} di atas batas {FormatNilai(BatasPersetujuan)}. Harus
                    disetujui pengguna lain yang berwenang menyetujui penyesuaian stok, bukan pembuat atau pengajunya.
                </Pemberitahuan>
            ) : null}
            {Penyesuaian.Status === 'Draf' && Penyesuaian.AlasanTolak ? (
                <Pemberitahuan jenis="bahaya" judul="Ditolak">
                    {Penyesuaian.AlasanTolak} Perbaiki draf lalu ajukan lagi, atau batalkan.
                </Pemberitahuan>
            ) : null}
            {Penyesuaian.Status === 'Dibatalkan' ? (
                <Pemberitahuan jenis="info" judul="Draf dibatalkan">
                    Stok tidak berubah. Dokumen tetap tersimpan sebagai catatan.
                </Pemberitahuan>
            ) : null}
            {dialog === null ? <DaftarGalatServer galat={galat} /> : null}

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-penyesuaian">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Lokasi stok">
                        {Penyesuaian.NamaGudang}
                        {Penyesuaian.NamaOutlet ? (
                            <span className="block text-keterangan text-teks-sekunder">{Penyesuaian.NamaOutlet}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Tanggal">{FormatTanggal(Penyesuaian.Tanggal)}</Keterangan>
                    <Keterangan label="Alasan">
                        {Penyesuaian.LabelAlasan}
                        {Penyesuaian.Keterangan ? (
                            <span className="block text-keterangan text-teks-sekunder">{Penyesuaian.Keterangan}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label={Penyesuaian.Status === 'Diposting' ? 'Nilai tercatat' : 'Nilai (perkiraan)'}>
                        <span className="font-semibold tabular-nums">
                            {Penyesuaian.Status === 'Diposting'
                                ? `Masuk ${FormatNilai(Penyesuaian.TotalNilaiMasuk)} | Keluar ${FormatNilai(Penyesuaian.TotalNilaiKeluar)}`
                                : FormatNilai(Penyesuaian.NilaiPerkiraan)}
                        </span>
                    </Keterangan>
                    <Keterangan label="Dibuat">
                        {FormatTanggalWaktu(Penyesuaian.DibuatPada)}
                        {Penyesuaian.DibuatOleh ? ` oleh ${Penyesuaian.DibuatOleh}` : ''}
                    </Keterangan>
                    {Penyesuaian.DisetujuiOleh ? (
                        <Keterangan label="Disetujui oleh">{Penyesuaian.DisetujuiOleh}</Keterangan>
                    ) : null}
                </dl>
            </Panel>

            <Panel judul="Barang" idJudul="judul-barang-detail-penyesuaian">
                <TabelData
                    id="persediaan-penyesuaian-baris"
                    label={`Barang penyesuaian, ${String(Baris.length)} baris`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    cari="Cari nama produk atau SKU"
                    kosong={{ judul: 'Penyesuaian ini belum berisi barang.' }}
                />
            </Panel>

            <PanelJurnalDokumen
                id="persediaan-penyesuaian-jurnal"
                jurnal={Jurnal}
                lihatJurnal={Izin.LihatJurnal}
                keterangan="Keluar: Debit susut & barang rusak (rusak, hilang, kedaluwarsa) atau selisih HPP. Masuk: Kredit selisih HPP."
                kosong={
                    Penyesuaian.Status === 'Diposting'
                        ? 'Tidak ada jurnal karena nilai penyesuaian Rp 0.'
                        : 'Jurnal dibuat saat penyesuaian dicatat.'
                }
            />
            <PanelRiwayatDokumen riwayat={Riwayat} id="judul-riwayat-penyesuaian" />

            {dialog === 'Ajukan' ? (
                <DialogKonfirmasi
                    galatUmum={galat.Umum}
                    judul="Ajukan penyesuaian?"
                    labelAksi="Ajukan penyesuaian"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('ajukan')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>
                        Nilai perkiraan {FormatNilai(Penyesuaian.NilaiPerkiraan)}. Batas persetujuan{' '}
                        {FormatNilai(BatasPersetujuan)}.
                    </p>
                    <p>
                        {diAtasBatas
                            ? 'Nilainya di atas batas, jadi penyesuaian menunggu persetujuan pengguna lain.'
                            : 'Di bawah batas, penyesuaian langsung dicatat: stok dan jurnal tercatat dan dokumen tidak bisa diubah.'}
                    </p>
                </DialogKonfirmasi>
            ) : null}
            {dialog === 'Setujui' ? (
                <DialogKonfirmasi
                    galatUmum={galat.Umum}
                    judul="Setujui penyesuaian?"
                    labelAksi="Setujui & catat"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('setujui')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>
                        Stok dan jurnal dicatat sekarang. Penyesuaian yang sudah dicatat hanya bisa dikoreksi dengan
                        penyesuaian baru.
                    </p>
                </DialogKonfirmasi>
            ) : null}
            {dialog === 'Tolak' ? (
                <DialogAlasan
                    judul="Tolak penyesuaian?"
                    keterangan={<p>Penyesuaian kembali menjadi draf dan stok tidak berubah.</p>}
                    labelAksi="Tolak penyesuaian"
                    labelAlasan="Alasan penolakan"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('tolak', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Batalkan' ? (
                <DialogKonfirmasi
                    judul="Batalkan draf penyesuaian?"
                    labelAksi="Batalkan draf"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('batalkan')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>Draf tidak dipakai lagi dan stok tidak berubah.</p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
