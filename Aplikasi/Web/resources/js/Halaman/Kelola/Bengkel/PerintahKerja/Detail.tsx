import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { AlamatPerintahKerja } from '@/Komponen/Bengkel/KolomPerintahKerja';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { KartuKeteranganGrosir, KeteranganGrosir, RingkasanNilaiGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import {
    JenisStatusPerintahKerja,
    LabelAksiStatusBengkel,
    type BarisPerintahKerja,
    type PerintahKerja,
    type PropsDetailPerintahKerja,
} from '@/Tipe/Bengkel';

const kolom: KolomTabel<BarisPerintahKerja>[] = [
    {
        id: 'NamaProduk',
        accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''}`,
        header: 'Pekerjaan / sparepart',
        meta: { label: 'Pekerjaan / sparepart', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col gap-0.5">
                <span className="text-keterangan font-semibold text-teks-sekunder">{b.Jenis}</span>
                <span className="font-semibold break-words">
                    {b.Urutan}. {b.NamaProduk}
                </span>
                {b.Karyawan ? (
                    <span className="text-keterangan text-teks-sekunder">Mekanik {b.Karyawan.Nama}</span>
                ) : null}
                {b.NomorSeri.length > 0 ? (
                    <span className="font-mono text-keterangan break-words text-teks-sekunder">
                        No. seri {b.NomorSeri.join(', ')}
                    </span>
                ) : null}
                {b.StokTersedia !== null ? (
                    <span className="text-keterangan text-teks-sekunder">
                        Stok tersedia {FormatJumlahStok(b.StokTersedia, b.SimbolSatuan)}
                    </span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Jumlah',
        header: 'Jumlah',
        enableSorting: false,
        meta: { label: 'Jumlah', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: b } }) => FormatJumlahStok(b.Jumlah, b.SimbolSatuan),
    },
    {
        id: 'HargaSatuan',
        header: 'Harga',
        enableSorting: false,
        meta: { label: 'Harga per satuan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.HargaSatuan),
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
        id: 'Disetujui',
        header: 'Persetujuan',
        enableSorting: false,
        meta: { label: 'Persetujuan', prioritas: 'penting' },
        cell: ({ row: { original: b } }) =>
            b.Disetujui ? <LabelStatus jenis="sukses" teks="Disetujui" /> : <LabelStatus jenis="netral" teks="Belum" />,
    },
];

/** Tautan persetujuan yang bisa disalin + tombol kirim ulang lewat WhatsApp. */
function PanelPersetujuan({
    pk,
    bolehMinta,
    bolehCatat,
    saatCatat,
}: {
    pk: PerintahKerja;
    bolehMinta: boolean;
    bolehCatat: boolean;
    saatCatat: () => void;
}) {
    const [tersalin, AturTersalin] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const Minta = (kirimWhatsapp: boolean) =>
        router.post(
            `${AlamatPerintahKerja}/${pk.Uuid}/persetujuan`,
            { KirimWhatsapp: kirimWhatsapp },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    const p = pk.Persetujuan;

    return (
        <Panel
            judul="Persetujuan pelanggan"
            keterangan="Pelanggan membuka tautan, melihat rincian estimasi, lalu menyetujui sebagian atau semua pekerjaan. Tautan berlaku 7 hari."
        >
            <div className="flex flex-col gap-3">
                {p.DiputuskanPada ? (
                    <p className="text-isi text-teks-utama">
                        Diputuskan {FormatTanggalWaktu(p.DiputuskanPada)}{' '}
                        {p.DiputuskanLewat === 'Tautan' ? 'oleh pelanggan lewat tautan' : 'dicatat staf'}
                        {p.CatatanPelanggan ? ` — "${p.CatatanPelanggan}"` : ''}
                    </p>
                ) : null}
                {p.Tautan ? (
                    <>
                        <code className="rounded-kontrol bg-latar p-2 font-mono text-keterangan break-all">
                            {p.Tautan}
                        </code>
                        <p className="text-keterangan text-teks-sekunder">
                            Berlaku sampai {FormatTanggalWaktu(p.KedaluwarsaPada)} |{' '}
                            {p.DikirimPada
                                ? `terkirim lewat WhatsApp ${FormatTanggalWaktu(p.DikirimPada)}`
                                : 'belum terkirim lewat WhatsApp'}
                        </p>
                    </>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {p.Tautan ? (
                        <Tombol
                            varian="sekunder"
                            onClick={() => {
                                void navigator.clipboard.writeText(p.Tautan ?? '').then(() => AturTersalin(true));
                            }}
                        >
                            {tersalin ? 'Tautan tersalin' : 'Salin tautan'}
                        </Tombol>
                    ) : null}
                    {bolehMinta ? (
                        <>
                            <Tombol memproses={memproses} onClick={() => Minta(true)}>
                                {p.Tautan ? 'Kirim ulang lewat WhatsApp' : 'Minta persetujuan lewat WhatsApp'}
                            </Tombol>
                            <Tombol varian="sekunder" memproses={memproses} onClick={() => Minta(false)}>
                                {p.Tautan ? 'Buat tautan baru' : 'Buat tautan saja'}
                            </Tombol>
                        </>
                    ) : null}
                    {bolehCatat ? (
                        <Tombol varian="sekunder" onClick={saatCatat}>
                            Catat persetujuan langsung
                        </Tombol>
                    ) : null}
                </div>
            </div>
        </Panel>
    );
}

/** Pelanggan memutuskan di tempat/telepon: staf memilih baris yang disetujui, atau mencatat penolakan. */
function DialogCatatPersetujuan({ pk, saatTutup }: { pk: PerintahKerja; saatTutup: () => void }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [dipilih, AturDipilih] = useState<string[]>(pk.Baris.map((b) => b.Uuid));
    const [catatan, AturCatatan] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const Kirim = (setuju: boolean) =>
        router.post(
            `${AlamatPerintahKerja}/${pk.Uuid}/persetujuan/catat`,
            { Setuju: setuju, Baris: setuju ? dipilih : [], Catatan: catatan === '' ? null : catatan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );

    return (
        <DialogFormulir
            judul={`Catat persetujuan ${pk.Nomor}`}
            keterangan="Centang pekerjaan & sparepart yang disetujui pelanggan. Yang tidak dicentang tidak ikut ditagih."
            galatUmum={props.errors.Umum ?? props.errors.Baris}
            saatTutup={saatTutup}
        >
            <form
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    Kirim(true);
                }}
                noValidate
                className="flex flex-col gap-3"
                aria-label="Catat persetujuan"
            >
                {pk.Baris.map((b) => (
                    <KotakCentang
                        key={b.Uuid}
                        label={`${b.NamaProduk} — ${FormatRupiah(b.Subtotal)}`}
                        nilai={dipilih.includes(b.Uuid)}
                        saatBerubah={(nilai) =>
                            AturDipilih((lama) => (nilai ? [...lama, b.Uuid] : lama.filter((u) => u !== b.Uuid)))
                        }
                    />
                ))}
                <BidangTeksPanjang label="Catatan pelanggan" nilai={catatan} saatBerubah={AturCatatan} maksimal={255} />
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" memproses={memproses} disabled={dipilih.length === 0}>
                        Simpan persetujuan
                    </Tombol>
                    <Tombol type="button" varian="bahaya" memproses={memproses} onClick={() => Kirim(false)}>
                        Catat ditolak
                    </Tombol>
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/** Ubah status yang butuh isian: Dibatalkan (alasan wajib) atau Qc/Selesai (catatan QC opsional). */
function DialogStatus({
    pk,
    status,
    label,
    saatTutup,
}: {
    pk: PerintahKerja;
    status: string;
    label: string;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const batal = status === 'Dibatalkan';
    const [teks, AturTeks] = useState(batal ? '' : (pk.CatatanQc ?? ''));
    const [memproses, AturMemproses] = useState(false);

    return (
        <DialogFormulir
            judul={`${label} ${pk.Nomor}`}
            galatUmum={props.errors.Umum ?? props.errors.Status}
            saatTutup={saatTutup}
        >
            <form
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    router.post(
                        `${AlamatPerintahKerja}/${pk.Uuid}/status`,
                        batal
                            ? { Status: status, Alasan: teks }
                            : { Status: status, CatatanQc: teks === '' ? null : teks },
                        {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: () => AturMemproses(false),
                            onSuccess: saatTutup,
                        },
                    );
                }}
                noValidate
                className="flex flex-col gap-4"
                aria-label={label}
            >
                <BidangTeksPanjang
                    label={batal ? 'Alasan pembatalan' : 'Catatan pemeriksaan akhir (QC)'}
                    nilai={teks}
                    saatBerubah={AturTeks}
                    galat={batal ? props.errors.Alasan : props.errors.CatatanQc}
                    maksimal={batal ? 255 : 500}
                    required={batal}
                />
                <div className="flex flex-wrap gap-2">
                    <Tombol
                        type="submit"
                        varian={batal ? 'bahaya' : 'utama'}
                        memproses={memproses}
                        disabled={batal && teks.trim().length < 5}
                    >
                        {label}
                    </Tombol>
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/** Jadwal servis berkala (tanggal dan/atau KM); pengingat WhatsApp dikirim H-3. */
function PanelServisBerikutnya({ pk, hariIni }: { pk: PerintahKerja; hariIni: string }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [tanggal, AturTanggal] = useState(pk.ServisBerikutnyaPada ?? '');
    const [km, AturKm] = useState(pk.ServisBerikutnyaKm === null ? '' : String(pk.ServisBerikutnyaKm));
    const [memproses, AturMemproses] = useState(false);

    return (
        <Panel
            judul="Servis berikutnya"
            keterangan="Pelanggan diingatkan lewat WhatsApp 3 hari sebelum tanggalnya, dan muncul di Kotak Tindakan saat jatuh tempo."
        >
            <form
                onSubmit={(e: FormEvent) => {
                    e.preventDefault();
                    router.put(
                        `${AlamatPerintahKerja}/${pk.Uuid}/servis-berikutnya`,
                        {
                            ServisBerikutnyaPada: tanggal === '' ? null : tanggal,
                            ServisBerikutnyaKm: km === '' ? null : Number(km),
                        },
                        {
                            preserveScroll: true,
                            onStart: () => AturMemproses(true),
                            onFinish: () => AturMemproses(false),
                        },
                    );
                }}
                noValidate
                className="flex flex-col gap-3"
                aria-label="Servis berikutnya"
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <PemilihTanggal
                        label="Tanggal servis berikutnya"
                        nilai={tanggal}
                        saatBerubah={AturTanggal}
                        galat={props.errors.ServisBerikutnyaPada}
                        min={hariIni}
                    />
                    <BidangJumlah
                        label="Atau saat KM mencapai"
                        nilai={km}
                        saatBerubah={AturKm}
                        galat={props.errors.ServisBerikutnyaKm}
                        desimal={0}
                        digitBulat={7}
                        akhiran="km"
                    />
                </div>
                {pk.PengingatServisTerkirimPada ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Pengingat terkirim {FormatTanggalWaktu(pk.PengingatServisTerkirimPada)}.
                    </p>
                ) : null}
                <div>
                    <Tombol type="submit" varian="sekunder" memproses={memproses}>
                        Simpan jadwal servis
                    </Tombol>
                </div>
            </form>
        </Panel>
    );
}

/**
 * Detail perintah kerja bengkel (§9.10): estimasi berharga server, persetujuan pelanggan (tautan WhatsApp atau dicatat
 * langsung), tombol status pengerjaan, penjualan yang menagihnya, servis berikutnya, dan riwayat status.
 */
export default function HalamanDetailPerintahKerja({
    PerintahKerja: pk,
    Riwayat,
    Tindakan,
    HariIni,
    LihatPenjualan,
}: PropsDetailPerintahKerja) {
    const [dialog, AturDialog] = useState<{ status: string; label: string } | 'persetujuan' | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const UbahStatus = (status: string) =>
        router.post(
            `${AlamatPerintahKerja}/${pk.Uuid}/status`,
            { Status: status },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <TataLetakAplikasi
            judul={`Perintah kerja ${pk.Nomor}`}
            jejak={[{ label: 'Perintah kerja', href: AlamatPerintahKerja }]}
        >
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatus jenis={JenisStatusPerintahKerja(pk.Status)} teks={pk.LabelStatus} />
            </div>

            {pk.AlasanBatal !== null ? (
                <Pemberitahuan jenis="bahaya" judul="Perintah kerja dibatalkan">
                    {pk.AlasanBatal}
                </Pemberitahuan>
            ) : null}
            {pk.Status === 'Disetujui' ||
            pk.Status === 'Dikerjakan' ||
            pk.Status === 'Qc' ||
            pk.Status === 'Selesai' ? (
                <Pemberitahuan jenis="info" judul="Siap ditagih di kasir">
                    Buka menu perintah kerja di aplikasi kasir untuk memuat {FormatRupiah(pk.TotalDisetujui)} pekerjaan
                    yang disetujui ke keranjang. Stok sparepart berkurang saat penjualannya tersimpan.
                </Pemberitahuan>
            ) : null}

            <AksiHalaman>
                {Tindakan.Ubah ? (
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPerintahKerja}/${pk.Uuid}/ubah`}>Ubah estimasi</Link>
                    </Button>
                ) : null}
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <a href={`${AlamatPerintahKerja}/${pk.Uuid}/cetak`} target="_blank" rel="noreferrer">
                        Cetak
                    </a>
                </Button>
                {Tindakan.TujuanStatus.map((s) =>
                    s.Nilai === 'Dibatalkan' || s.Nilai === 'Selesai' ? (
                        <Tombol
                            key={s.Nilai}
                            varian={s.Nilai === 'Dibatalkan' ? 'bahaya' : 'utama'}
                            onClick={() =>
                                AturDialog({ status: s.Nilai, label: LabelAksiStatusBengkel[s.Nilai] ?? s.Label })
                            }
                        >
                            {LabelAksiStatusBengkel[s.Nilai] ?? s.Label}
                        </Tombol>
                    ) : (
                        <Tombol
                            key={s.Nilai}
                            varian={s.Nilai === 'Diagnosis' ? 'sekunder' : 'utama'}
                            memproses={memproses}
                            onClick={() => UbahStatus(s.Nilai)}
                        >
                            {LabelAksiStatusBengkel[s.Nilai] ?? s.Label}
                        </Tombol>
                    ),
                )}
            </AksiHalaman>

            <KartuKeteranganGrosir>
                <KeteranganGrosir label="Pelanggan">
                    {pk.Pelanggan.Nama}
                    {pk.Pelanggan.NoHp ? (
                        <span className="block text-teks-sekunder tabular-nums">{pk.Pelanggan.NoHp}</span>
                    ) : null}
                </KeteranganGrosir>
                <KeteranganGrosir label="Kendaraan">
                    {pk.Kendaraan ? (
                        <Link
                            href={`/kelola/bengkel/kendaraan/${pk.Kendaraan.Uuid}`}
                            className="font-mono font-semibold text-brand underline"
                        >
                            {pk.Kendaraan.NomorPolisi}
                        </Link>
                    ) : (
                        '-'
                    )}
                    {pk.Kendaraan ? <span className="block text-teks-sekunder">{pk.Kendaraan.Label}</span> : null}
                </KeteranganGrosir>
                <KeteranganGrosir label="KM masuk">
                    {pk.KmMasuk === null ? '-' : `${pk.KmMasuk.toLocaleString('id-ID')} km`}
                </KeteranganGrosir>
                <KeteranganGrosir label="Outlet">{pk.Outlet.Nama}</KeteranganGrosir>
                <KeteranganGrosir label="Masuk">{FormatTanggalWaktu(pk.DibuatPada)}</KeteranganGrosir>
                <KeteranganGrosir label="Perkiraan selesai">
                    {FormatTanggalWaktu(pk.EstimasiSelesaiPada)}
                </KeteranganGrosir>
                <KeteranganGrosir label="Keluhan">
                    <span className="whitespace-pre-line">{pk.Keluhan}</span>
                </KeteranganGrosir>
                <KeteranganGrosir label="Diagnosis">
                    <span className="whitespace-pre-line">{pk.Diagnosis ?? '-'}</span>
                </KeteranganGrosir>
                {pk.CatatanQc ? <KeteranganGrosir label="Catatan QC">{pk.CatatanQc}</KeteranganGrosir> : null}
                {pk.Penjualan ? (
                    <KeteranganGrosir label="Ditagih lewat">
                        {LihatPenjualan ? (
                            <Link
                                href={`/kelola/penjualan/${pk.Penjualan.Uuid}`}
                                className="font-mono font-semibold text-brand underline"
                            >
                                {pk.Penjualan.Nomor}
                            </Link>
                        ) : (
                            <span className="font-mono">{pk.Penjualan.Nomor}</span>
                        )}{' '}
                        | {FormatRupiah(pk.Penjualan.TotalAkhir)}
                    </KeteranganGrosir>
                ) : null}
            </KartuKeteranganGrosir>

            <Panel
                judul="Estimasi jasa & sparepart"
                keterangan="Harga dari daftar harga saat disimpan. Total adalah perkiraan; kasir bisa menerapkan promo atau pembulatan saat menagih."
            >
                <TabelData
                    id="bengkel-perintah-kerja-baris"
                    label={`Estimasi ${pk.Nomor}`}
                    kolom={kolom}
                    sumber={{ mode: 'lokal', data: pk.Baris }}
                    ambilIdBaris={(b) => b.Uuid}
                    kosong={{ judul: 'Estimasi belum berisi jasa atau sparepart.' }}
                />
                <RingkasanNilaiGrosir
                    baris={[
                        { label: 'Subtotal', nilai: pk.Subtotal },
                        { label: 'Diskon', nilai: pk.Diskon },
                        { label: 'Pajak', nilai: pk.Pajak },
                        { label: 'Perkiraan total', nilai: pk.Total, tebal: true },
                        ...(pk.TotalDisetujui !== '0.00'
                            ? [{ label: 'Total disetujui pelanggan', nilai: pk.TotalDisetujui, tebal: true }]
                            : []),
                    ]}
                />
            </Panel>

            {Tindakan.MintaPersetujuan || Tindakan.CatatPersetujuan || pk.Persetujuan.DiputuskanPada ? (
                <PanelPersetujuan
                    pk={pk}
                    bolehMinta={Tindakan.MintaPersetujuan && pk.Baris.length > 0}
                    bolehCatat={Tindakan.CatatPersetujuan && pk.Baris.length > 0}
                    saatCatat={() => AturDialog('persetujuan')}
                />
            ) : null}

            {Tindakan.AturServis ? <PanelServisBerikutnya pk={pk} hariIni={HariIni} /> : null}

            {Riwayat.length > 0 ? (
                <section aria-label="Riwayat status" className="flex flex-col gap-2">
                    <h2 className="text-subjudul font-semibold text-teks-utama">Riwayat status</h2>
                    <ol className="flex flex-col gap-1 text-isi">
                        {Riwayat.map((r, i) => (
                            <li key={`${r.Ke}-${String(i)}`} className="break-words text-teks-sekunder">
                                <span className="font-semibold text-teks-utama">{r.Label}</span> |{' '}
                                {FormatTanggalWaktu(r.Pada)}
                                {r.Alasan ? ` — ${r.Alasan}` : ''}
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}

            {dialog === 'persetujuan' ? <DialogCatatPersetujuan pk={pk} saatTutup={() => AturDialog(null)} /> : null}
            {dialog !== null && dialog !== 'persetujuan' ? (
                <DialogStatus pk={pk} status={dialog.status} label={dialog.label} saatTutup={() => AturDialog(null)} />
            ) : null}
            {pk.ServisBerikutnyaPada ? (
                <p className="text-keterangan text-teks-sekunder">
                    Servis berikutnya {FormatTanggal(pk.ServisBerikutnyaPada)}.
                </p>
            ) : null}
        </TataLetakAplikasi>
    );
}
