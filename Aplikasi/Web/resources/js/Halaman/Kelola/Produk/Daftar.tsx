import { Link, router, usePage } from '@inertiajs/react';

import AksiMassalProduk from '@/Komponen/Katalog/AksiMassalProduk';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import GambarProduk from '@/Komponen/Katalog/GambarProduk';
import KuotaProduk from '@/Komponen/Katalog/KuotaProduk';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisProduk, PropsDaftarProduk } from '@/Tipe/Katalog';
import { CekBatasPenuh } from '@/Tipe/Organisasi';

const alamat = '/kelola/produk';

const kolom: KolomTabel<BarisProduk>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Produk',
        meta: { label: 'Produk', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: produk } }) => (
            <div className="flex items-start gap-2">
                <GambarProduk
                    nama={produk.Nama}
                    url={produk.UrlGambarKecil}
                    className="hidden size-10 shrink-0 rounded-kontrol border border-garis bg-permukaan-redup sm:flex"
                />
                <div className="min-w-0">
                    <Link href={`${alamat}/${produk.Uuid}`} className="font-semibold break-words text-brand underline">
                        {produk.Nama}
                    </Link>
                    <span className="block text-keterangan font-normal text-teks-sekunder">
                        {[
                            produk.NamaKategori ?? 'Tanpa kategori',
                            produk.Merek,
                            produk.JumlahVarian > 0 ? `${String(produk.JumlahVarian)} varian` : null,
                        ]
                            .filter(Boolean)
                            .join(' | ')}
                    </span>
                </div>
            </div>
        ),
    },
    {
        id: 'Sku',
        accessorKey: 'Sku',
        header: 'SKU',
        meta: { label: 'SKU', prioritas: 'penting', kelasSel: 'font-mono text-label break-all' },
        cell: ({ row }) => row.original.Sku ?? '—',
    },
    {
        id: 'Jenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => row.original.LabelJenis,
    },
    {
        id: 'HargaDasar',
        header: 'Harga jual',
        enableSorting: false,
        meta: { label: 'Harga jual', angka: true, prioritas: 'penting' },
        cell: ({ row: { original: produk } }) =>
            produk.HargaDasar === null ? (
                <span className="text-teks-sekunder">Belum ada harga</span>
            ) : (
                <>
                    {FormatRupiah(produk.HargaDasar)}
                    <span className="text-teks-sekunder"> / {produk.SimbolSatuan}</span>
                </>
            ),
    },
    {
        id: 'TampilDiPos',
        header: 'Kasir',
        enableSorting: false,
        meta: { label: 'Tampil di kasir', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => (row.original.TampilDiPos ? 'Tampil' : 'Tersembunyi'),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.Status === 'Aktif' ? (
                <LabelStatus jenis="sukses" teks="Aktif" />
            ) : (
                <LabelStatus jenis="netral" teks="Diarsipkan" />
            ),
    },
    {
        id: 'DiubahPada',
        accessorKey: 'DiubahPada',
        header: 'Diubah',
        meta: { label: 'Terakhir diubah', prioritas: 'rendah', kelasSel: 'whitespace-nowrap text-teks-sekunder' },
        cell: ({ row }) => FormatTanggalWaktu(row.original.DiubahPada),
    },
];

/** F-03: daftar produk (TabelData D-16) dengan pencarian, saringan, arsip/pulihkan, aksi massal (#19), dan ekspor Excel. */
export default function HalamanDaftarProduk({ Produk, Kategori, Jenis, BatasSku, Izin }: PropsDaftarProduk) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const penuh = CekBatasPenuh(BatasSku);

    const saring: DefinisiSaring[] = [
        {
            id: 'Kategori',
            label: 'Kategori',
            jenis: 'pilihan',
            opsi: Kategori.map((kategori) => ({ nilai: kategori.Uuid, label: kategori.Jalur })),
        },
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihan',
            opsi: Jenis.map((jenis) => ({ nilai: jenis.Nilai, label: jenis.Label })),
        },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihan',
            nilaiBawaan: 'Aktif',
            opsi: [
                { nilai: 'Aktif', label: 'Aktif' },
                { nilai: 'Diarsipkan', label: 'Diarsipkan' },
                { nilai: 'Semua', label: 'Semua status' },
            ],
        },
    ];

    const tombolTambah = penuh ? (
        <Button disabled className="h-8 pointer-coarse:h-11">
            Tambah produk
        </Button>
    ) : (
        <Button asChild className="h-8 pointer-coarse:h-11">
            <Link href={`${alamat}/buat`}>Tambah produk</Link>
        </Button>
    );

    return (
        <TataLetakAplikasi judul="Produk">
            <KuotaProduk batas={BatasSku} bolehKelola={Izin.Kelola} />
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="produk" /> : null}
            <DaftarGalatServer galat={props.errors} />

            <AksiHalaman
                {...(Izin.Kelola
                    ? { utama: tombolTambah, lainnya: [{ label: 'Impor dari Excel', href: `${alamat}/impor` }] }
                    : {})}
            />

            <TabelData
                id="katalog-produk"
                label="Daftar produk"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Produk }}
                ambilIdBaris={(produk) => produk.Uuid}
                urutBawaan="Nama"
                cari="Cari nama, SKU, atau barcode lengkap"
                pindaiKamera
                saring={saring}
                alamatDetail={(produk) => `${alamat}/${produk.Uuid}`}
                ekspor={{ alamat: `${alamat}/ekspor`, label: 'Ekspor ke Excel' }}
                aksiMassal={(konteks: KonteksAksiMassal<BarisProduk>) => (
                    <AksiMassalProduk
                        konteks={konteks}
                        kategori={Kategori}
                        bolehUbahHarga={Izin.UbahHarga}
                        bolehKelola={Izin.Kelola}
                    />
                )}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (produk: BarisProduk) => {
                              const aktif = produk.Status === 'Aktif';

                              return (
                                  <>
                                      <DropdownMenuItem asChild>
                                          <Link href={`${alamat}/${produk.Uuid}`}>Lihat detail</Link>
                                      </DropdownMenuItem>
                                      <DropdownMenuItem asChild>
                                          <Link href={`${alamat}/${produk.Uuid}/ubah`}>Ubah produk</Link>
                                      </DropdownMenuItem>
                                      <DropdownMenuSeparator />
                                      <DropdownMenuItem
                                          onSelect={() =>
                                              router.post(
                                                  `${alamat}/${produk.Uuid}/${aktif ? 'arsipkan' : 'pulihkan'}`,
                                                  {},
                                                  { preserveScroll: true },
                                              )
                                          }
                                      >
                                          {aktif ? 'Arsipkan' : 'Pulihkan'}
                                      </DropdownMenuItem>
                                  </>
                              );
                          },
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada produk. Impor dari Excel atau Tambah produk',
                    ...(Izin.Kelola ? {} : { aksi: <span>Minta pengelola produk menambahkan produk.</span> }),
                }}
            />
        </TataLetakAplikasi>
    );
}
