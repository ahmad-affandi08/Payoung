import { router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import { DialogAlasan } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { ItemAksiBaris } from '@/Komponen/Tindakan/MenuAksiBaris';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisQrisTanpaPenjualan, HasilPenutupanQris, PropsDaftarQrisTanpaPenjualan } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/qris-tanpa-penjualan';

const kolom: KolomTabel<BarisQrisTanpaPenjualan>[] = [
    {
        id: 'NomorPesanan',
        accessorKey: 'NomorPesanan',
        header: 'Nomor QRIS',
        meta: { label: 'Nomor QRIS', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => <span className="font-mono font-semibold break-all">{t.NomorPesanan}</span>,
    },
    {
        id: 'LunasPada',
        accessorKey: 'LunasPada',
        header: 'Dibayar',
        meta: { label: 'Dibayar pada', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: t } }) => (t.LunasPada ? FormatTanggal(t.LunasPada) : '—'),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        meta: { label: 'Jumlah diterima', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => <span className="font-mono">{row.original.KodeOutlet}</span>,
    },
    {
        id: 'Alasan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah' },
        cell: ({ row: { original: t } }) => (
            <span className="break-words">{t.Ditutup ? (t.CatatanPenutupan ?? '') : (t.AlasanTinjauan ?? '')}</span>
        ),
    },
    {
        id: 'Penutupan',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: t } }) =>
            t.Ditutup ? (
                <LabelStatus jenis="sukses" teks={t.LabelHasil ?? 'Ditutup'} />
            ) : (
                <LabelStatus jenis="peringatan" teks="Menunggu keputusan" />
            ),
    },
];

