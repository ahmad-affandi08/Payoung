import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormKelompokPilihan, { AmbilPilihanTampil, RingkasAturanPilih } from '@/Komponen/Katalog/FormKelompokPilihan';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { BandingkanDesimal, CekDesimalValid, FormatJumlahSatuan } from '@/Pustaka/MasukanJumlah';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDaftarKelompokPilihan } from '@/Tipe/Katalog';

type Kelompok = PropsDaftarKelompokPilihan['KelompokPilihan'][number];
/** Rincian pilihan di satu kelompok: nama, bahan yang dipotong, dan tambahan harga. */
function DaftarPilihan({ kelompok }: { kelompok: Kelompok }) {
    return (
        <ul className="flex flex-col gap-1 text-label" aria-label={`Pilihan di ${kelompok.Nama}`}>
            {AmbilPilihanTampil(kelompok).map((pilihan) => (
                <li
                    key={pilihan.Uuid ?? pilihan.Nama}
                    className="flex flex-wrap items-baseline justify-between gap-x-3"
                >
                    <span className="text-teks-utama">
                        {pilihan.Nama} {!pilihan.Aktif ? <LabelStatus jenis="netral" teks="Nonaktif" /> : null}
                        {pilihan.NamaProdukBahan ? (
                            <span className="block text-keterangan text-teks-sekunder">
                                {`${pilihan.NamaProdukBahan} ${FormatJumlahSatuan(pilihan.Jumlah, pilihan.SimbolSatuanBahan ?? '')}`}
                            </span>
                        ) : null}
                    </span>
                    <span className="tabular-nums">
                        {CekDesimalValid(pilihan.Harga) && BandingkanDesimal(pilihan.Harga, '0') === 0
                            ? 'Gratis'
                            : `+${FormatRupiah(pilihan.Harga)}`}
                    </span>
                </li>
            ))}
        </ul>
    );
}

const kolom: KolomTabel<Kelompok>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Kelompok',
        meta: { label: 'Kelompok', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: kelompok } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{kelompok.Nama}</span>
                <span className="block text-keterangan text-teks-sekunder">
                    {RingkasAturanPilih(kelompok.MinimalPilih, kelompok.MaksimalPilih)} | dipakai{' '}
                    {kelompok.JumlahProduk} produk
                </span>
            </>
        ),
    },
    {
        id: 'Pilihan',
        header: 'Pilihan',
        enableSorting: false,
        meta: { label: 'Pilihan', prioritas: 'penting', kelasSel: 'min-w-64' },
        cell: ({ row }) => <DaftarPilihan kelompok={row.original} />,
    },
    {
        id: 'JumlahProduk',
        accessorKey: 'JumlahProduk',
        header: 'Produk',
        meta: { label: 'Jumlah produk', angka: true, prioritas: 'rendah' },
    },
];

/** F-03 kelompok pilihan (modifier) dengan harga tambahan dan bahan opsional untuk potong stok. */
export default function HalamanDaftarKelompokPilihan({ KelompokPilihan, Izin }: PropsDaftarKelompokPilihan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [sunting, AturSunting] = useState<Kelompok | null>(null);
    const [hapus, AturHapus] = useState<Kelompok | null>(null);

    return (
        <TataLetakAplikasi judul="Pilihan (modifier)">
            <p className="text-keterangan text-teks-sekunder">
                Pilihan yang ditanyakan kasir saat menjual, misal Level gula atau Topping. Pasang ke produk di tab
                Pilihan.
            </p>
            {!Izin.Kelola ? <PesanHanyaLihat izin="produk.kelola" objek="kelompok pilihan" /> : null}
            <DaftarGalatServer galat={props.errors} kecuali={sunting !== null ? Object.keys(props.errors) : []} />
            <AksiHalaman>
                {Izin.Kelola ? (
                    <Button asChild>
                        <Link href="/kelola/kelompok-pilihan/buat">Tambah kelompok pilihan</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <Sheet open={sunting !== null} onOpenChange={(buka) => (buka ? undefined : AturSunting(null))}>
                {sunting !== null ? (
                    <SheetContent className="w-full overflow-y-auto sm:max-w-2xl">
                        <SheetHeader>
                            <SheetTitle>Ubah kelompok {sunting.Nama}</SheetTitle>
                            <SheetDescription>
                                Atur batas pilih, harga tambahan, dan bahan yang dipotong dari stok.
                            </SheetDescription>
                        </SheetHeader>
                        <div className="px-4 pb-4">
                            <FormKelompokPilihan
                                key={sunting.Uuid}
                                kelompok={sunting}
                                bolehUbahHarga={Izin.UbahHarga}
                                saatSelesai={() => AturSunting(null)}
                                saatBatal={() => AturSunting(null)}
                            />
                        </div>
                    </SheetContent>
                ) : null}
            </Sheet>
            {hapus !== null ? (
                <DialogKonfirmasi
                    judul={`Hapus kelompok ${hapus.Nama}?`}
                    labelAksi="Ya, hapus kelompok"
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`/kelola/kelompok-pilihan/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onFinish: () => AturHapus(null),
                        })
                    }
                >
                    Kelompok ini dilepas dari {hapus.JumlahProduk} produk. Transaksi lama tidak berubah.
                </DialogKonfirmasi>
            ) : null}
            <TabelData
                id="katalog-kelompok-pilihan"
                label="Daftar kelompok pilihan"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: KelompokPilihan }}
                ambilIdBaris={(kelompok) => kelompok.Uuid}
                cari="Cari nama kelompok"
                labelBaris={(kelompok) => kelompok.Nama}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (kelompok: Kelompok) => (
                              <>
                                  <DropdownMenuItem onSelect={() => AturSunting(kelompok)}>
                                      Ubah kelompok
                                  </DropdownMenuItem>
                                  <DropdownMenuItem variant="destructive" onSelect={() => AturHapus(kelompok)}>
                                      Hapus kelompok
                                  </DropdownMenuItem>
                              </>
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kelompok pilihan. Tambah kelompok, misal Level gula: Normal, Kurang manis, Tanpa gula.',
                }}
            />
        </TataLetakAplikasi>
    );
}
