import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { TautanEkspor } from '@/Komponen/Laporan/NavigasiTab';
import TabKartuStok from '@/Komponen/Persediaan/TabKartuStok';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { Input } from '@/Komponen/Ui/input';
import { Label } from '@/Komponen/Ui/label';
import { AmbilJenisLabelStatusNomorSeri } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisRiwayatNomorSeri, PropsNomorSeri, UnitNomorSeri } from '@/Tipe/Persediaan';

const alamat = '/kelola/persediaan/kartu-stok/nomor-seri';

/** Query halaman dari saringan yang terisi saja (URL bersih, bisa dibagikan). */
export function BuatQueryNomorSeri(saring: {
    Cari?: string;
    Status?: string;
    Produk?: string;
    Unit?: string;
}): Record<string, string> {
    const query: Record<string, string> = {};

    if (saring.Cari) {
        query.cari = saring.Cari;
    }

    if (saring.Status) {
        query.status = saring.Status;
    }

    if (saring.Produk) {
        query.produk = saring.Produk;
    }

    if (saring.Unit) {
        query.unit = saring.Unit;
    }

    return query;
}

/** "Terjual 7 Oktober 2026 | INV/…" untuk unit terjual, selain itu lokasi stok. */
function KeteranganUnit(u: UnitNomorSeri): string {
    if (u.Status === 'Terjual' && u.NomorPenjualan) {
        return `Terjual ${u.TanggalJual ? FormatTanggal(u.TanggalJual) : ''} | ${u.NomorPenjualan}`.replace('  ', ' ');
    }

    return u.NamaGudang ?? '—';
}

function LabelStatusUnit({ unit }: { unit: UnitNomorSeri }) {
    return <LabelStatus jenis={AmbilJenisLabelStatusNomorSeri(unit.Status)} teks={unit.LabelStatus} />;
}

function LabelGaransi({ unit }: { unit: UnitNomorSeri }) {
    if (unit.GaransiSampai === null || unit.StatusGaransi === null) {
        return <span className="text-teks-sekunder">—</span>;
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span>{FormatTanggal(unit.GaransiSampai)}</span>
            <LabelStatus
                jenis={unit.StatusGaransi === 'Aktif' ? 'sukses' : 'bahaya'}
                teks={unit.StatusGaransi === 'Aktif' ? 'Masih berlaku' : 'Sudah berakhir'}
            />
        </span>
    );
}

function BuatKolomUnit(): KolomTabel<UnitNomorSeri>[] {
    return [
        {
            id: 'Nomor',
            accessorKey: 'Nomor',
            header: 'Nomor seri',
            meta: { label: 'Nomor seri', prioritas: 'utama', wajib: true },
            cell: ({ row }) => <span className="font-mono">{row.original.Nomor}</span>,
        },
        {
            id: 'NamaProduk',
            accessorKey: 'NamaProduk',
            header: 'Produk',
            meta: { label: 'Produk', prioritas: 'utama' },
        },
        {
            id: 'LabelStatus',
            accessorKey: 'LabelStatus',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => <LabelStatusUnit unit={row.original} />,
        },
        {
            id: 'Keterangan',
            header: 'Keterangan',
            enableSorting: false,
            meta: { label: 'Keterangan', prioritas: 'penting' },
            cell: ({ row }) => KeteranganUnit(row.original),
        },
        {
            id: 'GaransiSampai',
            accessorKey: 'GaransiSampai',
            header: 'Garansi sampai',
            meta: { label: 'Garansi sampai', prioritas: 'rendah' },
            cell: ({ row }) => <LabelGaransi unit={row.original} />,
        },
    ];
}

const kolomUnit = BuatKolomUnit();

const kolomRiwayat: KolomTabel<BarisRiwayatNomorSeri>[] = [
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        enableSorting: false,
        meta: { label: 'Tanggal', prioritas: 'utama', wajib: true },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Peristiwa',
        enableSorting: false,
        meta: { label: 'Peristiwa', prioritas: 'utama' },
        cell: ({ row }) => `${row.original.Jenis} (${row.original.Arah === 'Masuk' ? 'masuk stok' : 'keluar stok'})`,
    },
    {
        id: 'NomorDokumen',
        accessorKey: 'NomorDokumen',
        header: 'Dokumen',
        enableSorting: false,
        meta: { label: 'Dokumen', prioritas: 'penting' },
        cell: ({ row }) => <span className="font-mono">{row.original.NomorDokumen ?? '—'}</span>,
    },
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        enableSorting: false,
        meta: { label: 'Lokasi stok', prioritas: 'rendah' },
        cell: ({ row }) => row.original.NamaGudang ?? '—',
    },
];

function Butir({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-label text-teks-sekunder">{judul}</dt>
            <dd className="text-teks-utama wrap-anywhere">{children}</dd>
        </div>
    );
}