/** Dialog penutupan: pilih hasil, isi catatan; pengakuan pendapatan menambah akun kas/bank dan tanggal. */
function DialogTutup({
    tagihan,
    opsiAkun,
    opsiHasil,
    hariIni,
    saatTutup,
}: {
    tagihan: BarisQrisTanpaPenjualan;
    opsiAkun: PropsDaftarQrisTanpaPenjualan['OpsiAkun'];
    opsiHasil: PropsDaftarQrisTanpaPenjualan['OpsiHasil'];
    hariIni: string;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors as Record<string, string | undefined>;
    const [hasil, AturHasil] = useState<HasilPenutupanQris>('Dikembalikan');
    const [catatan, AturCatatan] = useState('');
    const [uuidAkun, AturUuidAkun] = useState(opsiAkun.length === 1 ? (opsiAkun[0]?.Uuid ?? '') : '');
    const [tanggal, AturTanggal] = useState(hariIni);
    const [memproses, AturMemproses] = useState(false);
    const diakui = hasil === 'DiakuiPendapatan';

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `${alamat}/${tagihan.Uuid}/tutup`,
            { Hasil: hasil, Catatan: catatan, ...(diakui ? { UuidAkun: uuidAkun, Tanggal: tanggal } : {}) },
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
            judul={`Tutup uang QRIS ${tagihan.NomorPesanan}?`}
            keterangan={`Pelanggan membayar ${FormatRupiah(tagihan.Jumlah)} setelah tagihannya tidak berlaku, dan tidak ada penjualan yang memakainya. Pilih ke mana uang ini berujung.`}
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <form
                onSubmit={Kirim}
                noValidate
                className="flex flex-col gap-4"
                aria-label="Tutup uang QRIS tanpa penjualan"
            >
                <BidangPilihan
                    label="Hasil penutupan"
                    nilai={hasil}
                    opsi={opsiHasil}
                    saatBerubah={(nilai) =>
                        AturHasil(nilai === 'DiakuiPendapatan' ? 'DiakuiPendapatan' : 'Dikembalikan')
                    }
                    galat={galat.Hasil}
                    required
                />
                {diakui ? (
                    <>
                        <BidangPilihan
                            label="Akun kas/bank tempat uang mendarat"
                            nilai={uuidAkun}
                            opsi={opsiAkun.map((a) => ({ Nilai: a.Uuid, Label: `${a.Kode} ${a.Nama}` }))}
                            saatBerubah={AturUuidAkun}
                            galat={galat.UuidAkun}
                            required
                        />
                        <PemilihTanggal
                            id="tanggal-pengakuan-qris"
                            label="Tanggal pengakuan"
                            nilai={tanggal}
                            max={hariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galat.Tanggal}
                        />
                    </>
                ) : null}
                <BidangTeksPanjang
                    label={diakui ? 'Alasan diakui sebagai pendapatan' : 'Referensi pengembalian dana'}
                    nilai={catatan}
                    saatBerubah={AturCatatan}
                    galat={galat.Catatan}
                    maksimal={255}
                    required
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol
                        type="submit"
                        memproses={memproses}
                        disabled={catatan.trim().length < 5 || (diakui && uuidAkun === '')}
                    >
                        Tutup tagihan
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}

/** E8: uang QRIS yang masuk tanpa penjualan, dengan jalan keluarnya (kembalikan ke pelanggan / akui pendapatan lain). */
export default function HalamanDaftarQrisTanpaPenjualan({
    Tagihan,
    OpsiAkun,
    OpsiHasil,
    HariIni,
    Izin,
}: PropsDaftarQrisTanpaPenjualan) {
    const [ditutup, AturDitutup] = useState<BarisQrisTanpaPenjualan | null>(null);
    const [dibuka, AturDibuka] = useState<BarisQrisTanpaPenjualan | null>(null);
    const saring: DefinisiSaring[] = [
        {
            id: 'Penutupan',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: [
                { nilai: 'Terbuka', label: 'Menunggu keputusan' },
                { nilai: 'Ditutup', label: 'Sudah ditutup' },
            ],
        },
    ];

    return (
        <TataLetakAplikasi judul="Uang QRIS tanpa penjualan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pelanggan membayar QRIS dinamis setelah tagihannya kedaluwarsa, dibatalkan, atau tidak pasti, sehingga
                uangnya masuk tanpa penjualan. Kembalikan dananya ke pelanggan, atau akui sebagai pendapatan lain bila
                tidak ada yang menuntutnya.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="akuntansi.kelola" objek="penutupan uang QRIS" /> : null}
            <TabelData
                id="qris-tanpa-penjualan"
                label="Daftar uang QRIS tanpa penjualan"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Tagihan }}
                ambilIdBaris={(t) => t.Uuid}
                urutBawaan="-LunasPada"
                cari="Cari nomor QRIS"
                saring={saring}
                labelBaris={(t) => `tagihan QRIS ${t.NomorPesanan}`}
                aksiBaris={(t: BarisQrisTanpaPenjualan) => (
                    <ItemAksiBaris
                        aksi={
                            !Izin.Kelola
                                ? []
                                : t.Ditutup
                                  ? [{ label: 'Buka kembali penutupan', saatPilih: () => AturDibuka(t) }]
                                  : [{ label: 'Tutup tagihan', saatPilih: () => AturDitutup(t) }]
                        }
                    />
                )}
                kosong={{
                    ilustrasi: true,
                    judul: 'Tidak ada uang QRIS yang masuk tanpa penjualan.',
                }}
            />
            {ditutup ? (
                <DialogTutup
                    tagihan={ditutup}
                    opsiAkun={OpsiAkun}
                    opsiHasil={OpsiHasil}
                    hariIni={HariIni}
                    saatTutup={() => AturDitutup(null)}
                />
            ) : null}
            {dibuka ? (
                <DialogAlasan
                    judul={`Buka kembali penutupan ${dibuka.NomorPesanan}?`}
                    keterangan="Pengakuan pendapatan (bila ada) dibalik dan tagihan kembali menunggu keputusan."
                    labelAksi="Buka kembali"
                    alamat={`${alamat}/${dibuka.Uuid}/buka-kembali`}
                    saatTutup={() => AturDibuka(null)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}
