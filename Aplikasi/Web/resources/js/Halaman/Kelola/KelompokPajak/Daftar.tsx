import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormKelompokPajak from '@/Komponen/Katalog/FormKelompokPajak';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Komponen/Ui/sheet';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsDaftarKelompokPajak } from '@/Tipe/Katalog';

type KelompokPajak = PropsDaftarKelompokPajak['KelompokPajak'][number];

const kolom: KolomTabel<KelompokPajak>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Kelompok',
        meta: { label: 'Kelompok', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: item } }) => (
            <>
                <span className="block font-semibold break-words text-teks-utama">{item.Nama}</span>
                <span className="text-keterangan text-teks-sekunder">{item.LabelKategori}</span>
            </>
        ),
    },
    {
        id: 'Kategori',
        accessorKey: 'Kategori',
        header: 'Kategori pajak',
        meta: { label: 'Kategori pajak', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => row.original.LabelKategori,
    },
    {
        id: 'Pajak',
        header: 'Pajak',
        enableSorting: false,
        meta: { label: 'Pajak', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: item } }) =>
            item.Pajak.length === 0 ? (
                'Tanpa pajak'
            ) : (
                <ol className="flex flex-col gap-0.5">
                    {item.Pajak.map((pajak) => (
                        <li key={pajak.KodeJenisPajak}>
                            {pajak.NamaJenisPajak} | {pajak.LabelDasarPengenaan}
                        </li>
                    ))}
                </ol>
            ),
    },
    {
        id: 'JumlahProduk',
        accessorKey: 'JumlahProduk',
        header: 'Produk',
        meta: { label: 'Jumlah produk', angka: true, prioritas: 'penting' },
    },
];

/** F-03 kelompok pajak produk (kategori PPN/PBJT/bebas/non-pajak/lainnya). Ubah butuh izin akuntansi.kelola. */
export default function HalamanDaftarKelompokPajak(propsHalaman: PropsDaftarKelompokPajak) {
    const { KelompokPajak, Izin } = propsHalaman;
    const { props } = usePage<PropsBersamaAplikasi>();
    const [sunting, AturSunting] = useState<KelompokPajak | null>(null);

    return (
        <TataLetakAplikasi judul="Kelompok pajak">
            {!Izin.KelolaPajak ? <PesanHanyaLihat izin="akuntansi.kelola" objek="kelompok pajak" /> : null}
            <DaftarGalatServer galat={props.errors} kecuali={sunting !== null ? Object.keys(props.errors) : []} />
            <AksiHalaman
                keterangan={
                    <p className="max-w-2xl text-isi text-teks-sekunder">
                        Setiap produk memakai satu kelompok pajak. PBJT makanan dan minuman serta PPN tidak boleh
                        bersamaan pada satu produk.
                    </p>
                }
            >
                {Izin.KelolaPajak ? (
                    <Button asChild>
                        <Link href="/kelola/kelompok-pajak/buat">Tambah kelompok pajak</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <Sheet open={sunting !== null} onOpenChange={(buka) => (buka ? undefined : AturSunting(null))}>
                {sunting !== null ? (
                    <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                        <SheetHeader>
                            <SheetTitle>Ubah kelompok pajak {sunting.Nama}</SheetTitle>
                            <SheetDescription>
                                PBJT makanan & minuman dan PPN tidak boleh dikenakan bersamaan pada satu produk.
                            </SheetDescription>
                        </SheetHeader>
                        <div className="px-4 pb-4">
                            <FormKelompokPajak
                                key={sunting.Uuid}
                                kelompok={sunting}
                                props={propsHalaman}
                                saatSelesai={() => AturSunting(null)}
                                saatBatal={() => AturSunting(null)}
                            />
                        </div>
                    </SheetContent>
                ) : null}
            </Sheet>
            <TabelData
                id="katalog-kelompok-pajak"
                label="Daftar kelompok pajak"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: KelompokPajak }}
                ambilIdBaris={(item) => item.Uuid}
                urutBawaan="Nama"
                cari="Cari nama kelompok pajak"
                saring={[
                    {
                        id: 'Kategori',
                        label: 'Kategori pajak',
                        jenis: 'pilihanBanyak',
                        opsi: propsHalaman.Kategori.map((o) => ({ nilai: o.Nilai, label: o.Label })),
                    },
                ]}
                labelBaris={(item) => item.Nama}
                {...(Izin.KelolaPajak
                    ? {
                          aksiBaris: (item: KelompokPajak) => (
                              <DropdownMenuItem onSelect={() => AturSunting(item)}>
                                  Ubah kelompok pajak
                              </DropdownMenuItem>
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada kelompok pajak. Tambah kelompok pajak sebelum menambah produk yang dijual.',
                }}
            />
        </TataLetakAplikasi>
    );
}
