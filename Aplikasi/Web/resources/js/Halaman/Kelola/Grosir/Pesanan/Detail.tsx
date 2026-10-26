import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import {
    AlamatGrosir,
    DialogAlasanGrosir,
    KartuKeteranganGrosir,
    KeteranganGrosir,
    LabelStatusGrosir,
    RingkasanNilaiGrosir,
    RiwayatGrosirDokumen,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import DialogKirimGrosir from '@/Komponen/Grosir/DialogKirimGrosir';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen, FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisDetailPesananGrosir, PropsDetailPesananGrosir } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/pesanan`;

const kolom: KolomTabel<BarisDetailPesananGrosir>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">
                    {b.Urutan}. {b.NamaProduk}
                </span>
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
        id: 'JumlahTerkirim',
        header: 'Terkirim',
        enableSorting: false,
        meta: { label: 'Jumlah terkirim', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.JumlahTerkirim, b.SimbolSatuan),
    },
    {
        id: 'Harga',
        header: 'Harga',
        enableSorting: false,
        meta: { label: 'Harga per satuan', angka: true, prioritas: 'penting' },
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
];

/**
 * Detail pesanan grosir (F-12, §9.7). Harga di sini datang dari price engine server, bukan dari formulir — operator
 * memeriksanya di halaman ini sebelum mengonfirmasi, karena setelah dikonfirmasi barisnya terkunci.
 */
export default function HalamanDetailPesananGrosir({
    Pesanan,
    Baris,
    SuratJalan,
    Riwayat,
    OpsiGudang,
    OpsiArmada,
    HariIni,
    Tindakan,
}: PropsDetailPesananGrosir) {
    const [dialog, AturDialog] = useState<'kirim' | 'batalkan' | null>(null);
    const [memproses, AturMemproses] = useState(false);

    const Konfirmasi = () =>
        router.post(
            `${alamat}/${Pesanan.Uuid}/konfirmasi`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );

    return (
        <TataLetakAplikasi judul={`Pesanan grosir ${Pesanan.Nomor}`}>
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatusGrosir status={Pesanan.Status} label={Pesanan.LabelStatus} />
            </div>

            {Pesanan.AlasanPersetujuanKredit !== null ? (
                <Pemberitahuan jenis="peringatan" judul="Dikonfirmasi dengan persetujuan kredit">
                    {Pesanan.AlasanPersetujuanKredit}
                </Pemberitahuan>
            ) : null}
            {Pesanan.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Pesanan dibatalkan">
                    {Pesanan.AlasanBatal}
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                {Tindakan.Ubah ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${alamat}/${Pesanan.Uuid}/ubah`}>Ubah draf</Link>
                    </Button>
                ) : null}
                {Tindakan.Konfirmasi ? (
                    <Button onClick={Konfirmasi} disabled={memproses} className="h-8 pointer-coarse:h-11">
                        Konfirmasi pesanan
                    </Button>
                ) : null}
                {Tindakan.Kirim ? (
                    <Button onClick={() => AturDialog('kirim')} className="h-8 pointer-coarse:h-11">
                        Kirim barang
                    </Button>
                ) : null}
                {/* Daftar ambil barang hanya berguna selama masih ada sisa yang belum dikirim. */}
                {Tindakan.Kirim ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <a href={`${alamat}/${Pesanan.Uuid}/ambil-barang`} target="_blank" rel="noreferrer">
                            Cetak daftar ambil barang
                        </a>
                    </Button>
                ) : null}
                {Tindakan.Batalkan ? (
                    <Button
                        variant="outline"
                        onClick={() => AturDialog('batalkan')}
                        className="h-8 text-bahaya pointer-coarse:h-11"
                    >
                        Batalkan
                    </Button>
                ) : null}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">{Pesanan.NamaPelanggan}</KeteranganGrosir>
                {Pesanan.Sumber === 'Salesman' ? (
                    <KeteranganGrosir label="Sumber pesanan">
                        {Pesanan.NamaSalesman !== null
                            ? `Dari salesman ${Pesanan.NamaSalesman}`
                            : 'Dari aplikasi salesman'}
                    </KeteranganGrosir>
                ) : null}
                <KeteranganGrosir label="Outlet penjual">{Pesanan.KodeOutlet}</KeteranganGrosir>
                <KeteranganGrosir label="Tanggal">{FormatTanggal(Pesanan.Tanggal)}</KeteranganGrosir>
                <KeteranganGrosir label="Minta dikirim">
                    {Pesanan.TanggalKirimDiminta === null ? '—' : FormatTanggal(Pesanan.TanggalKirimDiminta)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Termin">
                    {Pesanan.TerminHari === 0 ? 'Tunai' : `${String(Pesanan.TerminHari)} hari`}
                </KeteranganGrosir>
                <KeteranganGrosir label="Tarif PPN">
                    {Pesanan.TarifPpn === null ? 'Tanpa PPN' : FormatPersen(Pesanan.TarifPpn)}
                </KeteranganGrosir>
                {Pesanan.Catatan !== null ? (
                    <KeteranganGrosir label="Catatan">{Pesanan.Catatan}</KeteranganGrosir>
                ) : null}
            </KartuKeteranganGrosir>

            <Panel judul="Barang">
                <TabelData
                    id="grosir-pesanan-baris"
                    label={`Barang pesanan ${Pesanan.Nomor}`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: Baris }}
                    ambilIdBaris={(b) => String(b.Urutan)}
                    kosong={{ judul: 'Pesanan ini belum punya barang.' }}
                />
                <RingkasanNilaiGrosir
                    baris={[
                        { label: 'Subtotal', nilai: Pesanan.Subtotal },
                        { label: 'Diskon', nilai: Pesanan.Diskon },
                        { label: 'Dasar pengenaan pajak', nilai: Pesanan.DasarPengenaanPajak },
                        { label: 'Pajak', nilai: Pesanan.Pajak },
                        { label: 'Total', nilai: Pesanan.Total, tebal: true },
                    ]}
                />
            </Panel>

            <section aria-label="Surat jalan" className="flex flex-col gap-2">
                <h2 className="text-subjudul font-semibold text-teks-utama">Surat jalan</h2>
                {SuratJalan.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Belum ada barang yang diserahkan. Stok, HPP, pendapatan, dan PPN baru bergerak saat surat jalan
                        dibuat.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-1 text-isi">
                        {SuratJalan.map((sj) => (
                            <li key={sj.Uuid} className="break-words text-teks-sekunder">
                                <Link
                                    href={`${AlamatGrosir}/surat-jalan/${sj.Uuid}`}
                                    className="font-mono font-semibold text-brand underline"
                                >
                                    {sj.Nomor}
                                </Link>{' '}
                                | {FormatTanggal(sj.Tanggal)} | {sj.LabelStatus} | {FormatRupiah(sj.Total)}
                                {sj.Difakturkan ? ' | sudah difakturkan' : ' | belum difakturkan'}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <RiwayatGrosirDokumen riwayat={Riwayat} />

            {dialog === 'kirim' ? (
                <DialogKirimGrosir
                    uuidPesanan={Pesanan.Uuid}
                    baris={Baris}
                    opsiGudang={OpsiGudang}
                    opsiArmada={OpsiArmada ?? []}
                    hariIni={HariIni}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'batalkan' ? (
                <DialogAlasanGrosir
                    judul="Batalkan pesanan grosir"
                    keterangan="Pesanan yang sudah ada surat jalannya tidak bisa dibatalkan. Koreksinya lewat retur."
                    labelAksi="Batalkan pesanan"
                    alamat={`${alamat}/${Pesanan.Uuid}/batalkan`}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
