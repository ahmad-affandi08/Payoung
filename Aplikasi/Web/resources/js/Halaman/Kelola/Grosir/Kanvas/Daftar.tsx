import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import {
    AlamatGrosir,
    HalamanGrosir,
    KartuKeteranganGrosir,
    KeteranganGrosir,
} from '@/Komponen/Grosir/BagianDokumenGrosir';
import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
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
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisRekapKanvas, KendaraanKanvas, PropsDaftarKanvas, RekapKanvas, TransferKanvas } from '@/Tipe/Grosir';
import { CekBatasPenuh, FormatBatas } from '@/Tipe/Organisasi';

const alamat = `${AlamatGrosir}/kanvas`;

/** Alamat halaman kanvas untuk satu kendaraan & tanggal (keadaan pilihan disimpan di URL). */
export function BuatAlamatRekap(uuidOutlet: string, tanggal: string): string {
    return `${alamat}?outlet=${encodeURIComponent(uuidOutlet)}&tanggal=${encodeURIComponent(tanggal)}`;
}

const SusunKolomKendaraan = (tanggal: string, terpilih: string | null): KolomTabel<KendaraanKanvas>[] => [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Kendaraan',
        meta: { label: 'Kendaraan', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: k } }) => (
            <Link
                href={BuatAlamatRekap(k.Uuid, tanggal)}
                className="font-semibold break-words text-brand underline"
                aria-current={k.Uuid === terpilih ? 'true' : undefined}
            >
                {k.Nama}
            </Link>
        ),
    },
    {
        id: 'NomorKendaraan',
        accessorFn: (k) => k.NomorKendaraan ?? '',
        header: 'Nomor kendaraan',
        meta: { label: 'Nomor kendaraan', prioritas: 'penting', kelasSel: 'whitespace-nowrap font-mono' },
        cell: ({ row }) =>
            row.original.NomorKendaraan ?? <span className="font-sans text-teks-sekunder">Belum diisi</span>,
    },
    {
        id: 'Kode',
        accessorKey: 'Kode',
        header: 'Kode outlet',
        meta: { label: 'Kode outlet', prioritas: 'rendah', kelasSel: 'font-mono' },
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Status === 'Aktif' ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Diarsipkan" />
            ),
    },
];

function KolomJumlah(
    id: keyof Omit<BarisRekapKanvas, 'UuidProduk' | 'NamaProduk' | 'Sku' | 'Satuan'>,
    label: string,
    prioritas: 'penting' | 'rendah' = 'penting',
): KolomTabel<BarisRekapKanvas> {
    return {
        id,
        accessorKey: id,
        header: label,
        enableSorting: false,
        meta: { label, angka: true, prioritas, kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatJumlahStok(row.original[id], row.original.Satuan),
    };
}

const kolomRekap: KolomTabel<BarisRekapKanvas>[] = [
    {
        id: 'NamaProduk',
        accessorKey: 'NamaProduk',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col">
                <span className="break-words">{p.NamaProduk}</span>
                {p.Sku !== null ? <span className="font-mono text-keterangan text-teks-sekunder">{p.Sku}</span> : null}
            </span>
        ),
    },
    KolomJumlah('Awal', 'Stok awal', 'rendah'),
    KolomJumlah('Muat', 'Muat'),
    KolomJumlah('Terjual', 'Terjual'),
    KolomJumlah('Retur', 'Retur pelanggan'),
    KolomJumlah('Bongkar', 'Bongkar'),
    KolomJumlah('Lain', 'Penyesuaian lain', 'rendah'),
    KolomJumlah('Sisa', 'Sisa akhir'),
];

