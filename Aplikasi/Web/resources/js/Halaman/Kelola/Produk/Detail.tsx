import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import BidangGambar from '@/Komponen/Formulir/BidangGambar';
import Tombol from '@/Komponen/Formulir/Tombol';
import { LabelTigaKeadaan } from '@/Komponen/Katalog/BantuanKatalog';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormBatasStok from '@/Komponen/Katalog/FormBatasStok';
import PembuatVarian from '@/Komponen/Katalog/PembuatVarian';
import TabelUbahVarian from '@/Komponen/Katalog/TabelUbahVarian';
import KepalaProduk from '@/Komponen/Katalog/KepalaProduk';
import PanelKetersediaan from '@/Komponen/Katalog/PanelKetersediaan';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Komponen/Ui/alert-dialog';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { FormatMasukanJumlah } from '@/Pustaka/MasukanJumlah';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { labelGolonganObat, type PropsDetailProduk } from '@/Tipe/Katalog';

type Satuan = PropsDetailProduk['Produk']['Satuan'][number];
type Varian = PropsDetailProduk['Varian'][number];

const kolomVarian: KolomTabel<Varian>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Varian',
        meta: { label: 'Varian', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: varian } }) => (
            <>
                <Link href={`/kelola/produk/${varian.Uuid}`} className="font-semibold text-brand underline">
                    {varian.Nama}
                </Link>
                <span className="block text-keterangan text-teks-sekunder">
                    {varian.Atribut.map((a) => `${a.Nama}: ${a.Nilai}`).join(' | ')}
                </span>
            </>
        ),
    },
    {
        id: 'Sku',
        accessorFn: (varian) => varian.Sku ?? '—',
        header: 'SKU',
        meta: { label: 'SKU', prioritas: 'penting', kelasSel: 'font-mono text-label' },
    },
    {
        id: 'HargaDasar',
        header: 'Harga dasar',
        enableSorting: false,
        meta: { label: 'Harga dasar', angka: true, prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.HargaDasar === null ? (
                <span className="text-teks-sekunder">Belum ada harga</span>
            ) : (
                FormatRupiah(row.original.HargaDasar)
            ),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        meta: { label: 'Status', prioritas: 'rendah' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Status === 'Aktif' ? 'sukses' : 'netral'}
                teks={row.original.Status === 'Aktif' ? 'Aktif' : 'Diarsipkan'}
            />
        ),
    },
];

function Baris({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 border-b border-garis py-2 last:border-b-0 sm:flex-row sm:gap-4">
            <dt className="text-label text-teks-sekunder sm:w-48 sm:shrink-0">{label}</dt>
            <dd className="text-isi break-words text-teks-utama">{children}</dd>
        </div>
    );
}

