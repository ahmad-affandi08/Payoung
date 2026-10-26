import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihRentangTanggal from '@/Komponen/Tanggal/PemilihRentangTanggal';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Button } from '@/Komponen/Ui/button';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisProdukPenitip, BarisSetoranKonsinyasi, PropsPenitipKonsinyasi } from '@/Tipe/Pembelian';

import { AlamatKonsinyasi } from '../Daftar';

const kolomProduk: KolomTabel<BarisProdukPenitip>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span className="break-words">{p.NamaProduk}</span>
                <span className="font-mono text-keterangan text-teks-sekunder">{p.Sku ?? 'Tanpa SKU'}</span>
            </span>
        ),
    },
    {
        id: 'Masuk',
        header: 'Masuk',
        enableSorting: false,
        meta: { label: 'Masuk', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.Masuk, row.original.SimbolSatuan),
    },
    {
        id: 'Terjual',
        header: 'Terjual',
        enableSorting: false,
        meta: { label: 'Terjual', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Terjual, row.original.SimbolSatuan),
    },
    {
        id: 'Retur',
        header: 'Diretur',
        enableSorting: false,
        meta: { label: 'Diretur', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatJumlahStok(row.original.Retur, row.original.SimbolSatuan),
    },
    {
        id: 'NilaiTerjual',
        header: 'Nilai terjual',
        enableSorting: false,
        meta: { label: 'Nilai terjual', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.NilaiTerjual),
    },
    {
        id: 'Saldo',
        header: 'Sisa stok',
        enableSorting: false,
        meta: { label: 'Sisa stok', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatJumlahStok(row.original.Saldo, row.original.SimbolSatuan),
    },
];

const kolomSetoran: KolomTabel<BarisSetoranKonsinyasi>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row }) => <span className="font-mono">{row.original.Nomor}</span>,
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: s } }) => (
            <span className="flex flex-col">
                <LabelStatus
                    jenis={s.Status === 'Diposting' ? 'sukses' : 'bahaya'}
                    teks={s.Status === 'Diposting' ? 'Tercatat' : 'Dibatalkan'}
                />
                {s.AlasanBatal ? <span className="text-keterangan text-teks-sekunder">{s.AlasanBatal}</span> : null}
            </span>
        ),
    },
    {
        id: 'Akun',
        header: 'Dari akun',
        enableSorting: false,
        meta: { label: 'Dari akun', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaAkun,
    },
    {
        id: 'Jurnal',
        header: 'Jurnal',
        enableSorting: false,
        meta: { label: 'Jurnal', prioritas: 'rendah' },
        cell: ({ row }) => (
            <span className="flex flex-col">
                {row.original.Jurnal.map((j) => (
                    <Link
                        key={j.Uuid}
                        href={`/kelola/akuntansi/jurnal/${j.Uuid}`}
                        className="font-mono text-brand underline"
                    >
                        {j.Nomor}
                    </Link>
                ))}
            </span>
        ),
    },
];

/**
 * F-05i: rincian satu penitip — hutang (titipan terjual − setoran), per produk dalam periode (masuk, terjual, retur,
 * sisa stok), riwayat setoran, dan aksi setor/batalkan setoran.
 */
