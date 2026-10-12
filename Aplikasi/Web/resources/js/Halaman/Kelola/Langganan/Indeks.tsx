import { Link, useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import BagianAddon, { type DataAddonLangganan } from '@/Komponen/Langganan/BagianAddon';
import PanelTurunPaket, { type DataTurunPaket } from '@/Komponen/Langganan/PanelTurunPaket';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card } from '@/Komponen/Ui/card';
import { FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { RadioGroup, RadioGroupItem } from '@/Komponen/Ui/radio-group';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Komponen/Ui/table';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { JenisLabelTagihan, type TagihanLangganan } from '@/Tipe/TagihanLangganan';

type RingkasanLangganan = {
    Status: 'Trial' | 'Aktif' | 'Tertunggak' | 'Ditangguhkan' | 'Berhenti' | 'Gratis';
    LabelStatus: string;
    KodePaket: string;
    NamaPaket: string;
    SiklusTagihan: 'Bulanan' | 'Tahunan';
    TrialBerakhirPada: string | null;
    PeriodeMulai: string | null;
    PeriodeSelesai: string | null;
    BatasTenggangPada: string | null;
};

type PilihanPaket = {
    Kode: string;
    Nama: string;
    Keterangan: string | null;
    HargaBulanan: string;
    HargaTahunan: string;
    PaketBerjalan: boolean;
    /** D-98: pratinjau naik paket (selisih prorata sebelum PPN); null bila bukan naik paket. */
    NaikPaket: { Selisih: string; Subtotal: string; HariDitagih: number; HariPeriode: number } | null;
    BisaDipilih: boolean;
};

type PropsLangganan = {
    Langganan: RingkasanLangganan | null;
    Addon: DataAddonLangganan;
    PilihanPaket: PilihanPaket[];
    Tagihan: TagihanLangganan[];
    HariMasaTenggang: number;
    TurunPaket?: DataTurunPaket;
};

const jenisStatusLangganan = {
    Trial: 'peringatan',
    Aktif: 'sukses',
    Tertunggak: 'bahaya',
    Ditangguhkan: 'bahaya',
    Berhenti: 'netral',
    Gratis: 'netral',
} as const;

/** Langganan & tagihan tenant (P-08/F-19 Fase 0): pilih paket, buat tagihan, bayar lewat transfer manual. */
export default function HalamanLangganan({
    Langganan,
    Addon,
    PilihanPaket,
    Tagihan,
    HariMasaTenggang,
    TurunPaket,
}: PropsLangganan) {
    const tagihanTerbuka = Tagihan.find((tagihan) => tagihan.Status === 'Terbit' || tagihan.Status === 'JatuhTempo');

    return (
        <TataLetakAplikasi judul="Langganan">
            {Langganan === null ? (
                <Pemberitahuan jenis="bahaya" judul="Data langganan tidak ditemukan">
                    Hubungi tim kami agar langganan usaha Anda diperiksa.
                </Pemberitahuan>
            ) : (
                <>
                    <BannerStatus langganan={Langganan} hariMasaTenggang={HariMasaTenggang} />
                    <RingkasanStatus langganan={Langganan} />
                </>
            )}
            {tagihanTerbuka ? (
                <Pemberitahuan jenis="peringatan" judul={`Tagihan ${tagihanTerbuka.Nomor} menunggu pembayaran`}>
                    <p>
                        Total {FormatRupiah(tagihanTerbuka.Total)}, jatuh tempo{' '}
                        {FormatTanggalWaktu(tagihanTerbuka.JatuhTempoPada)}.{' '}
                        <Link
                            href={`/kelola/langganan/tagihan/${tagihanTerbuka.Uuid}`}
                            className="font-semibold text-brand underline"
                        >
                            Bayar tagihan
                        </Link>
                    </p>
                </Pemberitahuan>
            ) : Langganan !== null && Langganan.Status !== 'Berhenti' ? (
                <FormPilihPaket pilihan={PilihanPaket} langganan={Langganan} />
            ) : null}
            {TurunPaket ? <PanelTurunPaket data={TurunPaket} /> : null}
            <BagianAddon addon={Addon} />
            <RiwayatTagihan tagihan={Tagihan} />
        </TataLetakAplikasi>
    );
}

function BannerStatus({ langganan, hariMasaTenggang }: { langganan: RingkasanLangganan; hariMasaTenggang: number }) {
    switch (langganan.Status) {
        case 'Trial':
            return (
                <Pemberitahuan jenis="info" judul="Masa trial">
                    Trial berakhir {FormatTanggalWaktu(langganan.TrialBerakhirPada)}. Setelah itu usaha Anda turun ke
                    paket Gratis. Pilih paket di bawah untuk tetap memakai semua fitur.
                </Pemberitahuan>
            );
        case 'Tertunggak':
            return (
                <Pemberitahuan jenis="peringatan" judul="Langganan tertunggak">
                    Periode langganan sudah berakhir. Semua fitur masih berjalan sampai{' '}
                    {FormatTanggalWaktu(langganan.BatasTenggangPada)} (masa tenggang {hariMasaTenggang} hari). Bayar
                    perpanjangan agar kasir tidak terkunci.
                </Pemberitahuan>
            );
        case 'Ditangguhkan':
            return (
                <Pemberitahuan jenis="bahaya" judul="Langganan ditangguhkan">
                    Kasir terkunci. Anda tetap bisa masuk, melihat laporan, mengekspor data, dan membayar tagihan.
                </Pemberitahuan>
            );
        default:
            return null;
    }
}

function RingkasanStatus({ langganan }: { langganan: RingkasanLangganan }) {
    return (
        <Card aria-labelledby="judul-status" role="region" className="px-4 py-3 rounded-panel shadow-none">
            <h2 id="judul-status" className="sr-only">
                Status langganan
            </h2>
            <dl className="grid gap-x-6 gap-y-2 text-isi sm:grid-cols-4">
                <div>
                    <dt className="text-keterangan text-teks-sekunder">Paket</dt>
                    <dd className="font-semibold text-teks-utama">{langganan.NamaPaket}</dd>
                </div>
                <div>
                    <dt className="text-keterangan text-teks-sekunder">Status</dt>
                    <dd>
                        <LabelStatus jenis={jenisStatusLangganan[langganan.Status]} teks={langganan.LabelStatus} />
                    </dd>
                </div>
                <div>
                    <dt className="text-keterangan text-teks-sekunder">Siklus tagihan</dt>
                    <dd>
                        {langganan.Status === 'Aktif' || langganan.Status === 'Tertunggak'
                            ? langganan.SiklusTagihan
                            : '—'}
                    </dd>
                </div>
                <div>
                    <dt className="text-keterangan text-teks-sekunder">Berlaku sampai</dt>
                    <dd>
                        {langganan.Status === 'Trial'
                            ? FormatTanggalWaktu(langganan.TrialBerakhirPada)
                            : FormatTanggalWaktu(langganan.PeriodeSelesai)}
                    </dd>
                </div>
            </dl>
        </Card>
    );
}

function FormPilihPaket({ pilihan, langganan }: { pilihan: PilihanPaket[]; langganan: RingkasanLangganan }) {
    const idLegenda = useId();
    const bisaDipilih = pilihan.filter((paket) => paket.BisaDipilih);
    // D-23: dialog fitur terkunci menautkan `?paket=KODE` agar paket yang memuat fitur itu langsung terpilih.
    const diminta = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('paket');
    const bawaan =
        bisaDipilih.find((paket) => paket.Kode === diminta) ??
        bisaDipilih.find((paket) => paket.PaketBerjalan) ??
        bisaDipilih.find((paket) => paket.Kode === langganan.KodePaket) ??
        bisaDipilih[0];
    const formulir = useForm<{ KodePaket: string; Siklus: string; KodeKupon: string }>({
        KodePaket: bawaan?.Kode ?? '',
        Siklus: langganan.SiklusTagihan,
        KodeKupon: '',
    });
    const perpanjangan = langganan.Status === 'Aktif' || langganan.Status === 'Tertunggak';
    const naikPaket = pilihan.find((paket) => paket.Kode === formulir.data.KodePaket)?.NaikPaket ?? null;
    const adaNaikPaket = pilihan.some((paket) => paket.NaikPaket !== null);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/kelola/langganan/tagihan', { preserveScroll: true });
    };

    if (pilihan.length === 0) {
        return (
            <Pemberitahuan jenis="info" judul="Belum ada paket yang bisa dibeli">
                Harga paket sedang disiapkan. Coba lagi nanti atau hubungi tim kami.
            </Pemberitahuan>
        );
    }

    return (
        <Card className="p-4 rounded-panel shadow-none">
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <h2 className="text-subjudul font-semibold text-teks-utama">
                    {adaNaikPaket
                        ? 'Perpanjang atau naik paket'
                        : perpanjangan
                          ? 'Perpanjang langganan'
                          : 'Pilih paket berbayar'}
                </h2>
                <FieldSet className="gap-2">
                    <FieldLegend id={idLegenda} className="mb-0 text-label font-semibold text-teks-utama">
                        Paket
                    </FieldLegend>
                    <RadioGroup
                        name="KodePaket"
                        value={formulir.data.KodePaket}
                        onValueChange={(nilai) => formulir.setData('KodePaket', nilai)}
                        aria-labelledby={idLegenda}
                        aria-invalid={formulir.errors.KodePaket ? true : undefined}
                        className="block"
                    >
                        <Table className="text-isi">
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col" className="px-3">
                                        Paket
                                    </TableHead>
                                    <TableHead scope="col" className="px-3 text-right">
                                        Per bulan
                                    </TableHead>
                                    <TableHead scope="col" className="px-3 text-right">
                                        Per tahun
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pilihan.map((paket) => (
                                    <TableRow
                                        key={paket.Kode}
                                        data-state={formulir.data.KodePaket === paket.Kode ? 'selected' : undefined}
                                    >
                                        <TableCell className="px-3 whitespace-normal">
                                            <div className="flex items-start gap-2">
                                                <RadioGroupItem
                                                    id={`${idLegenda}-${paket.Kode}`}
                                                    value={paket.Kode}
                                                    disabled={!paket.BisaDipilih}
                                                    className="mt-1"
                                                />
                                                <label
                                                    htmlFor={`${idLegenda}-${paket.Kode}`}
                                                    className={paket.BisaDipilih ? 'cursor-pointer' : undefined}
                                                >
                                                    <span className="font-semibold text-teks-utama">{paket.Nama}</span>
                                                    {paket.PaketBerjalan ? (
                                                        <span className="block text-keterangan text-teks-sekunder">
                                                            Paket Anda saat ini
                                                        </span>
                                                    ) : null}
                                                    {paket.NaikPaket ? (
                                                        <span className="block text-keterangan text-teks-sekunder">
                                                            Naik paket: bayar selisih{' '}
                                                            {FormatRupiah(paket.NaikPaket.Subtotal)} untuk{' '}
                                                            {paket.NaikPaket.HariDitagih} dari{' '}
                                                            {paket.NaikPaket.HariPeriode} hari periode ini (belum
                                                            termasuk PPN). Paket langsung berganti setelah lunas.
                                                        </span>
                                                    ) : null}
                                                    {!paket.BisaDipilih ? (
                                                        <span className="block text-keterangan text-teks-sekunder">
                                                            Turun paket berlaku mulai periode berikutnya. Hubungi tim
                                                            kami untuk mengaturnya.
                                                        </span>
                                                    ) : null}
                                                </label>
                                            </div>
                                        </TableCell>
                                        <TableCell className="px-3 text-right tabular-nums">
                                            {FormatRupiah(paket.HargaBulanan)}
                                        </TableCell>
                                        <TableCell className="px-3 text-right tabular-nums">
                                            {FormatRupiah(paket.HargaTahunan)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </RadioGroup>
                    {formulir.errors.KodePaket ? (
                        <FieldError className="text-keterangan font-semibold">{formulir.errors.KodePaket}</FieldError>
                    ) : null}
                </FieldSet>
                {naikPaket ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Siklus tagihan tetap {langganan.SiklusTagihan === 'Tahunan' ? 'tahunan' : 'bulanan'} dan masa
                        berlangganan tidak berubah. Perpanjangan berikutnya memakai harga paket baru.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <BidangPilihan
                            label="Siklus tagihan"
                            nilai={formulir.data.Siklus}
                            opsi={[
                                { Nilai: 'Bulanan', Label: 'Bulanan' },
                                { Nilai: 'Tahunan', Label: 'Tahunan (lebih hemat)' },
                            ]}
                            saatBerubah={(nilai) => formulir.setData('Siklus', nilai)}
                            galat={formulir.errors.Siklus}
                            required
                        />
                        <BidangTeks
                            label="Kode kupon (opsional)"
                            kode
                            maxLength={30}
                            nilai={formulir.data.KodeKupon}
                            saatBerubah={(nilai) => formulir.setData('KodeKupon', nilai.toUpperCase())}
                            galat={formulir.errors.KodeKupon}
                        />
                    </div>
                )}
                <p className="text-keterangan text-teks-sekunder">
                    Harga belum termasuk PPN. Rincian PPN dan total tampil di tagihan sebelum Anda membayar.
                </p>
                <div>
                    <Tombol type="submit" memproses={formulir.processing} disabled={formulir.data.KodePaket === ''}>
                        {naikPaket ? 'Buat tagihan naik paket' : 'Buat tagihan'}
                    </Tombol>
                </div>
            </form>
        </Card>
    );
}

const kolomTagihan: KolomTabel<TagihanLangganan>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        enableSorting: false,
        meta: { label: 'Nomor tagihan', prioritas: 'utama', wajib: true, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (
            <Link
                href={`/kelola/langganan/tagihan/${row.original.Uuid}`}
                className="font-mono text-label text-brand underline"
            >
                {row.original.Nomor}
            </Link>
        ),
    },
    {
        id: 'Paket',
        header: 'Paket',
        enableSorting: false,
        meta: { label: 'Paket', prioritas: 'rendah' },
        cell: ({ row }) => `${row.original.NamaPaket} | ${row.original.Siklus}`,
    },
    {
        id: 'JatuhTempoPada',
        accessorKey: 'JatuhTempoPada',
        header: 'Jatuh tempo',
        meta: { label: 'Jatuh tempo', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.JatuhTempoPada),
    },
    {
        id: 'Total',
        header: 'Total',
        enableSorting: false,
        meta: { label: 'Total', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.Total),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={JenisLabelTagihan(row.original.Status)} teks={row.original.LabelStatus} />
        ),
    },
];

function RiwayatTagihan({ tagihan }: { tagihan: TagihanLangganan[] }) {
    return (
        <section aria-labelledby="judul-riwayat" className="flex flex-col gap-2">
            <h2 id="judul-riwayat" className="text-subjudul font-semibold text-teks-utama">
                Riwayat tagihan
            </h2>
            <TabelData
                id="langganan-riwayat-tagihan"
                label="Riwayat tagihan langganan"
                kolom={kolomTagihan}
                sumber={{ mode: 'lokal', data: tagihan }}
                ambilIdBaris={(baris) => baris.Uuid}
                urutBawaan="-JatuhTempoPada"
                alamatDetail={(baris) => `/kelola/langganan/tagihan/${baris.Uuid}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada tagihan.' }}
            />
        </section>
    );
}
