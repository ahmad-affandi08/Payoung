import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { DialogAlasan, Keterangan, LabelStatusDokumen } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, HasilTabel, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihRentangTanggal from '@/Komponen/Tanggal/PemilihRentangTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatJumlahStok, FormatLabelGudang, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { AmbilTandaDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { OpsiGudang } from '@/Tipe/Persediaan';

const Alamat = '/kelola/persediaan/bahan-terbuang';

type Opsi = { Nilai: string; Label: string };

export type BarisBahanTerbuang = {
    Uuid: string;
    TanggalBisnis: string;
    NamaProduk: string;
    Jumlah: string;
    Alasan: string;
    LabelAlasan: string;
    Catatan: string | null;
    Nilai: string;
    Status: 'Tercatat' | 'Dibatalkan';
    LabelStatus: string;
    Sumber: 'Pos' | 'BackOffice';
    NamaGudang: string;
    NamaOutlet: string | null;
    NamaPencatat: string | null;
    PerluTinjauan: boolean;
    AlasanTinjauan: string | null;
    AlasanBatal: string | null;
};

export type PropsDaftarBahanTerbuang = {
    BahanTerbuang: HasilTabel<BarisBahanTerbuang>;
    Ringkasan: {
        NilaiTerbuang: string;
        HppPenjualan: string;
        Persen: string | null;
        Jumlah: number;
        Dari: string;
        Sampai: string;
    };
    OpsiGudang: OpsiGudang[];
    OpsiAlasan: Opsi[];
    OpsiStatus: Opsi[];
    HariIni: string;
    Izin: { Catat: boolean; Batalkan: boolean };
};

