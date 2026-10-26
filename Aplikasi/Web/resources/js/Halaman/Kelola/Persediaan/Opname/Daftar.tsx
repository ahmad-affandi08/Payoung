import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { LabelStatusDokumen } from '@/Komponen/Persediaan/Dokumen/KomponenDokumen';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { FormatLabelGudang, FormatNilai } from '@/Pustaka/FormatPersediaan';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDaftarStokOpname, PropsDaftarStokOpname } from '@/Tipe/DokumenPersediaan';

export const AlamatOpname = '/kelola/persediaan/opname';

const kolom: KolomTabel<BarisDaftarStokOpname>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: o } }) => (
            <>
                <Link
                    href={`${AlamatOpname}/${o.Uuid}`}
                    className="font-mono font-semibold break-all text-brand underline"
                >
                    {o.Nomor}
                </Link>
                {o.HitungButa ? <span className="block text-keterangan text-teks-sekunder">Hitung buta</span> : null}
            </>
        ),
    },
    {
        id: 'TanggalSnapshot',
        accessorKey: 'TanggalSnapshot',
        header: 'Tanggal mulai',
        meta: { label: 'Tanggal mulai', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalSnapshot),
    },
    {
        id: 'Gudang',
        header: 'Lokasi & cakupan',
        enableSorting: false,
        meta: { label: 'Lokasi & cakupan', prioritas: 'penting' },
        cell: ({ row: { original: o } }) => (
            <>
                <span className="block break-words text-teks-utama">{o.NamaGudang}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {o.NamaRak ? `Rak ${o.NamaRak}` : o.NamaKategori ? `Kategori ${o.NamaKategori}` : 'Seluruh produk'}
                    {o.NamaOutlet ? ` | ${o.NamaOutlet}` : ''}
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
        id: 'Dihitung',
        header: 'Dihitung',
        enableSorting: false,
        meta: { label: 'Baris dihitung', angka: true, prioritas: 'rendah' },
        cell: ({ row }) =>
            `${row.original.JumlahDihitung.toLocaleString('id-ID')} / ${row.original.JumlahBaris.toLocaleString('id-ID')}`,
    },
    {
        id: 'Selisih',
        header: 'Selisih nilai',
        enableSorting: false,
        meta: { label: 'Selisih nilai (lebih / kurang)', angka: true, prioritas: 'rendah' },
        cell: ({ row: { original: o } }) =>
            o.Status === 'Disetujui' ? `+${FormatNilai(o.TotalNilaiLebih)} / −${FormatNilai(o.TotalNilaiKurang)}` : '—',
    },
];

