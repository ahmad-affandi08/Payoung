import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import LangkahImpor, { JenisLabelImpor } from '@/Komponen/Katalog/LangkahImpor';
import Panel from '@/Komponen/Kelola/Panel';
import TabelBarisGalatImpor from '@/Komponen/Katalog/TabelBarisGalatImpor';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import KemajuanImporStokAwal, { StatusBerjalanStokAwal } from '@/Komponen/Persediaan/Impor/KemajuanImporStokAwal';
import PemetaanImporStokAwal from '@/Komponen/Persediaan/Impor/PemetaanImporStokAwal';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDetailImporStokAwal, RingkasanImporStokAwal, StatusStokAwal } from '@/Tipe/Persediaan';

const alamatImpor = '/kelola/persediaan/stok-awal/impor';

/** Tautan laporan impor stok awal (xlsx/csv): `galat` hanya baris bermasalah, `semua` seluruh baris. */
export function BuatUrlLaporanImporStokAwal(
    uuid: string,
    jenis: 'galat' | 'semua',
    format: 'xlsx' | 'csv' = 'xlsx',
): string {
    return `${alamatImpor}/${uuid}/laporan?jenis=${jenis}&format=${format}`;
}

const labelStatusDraf: Record<StatusStokAwal, string> = {
    Draf: 'Draf',
    Memproses: 'Sedang dicatat',
    Diposting: 'Tercatat',
    Dibatalkan: 'Dibatalkan',
    Dibuang: 'Dibuang',
};

function JenisLabelDraf(status: StatusStokAwal): 'sukses' | 'peringatan' | 'bahaya' | 'netral' {
    if (status === 'Diposting') {
        return 'sukses';
    }

    if (status === 'Draf') {
        return 'peringatan';
    }

    return status === 'Dibatalkan' ? 'bahaya' : 'netral';
}

type RingkasanDraf = NonNullable<PropsDetailImporStokAwal['Pratinjau']>['RingkasanDokumen'][number];
type DokumenImpor = PropsDetailImporStokAwal['Dokumen'][number];

const kolomRingkasan: KolomTabel<RingkasanDraf>[] = [
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'utama', wajib: true },
    },
    {
        id: 'JumlahBaris',
        accessorKey: 'JumlahBaris',
        header: 'Baris',
        meta: { label: 'Jumlah baris', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.JumlahBaris.toLocaleString('id-ID'),
    },
    {
        id: 'TotalNilai',
        header: 'Total nilai',
        enableSorting: false,
        meta: { label: 'Total nilai', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.TotalNilai),
    },
];

const kolomDokumen: KolomTabel<DokumenImpor>[] = [
    {
        id: 'NamaGudang',
        accessorKey: 'NamaGudang',
        header: 'Lokasi stok',
        meta: { label: 'Lokasi stok', prioritas: 'utama', wajib: true },
        cell: ({ row }) => (
            <Link
                href={`/kelola/persediaan/stok-awal/${row.original.Uuid}`}
                className="font-semibold text-brand underline"
            >
                {row.original.NamaGudang}
            </Link>
        ),
    },
    {
        id: 'JumlahBaris',
        accessorKey: 'JumlahBaris',
        header: 'Baris',
        meta: { label: 'Jumlah baris', angka: true, prioritas: 'penting' },
        cell: ({ row }) => row.original.JumlahBaris.toLocaleString('id-ID'),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus jenis={JenisLabelDraf(row.original.Status)} teks={labelStatusDraf[row.original.Status]} />
        ),
    },
];

function Angka({ label, nilai }: { label: string; nilai: number }) {
    return (
        <div className="flex flex-col rounded-kontrol border border-garis bg-card px-3 py-2">
            <dt className="text-keterangan text-teks-sekunder">{label}</dt>
            <dd className="text-subjudul font-semibold text-teks-utama tabular-nums">
                {nilai.toLocaleString('id-ID')}
            </dd>
        </div>
    );
}

function TautanLaporan({ impor }: { impor: RingkasanImporStokAwal }) {
    return (
        <p className="flex flex-wrap gap-x-4 gap-y-1 text-label">
            {impor.JumlahGalat > 0 ? (
                <>
                    <Button asChild variant="link" className="h-auto px-0">
                        <a href={BuatUrlLaporanImporStokAwal(impor.Uuid, 'galat')}>Unduh laporan masalah (Excel)</a>
                    </Button>
                    <Button asChild variant="link" className="h-auto px-0">
                        <a href={BuatUrlLaporanImporStokAwal(impor.Uuid, 'galat', 'csv')}>
                            Unduh laporan masalah (CSV)
                        </a>
                    </Button>
                </>
            ) : null}
            <Button asChild variant="link" className="h-auto px-0">
                <a href={BuatUrlLaporanImporStokAwal(impor.Uuid, 'semua')}>Unduh laporan semua baris</a>
            </Button>
        </p>
    );
}

