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
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BahanDetailOrderProduksi, PropsDetailOrderProduksi } from '@/Tipe/Produksi';

import { AlamatProduksi } from './Daftar';

type JenisDialog = 'Posting' | 'Batalkan' | null;

const kolom: KolomTabel<BahanDetailOrderProduksi>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Bahan',
        meta: { label: 'Bahan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="block font-mono text-keterangan text-teks-sekunder">{b.Sku ?? 'Tanpa SKU'}</span>
            </>
        ),
    },
    {
        id: 'JumlahStandar',
        header: 'Standar resep',
        enableSorting: false,
        meta: { label: 'Standar resep', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.JumlahStandar, b.SimbolSatuan),
    },
    {
        id: 'Jumlah',
        header: 'Dipakai',
        enableSorting: false,
        meta: { label: 'Dipakai (aktual)', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, b.SimbolSatuan),
    },
    {
        id: 'Selisih',
        header: 'Selisih',
        enableSorting: false,
        meta: { label: 'Selisih dari standar', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => {
            const tanda = AmbilTandaDesimal(b.Selisih);

            return (
                <span className={tanda > 0 ? 'text-peringatan' : undefined}>
                    {tanda === 0
                        ? 'Sesuai'
                        : `${tanda > 0 ? 'Boros ' : 'Hemat '}${FormatJumlahStok(b.Selisih.replace('-', ''), b.SimbolSatuan)}`}
                </span>
            );
        },
    },
    {
        id: 'Nilai',
        header: 'Nilai',
        enableSorting: false,
        meta: { label: 'Nilai bahan (HPP)', angka: true, prioritas: 'penting' },
        cell: ({ row }) => (row.original.Nilai === null ? 'Saat dicatat' : FormatNilai(row.original.Nilai)),
    },
];