const kolom: KolomTabel<BarisBahanTerbuang>[] = [
    {
        id: 'TanggalBisnis',
        accessorKey: 'TanggalBisnis',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalBisnis),
    },
    {
        id: 'NamaProduk',
        header: 'Produk',
        enableSorting: false,
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{b.NamaProduk}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {FormatJumlahStok(b.Jumlah)} | {b.LabelAlasan}
                    {b.Catatan ? ` | ${b.Catatan}` : ''}
                </span>
                {b.PerluTinjauan ? <LabelStatus jenis="peringatan" teks="Perlu dicek" /> : null}
            </>
        ),
    },
    {
        id: 'Lokasi',
        header: 'Lokasi & pencatat',
        enableSorting: false,
        meta: { label: 'Lokasi & pencatat', prioritas: 'rendah' },
        cell: ({ row: { original: b } }) => (
            <>
                <span className="block break-words text-teks-utama">{b.NamaGudang}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {b.NamaPencatat ?? '-'} | {b.Sumber === 'Pos' ? 'kasir' : 'back-office'}
                </span>
            </>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => <LabelStatusDokumen status={row.original.Status} label={row.original.LabelStatus} />,
    },
    {
        id: 'Nilai',
        accessorKey: 'Nilai',
        header: 'Nilai (HPP)',
        meta: { label: 'Nilai (HPP)', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatNilai(row.original.Nilai),
    },
];

/** F-05f: bahan/menu terbuang (waste) + ringkasan food cost periode. */
export default function HalamanBahanTerbuang({
    BahanTerbuang,
    Ringkasan,
    OpsiGudang,
    OpsiAlasan,
    OpsiStatus,
    Izin,
}: PropsDaftarBahanTerbuang) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [catat, AturCatat] = useState(false);
    const [batal, AturBatal] = useState<BarisBahanTerbuang | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
        {
            id: 'Alasan',
            label: 'Alasan',
            jenis: 'pilihanBanyak',
            opsi: OpsiAlasan.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Gudang',
            label: 'Lokasi stok',
            jenis: 'pilihan',
            opsi: OpsiGudang.map((g) => ({ nilai: g.Uuid, label: FormatLabelGudang(g) })),
        },
    ];

    return (
        <TataLetakAplikasi judul="Bahan terbuang">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Catat bahan atau menu yang terbuang (basi, rusak, salah buat) supaya food cost terlihat.
            </p>
            <DaftarGalatServer galat={props.errors} />
            {catat ? (
                <FormCatat opsiGudang={OpsiGudang} opsiAlasan={OpsiAlasan} saatTutup={() => AturCatat(false)} />
            ) : null}
            {batal ? (
                <DialogAlasan
                    judul={`Batalkan catatan ${batal.NamaProduk}?`}
                    keterangan={<p>Stok bahan dikembalikan dan jurnal susut dibalik.</p>}
                    labelAksi="Batalkan catatan"
                    labelAlasan="Alasan pembatalan"
                    memproses={memproses}
                    galat={props.errors}
                    saatKirim={(alasan) =>
                        router.post(
                            `${Alamat}/${batal.Uuid}/batalkan`,
                            { Alasan: alasan },
                            {
                                preserveScroll: true,
                                onStart: () => AturMemproses(true),
                                onFinish: () => AturMemproses(false),
                                onSuccess: () => AturBatal(null),
                            },
                        )
                    }
                    saatTutup={() => AturBatal(null)}
                />
            ) : null}

            <Panel
                judul="Food cost"
                idJudul="judul-food-cost"
                keterangan="Nilai terbuang dibanding HPP penjualan pada periode yang sama."
            >
                <div className="max-w-sm">
                    <PemilihRentangTanggal
                        label="Periode"
                        nilai={`${Ringkasan.Dari}..${Ringkasan.Sampai}`}
                        saatBerubah={(nilai) => {
                            const [dari, sampai] = nilai.split('..');
                            router.get(
                                Alamat,
                                { dari: dari ?? '', sampai: sampai ?? '' },
                                { preserveScroll: true, preserveState: true, only: ['Ringkasan'] },
                            );
                        }}
                    />
                </div>
                <dl className="grid gap-3 sm:grid-cols-3">
                    <Keterangan label="Nilai terbuang">
                        <span className="font-semibold tabular-nums">{FormatNilai(Ringkasan.NilaiTerbuang)}</span>
                        <span className="block text-keterangan text-teks-sekunder">
                            {Ringkasan.Jumlah.toLocaleString('id-ID')} catatan
                        </span>
                    </Keterangan>
                    <Keterangan label="HPP penjualan">
                        <span className="tabular-nums">{FormatNilai(Ringkasan.HppPenjualan)}</span>
                    </Keterangan>
                    <Keterangan label="Terbuang dari HPP penjualan">
                        <span className="font-semibold tabular-nums">
                            {Ringkasan.Persen === null
                                ? 'Belum ada penjualan'
                                : `${Ringkasan.Persen.replace('.', ',')}%`}
                        </span>
                    </Keterangan>
                </dl>
            </Panel>

            <AksiHalaman>
                {Izin.Catat ? <Tombol onClick={() => AturCatat(true)}>Catat bahan terbuang</Tombol> : null}
            </AksiHalaman>

            <TabelData
                id="persediaan-bahan-terbuang"
                label="Daftar bahan terbuang"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: Alamat, awal: BahanTerbuang }}
                ambilIdBaris={(b) => b.Uuid}
                urutBawaan="-TanggalBisnis"
                cari="Cari nama produk atau catatan"
                saring={saring}
                {...(Izin.Batalkan
                    ? {
                          aksiBaris: (b: BarisBahanTerbuang) =>
                              b.Status === 'Tercatat' ? (
                                  <DropdownMenuItem variant="destructive" onSelect={() => AturBatal(b)}>
                                      Batalkan catatan
                                  </DropdownMenuItem>
                              ) : null,
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada bahan terbuang yang dicatat.' }}
            />
        </TataLetakAplikasi>
    );
}

function FormCatat({
    opsiGudang,
    opsiAlasan,
    saatTutup,
}: {
    opsiGudang: OpsiGudang[];
    opsiAlasan: Opsi[];
    saatTutup: () => void;
}) {
    const [uuid] = useState(() => BuatUlid());
    const [produk, AturProduk] = useState<ProdukStokTerpilih | null>(null);
    const formulir = useForm({ Uuid: uuid, UuidGudang: '', UuidProduk: '', Jumlah: '', Alasan: '', Catatan: '' });
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const [periksa, AturPeriksa] = useState(false);
    const jumlahValid =
        CekDesimalValid(d.Jumlah) &&
        AmbilTandaDesimal(d.Jumlah) > 0 &&
        (produk?.BolehDesimal !== false || !d.Jumlah.includes('.'));

    const Kirim = (p: FormEvent) => {
        p.preventDefault();
        AturPeriksa(true);

        if (d.UuidGudang === '' || produk === null || !jumlahValid || d.Alasan === '') {
            return;
        }

        formulir.post(Alamat, { preserveScroll: true, onSuccess: saatTutup });
    };

    return (
        <DialogFormulir judul="Catat bahan terbuang" saatTutup={saatTutup} galatUmum={galat.Umum}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangOutlet
                    label="Lokasi stok"
                    nilai={d.UuidGudang}
                    kosong="Pilih lokasi stok"
                    opsi={opsiGudang
                        .filter((g) => g.Aktif)
                        .map((g) => ({ Nilai: g.Uuid, Label: FormatLabelGudang(g) }))}
                    saatBerubah={(v) => formulir.setData('UuidGudang', v)}
                    galat={galat.UuidGudang ?? (periksa && d.UuidGudang === '' ? 'Pilih lokasi stok.' : undefined)}
                />
                {produk === null ? (
                    <PemilihProdukStok
                        label="Produk"
                        uuidGudang={d.UuidGudang === '' ? null : d.UuidGudang}
                        saatPilih={(p) => {
                            AturProduk(p);
                            formulir.setData('UuidProduk', p.Uuid);
                        }}
                        disabled={d.UuidGudang === ''}
                        keterangan={
                            d.UuidGudang === '' ? 'Pilih lokasi stok dulu.' : 'Bahan atau barang yang terbuang.'
                        }
                        galat={galat.UuidProduk ?? (periksa ? 'Pilih produk.' : undefined)}
                    />
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-kontrol border border-garis p-3">
                        <span className="font-semibold break-words text-teks-utama">{produk.Nama}</span>
                        <Tombol varian="sekunder" onClick={() => AturProduk(null)}>
                            Ganti
                        </Tombol>
                    </div>
                )}
                <BidangJumlah
                    label="Jumlah terbuang"
                    nilai={d.Jumlah}
                    saatBerubah={(v) => formulir.setData('Jumlah', v)}
                    desimal={produk?.BolehDesimal === false ? 0 : 4}
                    {...(produk ? { akhiran: produk.SimbolSatuan } : {})}
                    galat={galat.Jumlah ?? (periksa && !jumlahValid ? 'Isi jumlah lebih dari 0.' : undefined)}
                    required
                />
                <BidangPilihan
                    label="Alasan"
                    nilai={d.Alasan}
                    kosong="Pilih alasan"
                    opsi={opsiAlasan}
                    saatBerubah={(v) => formulir.setData('Alasan', v)}
                    required
                    galat={galat.Alasan ?? (periksa && d.Alasan === '' ? 'Pilih alasan.' : undefined)}
                />
                <BidangTeks
                    label="Catatan (opsional)"
                    nilai={d.Catatan}
                    saatBerubah={(v) => formulir.setData('Catatan', v)}
                    maxLength={255}
                />
                <div className="flex justify-end gap-2">
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Catat terbuang
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