const tautanKelas = 'font-semibold text-brand underline';

/**
 * F-05h (PRD v3.13): cari nomor seri/IMEI atau produk berseri, saring status, lalu lihat satu unit: produk, lokasi atau
 * penjualan & pembelinya, garansi, dan riwayatnya dari masuk ke stok sampai terjual atau diretur. Dibaca dari buku stok.
 * Satu hasil langsung dibuka (alur pindai IMEI → Enter). Rumah menunya tab di "Kartu stok" (D-27).
 */
export default function HalamanNomorSeri({
    Saring,
    Produk,
    Hasil,
    TotalHasil,
    Detail,
    BatasHasil,
    OpsiStatus,
    Izin,
}: PropsNomorSeri) {
    const [cari, AturCari] = useState(Saring.Cari);
    const [status, AturStatus] = useState(Saring.Status);
    const petaDetail = useRef<HTMLDivElement>(null);
    const uuidDetail = Detail?.Unit.Uuid ?? '';
    const adaSaring = Saring.Cari !== '' || Saring.Produk !== '';

    // Saat satu unit dibuka (klik baris atau hasil tunggal), bawa pandangan ke riwayatnya: di layar sempit ia di bawah tabel.
    useEffect(() => {
        if (uuidDetail !== '') {
            petaDetail.current?.scrollIntoView?.({ block: 'start' });
        }
    }, [uuidDetail]);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.get(alamat, BuatQueryNomorSeri({ Cari: cari.trim(), Status: status, Produk: Saring.Produk }), {
            preserveScroll: true,
        });
    };

    const queryEkspor = BuatQueryNomorSeri({ Cari: Saring.Cari, Status: Saring.Status, Produk: Saring.Produk });
    const unit = Detail?.Unit;
    const queryKartuStok = unit
        ? new URLSearchParams({
              produk: unit.UuidProduk,
              ...(unit.UuidGudang ? { gudang: unit.UuidGudang } : {}),
          }).toString()
        : '';

    return (
        <TataLetakAplikasi judul="Kartu stok">
            <TabKartuStok aktif="nomor-seri" uuidProduk={Saring.Produk || null} />

            <form
                onSubmit={Kirim}
                className="flex flex-wrap items-end gap-2"
                role="search"
                aria-label="Cari nomor seri"
            >
                <div className="flex min-w-64 flex-1 flex-col gap-1">
                    <Label htmlFor="cari-nomor-seri" className="text-label font-semibold text-teks-utama">
                        Nomor seri / IMEI atau nama produk
                    </Label>
                    <div className="flex items-center gap-2">
                        <Input
                            id="cari-nomor-seri"
                            value={cari}
                            onChange={(e) => AturCari(e.target.value)}
                            placeholder="Pindai atau ketik sebagian nomor, atau nama produk"
                            autoFocus
                            autoComplete="off"
                            className="min-w-0 flex-1"
                        />
                        <TombolPindaiKamera
                            ikonSaja
                            jenisKode="Seri"
                            label="Pindai nomor seri atau IMEI dengan kamera"
                            saatTerbaca={(kode) => {
                                AturCari(kode);
                                router.get(
                                    alamat,
                                    BuatQueryNomorSeri({ Cari: kode, Status: status, Produk: Saring.Produk }),
                                    {
                                        preserveScroll: true,
                                    },
                                );
                            }}
                        />
                    </div>
                </div>
                <div className="w-full sm:w-52">
                    <BidangPilihan
                        label="Status"
                        nilai={status}
                        kosong="Semua status"
                        opsi={[
                            { Nilai: '', Label: 'Semua status' },
                            ...OpsiStatus.map((o) => ({ Nilai: o.Nilai, Label: o.Label })),
                        ]}
                        saatBerubah={AturStatus}
                    />
                </div>
                <Tombol type="submit">Cari</Tombol>
            </form>

            {Produk ? (
                <p className="flex flex-wrap items-center gap-2 text-label text-teks-sekunder">
                    Hanya unit produk{' '}
                    <span className="font-semibold text-teks-utama">
                        {Produk.Nama}
                        {Produk.Sku ? ` (${Produk.Sku})` : ''}
                    </span>
                    .
                    <Link
                        href={alamat}
                        data={BuatQueryNomorSeri({ Cari: Saring.Cari, Status: Saring.Status })}
                        className={tautanKelas}
                    >
                        Tampilkan semua produk
                    </Link>
                </p>
            ) : null}

            {!adaSaring ? (
                <p className="text-label text-teks-sekunder">
                    Masukkan nomor seri atau IMEI untuk melihat status dan riwayatnya: kapan masuk, kapan dijual, kepada
                    siapa, sampai kapan garansinya, dan apakah pernah diretur. Nama produk menampilkan semua unitnya;
                    sempitkan dengan status, misalnya hanya yang tersedia.
                </p>
            ) : (
                <section aria-labelledby="judul-hasil-seri" className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id="judul-hasil-seri" className="text-subjudul font-semibold text-teks-utama">
                            Hasil pencarian ({String(TotalHasil)})
                        </h2>
                        {TotalHasil > 0 ? (
                            <span className="ml-auto">
                                <TautanEkspor alamat={`${alamat}/ekspor`} query={queryEkspor} />
                            </span>
                        ) : null}
                    </div>
                    {TotalHasil > BatasHasil ? (
                        <p className="text-label text-teks-sekunder">
                            Menampilkan {String(BatasHasil)} dari {String(TotalHasil)} nomor. Persempit pencarian atau
                            filter status untuk hasil lain. Ekspor CSV memuat lebih banyak.
                        </p>
                    ) : null}
                    <TabelData
                        id="hasil-nomor-seri"
                        label="Hasil pencarian nomor seri"
                        kolom={kolomUnit}
                        sumber={{ mode: 'lokal', data: Hasil }}
                        ambilIdBaris={(u) => u.Uuid}
                        cari={false}
                        alamatDetail={(u) =>
                            `${alamat}?${new URLSearchParams(BuatQueryNomorSeri({ ...Saring, Unit: u.Uuid })).toString()}`
                        }
                        kosong={{ ilustrasi: true, judul: 'Nomor seri tidak ditemukan.' }}
                    />
                </section>
            )}

            {Detail && unit ? (
                <div ref={petaDetail} className="scroll-mt-20">
                    <Panel
                        judul={`Riwayat ${unit.Nomor}`}
                        aksi={<LabelStatusUnit unit={unit} />}
                        keterangan={unit.Sku ? `${unit.NamaProduk} | ${unit.Sku}` : unit.NamaProduk}
                    >
                        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Butir judul="Produk">
                                {Izin.Produk ? (
                                    <Link href={`/kelola/produk/${unit.UuidProduk}`} className={tautanKelas}>
                                        {unit.NamaProduk}
                                    </Link>
                                ) : (
                                    unit.NamaProduk
                                )}
                            </Butir>
                            <Butir judul={unit.Status === 'Terjual' ? 'Terjual' : 'Lokasi stok'}>
                                {unit.Status === 'Terjual' && unit.NomorPenjualan ? (
                                    <>
                                        {unit.TanggalJual ? `${FormatTanggal(unit.TanggalJual)} | ` : ''}
                                        {Izin.Penjualan && unit.UuidPenjualan ? (
                                            <Link
                                                href={`/kelola/penjualan/${unit.UuidPenjualan}`}
                                                className={`${tautanKelas} font-mono`}
                                            >
                                                {unit.NomorPenjualan}
                                            </Link>
                                        ) : (
                                            <span className="font-mono">{unit.NomorPenjualan}</span>
                                        )}
                                    </>
                                ) : (
                                    (unit.NamaGudang ?? '—')
                                )}
                            </Butir>
                            {unit.Status === 'Terjual' ? (
                                <Butir judul="Pembeli">
                                    {unit.NamaPelanggan ? (
                                        Izin.Pelanggan && unit.UuidPelanggan ? (
                                            <Link
                                                href={`/kelola/pelanggan/${unit.UuidPelanggan}`}
                                                className={tautanKelas}
                                            >
                                                {unit.NamaPelanggan}
                                            </Link>
                                        ) : (
                                            unit.NamaPelanggan
                                        )
                                    ) : (
                                        <span className="text-teks-sekunder">Tanpa data pelanggan</span>
                                    )}
                                </Butir>
                            ) : null}
                            {unit.Status === 'Terjual' && unit.MasaGaransiBulan !== null ? (
                                <Butir judul={`Garansi ${String(unit.MasaGaransiBulan)} bulan`}>
                                    <LabelGaransi unit={unit} />
                                </Butir>
                            ) : null}
                        </dl>
                        <TabelData
                            id="riwayat-nomor-seri"
                            label="Riwayat nomor seri"
                            kolom={kolomRiwayat}
                            sumber={{ mode: 'lokal', data: Detail.Riwayat }}
                            ambilIdBaris={(b) => `${b.Tanggal}-${b.Jenis}-${b.NomorDokumen ?? ''}-${b.Arah}`}
                            cari={false}
                            kosong={{ ilustrasi: true, judul: 'Belum ada riwayat.' }}
                        />
                        <p className="text-label text-teks-sekunder">
                            <Link href={`/kelola/persediaan/kartu-stok?${queryKartuStok}`} className={tautanKelas}>
                                Lihat kartu stok produk ini
                            </Link>
                        </p>
                    </Panel>
                </div>
            ) : null}
        </TataLetakAplikasi>
    );
}