/** F-05e: detail order produksi: posting (stok & jurnal J-05.6), batalkan (draf, atau tercatat dengan pembalik). */
export default function HalamanDetailOrderProduksi({
    Order,
    Bahan,
    Jurnal,
    Riwayat,
    Tindakan,
    Izin,
}: PropsDetailOrderProduksi) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState<JenisDialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const nomor = Order.Nomor ?? 'Draf tanpa nomor';

    const Kirim = (aksi: string, data: Parameters<typeof router.post>[1] = {}) =>
        router.post(`${AlamatProduksi}/${Order.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturDialog(null),
        });

    return (
        <TataLetakAplikasi judul={`Produksi ${nomor}`}>
            <AksiHalaman
                keterangan={<LabelStatusDokumen status={Order.Status} label={Order.LabelStatus} />}
                langkah={[
                    ...(Tindakan.Posting ? [{ label: 'Catat produksi', saatKlik: () => AturDialog('Posting') }] : []),
                ]}
                lainnya={[
                    ...(Tindakan.Ubah ? [{ label: 'Ubah draf', href: `${AlamatProduksi}/${Order.Uuid}/ubah` }] : []),
                    ...(Tindakan.Batalkan
                        ? [
                              {
                                  label: Tindakan.WajibAlasanBatal ? 'Batalkan produksi' : 'Batalkan draf',
                                  saatKlik: () => AturDialog('Batalkan'),
                                  bahaya: true,
                              },
                          ]
                        : []),
                ]}
            />

            {Order.Status === 'Dibatalkan' ? (
                <Pemberitahuan jenis="info" judul="Dibatalkan">
                    {Order.Nomor
                        ? `Stok dan jurnal sudah dibalik${Order.AlasanBatal ? `: ${Order.AlasanBatal}` : '.'}`
                        : 'Draf tidak dipakai dan stok tidak berubah.'}
                </Pemberitahuan>
            ) : null}
            {dialog === null ? <DaftarGalatServer galat={galat} /> : null}

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-produksi">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Hasil produksi">
                        {Order.NamaProduk}
                        <span className="block text-keterangan text-teks-sekunder">
                            {FormatJumlahStok(Order.JumlahHasil, Order.SimbolSatuan)}
                            {Order.NomorBatch ? ` | batch ${Order.NomorBatch}` : ''}
                            {Order.TanggalKedaluwarsa
                                ? ` | kedaluwarsa ${FormatTanggal(Order.TanggalKedaluwarsa)}`
                                : ''}
                        </span>
                    </Keterangan>
                    <Keterangan label="Lokasi stok">
                        {Order.NamaGudang}
                        {Order.NamaOutlet ? (
                            <span className="block text-keterangan text-teks-sekunder">{Order.NamaOutlet}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Tanggal">{FormatTanggal(Order.Tanggal)}</Keterangan>
                    <Keterangan label="Biaya overhead">
                        <span className="tabular-nums">{FormatNilai(Order.BiayaOverhead)}</span>
                    </Keterangan>
                    {Order.Status !== 'Draf' || Order.Nomor ? (
                        <Keterangan label="Nilai hasil">
                            <span className="font-semibold tabular-nums">{FormatNilai(Order.NilaiHasil)}</span>
                            <span className="block text-keterangan text-teks-sekunder">
                                Bahan {FormatNilai(Order.TotalNilaiBahan)} | HPP {FormatHppSatuan(Order.HppSatuanHasil)}{' '}
                                per {Order.SimbolSatuan}
                            </span>
                        </Keterangan>
                    ) : null}
                    <Keterangan label="Resep">
                        {Order.VersiResep ? `Versi ${String(Order.VersiResep)}` : 'Bahan ditulis manual'}
                    </Keterangan>
                    {Order.Keterangan ? <Keterangan label="Catatan">{Order.Keterangan}</Keterangan> : null}
                    {Order.DipostingPada ? (
                        <Keterangan label="Tercatat">
                            {FormatTanggalWaktu(Order.DipostingPada)}
                            {Order.DipostingOleh ? ` oleh ${Order.DipostingOleh}` : ''}
                        </Keterangan>
                    ) : null}
                </dl>
            </Panel>

            <Panel judul="Bahan" idJudul="judul-bahan-produksi">
                <TabelData
                    id="persediaan-produksi-bahan"
                    label={`Bahan produksi, ${String(Bahan.length)} baris`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Bahan }}
                    ambilIdBaris={(b) => b.UuidProduk}
                    cari="Cari nama bahan atau SKU"
                    kosong={{ judul: 'Order ini belum berisi bahan.' }}
                />
            </Panel>

            <PanelJurnalDokumen
                id="persediaan-produksi-jurnal"
                jurnal={Jurnal}
                lihatJurnal={Izin.LihatJurnal}
                keterangan="Nilai barang hasil produksi bertambah, nilai bahan berkurang, dan biaya overhead produksi ikut dicatat."
                kosong={
                    Order.Status === 'Draf'
                        ? 'Jurnal dibuat saat produksi dicatat.'
                        : 'Tidak ada jurnal karena nilai Rp 0.'
                }
            />
            <PanelRiwayatDokumen riwayat={Riwayat} id="judul-riwayat-produksi" />

            {dialog === 'Posting' ? (
                <DialogKonfirmasi
                    galatUmum={galat.Umum}
                    judul="Catat produksi?"
                    labelAksi="Catat produksi"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('posting')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>
                        Bahan dikurangi dari {Order.NamaGudang},{' '}
                        {FormatJumlahStok(Order.JumlahHasil, Order.SimbolSatuan)} {Order.NamaProduk} bertambah, dan
                        jurnal dicatat. Order tidak bisa diubah lagi.
                    </p>
                </DialogKonfirmasi>
            ) : null}
            {dialog === 'Batalkan' && Tindakan.WajibAlasanBatal ? (
                <DialogAlasan
                    judul="Batalkan produksi?"
                    keterangan={
                        <p>
                            Hasil produksi dikeluarkan lagi dan bahan dikembalikan ke stok dengan nilai semula. Hanya
                            bisa bila hasil belum terjual atau berpindah.
                        </p>
                    }
                    labelAksi="Batalkan produksi"
                    labelAlasan="Alasan pembatalan"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('batalkan', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Batalkan' && !Tindakan.WajibAlasanBatal ? (
                <DialogKonfirmasi
                    judul="Batalkan draf produksi?"
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
