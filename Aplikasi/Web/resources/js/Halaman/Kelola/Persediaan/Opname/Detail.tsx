import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type KeyboardEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import {
    DialogAlasan,
    Keterangan,
    LabelStatusDokumen,
    PanelJurnalDokumen,
    PanelRiwayatDokumen,
} from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import PemilihProdukStok, {
    BuatUrlCariProdukStok,
    type ProdukStokTerpilih,
} from '@/Komponen/Persediaan/PemilihProdukStok';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import { Input } from '@/Komponen/Ui/input';
import { Label } from '@/Komponen/Ui/label';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatJumlahStok, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal, CekDesimalValid, JumlahkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisStokOpname, MasukanHitungOpname, PropsDetailStokOpname } from '@/Tipe/DokumenPersediaan';
import type { HasilCariProdukStok } from '@/Tipe/Persediaan';

const alamat = '/kelola/persediaan/opname';

type JenisDialog = 'Ajukan' | 'Kembalikan' | 'Setujui' | 'Batalkan' | null;

/** Baris baru hasil hitung/pindai (belum tersimpan). */
type BarisBaru = {
    Kunci: string;
    Produk: ProdukStokTerpilih;
    JumlahFisik: string;
    NomorBatch: string;
    TanggalKedaluwarsa: string;
    NomorSeri: string;
};

let nomorKunci = 0;

function Rapikan(jumlah: string | null): string {
    return jumlah === null ? '' : jumlah.includes('.') ? jumlah.replace(/\.?0+$/, '') : jumlah;
}

/** Jumlah fisik: kosong = belum dihitung; selain itu ≥ 0, bulat bila satuan tidak desimal, 0/1 untuk nomor seri. */
export function PeriksaJumlahFisik(jumlah: string, bolehDesimal: boolean, seri: boolean): string | null {
    if (jumlah === '') {
        return null;
    }

    if (!CekDesimalValid(jumlah) || AmbilTandaDesimal(jumlah) < 0 || (!bolehDesimal && jumlah.includes('.'))) {
        return 'Jumlah fisik tidak valid.';
    }

    return seri && jumlah !== '0' && jumlah !== '1' ? 'Nomor seri dihitung 1 (ada) atau 0 (tidak ada).' : null;
}

/** Tambah satu ke jumlah fisik (hasil pindai). */
export function TambahSatu(jumlah: string): string {
    return jumlah === '' || !CekDesimalValid(jumlah) ? '1' : Rapikan(JumlahkanDesimal([jumlah, '1']));
}

async function CariBarcode(kata: string, uuidGudang: string): Promise<ProdukStokTerpilih[]> {
    const respons = await fetch(BuatUrlCariProdukStok(kata, uuidGudang, 5), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    return respons.ok ? ((await respons.json()) as HasilCariProdukStok).Data : [];
}

function KolomLembar(sembunyi: boolean): KolomTabel<BarisStokOpname>[] {
    const kolom: KolomTabel<BarisStokOpname>[] = [
        {
            id: 'NamaProduk',
            accessorFn: (b) => `${b.NamaProduk} ${b.Sku ?? ''} ${b.NomorBatch ?? ''} ${b.NomorSeri ?? ''}`,
            header: 'Produk',
            meta: { label: 'Produk', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: b } }) => (
                <>
                    <span className="block font-semibold break-words text-teks-utama">{b.NamaProduk}</span>
                    <span className="block text-keterangan text-teks-sekunder">
                        <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                        {b.NomorBatch ? ` | batch ${b.NomorBatch}` : ''}
                        {b.NomorSeri ? ` | ${b.NomorSeri}` : ''}
                        {!b.DariSnapshot ? ' | ditambahkan saat hitung' : ''}
                    </span>
                </>
            ),
        },
        {
            id: 'JumlahFisik',
            header: 'Fisik',
            enableSorting: false,
            meta: { label: 'Jumlah fisik', angka: true, prioritas: 'penting' },
            cell: ({ row }) =>
                row.original.JumlahFisik === null
                    ? 'Belum dihitung'
                    : FormatJumlahStok(row.original.JumlahFisik, row.original.SimbolSatuan),
        },
        {
            id: 'DihitungOleh',
            header: 'Dihitung oleh',
            enableSorting: false,
            meta: { label: 'Dihitung oleh', prioritas: 'rendah' },
            cell: ({ row }) => row.original.DihitungOleh ?? '—',
        },
    ];

    if (sembunyi) {
        return kolom;
    }

    return [
        ...kolom,
        {
            id: 'JumlahSistem',
            header: 'Sistem saat mulai',
            enableSorting: false,
            meta: { label: 'Sistem saat mulai', angka: true, prioritas: 'penting' },
            cell: ({ row }) => FormatJumlahStok(row.original.JumlahSistem ?? '0', row.original.SimbolSatuan),
        },
        {
            id: 'MutasiSelamaOpname',
            header: 'Mutasi selama opname',
            enableSorting: false,
            meta: { label: 'Mutasi selama opname', angka: true, prioritas: 'rendah' },
            cell: ({ row }) =>
                row.original.MutasiSelamaOpname === null
                    ? '—'
                    : FormatJumlahStok(row.original.MutasiSelamaOpname, row.original.SimbolSatuan),
        },
        {
            id: 'Selisih',
            header: 'Selisih',
            enableSorting: false,
            meta: { label: 'Selisih', angka: true, prioritas: 'penting' },
            cell: ({ row }) =>
                row.original.Selisih === null ? '—' : FormatJumlahStok(row.original.Selisih, row.original.SimbolSatuan),
        },
        {
            id: 'NilaiSelisih',
            header: 'Nilai selisih',
            enableSorting: false,
            meta: { label: 'Nilai selisih', angka: true, prioritas: 'rendah' },
            cell: ({ row }) => (row.original.NilaiSelisih === null ? '—' : FormatNilai(row.original.NilaiSelisih)),
        },
    ];
}