/**
 * F-05a impor stok awal langkah 2–5 (DesainF05a C.7): pemetaan kolom → pratinjau draf per lokasi → pembuatan draf
 * (polling) → daftar draf yang harus diperiksa & dicatat satu per satu. Impor tidak pernah memposting.
 */
export default function HalamanDetailImporStokAwal({
    Impor,
    Pemetaan,
    Pratinjau,
    Dokumen,
    OpsiGudang,
}: PropsDetailImporStokAwal) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [ubahPemetaan, AturUbahPemetaan] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const berjalan = StatusBerjalanStokAwal.includes(Impor.Status);
    const tampilPemetaan =
        Pemetaan !== null && (Impor.Status === 'MenungguPemetaan' || (Impor.Status === 'Pratinjau' && ubahPemetaan));
    const Kirim = (aksi: 'terapkan' | 'lanjutkan' | 'batalkan') =>
        router.post(
            `${alamatImpor}/${Impor.Uuid}/${aksi}`,
            {},
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    const bolehBatal = Impor.Status === 'MenungguPemetaan' || Impor.Status === 'Pratinjau';
    const galatPemetaan = tampilPemetaan
        ? Object.keys(props.errors).filter(
              (kunci) => kunci.startsWith('Pemetaan.') || kunci === 'Tanggal' || kunci === 'UuidGudangBawaan',
          )
        : [];

    return (
        <TataLetakAplikasi judul={`Impor ${Impor.NamaBerkas}`}>
            <p className="text-label">
                <Link href={alamatImpor} className="font-semibold text-brand underline">
                    Kembali ke riwayat impor stok awal
                </Link>
            </p>
            <LangkahImpor status={Impor.Status} />
            <DaftarGalatServer galat={props.errors} kecuali={galatPemetaan} />

            <div className="flex flex-wrap items-center gap-2 text-isi text-teks-sekunder">
                <LabelStatus jenis={JenisLabelImpor(Impor.Status)} teks={Impor.LabelStatus} />
                <span>
                    Diunggah {FormatTanggalWaktu(Impor.DibuatPada)} oleh {Impor.NamaPengguna ?? 'Sistem'}
                    {Impor.JumlahBaris > 0 ? ` | ${Impor.JumlahBaris.toLocaleString('id-ID')} baris` : ''}
                    {Impor.NamaGudangBawaan ? ` | lokasi bawaan ${Impor.NamaGudangBawaan}` : ''}
                </span>
            </div>

            {Impor.BerkasPernahDiimpor ? (
                <Pemberitahuan jenis="peringatan" judul="Berkas yang sama pernah diimpor">
                    Berkas ini pernah dibuatkan draf pada {FormatTanggal(Impor.BerkasPernahDiimpor.slice(0, 10))}.
                    Periksa daftar stok awal agar tidak ada draf ganda.
                </Pemberitahuan>
            ) : null}

            {berjalan ? <KemajuanImporStokAwal impor={Impor} /> : null}

            {tampilPemetaan ? (
                <PemetaanImporStokAwal
                    uuidImpor={Impor.Uuid}
                    pemetaan={Pemetaan}
                    opsiGudang={OpsiGudang}
                    galatServer={props.errors}
                    {...(Impor.Status === 'Pratinjau' ? { saatBatal: () => AturUbahPemetaan(false) } : {})}
                />
            ) : null}

            {Impor.Status === 'Pratinjau' && Pratinjau !== null && !ubahPemetaan ? (
                <Panel judul="Pratinjau" idJudul="judul-pratinjau-stok-awal">
                    <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <Angka label="Baris valid" nilai={Impor.JumlahValid} />
                        <Angka label="Baris bermasalah" nilai={Impor.JumlahGalat} />
                        <Angka label="Draf yang akan dibuat" nilai={Pratinjau.RingkasanDokumen.length} />
                    </dl>
                    {Pratinjau.RingkasanDokumen.length > 0 ? (
                        <TabelData
                            id="persediaan-impor-ringkasan-draf"
                            label="Draf stok awal yang akan dibuat"
                            kolom={kolomRingkasan}
                            sumber={{ mode: 'lokal', data: Pratinjau.RingkasanDokumen }}
                            ambilIdBaris={(dokumen) => dokumen.NamaGudang}
                            kosong={{ judul: 'Tidak ada draf yang akan dibuat.' }}
                        />
                    ) : null}
                    {Pratinjau.Peringatan.length > 0 ? (
                        <Pemberitahuan jenis="info" judul="Catatan">
                            <ul className="list-disc pl-5">
                                {Pratinjau.Peringatan.map((pesan) => (
                                    <li key={pesan}>{pesan}</li>
                                ))}
                            </ul>
                        </Pemberitahuan>
                    ) : null}
                    {Pratinjau.BarisGalat.length > 0 ? (
                        <div className="flex flex-col gap-2">
                            <h3 className="text-label font-semibold text-teks-utama">
                                Baris bermasalah
                                {Impor.JumlahGalat > Pratinjau.BarisGalat.length
                                    ? ` (${String(Pratinjau.BarisGalat.length)} pertama dari ${String(Impor.JumlahGalat)})`
                                    : ''}
                            </h3>
                            <p className="text-keterangan text-teks-sekunder">
                                Baris ini tidak ikut dibuatkan draf. Perbaiki di berkas lalu unggah ulang, atau
                                lanjutkan tanpa baris ini.
                            </p>
                            <TabelBarisGalatImpor
                                id="persediaan-impor-galat"
                                baris={Pratinjau.BarisGalat}
                                ambilNama={(baris) => baris.Data.Produk ?? '—'}
                            />
                        </div>
                    ) : null}
                    <TautanLaporan impor={Impor} />
                    <p className="text-keterangan text-teks-sekunder">
                        Impor hanya membuat draf. Stok dan jurnal baru berubah setelah setiap draf dicatat.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Tombol
                            onClick={() => Kirim('terapkan')}
                            memproses={memproses}
                            disabled={Impor.JumlahValid === 0}
                        >
                            Buat draf dari {Impor.JumlahValid.toLocaleString('id-ID')} baris valid
                        </Tombol>
                        {Pemetaan !== null ? (
                            <Tombol varian="sekunder" onClick={() => AturUbahPemetaan(true)}>
                                Ubah pemetaan kolom
                            </Tombol>
                        ) : null}
                    </div>
                </Panel>
            ) : null}

            {Impor.Status === 'Selesai' ? (
                <Panel judul="Draf stok awal dibuat" idJudul="judul-hasil-impor-stok-awal">
                    <p className="text-isi text-teks-utama">
                        Periksa setiap draf, lalu catat agar stok dan jurnal saldo awal tercatat.
                    </p>
                    {Impor.SelesaiPada ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Selesai {FormatTanggalWaktu(Impor.SelesaiPada)}.
                        </p>
                    ) : null}
                    <TautanLaporan impor={Impor} />
                </Panel>
            ) : null}

            {Dokumen.length > 0 || Impor.Status === 'Selesai' ? (
                <section aria-labelledby="judul-draf-impor" className="flex flex-col gap-2">
                    <h2 id="judul-draf-impor" className="text-subjudul font-semibold text-teks-utama">
                        Dokumen stok awal dari impor ini
                    </h2>
                    <TabelData
                        id="persediaan-impor-dokumen"
                        label="Dokumen stok awal dari impor ini"
                        kolom={kolomDokumen}
                        sumber={{ mode: 'lokal', data: Dokumen }}
                        ambilIdBaris={(dokumen) => dokumen.Uuid}
                        alamatDetail={(dokumen) => `/kelola/persediaan/stok-awal/${dokumen.Uuid}`}
                        kosong={{ judul: 'Belum ada draf yang dibuat.' }}
                    />
                </section>
            ) : null}

            {Impor.Status === 'Gagal' ? (
                <Pemberitahuan jenis="bahaya" judul="Impor berhenti">
                    <p>{Impor.PesanGalat ?? 'Terjadi masalah saat memproses berkas.'}</p>
                    {Impor.JumlahDokumen > 0 ? (
                        <p className="tabular-nums">
                            {Impor.JumlahDokumen.toLocaleString('id-ID')} draf sudah dibuat dan tidak akan diulang.
                        </p>
                    ) : null}
                    {Impor.BolehLanjutkan ? (
                        <div className="mt-2">
                            <Tombol onClick={() => Kirim('lanjutkan')} memproses={memproses}>
                                Lanjutkan impor
                            </Tombol>
                        </div>
                    ) : null}
                    <div className="mt-2">
                        <TautanLaporan impor={Impor} />
                    </div>
                </Pemberitahuan>
            ) : null}

            {Impor.Status === 'Menerapkan' && Impor.BolehLanjutkan ? (
                <Pemberitahuan jenis="peringatan" judul="Impor tampak terhenti">
                    <p>Tidak ada kemajuan lebih dari 10 menit. Lanjutkan untuk membuat draf yang belum dibuat.</p>
                    <div className="mt-2">
                        <Tombol onClick={() => Kirim('lanjutkan')} memproses={memproses}>
                            Lanjutkan impor
                        </Tombol>
                    </div>
                </Pemberitahuan>
            ) : null}

            {Impor.Status === 'Dibatalkan' ? (
                <Pemberitahuan jenis="info" judul="Impor dibatalkan">
                    Tidak ada draf stok awal yang dibuat.{' '}
                    <Link href={alamatImpor} className="font-semibold text-brand underline">
                        Unggah berkas lain
                    </Link>
                </Pemberitahuan>
            ) : null}

            {bolehBatal ? (
                <div>
                    <Tombol varian="bahaya" onClick={() => Kirim('batalkan')} disabled={memproses}>
                        Batalkan impor
                    </Tombol>
                </div>
            ) : null}
        </TataLetakAplikasi>
    );
}