/** D-95: transfer muat/bongkar kendaraan pada hari itu, dengan jalan ke detail dan cetak surat jalan. */
const kolomTransfer: KolomTabel<TransferKanvas>[] = [
    {
        id: 'Nomor',
        accessorFn: (t) => t.Nomor ?? '(draf)',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true, kelasSel: 'font-mono' },
        cell: ({ row: { original: t } }) => (
            <Link href={`/kelola/persediaan/transfer/${t.Uuid}`} className="font-semibold text-brand hover:underline">
                {t.Nomor ?? '(draf)'}
            </Link>
        ),
    },
    {
        id: 'Arah',
        accessorKey: 'Arah',
        header: 'Arah',
        meta: { label: 'Arah', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="break-words">
                {t.Arah === 'Muat' ? 'Muat dari' : 'Bongkar ke'} {t.NamaLawan}
            </span>
        ),
    },
    {
        id: 'JumlahBaris',
        accessorKey: 'JumlahBaris',
        header: 'Barang',
        meta: { label: 'Barang', prioritas: 'rendah', angka: true },
        cell: ({ row: { original: t } }) => `${String(t.JumlahBaris)} jenis`,
    },
    {
        id: 'Status',
        accessorKey: 'LabelStatus',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
    },
    {
        id: 'Cetak',
        header: 'Dokumen',
        enableSorting: false,
        meta: { label: 'Dokumen', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-wrap gap-x-3 gap-y-1">
                {t.BisaCetak ? (
                    <a
                        href={`/kelola/persediaan/transfer/${t.Uuid}/cetak`}
                        target="_blank"
                        rel="noreferrer"
                        className="font-semibold text-brand hover:underline"
                    >
                        Surat jalan
                    </a>
                ) : null}
                <a
                    href={`/kelola/persediaan/transfer/${t.Uuid}/cetak-ambil`}
                    target="_blank"
                    rel="noreferrer"
                    className="font-semibold text-brand hover:underline"
                >
                    Daftar ambil
                </a>
            </span>
        ),
    },
];

/** Ringkasan uang & setoran hari itu (Rupiah tabular rata kanan; teks selalu menyertai warna). */
export function RingkasanRekapKanvas({ rekap }: { rekap: RekapKanvas }) {
    const { Uang: uang, Setoran: setoran } = rekap;
    const selisihNegatif = setoran.Selisih.startsWith('-');
    const selisihNol = /^-?0+(\.0+)?$/.test(setoran.Selisih);

    return (
        <KartuKeteranganGrosir>
            <KeteranganGrosir label="Penjualan tunai">
                <span className="tabular-nums">{FormatRupiah(uang.PenjualanTunai)}</span>
            </KeteranganGrosir>
            <KeteranganGrosir label="Penjualan tempo (piutang baru)">
                <span className="tabular-nums">{FormatRupiah(uang.PenjualanTempo)}</span>
            </KeteranganGrosir>
            <KeteranganGrosir label="Metode bayar lain">
                <span className="tabular-nums">{FormatRupiah(uang.PenjualanLain)}</span>
            </KeteranganGrosir>
            <KeteranganGrosir label="Transaksi">
                {`${String(uang.JumlahTransaksi)} transaksi | ${String(uang.JumlahVoid)} void | ${String(uang.JumlahRetur)} retur`}
            </KeteranganGrosir>
            <KeteranganGrosir label="Retur pelanggan">
                <span className="tabular-nums">{FormatRupiah(uang.NilaiRetur)}</span>
                {uang.RefundTunai !== '0.00' ? (
                    <span className="text-teks-sekunder">{` (refund tunai ${FormatRupiah(uang.RefundTunai)})`}</span>
                ) : null}
            </KeteranganGrosir>
            <KeteranganGrosir label="Penjualan bersih">
                <span className="tabular-nums font-semibold">{FormatRupiah(uang.Bersih)}</span>
            </KeteranganGrosir>
            <KeteranganGrosir label="Setoran (kas dihitung saat tutup shift)">
                {setoran.JumlahShiftTertutup === 0 ? (
                    <span className="text-teks-sekunder">Belum ada shift yang ditutup</span>
                ) : (
                    <span className="tabular-nums">{FormatRupiah(setoran.KasAktual)}</span>
                )}
                {setoran.JumlahShiftBelumDitutup > 0 ? (
                    <span className="block">
                        <LabelStatus
                            jenis="peringatan"
                            teks={`${String(setoran.JumlahShiftBelumDitutup)} shift belum ditutup`}
                        />
                    </span>
                ) : null}
            </KeteranganGrosir>
            <KeteranganGrosir label="Kas seharusnya">
                <span className="tabular-nums">{FormatRupiah(setoran.KasSeharusnya)}</span>
            </KeteranganGrosir>
            <KeteranganGrosir label="Selisih kas">
                {selisihNol ? (
                    <span className="tabular-nums">{FormatRupiah(setoran.Selisih)}</span>
                ) : (
                    <LabelStatus
                        jenis={selisihNegatif ? 'bahaya' : 'peringatan'}
                        teks={`${selisihNegatif ? 'Kurang' : 'Lebih'} ${FormatRupiah(setoran.Selisih.replace(/^-/, ''))}`}
                    />
                )}
            </KeteranganGrosir>
        </KartuKeteranganGrosir>
    );
}