/** F-05b: detail & lembar hitung stok opname (BR-05.3): hitung/pindai, simpan berkali-kali, tinjau selisih, setujui. */
export default function HalamanDetailStokOpname({
    Opname,
    Baris,
    Penghitung,
    Jurnal,
    Riwayat,
    Tindakan,
    Izin,
}: PropsDetailStokOpname) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [dialog, AturDialog] = useState<JenisDialog>(null);
    const [memproses, AturMemproses] = useState(false);
    const [hitung, AturHitung] = useState<Record<number, string>>(() =>
        Object.fromEntries(Baris.map((b) => [b.Urutan, Rapikan(b.JumlahFisik)])),
    );
    const [baru, AturBaru] = useState<BarisBaru[]>([]);
    const [saring, AturSaring] = useState('');
    const [pindai, AturPindai] = useState('');
    const [pesanPindai, AturPesanPindai] = useState<string | null>(null);
    const kolom = useMemo(() => KolomLembar(Opname.SistemTersembunyi), [Opname.SistemTersembunyi]);
    const berubah = Baris.filter((b) => (hitung[b.Urutan] ?? '') !== Rapikan(b.JumlahFisik));
    const galatLokal = Baris.some(
        (b) => PeriksaJumlahFisik(hitung[b.Urutan] ?? '', b.BolehDesimal, b.Pelacakan === 'Seri') !== null,
    );
    const kata = saring.trim().toLowerCase();
    const tampil =
        kata === ''
            ? Baris
            : Baris.filter((b) =>
                  `${b.NamaProduk} ${b.Sku ?? ''} ${b.NomorBatch ?? ''} ${b.NomorSeri ?? ''}`
                      .toLowerCase()
                      .includes(kata),
              );

    const Kirim = (aksi: string, data: Parameters<typeof router.post>[1] = {}) =>
        router.post(`${alamat}/${Opname.Uuid}/${aksi}`, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => AturDialog(null),
        });

    const TambahBaru = (produk: ProdukStokTerpilih, jumlah = '') => {
        nomorKunci += 1;
        AturBaru((lama) => [
            ...lama,
            {
                Kunci: `opname-${String(nomorKunci)}`,
                Produk: produk,
                JumlahFisik: produk.Pelacakan === 'Seri' ? '1' : jumlah,
                NomorBatch: '',
                TanggalKedaluwarsa: '',
                NomorSeri: '',
            },
        ]);
    };

    const Pindai = async (peristiwa: KeyboardEvent<HTMLInputElement>) => {
        if (peristiwa.key !== 'Enter') {
            return;
        }

        peristiwa.preventDefault();
        const kode = pindai.trim();

        if (kode === '') {
            return;
        }

        const hasil = await CariBarcode(kode, Opname.UuidGudang);
        const produk = hasil.length === 1 ? hasil[0] : hasil.find((p) => p.Sku === kode);
        AturPindai('');

        if (produk === undefined) {
            AturPesanPindai(`Produk dengan kode ${kode} tidak ditemukan.`);

            return;
        }

        const ada = Baris.find((b) => b.UuidProduk === produk.Uuid && b.Pelacakan === 'Tidak');

        if (ada !== undefined) {
            AturHitung((lama) => ({ ...lama, [ada.Urutan]: TambahSatu(lama[ada.Urutan] ?? '') }));
            AturPesanPindai(`${produk.Nama}: +1`);

            return;
        }

        const baruAda = baru.find((b) => b.Produk.Uuid === produk.Uuid && produk.Pelacakan === 'Tidak');

        if (baruAda !== undefined) {
            AturBaru((lama) =>
                lama.map((b) => (b.Kunci === baruAda.Kunci ? { ...b, JumlahFisik: TambahSatu(b.JumlahFisik) } : b)),
            );
        } else {
            TambahBaru(produk, '1');
        }

        AturPesanPindai(
            produk.Pelacakan === 'Tidak'
                ? `${produk.Nama}: +1 (baris baru)`
                : `${produk.Nama}: isi nomor ${produk.Pelacakan === 'Batch' ? 'batch' : 'seri'} di baris baru.`,
        );
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (galatLokal) {
            return;
        }

        const isi: MasukanHitungOpname[] = [
            ...berubah.map((b) => ({
                Urutan: b.Urutan,
                UuidProduk: null,
                JumlahFisik: (hitung[b.Urutan] ?? '') === '' ? null : (hitung[b.Urutan] ?? null),
                NomorBatch: null,
                TanggalKedaluwarsa: null,
                NomorSeri: null,
            })),
            ...baru
                .filter((b) => b.JumlahFisik !== '')
                .map((b) => ({
                    Urutan: null,
                    UuidProduk: b.Produk.Uuid,
                    JumlahFisik: b.JumlahFisik,
                    NomorBatch: b.Produk.Pelacakan === 'Batch' ? b.NomorBatch : null,
                    TanggalKedaluwarsa:
                        b.Produk.Pelacakan === 'Batch' && b.TanggalKedaluwarsa !== '' ? b.TanggalKedaluwarsa : null,
                    NomorSeri: b.Produk.Pelacakan === 'Seri' ? b.NomorSeri : null,
                })),
        ];

        if (isi.length === 0) {
            return;
        }

        router.put(
            `${alamat}/${Opname.Uuid}/hitung`,
            { Hitung: isi },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturBaru([]),
            },
        );
    };

    return (
        <TataLetakAplikasi judul={`Stok opname ${Opname.Nomor}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-subjudul font-semibold text-teks-utama">{Opname.Nomor}</span>
                    <LabelStatusDokumen status={Opname.Status} label={Opname.LabelStatus} />
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={alamat}>Kembali ke daftar</Link>
                    </Button>
                    {Tindakan.Batalkan ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('Batalkan')}>
                            Batalkan opname
                        </Tombol>
                    ) : null}
                    {Tindakan.Kembalikan ? (
                        <Tombol varian="sekunder" onClick={() => AturDialog('Kembalikan')}>
                            Hitung ulang
                        </Tombol>
                    ) : null}
                    {Tindakan.Ajukan ? <Tombol onClick={() => AturDialog('Ajukan')}>Selesai hitung</Tombol> : null}
                    {Tindakan.Setujui ? <Tombol onClick={() => AturDialog('Setujui')}>Setujui opname</Tombol> : null}
                </div>
            </div>

            {Opname.SistemTersembunyi ? (
                <Pemberitahuan jenis="info" judul="Hitung buta">
                    Jumlah sistem disembunyikan selama penghitungan. Selisih tampil setelah hitung selesai dan diajukan
                    untuk ditinjau.
                </Pemberitahuan>
            ) : null}
            {Opname.Status === 'Ditinjau' && !Tindakan.Setujui ? (
                <Pemberitahuan jenis="info" judul="Menunggu persetujuan">
                    Persetujuan opname perlu izin persediaan.penyesuaian.setujui.
                </Pemberitahuan>
            ) : null}
            {Opname.Status === 'Dibatalkan' ? (
                <Pemberitahuan jenis="info" judul="Opname dibatalkan">
                    Stok tidak berubah{Opname.AlasanBatal ? `. Alasan: ${Opname.AlasanBatal}` : ''}.
                </Pemberitahuan>
            ) : null}
            {dialog === null ? <DaftarGalatServer galat={galat} /> : null}

            <Panel judul="Ringkasan" idJudul="judul-ringkasan-opname">
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Keterangan label="Lokasi stok">
                        {Opname.NamaGudang}
                        {Opname.NamaOutlet ? (
                            <span className="block text-keterangan text-teks-sekunder">{Opname.NamaOutlet}</span>
                        ) : null}
                    </Keterangan>
                    <Keterangan label="Cakupan">
                        {Opname.NamaRak
                            ? `Rak ${Opname.NamaRak}`
                            : Opname.NamaKategori
                              ? `Kategori ${Opname.NamaKategori}`
                              : 'Seluruh produk'}
                    </Keterangan>
                    <Keterangan label="Snapshot">{FormatTanggalWaktu(Opname.SnapshotPada)}</Keterangan>
                    <Keterangan label="Dihitung">
                        {Opname.JumlahDihitung.toLocaleString('id-ID')} dari{' '}
                        {Opname.JumlahBaris.toLocaleString('id-ID')} baris
                    </Keterangan>
                    {Opname.Status === 'Disetujui' ? (
                        <Keterangan label="Selisih nilai">
                            <span className="tabular-nums">
                                Lebih {FormatNilai(Opname.TotalNilaiLebih)} | Kurang{' '}
                                {FormatNilai(Opname.TotalNilaiKurang)}
                            </span>
                            {Opname.TanggalPosting ? (
                                <span className="block text-keterangan text-teks-sekunder">
                                    Diposting {FormatTanggal(Opname.TanggalPosting)}
                                </span>
                            ) : null}
                        </Keterangan>
                    ) : null}
                    {Opname.Catatan ? <Keterangan label="Catatan">{Opname.Catatan}</Keterangan> : null}
                </dl>
            </Panel>

            {Penghitung.length > 0 ? (
                <Panel judul="Penghitung" idJudul="judul-penghitung-opname" tingkat="h3">
                    <ul aria-label="Ringkasan per penghitung" className="flex flex-col divide-y divide-garis text-isi">
                        {Penghitung.map((p) => (
                            <li key={p.Nama} className="flex flex-wrap items-baseline justify-between gap-x-4 py-1.5">
                                <span className="font-semibold text-teks-utama">{p.Nama}</span>
                                <span className="text-teks-sekunder tabular-nums">
                                    {p.JumlahBaris.toLocaleString('id-ID')} baris
                                    {p.TerakhirPada ? ` | terakhir ${FormatTanggalWaktu(p.TerakhirPada)}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {Tindakan.Hitung ? (
                <form onSubmit={Simpan} noValidate aria-label="Lembar hitung" className="flex flex-col gap-4">
                    <Panel
                        judul="Lembar hitung"
                        idJudul="judul-lembar-hitung"
                        keterangan="Kosongkan jumlah bila barang belum dihitung; baris yang belum dihitung tidak disesuaikan."
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="pindai-opname" className="text-label font-semibold text-teks-utama">
                                    Pindai barcode
                                </Label>
                                <Input
                                    id="pindai-opname"
                                    value={pindai}
                                    onChange={(e) => AturPindai(e.target.value)}
                                    onKeyDown={(e) => void Pindai(e)}
                                    placeholder="Pindai atau ketik barcode/SKU lalu Enter"
                                    className="h-8 pointer-coarse:h-11 font-mono"
                                    autoComplete="off"
                                />
                                {pesanPindai ? (
                                    <p className="text-keterangan text-teks-sekunder" aria-live="polite">
                                        {pesanPindai}
                                    </p>
                                ) : null}
                            </div>
                            <BidangTeks
                                label="Saring baris"
                                nilai={saring}
                                saatBerubah={AturSaring}
                                keterangan="Nama, SKU, batch, atau nomor seri."
                            />
                        </div>
                        <ul className="flex flex-col divide-y divide-garis" aria-label="Baris hitung">
                            {tampil.map((b) => (
                                <li
                                    key={b.Urutan}
                                    className="grid gap-2 py-2 sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-start"
                                >
                                    <div className="min-w-0">
                                        <span className="block font-semibold break-words text-teks-utama">
                                            {b.NamaProduk}
                                        </span>
                                        <span className="block text-keterangan text-teks-sekunder">
                                            <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                                            {b.NomorBatch ? ` | batch ${b.NomorBatch}` : ''}
                                            {b.NomorSeri ? ` | ${b.NomorSeri}` : ''}
                                            {b.JumlahSistem !== null
                                                ? ` | sistem ${FormatJumlahStok(b.JumlahSistem, b.SimbolSatuan)}`
                                                : ''}
                                        </span>
                                    </div>
                                    <BidangJumlah
                                        label={`Fisik ${b.NamaProduk}${b.NomorBatch ? ` batch ${b.NomorBatch}` : ''}${b.NomorSeri ? ` ${b.NomorSeri}` : ''}`}
                                        labelTersembunyi
                                        nilai={hitung[b.Urutan] ?? ''}
                                        saatBerubah={(nilai) => AturHitung((lama) => ({ ...lama, [b.Urutan]: nilai }))}
                                        desimal={b.BolehDesimal ? 4 : 0}
                                        akhiran={b.SimbolSatuan}
                                        galat={
                                            PeriksaJumlahFisik(
                                                hitung[b.Urutan] ?? '',
                                                b.BolehDesimal,
                                                b.Pelacakan === 'Seri',
                                            ) ?? undefined
                                        }
                                    />
                                </li>
                            ))}
                            {tampil.length === 0 ? (
                                <li className="py-2 text-isi text-teks-sekunder">Tidak ada baris yang cocok.</li>
                            ) : null}
                        </ul>
                    </Panel>

                    <Panel
                        judul="Barang di luar snapshot"
                        idJudul="judul-baris-baru"
                        keterangan="Barang yang ditemukan tetapi tidak ada di daftar (batch baru, nomor seri lain, atau produk tanpa saldo)."
                    >
                        <PemilihProdukStok
                            label="Tambah barang yang ditemukan"
                            uuidGudang={Opname.UuidGudang}
                            saatPilih={(p) => TambahBaru(p)}
                        />
                        {baru.length > 0 ? (
                            <ul className="flex flex-col divide-y divide-garis" aria-label="Barang tambahan">
                                {baru.map((b) => (
                                    <li
                                        key={b.Kunci}
                                        className="grid gap-2 py-2 sm:grid-cols-2 lg:grid-cols-4 lg:items-start"
                                    >
                                        <span className="font-semibold break-words text-teks-utama">
                                            {b.Produk.Nama}
                                        </span>
                                        {b.Produk.Pelacakan === 'Batch' ? (
                                            <>
                                                <BidangTeks
                                                    label={`Nomor batch ${b.Produk.Nama}`}
                                                    nilai={b.NomorBatch}
                                                    kode
                                                    saatBerubah={(nilai) =>
                                                        AturBaru((lama) =>
                                                            lama.map((x) =>
                                                                x.Kunci === b.Kunci ? { ...x, NomorBatch: nilai } : x,
                                                            ),
                                                        )
                                                    }
                                                />
                                                <PemilihTanggal
                                                    id={`${b.Kunci}-kedaluwarsa`}
                                                    label="Kedaluwarsa (batch baru)"
                                                    nilai={b.TanggalKedaluwarsa}
                                                    saatBerubah={(nilai) =>
                                                        AturBaru((lama) =>
                                                            lama.map((x) =>
                                                                x.Kunci === b.Kunci
                                                                    ? { ...x, TanggalKedaluwarsa: nilai }
                                                                    : x,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </>
                                        ) : null}
                                        {b.Produk.Pelacakan === 'Seri' ? (
                                            <BidangTeks
                                                label={`Nomor seri ${b.Produk.Nama}`}
                                                nilai={b.NomorSeri}
                                                kode
                                                saatBerubah={(nilai) =>
                                                    AturBaru((lama) =>
                                                        lama.map((x) =>
                                                            x.Kunci === b.Kunci ? { ...x, NomorSeri: nilai } : x,
                                                        ),
                                                    )
                                                }
                                            />
                                        ) : (
                                            <BidangJumlah
                                                label={`Fisik ${b.Produk.Nama}`}
                                                nilai={b.JumlahFisik}
                                                saatBerubah={(nilai) =>
                                                    AturBaru((lama) =>
                                                        lama.map((x) =>
                                                            x.Kunci === b.Kunci ? { ...x, JumlahFisik: nilai } : x,
                                                        ),
                                                    )
                                                }
                                                desimal={b.Produk.BolehDesimal ? 4 : 0}
                                                akhiran={b.Produk.SimbolSatuan}
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </Panel>

                    <div className="sticky bottom-0 flex flex-wrap items-center gap-2 bg-latar py-2 tepi-bawah-aman sm:static sm:bg-transparent sm:py-0">
                        <Tombol
                            type="submit"
                            memproses={memproses}
                            disabled={berubah.length === 0 && baru.length === 0}
                        >
                            Simpan hasil hitung
                        </Tombol>
                        <span className="text-keterangan text-teks-sekunder">
                            {berubah.length + baru.length === 0
                                ? 'Belum ada perubahan.'
                                : `${String(berubah.length + baru.length)} baris belum disimpan.`}
                        </span>
                    </div>
                </form>
            ) : (
                <Panel judul="Hasil hitung" idJudul="judul-hasil-hitung">
                    <TabelData
                        id="persediaan-opname-baris"
                        label={`Baris opname, ${String(Baris.length)} baris`}
                        kolom={kolom}
                        sumber={{ mode: 'lokal', data: Baris }}
                        ambilIdBaris={(b) => String(b.Urutan)}
                        cari="Cari produk, SKU, batch, atau nomor seri"
                        kosong={{ judul: 'Lokasi ini tidak punya stok saat opname dimulai.' }}
                    />
                </Panel>
            )}

            <PanelJurnalDokumen
                id="persediaan-opname-jurnal"
                jurnal={Jurnal}
                lihatJurnal={Izin.LihatJurnal}
                keterangan="Opname kurang: Debit susut & barang rusak. Opname lebih: Kredit selisih HPP."
                kosong={
                    Opname.Status === 'Disetujui'
                        ? 'Tidak ada jurnal karena tidak ada selisih bernilai.'
                        : 'Jurnal dibuat saat opname disetujui.'
                }
            />
            <PanelRiwayatDokumen riwayat={Riwayat} id="judul-riwayat-opname" />

            {dialog === 'Ajukan' ? (
                <DialogKonfirmasi
                    judul="Selesai menghitung?"
                    labelAksi="Ajukan untuk ditinjau"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('ajukan')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>
                        {Opname.JumlahDihitung.toLocaleString('id-ID')} dari{' '}
                        {Opname.JumlahBaris.toLocaleString('id-ID')} baris sudah dihitung. Baris yang belum dihitung
                        tidak disesuaikan.
                    </p>
                    <p>
                        Simpan dulu hasil hitung yang belum tersimpan. Setelah diajukan, lembar hitung terkunci sampai
                        dikembalikan.
                    </p>
                    {galat.Umum ? <span className="font-semibold text-bahaya">{galat.Umum}</span> : null}
                </DialogKonfirmasi>
            ) : null}
            {dialog === 'Setujui' ? (
                <DialogKonfirmasi
                    judul="Setujui stok opname?"
                    labelAksi="Setujui opname"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('setujui')}
                    saatBatal={() => AturDialog(null)}
                >
                    <p>
                        Selisih dicatat ke stok dengan tanggal hari ini dan jurnal selisih dibuat otomatis. Tindakan ini
                        tidak bisa dibatalkan.
                    </p>
                    {galat.Umum ? <span className="font-semibold text-bahaya">{galat.Umum}</span> : null}
                </DialogKonfirmasi>
            ) : null}
            {dialog === 'Kembalikan' ? (
                <DialogAlasan
                    judul="Kembalikan untuk hitung ulang?"
                    keterangan={<p>Lembar hitung dibuka lagi supaya barang dihitung ulang.</p>}
                    labelAksi="Kembalikan"
                    varian="utama"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('kembalikan', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
            {dialog === 'Batalkan' ? (
                <DialogAlasan
                    judul="Batalkan stok opname?"
                    keterangan={<p>Hasil hitung tidak dipakai dan stok tidak berubah.</p>}
                    labelAksi="Batalkan opname"
                    memproses={memproses}
                    galat={galat}
                    saatKirim={(alasan) => Kirim('batalkan', { Alasan: alasan })}
                    saatTutup={() => AturDialog(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
