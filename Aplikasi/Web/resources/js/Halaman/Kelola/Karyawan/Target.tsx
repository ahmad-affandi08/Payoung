import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import { Progress } from '@/Komponen/Ui/progress';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisTargetPenjualan, CakupanTargetPenjualan, PropsTargetPenjualan } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/target';

function FormatPersenProgres(persen: string): string {
    return `${persen.replace('.', ',').replace(/,0$/, '')}%`;
}

function Kolom(berjalan: boolean): KolomTabel<BarisTargetPenjualan>[] {
    return [
        {
            id: 'NamaSasaran',
            accessorKey: 'NamaSasaran',
            header: 'Sasaran',
            meta: { label: 'Sasaran', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
            cell: ({ row }) => (
                <div className="flex flex-col">
                    <span>{row.original.NamaSasaran}</span>
                    <span className="text-keterangan text-teks-sekunder">{row.original.LabelCakupan}</span>
                </div>
            ),
        },
        {
            id: 'Nilai',
            accessorKey: 'Nilai',
            header: 'Target',
            enableSorting: false,
            meta: { label: 'Target', prioritas: 'rendah', angka: true },
            cell: ({ row }) => FormatRupiah(row.original.Nilai),
        },
        {
            id: 'Realisasi',
            accessorKey: 'Realisasi',
            header: 'Realisasi',
            enableSorting: false,
            meta: { label: 'Realisasi', prioritas: 'penting', angka: true },
            cell: ({ row }) => FormatRupiah(row.original.Realisasi),
        },
        {
            id: 'Persen',
            accessorKey: 'Persen',
            header: 'Progres',
            enableSorting: false,
            meta: { label: 'Progres', prioritas: 'penting' },
            cell: ({ row }) => (
                <div className="flex min-w-32 items-center gap-2">
                    <Progress
                        value={Math.min(100, Number(row.original.Persen))}
                        aria-label={`Progres ${row.original.NamaSasaran}`}
                        className="h-2 flex-1"
                    />
                    <span className="w-14 text-right tabular-nums">{FormatPersenProgres(row.original.Persen)}</span>
                </div>
            ),
        },
        {
            id: 'Sisa',
            accessorKey: 'Sisa',
            header: 'Kurang',
            enableSorting: false,
            meta: { label: 'Kurang', prioritas: 'rendah', angka: true },
            cell: ({ row }) => (row.original.Sisa === '0.00' ? 'Tercapai' : FormatRupiah(row.original.Sisa)),
        },
        ...(berjalan
            ? [
                  {
                      id: 'Proyeksi',
                      accessorKey: 'Proyeksi',
                      header: 'Proyeksi akhir bulan',
                      enableSorting: false,
                      meta: { label: 'Proyeksi akhir bulan', prioritas: 'rendah', angka: true },
                      cell: ({ row }) => (row.original.Proyeksi === null ? '—' : FormatRupiah(row.original.Proyeksi)),
                  } satisfies KolomTabel<BarisTargetPenjualan>,
              ]
            : []),
    ];
}

type Dialog =
    { jenis: 'simpan'; target: BarisTargetPenjualan | null } | { jenis: 'hapus'; target: BarisTargetPenjualan };

/**
 * Target penjualan (F-18 bagian 3, EMP-05): target bulanan per outlet atau per karyawan dengan progres. Realisasi
 * outlet = penjualan bersih; realisasi karyawan = nilai baris yang ia layani (staf di baris penjualan), setelah
 * void & retur.
 */
export default function HalamanTargetPenjualan({
    Periode,
    Berjalan,
    OpsiPeriode,
    Target,
    OpsiOutlet,
    OpsiKaryawan,
    Izin,
}: PropsTargetPenjualan) {
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const [menghapus, AturMenghapus] = useState(false);
    const Tutup = () => AturDialog(null);

    const Hapus = (target: BarisTargetPenjualan) => {
        AturMenghapus(true);
        router.delete(`${alamat}/${target.Uuid}`, {
            preserveScroll: true,
            onSuccess: Tutup,
            onFinish: () => AturMenghapus(false),
        });
    };

    return (
        <TataLetakAplikasi judul="Target penjualan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Realisasi outlet adalah penjualan bersih outlet itu. Realisasi karyawan adalah nilai baris penjualan
                yang ia layani (dipilih kasir sebagai staf), dikurangi void dan retur.
            </p>
            <AksiHalaman
                keterangan={
                    <div className="w-full sm:w-64">
                        <BidangPilihan
                            label="Periode"
                            nilai={Periode}
                            opsi={OpsiPeriode}
                            saatBerubah={(periode) => router.get(alamat, { periode }, { preserveState: false })}
                        />
                    </div>
                }
            >
                {Izin.Kelola ? (
                    <Tombol onClick={() => AturDialog({ jenis: 'simpan', target: null })}>Tambah target</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="karyawan-target-penjualan"
                label="Target penjualan"
                kolom={Kolom(Berjalan)}
                sumber={{ mode: 'lokal', data: Target }}
                ambilIdBaris={(t) => t.Uuid}
                labelBaris={(t) => `target ${t.NamaSasaran}`}
                cari="Cari outlet atau karyawan"
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (t: BarisTargetPenjualan) => (
                              <>
                                  <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'simpan', target: t })}>
                                      Ubah target
                                  </DropdownMenuItem>
                                  <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'hapus', target: t })}>
                                      Hapus target
                                  </DropdownMenuItem>
                              </>
                          ),
                      }
                    : {})}
                kosong={{ judul: 'Belum ada target penjualan untuk periode ini.', ilustrasi: true }}
            />
            {dialog?.jenis === 'simpan' ? (
                <FormTarget
                    periode={Periode}
                    target={dialog.target}
                    opsiOutlet={OpsiOutlet}
                    opsiKaryawan={OpsiKaryawan}
                    saatSelesai={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'hapus' ? (
                <DialogKonfirmasi
                    judul={`Hapus target ${dialog.target.NamaSasaran}?`}
                    labelAksi="Hapus target"
                    memproses={menghapus}
                    saatKonfirmasi={() => Hapus(dialog.target)}
                    saatBatal={Tutup}
                >
                    <p>Penjualan tidak berubah. Hanya target periode ini yang dihapus.</p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}

function FormTarget({
    periode,
    target,
    opsiOutlet,
    opsiKaryawan,
    saatSelesai,
}: {
    periode: string;
    target: BarisTargetPenjualan | null;
    opsiOutlet: PropsTargetPenjualan['OpsiOutlet'];
    opsiKaryawan: PropsTargetPenjualan['OpsiKaryawan'];
    saatSelesai: () => void;
}) {
    const formulir = useForm({
        Periode: periode,
        Cakupan: (target?.Cakupan ?? 'Outlet') as CakupanTargetPenjualan,
        Sasaran: target?.UuidSasaran ?? '',
        Nilai: target ? target.Nilai.replace(/\.00$/, '') : '',
    });
    const opsiSasaran = (formulir.data.Cakupan === 'Outlet' ? opsiOutlet : opsiKaryawan).map((o) => ({
        Nilai: o.Uuid,
        Label: o.Nama,
    }));

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(alamat, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={target ? `Ubah target ${target.NamaSasaran}` : 'Tambah target penjualan'}
            keterangan="Satu target per outlet atau karyawan untuk setiap bulan. Menyimpan ulang mengganti nilainya."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                {target === null ? (
                    <>
                        <BidangPilihan
                            label="Target untuk"
                            nilai={formulir.data.Cakupan}
                            opsi={[
                                { Nilai: 'Outlet', Label: 'Outlet' },
                                { Nilai: 'Karyawan', Label: 'Karyawan' },
                            ]}
                            saatBerubah={(nilai) =>
                                formulir.setData({
                                    ...formulir.data,
                                    Cakupan: nilai as CakupanTargetPenjualan,
                                    Sasaran: '',
                                })
                            }
                            galat={formulir.errors.Cakupan}
                            required
                        />
                        <BidangPilihan
                            label={formulir.data.Cakupan === 'Outlet' ? 'Outlet' : 'Karyawan'}
                            nilai={formulir.data.Sasaran}
                            opsi={opsiSasaran}
                            saatBerubah={(nilai) => formulir.setData('Sasaran', nilai)}
                            galat={formulir.errors.Sasaran}
                            required
                        />
                    </>
                ) : null}
                <BidangUang
                    label="Target penjualan"
                    nilai={formulir.data.Nilai}
                    saatBerubah={(nilai) => formulir.setData('Nilai', nilai)}
                    galat={formulir.errors.Nilai}
                    required
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan target
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