/** F-03 ringkasan produk: info, gambar, satuan & barcode, varian, batas stok, riwayat, arsip/hapus (BR-03.2). */
export default function HalamanDetailProduk({
    Kepala,
    Produk,
    Varian,
    BatasStok,
    Ketersediaan,
    Riwayat,
    Jenis,
    BatasSku,
    Izin,
}: PropsDetailProduk) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [gambar, AturGambar] = useState<File | null>(null);
    const [mengunggah, AturMengunggah] = useState(false);
    const dasar = Produk.SatuanDasar;
    const induk = Produk.Jenis === 'IndukVarian';
    const opsiKirim = { preserveScroll: true };

    const kolomSatuan: KolomTabel<Satuan>[] = [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Satuan',
            meta: { label: 'Satuan', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: satuan } }) => (
                <>
                    <span className="font-semibold text-teks-utama">
                        {satuan.Nama} ({satuan.Simbol})
                    </span>
                    <span className="block text-keterangan text-teks-sekunder">
                        {satuan.BisaDijual ? 'Dijual di kasir' : 'Hanya untuk pembelian (belum ada harga dasar)'}
                    </span>
                </>
            ),
        },
        {
            id: 'KonversiKeDasar',
            header: 'Isi',
            enableSorting: false,
            meta: { label: 'Isi', angka: true, prioritas: 'penting' },
            cell: ({ row }) => `${FormatMasukanJumlah(row.original.KonversiKeDasar)} ${dasar.Simbol}`,
        },
        {
            id: 'Bawaan',
            header: 'Bawaan',
            enableSorting: false,
            meta: { label: 'Bawaan', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
            cell: ({ row: { original: satuan } }) =>
                [satuan.DefaultJual ? 'Jual' : null, satuan.DefaultBeli ? 'Beli' : null].filter(Boolean).join(', ') ||
                '—',
        },
        {
            id: 'Barcode',
            header: 'Barcode',
            enableSorting: false,
            meta: { label: 'Barcode', prioritas: 'penting' },
            cell: ({ row: { original: satuan } }) => (
                <>
                    {satuan.Barcode.length === 0 ? (
                        <span className="text-teks-sekunder">Belum ada</span>
                    ) : (
                        <ul className="flex flex-col gap-0.5">
                            {satuan.Barcode.map((barcode) => (
                                <li key={barcode.Uuid} className="font-mono text-label break-all">
                                    {barcode.Barcode}
                                </li>
                            ))}
                        </ul>
                    )}
                    {Izin.Kelola ? (
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/kelola/produk/${Produk.Uuid}/satuan/${satuan.Uuid}/barcode-internal`,
                                    {},
                                    opsiKirim,
                                )
                            }
                            className="mt-1 h-auto px-0"
                        >
                            Buat barcode internal {satuan.Simbol}
                        </Button>
                    ) : null}
                </>
            ),
        },
    ];

    const Unggah = () => {
        if (gambar === null) {
            return;
        }

        router.post(
            `/kelola/produk/${Produk.Uuid}/gambar`,
            { Gambar: gambar },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => AturMengunggah(true),
                onFinish: () => AturMengunggah(false),
                onSuccess: () => AturGambar(null),
            },
        );
    };

    return (
        <TataLetakAplikasi judul={Produk.Nama}>
            <KepalaProduk kepala={Kepala} tabAktif="Ringkasan" />
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="produk ini" /> : null}
            <DaftarGalatServer galat={props.errors} />

            {Izin.Kelola ? (
                <div className="flex flex-wrap gap-2">
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`/kelola/produk/${Produk.Uuid}/ubah`}>Ubah produk</Link>
                    </Button>
                    {Produk.DiarsipkanPada === null ? (
                        <Tombol
                            varian="sekunder"
                            onClick={() => router.post(`/kelola/produk/${Produk.Uuid}/arsipkan`, {}, opsiKirim)}
                        >
                            Arsipkan produk
                        </Tombol>
                    ) : (
                        <Tombol
                            varian="sekunder"
                            onClick={() => router.post(`/kelola/produk/${Produk.Uuid}/pulihkan`, {}, opsiKirim)}
                        >
                            Pulihkan produk
                        </Tombol>
                    )}
                    {Produk.AlasanTidakBisaDihapus === null ? (
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="destructive" className="h-8 pointer-coarse:h-11">
                                    Hapus produk
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle className="text-subjudul font-semibold text-teks-utama">
                                        Hapus {Produk.Nama}?
                                    </AlertDialogTitle>
                                    <AlertDialogDescription className="text-isi text-teks-sekunder">
                                        Produk, barcode, dan harganya dihapus permanen, dan SKU bisa dipakai produk
                                        lain. Bila produk hanya tidak dijual lagi, pilih Arsipkan.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Batal</AlertDialogCancel>
                                    <AlertDialogAction
                                        variant="destructive"
                                        onClick={() => router.delete(`/kelola/produk/${Produk.Uuid}`)}
                                    >
                                        Ya, hapus produk
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    ) : null}
                </div>
            ) : null}

            {Izin.Kelola && Produk.AlasanTidakBisaDihapus !== null ? (
                <p className="text-keterangan text-teks-sekunder">
                    Produk ini tidak bisa dihapus: {Produk.AlasanTidakBisaDihapus}. Arsipkan bila tidak dijual lagi.
                </p>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <Panel judul="Informasi produk" idJudul="judul-info" className="lg:col-span-2">
                    <dl>
                        <Baris label="Nama di struk">{Produk.NamaStruk ?? 'Sama dengan nama produk'}</Baris>
                        <Baris label="SKU">
                            <span className="font-mono">{Produk.Sku ?? '—'}</span>
                        </Baris>
                        <Baris label="Jenis">{Produk.LabelJenis}</Baris>
                        <Baris label="Kategori">{Produk.NamaKategori ?? 'Tanpa kategori'}</Baris>
                        <Baris label="Merek">{Produk.Merek ?? '—'}</Baris>
                        <Baris label="Satuan dasar">
                            {dasar.Nama} ({dasar.Simbol})
                        </Baris>
                        <Baris label="Pelacakan">{Produk.LabelPelacakan}</Baris>
                        <Baris label="Kelompok pajak">
                            {Produk.KelompokPajak
                                ? `${Produk.KelompokPajak.Nama} | ${Produk.KelompokPajak.LabelKategori}`
                                : 'Belum dipilih'}
                        </Baris>
                        <Baris label="Harga termasuk pajak">
                            {LabelTigaKeadaan(Produk.HargaTermasukPajak, 'Ikuti pengaturan outlet')}
                        </Baris>
                        {BatasStok !== null ? (
                            <Baris label="Jual saat stok kosong">
                                {LabelTigaKeadaan(Produk.BolehMinus, 'Ikuti pengaturan usaha')}
                            </Baris>
                        ) : null}
                        <Baris label="Tampil di kasir">{Produk.TampilDiPos ? 'Ya' : 'Tidak'}</Baris>
                        <Baris label="Favorit di kasir">{Produk.Favorit ? 'Ya' : 'Tidak'}</Baris>
                        <Baris label="Tampil di toko online">{Produk.TampilOnline ? 'Ya' : 'Tidak'}</Baris>
                        {Produk.HargaTerbuka ? <Baris label="Harga">Diketik kasir saat menjual</Baris> : null}
                        {Produk.GolonganObat ? (
                            <Baris label="Golongan obat">
                                {labelGolonganObat[Produk.GolonganObat]}
                                {Produk.ObatWajibApotek ? ' (Obat Wajib Apotek)' : ''}
                                {Produk.Prekursor ? ', prekursor' : ''}
                            </Baris>
                        ) : null}
                        <Baris label="Dibuat">{FormatTanggalWaktu(Produk.DibuatPada)}</Baris>
                        <Baris label="Terakhir diubah">{FormatTanggalWaktu(Produk.DiubahPada)}</Baris>
                        {Produk.DiarsipkanPada ? (
                            <Baris label="Diarsipkan">
                                <LabelStatus jenis="netral" teks="Diarsipkan" />{' '}
                                {FormatTanggalWaktu(Produk.DiarsipkanPada)}
                            </Baris>
                        ) : null}
                    </dl>
                </Panel>
                <Panel judul="Gambar" idJudul="judul-gambar">
                    <BidangGambar
                        label="Gambar produk"
                        berkas={gambar}
                        saatBerubah={AturGambar}
                        tautanSaatIni={Produk.UrlGambar}
                        {...(Izin.Kelola
                            ? {
                                  saatHapusSaatIni: () =>
                                      router.delete(`/kelola/produk/${Produk.Uuid}/gambar`, opsiKirim),
                              }
                            : {})}
                        ukuranMaksimalKb={5120}
                        ekstensi={['jpg', 'jpeg', 'png', 'webp']}
                        keterangan="Minimal 200 × 200 piksel, disarankan persegi."
                        galat={props.errors.Gambar}
                        disabled={!Izin.Kelola}
                    />
                    {Izin.Kelola && gambar !== null ? (
                        <div>
                            <Tombol onClick={Unggah} memproses={mengunggah}>
                                Simpan gambar
                            </Tombol>
                        </div>
                    ) : null}
                </Panel>
            </div>

            <Panel
                judul="Satuan & barcode"
                idJudul="judul-satuan"
                keterangan="Barcode internal (EAN-13 berawalan 20) untuk barang tanpa barcode pabrik."
            >
                <TabelData
                    id="katalog-produk-satuan"
                    label="Satuan dan barcode produk"
                    kolom={kolomSatuan}
                    sumber={{ mode: 'lokal', data: Produk.Satuan }}
                    ambilIdBaris={(satuan) => satuan.Uuid}
                    kosong={{ judul: 'Belum ada satuan.' }}
                />
            </Panel>

            {induk ? (
                <Panel judul={`Varian (${String(Varian.length)})`} idJudul="judul-varian">
                    <TabelData
                        id="katalog-produk-varian"
                        label="Daftar varian"
                        kolom={kolomVarian}
                        sumber={{ mode: 'lokal', data: Varian }}
                        ambilIdBaris={(varian) => varian.Uuid}
                        urutBawaan="Nama"
                        cari="Cari nama atau SKU varian"
                        alamatDetail={(varian) => `/kelola/produk/${varian.Uuid}`}
                        kosong={{ judul: 'Belum ada varian. Buat varian dari atribut di bawah.' }}
                    />
                    {Izin.Kelola && Varian.length > 0 ? (
                        <div className="mt-4">
                            <TabelUbahVarian uuidProduk={Produk.Uuid} varian={Varian} bolehUbahHarga={Izin.UbahHarga} />
                        </div>
                    ) : null}
                </Panel>
            ) : null}

            {induk && Izin.Kelola ? (
                <PembuatVarian
                    uuidProduk={Produk.Uuid}
                    atributAwal={Produk.AtributVarian}
                    varian={Varian}
                    jenis={Jenis}
                    batasSku={BatasSku}
                    bolehUbahHarga={Izin.UbahHarga}
                    galat={props.errors}
                />
            ) : null}

            {BatasStok !== null ? (
                <FormBatasStok
                    uuidProduk={Produk.Uuid}
                    baris={BatasStok}
                    simbolSatuan={dasar.Simbol}
                    bolehDesimal={dasar.BolehDesimal}
                    bolehUbah={Izin.KelolaPersediaan}
                    galat={props.errors}
                />
            ) : null}

            {Ketersediaan !== null ? (
                <PanelKetersediaan uuidProduk={Produk.Uuid} baris={Ketersediaan} bolehUbah={Izin.Kelola} />
            ) : null}

            <Panel judul="Riwayat perubahan" idJudul="judul-riwayat">
                {Riwayat.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Belum ada riwayat.</p>
                ) : (
                    <ol className="flex flex-col divide-y divide-garis">
                        {Riwayat.map((item, indeks) => (
                            <li
                                key={`${item.DibuatPada}-${String(indeks)}`}
                                className="flex flex-wrap justify-between gap-2 py-2 text-isi"
                            >
                                <span className="text-teks-utama">{item.Peristiwa}</span>
                                <span className="text-keterangan text-teks-sekunder">
                                    {item.NamaPengguna ?? 'Sistem'} | {FormatTanggalWaktu(item.DibuatPada)}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </Panel>
        </TataLetakAplikasi>
    );
}