function DialogTambahKanvas({ saatTutup }: { saatTutup: () => void }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [nomor, AturNomor] = useState('');
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            alamat,
            { NomorKendaraan: nomor },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );
    };

    return (
        <DialogFormulir
            judul="Tambah kendaraan kanvas"
            keterangan="Kendaraan dibuat sebagai outlet baru bernama “Kanvas {nomor kendaraan}” lengkap dengan lokasi stok Toko (bak kendaraan), dan dihitung dalam batas outlet paket."
            galatUmum={props.errors.Umum ?? props.errors.Kode ?? props.errors.Merek}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Tambah kendaraan kanvas">
                <BidangTeks
                    label="Nomor kendaraan"
                    nilai={nomor}
                    saatBerubah={(nilai) => AturNomor(nilai.toUpperCase())}
                    galat={props.errors.NomorKendaraan}
                    keterangan="Plat nomor, misal AD 1234 XY."
                    maxLength={20}
                    kode
                    required
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={memproses} disabled={nomor.trim() === ''}>
                        Tambah kendaraan
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/**
 * Kanvas (Modul Salesman bagian 3, §9.7): salesman menjual langsung dari stok yang dibawa kendaraan. Kendaraan =
 * outlet kanvas; muat & bongkar = transfer stok, jual tunai/tempo = aplikasi kasir di outlet itu, setoran = tutup
 * shift. Halaman ini merekap satu kendaraan per hari bisnis. Baris per hari per kendaraan sedikit (≤ 200), jadi
 * `TabelData` memakai mode lokal.
 */
export default function HalamanDaftarKanvas({
    Kanvas,
    UuidTerpilih,
    Tanggal,
    Rekap,
    Transfer,
    BatasOutlet,
    Izin,
    IzinKanvas,
}: PropsDaftarKanvas) {
    const [dialog, AturDialog] = useState(false);
    const terpilih = Kanvas.find((k) => k.Uuid === UuidTerpilih) ?? null;
    const batasPenuh = CekBatasPenuh(BatasOutlet);

    const GantiTanggal = (tanggal: string) => {
        if (terpilih !== null && tanggal !== '') {
            router.get(BuatAlamatRekap(terpilih.Uuid, tanggal), {}, { preserveScroll: true });
        }
    };

    return (
        <HalamanGrosir
            judul="Kanvas"
            keterangan="Salesman kanvas membawa barang di kendaraan dan menjual langsung, tunai atau tempo. Muat stok pagi dan bongkar sisa sore lewat transfer stok, jual lewat aplikasi kasir di outlet kendaraan, dan setor kas saat tutup shift. Rekap harian di bawah merangkum semuanya per kendaraan."
            izin={Izin}
            objek="kanvas"
        >
            <AksiHalaman
                keterangan={
                    <p className="text-keterangan text-teks-sekunder">
                        Outlet aktif: {FormatBatas(BatasOutlet, 'outlet')}
                    </p>
                }
            >
                {IzinKanvas.TambahKendaraan ? (
                    <Tombol onClick={() => AturDialog(true)} disabled={batasPenuh}>
                        Tambah kendaraan kanvas
                    </Tombol>
                ) : null}
            </AksiHalaman>
            {IzinKanvas.TambahKendaraan && batasPenuh ? (
                <Pemberitahuan jenis="info" judul="Batas outlet paket sudah tercapai">
                    Setiap kendaraan kanvas dihitung sebagai outlet.{' '}
                    <AjakanTambahBatas teksLisensi="Minta berkas lisensi dengan batas outlet lebih besar ke penjual lisensi Payoung">
                        Tingkatkan paket atau tambah add-on outlet di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>
                    </AjakanTambahBatas>
                    .
                </Pemberitahuan>
            ) : null}

            <TabelData
                id="grosir-kanvas-kendaraan"
                label="Daftar kendaraan kanvas"
                kolom={SusunKolomKendaraan(Tanggal, UuidTerpilih)}
                sumber={{ mode: 'lokal', data: Kanvas }}
                ambilIdBaris={(k) => k.Uuid}
                cari="Cari nama atau nomor kendaraan"
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kendaraan kanvas. Tambahkan kendaraan, atau tandai outlet yang sudah ada sebagai outlet kanvas di menu Outlet.',
                }}
            />

            {terpilih !== null && Rekap !== null ? (
                <Panel
                    judul={`Rekap ${terpilih.Nama}`}
                    keterangan={`Hari bisnis ${FormatTanggal(Rekap.Tanggal)}. Barang dari buku stok ${terpilih.NamaGudang ?? 'lokasi Toko'}; uang dari penjualan kasir di outlet ini.`}
                >
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="w-full max-w-xs">
                            <PemilihTanggal
                                label="Tanggal bisnis"
                                nilai={Tanggal}
                                saatBerubah={GantiTanggal}
                                tanpaKosongkan
                            />
                        </div>
                        {IzinKanvas.Transfer ? (
                            <div className="ml-auto flex flex-wrap gap-2">
                                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                                    <Link
                                        href={`/kelola/persediaan/transfer/buat${
                                            terpilih.UuidGudang === null ? '' : `?tujuan=${terpilih.UuidGudang}`
                                        }`}
                                    >
                                        Muat stok
                                    </Link>
                                </Button>
                                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                                    <Link
                                        href={`/kelola/persediaan/transfer/buat${
                                            terpilih.UuidGudang === null ? '' : `?asal=${terpilih.UuidGudang}`
                                        }`}
                                    >
                                        Bongkar stok
                                    </Link>
                                </Button>
                            </div>
                        ) : null}
                    </div>
                    {terpilih.UuidGudang === null ? (
                        <Pemberitahuan jenis="peringatan" judul="Kendaraan belum punya lokasi stok Toko">
                            Tambahkan lokasi stok jenis Toko di detail outlet supaya barang bisa dimuat.
                        </Pemberitahuan>
                    ) : null}
                    <RingkasanRekapKanvas rekap={Rekap} />
                    {Transfer.length > 0 ? (
                        <section aria-label="Transfer muat dan bongkar hari ini" className="flex flex-col gap-2">
                            <h3 className="text-subjudul font-semibold text-teks-utama">Muat & bongkar hari ini</h3>
                            <TabelData
                                id="grosir-kanvas-transfer"
                                label={`Transfer ${terpilih.Nama}`}
                                kolom={kolomTransfer}
                                sumber={{ mode: 'lokal', data: Transfer }}
                                ambilIdBaris={(t) => t.Uuid}
                                kosong={{ ilustrasi: true, judul: 'Belum ada transfer hari ini.' }}
                            />
                        </section>
                    ) : null}
                    <TabelData
                        id="grosir-kanvas-rekap"
                        label={`Rekap barang ${terpilih.Nama}`}
                        kolom={kolomRekap}
                        sumber={{ mode: 'lokal', data: Rekap.Produk }}
                        ambilIdBaris={(p) => p.UuidProduk}
                        cari="Cari nama produk"
                        kosong={{
                            ilustrasi: true,
                            judul: 'Tidak ada barang di kendaraan dan tidak ada mutasi stok pada hari ini.',
                        }}
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        Sisa akhir = stok awal + muat − terjual + retur − bongkar ± penyesuaian lain. Terjual sudah
                        dikurangi void pada hari yang sama.
                    </p>
                </Panel>
            ) : null}

            {dialog ? <DialogTambahKanvas saatTutup={() => AturDialog(false)} /> : null}
        </HalamanGrosir>
    );
}