/** F-05b: daftar stok opname (TabelData D-16) dan dialog mulai opname baru (lokasi, kategori opsional, hitung buta). */
export default function HalamanDaftarStokOpname({
    Opname,
    OpsiGudang,
    OpsiKategori,
    OpsiRak,
    OpsiStatus,
    Izin,
}: PropsDaftarStokOpname) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [buka, AturBuka] = useState(false);
    const [uuid] = useState(() => BuatUlid());
    const [gudang, AturGudang] = useState('');
    const [kategori, AturKategori] = useState('');
    const [rak, AturRak] = useState('');
    const [buta, AturButa] = useState(false);
    const [catatan, AturCatatan] = useState('');
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const saring: DefinisiSaring[] = [
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
    const opsiRakLokasi = OpsiRak.filter((r) => r.UuidGudang === gudang);
    const tombolMulai = Izin.Kelola ? <Tombol onClick={() => AturBuka(true)}>Mulai stok opname</Tombol> : null;

    const Mulai = () => {
        AturPeriksa(true);

        if (gudang === '') {
            return;
        }

        router.post(
            AlamatOpname,
            {
                Uuid: uuid,
                UuidGudang: gudang,
                UuidKategori: kategori === '' ? null : kategori,
                UuidRak: rak === '' ? null : rak,
                HitungButa: buta,
                Catatan: catatan.trim() === '' ? null : catatan.trim(),
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Stok opname">
            <p className="max-w-2xl text-isi text-teks-sekunder">
                Hitung fisik stok per lokasi. Penjualan dan transaksi lain tetap berjalan selama opname. Selisih
                dihitung dari jumlah fisik dikurangi saldo saat mulai ditambah mutasi selama opname.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="persediaan.kelola" objek="stok opname" /> : null}
            {!buka ? <DaftarGalatServer galat={galat} /> : null}
            <AksiHalaman>{tombolMulai}</AksiHalaman>
            <TabelData
                id="persediaan-opname"
                label="Daftar stok opname"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatOpname, awal: Opname }}
                ambilIdBaris={(o) => o.Uuid}
                urutBawaan="-TanggalSnapshot"
                cari="Cari nomor, catatan, kategori, atau rak"
                saring={saring}
                alamatDetail={(o) => `${AlamatOpname}/${o.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada stok opname.',
                    ...(tombolMulai ? {} : { aksi: <span>Minta pengelola persediaan memulai opname.</span> }),
                }}
            />

            {buka ? (
                <DialogFormulir
                    judul="Mulai stok opname"
                    keterangan="Saldo sistem lokasi ini dicatat saat opname dimulai."
                    galatUmum={galat.Umum}
                    saatTutup={() => AturBuka(false)}
                >
                    <div className="flex flex-col gap-3">
                        <DaftarGalatServer galat={galat} kecuali={['Umum', 'UuidGudang', 'UuidKategori', 'UuidRak']} />
                        <BidangOutlet
                            label="Lokasi stok"
                            nilai={gudang}
                            kosong="Pilih lokasi stok"
                            opsi={OpsiGudang.filter((g) => g.Aktif).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={(nilai) => {
                                AturGudang(nilai);
                                AturRak('');
                            }}
                            galat={galat.UuidGudang ?? (periksa && gudang === '' ? 'Pilih lokasi stok.' : undefined)}
                        />
                        <BidangPilihan
                            label="Kategori (opsional)"
                            nilai={kategori}
                            kosong="Seluruh produk"
                            opsi={OpsiKategori.map((k) => ({ Nilai: k.Uuid, Label: k.Jalur }))}
                            saatBerubah={(nilai) => {
                                AturKategori(nilai);
                                AturRak('');
                            }}
                            galat={galat.UuidKategori}
                        />
                        {opsiRakLokasi.length > 0 ? (
                            <BidangPilihan
                                label="Rak (opsional)"
                                nilai={rak}
                                kosong="Semua rak"
                                opsi={opsiRakLokasi.map((r) => ({
                                    Nilai: r.Uuid,
                                    Label: `${r.Kode} | ${r.Nama} (${String(r.JumlahProduk)} produk)`,
                                }))}
                                saatBerubah={(nilai) => {
                                    AturRak(nilai);
                                    AturKategori('');
                                }}
                                galat={galat.UuidRak}
                            />
                        ) : null}
                        {opsiRakLokasi.length > 0 ? (
                            <p className="-mt-2 text-keterangan text-teks-sekunder">
                                Rak membatasi hitung ke produk di rak itu, jadi beberapa orang bisa menghitung rak
                                berbeda berbarengan.
                            </p>
                        ) : null}
                        <KotakCentang
                            label="Hitung buta: penghitung tidak melihat jumlah sistem"
                            nilai={buta}
                            saatBerubah={AturButa}
                        />
                        <BidangTeksPanjang
                            label="Catatan (opsional)"
                            nilai={catatan}
                            saatBerubah={AturCatatan}
                            baris={2}
                            maksimal={500}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            <Tombol varian="sekunder" onClick={() => AturBuka(false)} disabled={memproses}>
                                Kembali
                            </Tombol>
                            <Tombol memproses={memproses} onClick={Mulai}>
                                Mulai opname
                            </Tombol>
                        </div>
                    </div>
                </DialogFormulir>
            ) : null}
        </TataLetakAplikasi>
    );
}