export default function HalamanPenitipKonsinyasi({
    Penitip,
    Hutang,
    Periode,
    Produk,
    Setoran,
    OpsiAkun,
    HariIni,
    Izin,
}: PropsPenitipKonsinyasi) {
    const [setor, AturSetor] = useState(false);
    const [batal, AturBatal] = useState<BarisSetoranKonsinyasi | null>(null);
    const alamat = `${AlamatKonsinyasi}/penitip/${Penitip.Uuid}`;
    const adaHutang = AmbilTandaDesimal(Hutang.Sisa) > 0;

    return (
        <TataLetakAplikasi
            judul={`Konsinyasi ${Penitip.Nama}`}
            jejak={[{ label: 'Konsinyasi', href: AlamatKonsinyasi }]}
        >
            {Izin.Kelola ? (
                <AksiHalaman>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatKonsinyasi}/buat?jenis=Retur&pemasok=${Penitip.Uuid}`}>
                            Retur ke penitip
                        </Link>
                    </Button>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatKonsinyasi}/buat?jenis=Masuk&pemasok=${Penitip.Uuid}`}>
                            Catat titipan masuk
                        </Link>
                    </Button>
                    <Tombol onClick={() => AturSetor(true)} disabled={!adaHutang}>
                        Setor ke penitip
                    </Tombol>
                </AksiHalaman>
            ) : null}

            <Panel>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                    <Nilai label="Nilai terjual (semua waktu)">
                        <span className="tabular-nums">{FormatRupiah(Hutang.Terjual)}</span>
                    </Nilai>
                    <Nilai label="Sudah disetor">
                        <span className="tabular-nums">{FormatRupiah(Hutang.Dibayar)}</span>
                    </Nilai>
                    <Nilai label="Hutang ke penitip">
                        <span className="font-semibold tabular-nums">{FormatRupiah(Hutang.Sisa)}</span>
                    </Nilai>
                    <Nilai label="Rekening penitip">
                        {Penitip.NomorRekening
                            ? `${Penitip.NamaBank ?? ''} ${Penitip.NomorRekening} a.n. ${Penitip.AtasNamaRekening ?? Penitip.Nama}`
                            : 'Belum diisi'}
                    </Nilai>
                </dl>
            </Panel>

            <div className="flex flex-wrap items-end gap-3">
                <h2 className="text-subjudul font-semibold text-teks-utama">Titipan per produk</h2>
                <div className="ml-auto w-full sm:w-72">
                    <PemilihRentangTanggal
                        label="Periode"
                        nilai={`${Periode.Dari}..${Periode.Sampai}`}
                        max={HariIni}
                        saatBerubah={(nilai) => {
                            const [dari, sampai] = nilai.split('..');
                            router.get(
                                alamat,
                                { dari: dari ?? '', sampai: sampai ?? '' },
                                { preserveScroll: true, preserveState: true },
                            );
                        }}
                    />
                </div>
            </div>
            <p className="text-isi text-teks-sekunder">
                Nilai terjual pada periode ini:{' '}
                <span className="font-semibold tabular-nums">{FormatRupiah(Periode.NilaiTerjual)}</span>. Sisa stok
                selalu posisi terkini.
            </p>
            <TabelData
                id="pembelian-konsinyasi-produk"
                label="Titipan per produk"
                kolom={kolomProduk}
                sumber={{ mode: 'lokal', data: Produk }}
                ambilIdBaris={(p) => p.Uuid}
                cari="Cari produk"
                kosong={{ judul: 'Penitip ini belum menitipkan barang.' }}
            />

            <h2 className="text-subjudul font-semibold text-teks-utama">Riwayat setoran</h2>
            <TabelData
                id="pembelian-konsinyasi-setoran"
                label="Riwayat setoran"
                kolom={kolomSetoran}
                sumber={{ mode: 'lokal', data: Setoran }}
                ambilIdBaris={(s) => s.Uuid}
                cari={false}
                labelBaris={(s) => `setoran ${s.Nomor}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (s: BarisSetoranKonsinyasi) =>
                              s.Status === 'Diposting' ? (
                                  <ItemAksiBaris
                                      aksi={[{ label: 'Batalkan setoran', saatPilih: () => AturBatal(s) }]}
                                  />
                              ) : null,
                      }
                    : {})}
                kosong={{ judul: 'Belum ada setoran ke penitip ini.' }}
            />

            {setor ? (
                <FormSetor
                    alamat={alamat}
                    sisa={Hutang.Sisa}
                    hariIni={HariIni}
                    opsiAkun={OpsiAkun}
                    saatSelesai={() => AturSetor(false)}
                />
            ) : null}
            {batal ? <FormBatal setoran={batal} saatSelesai={() => AturBatal(null)} /> : null}
        </TataLetakAplikasi>
    );
}

function FormSetor({
    alamat,
    sisa,
    hariIni,
    opsiAkun,
    saatSelesai,
}: {
    alamat: string;
    sisa: string;
    hariIni: string;
    opsiAkun: PropsPenitipKonsinyasi['OpsiAkun'];
    saatSelesai: () => void;
}) {
    const formulir = useForm({ UuidAkun: opsiAkun[0]?.Uuid ?? '', Tanggal: hariIni, Jumlah: sisa, Catatan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/setoran`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Setor ke penitip"
            keterangan={`Hutang saat ini ${FormatRupiah(sisa)}. Setoran dijurnal: Hutang Konsinyasi berkurang, kas/bank berkurang.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangPilihan
                    label="Dari akun"
                    nilai={formulir.data.UuidAkun}
                    opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                    saatBerubah={(nilai) => formulir.setData('UuidAkun', nilai)}
                    galat={formulir.errors.UuidAkun}
                    required
                />
                <PemilihTanggal
                    label="Tanggal"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    max={hariIni}
                    required
                />
                <BidangUang
                    label="Jumlah setoran"
                    nilai={formulir.data.Jumlah}
                    saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                    galat={formulir.errors.Jumlah}
                    required
                />
                <div className="sm:col-span-2">
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={formulir.data.Catatan}
                        saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                        maksimal={500}
                        baris={2}
                        galat={formulir.errors.Catatan}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan setoran
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormBatal({ setoran, saatSelesai }: { setoran: BarisSetoranKonsinyasi; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${AlamatKonsinyasi}/setoran/${setoran.Uuid}/batalkan`, {
            preserveScroll: true,
            onSuccess: saatSelesai,
        });
    };

    return (
        <DialogFormulir
            judul={`Batalkan setoran ${setoran.Nomor}`}
            keterangan="Jurnal setoran dibalik hari ini dan hutang ke penitip kembali bertambah."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan membatalkan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={255}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Batalkan setoran
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function Nilai({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-teks-sekunder">{label}</dt>
            <dd className="text-teks-utama">{children}</dd>
        </div>
    );
}
