import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiMassalGaji from '@/Komponen/Karyawan/AksiMassalGaji';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData, { type KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import TombolEkspor from '@/Komponen/Laporan/TombolEkspor';
import { Button } from '@/Komponen/Ui/button';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { JumlahkanDesimal } from '@/Pustaka/HitungDesimal';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisGajiKaryawan, OpsiAkunGaji, PropsDetailRekapGaji } from '@/Tipe/Karyawan';

const alamatDaftar = '/kelola/karyawan/gaji';

/** Akun beban gaji bawaan bagan akun awal. */
const KODE_BEBAN_GAJI = '6-1000';

function Rupiah(nilai: string) {
    return <span className="tabular-nums">{FormatRupiah(nilai)}</span>;
}

const kolom: KolomTabel<BarisGajiKaryawan>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Karyawan',
        meta: { label: 'Karyawan', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
        cell: ({ row }) => (
            <div className="flex flex-col">
                <span>{row.original.Nama}</span>
                {row.original.Catatan ? (
                    <span className="text-keterangan text-teks-sekunder">{row.original.Catatan}</span>
                ) : null}
            </div>
        ),
    },
    {
        id: 'GajiPokok',
        accessorKey: 'GajiPokok',
        header: 'Gaji pokok',
        enableSorting: false,
        meta: { label: 'Gaji pokok', prioritas: 'rendah', angka: true },
        cell: ({ row }) => Rupiah(row.original.GajiPokok),
    },
    {
        id: 'Komisi',
        accessorKey: 'Komisi',
        header: 'Komisi',
        enableSorting: false,
        meta: { label: 'Komisi', prioritas: 'rendah', angka: true },
        cell: ({ row }) => Rupiah(row.original.Komisi),
    },
    {
        id: 'Tambahan',
        accessorKey: 'Tambahan',
        header: 'Tambahan',
        enableSorting: false,
        meta: { label: 'Tambahan', prioritas: 'rendah', angka: true },
        cell: ({ row }) => Rupiah(row.original.Tambahan),
    },
    {
        id: 'Lembur',
        accessorKey: 'Lembur',
        header: 'Lembur',
        enableSorting: false,
        meta: { label: 'Lembur', prioritas: 'rendah', angka: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col items-end">
                <span>{Rupiah(b.Lembur)}</span>
                {b.LemburMenit > 0 ? (
                    <span className="text-keterangan text-teks-sekunder">{b.LemburMenit} menit</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'PotonganKehadiran',
        header: 'Potongan kehadiran',
        enableSorting: false,
        meta: { label: 'Potongan terlambat & tidak masuk', prioritas: 'rendah', angka: true },
        cell: ({ row: { original: b } }) => (
            <span className="flex flex-col items-end">
                <span>{Rupiah(JumlahkanDesimal([b.PotonganTerlambat, b.PotonganTidakMasuk]))}</span>
                {b.TerlambatMenit > 0 || b.HariTidakMasuk > 0 ? (
                    <span className="text-keterangan text-teks-sekunder">
                        {b.TerlambatMenit} menit terlambat | {b.HariTidakMasuk} hari tidak masuk
                    </span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'PotonganKasbon',
        accessorKey: 'PotonganKasbon',
        header: 'Potongan kasbon',
        enableSorting: false,
        meta: { label: 'Potongan kasbon', prioritas: 'rendah', angka: true },
        cell: ({ row }) => Rupiah(row.original.PotonganKasbon),
    },
    {
        id: 'PotonganLain',
        accessorKey: 'PotonganLain',
        header: 'Potongan lain',
        enableSorting: false,
        meta: { label: 'Potongan lain', prioritas: 'rendah', angka: true },
        cell: ({ row }) => Rupiah(row.original.PotonganLain),
    },
    {
        id: 'Bersih',
        accessorKey: 'Bersih',
        header: 'Gaji bersih',
        enableSorting: false,
        meta: { label: 'Gaji bersih', prioritas: 'penting', angka: true },
        cell: ({ row }) => Rupiah(row.original.Bersih),
    },
];

type Dialog = { jenis: 'ubah'; baris: BarisGajiKaryawan } | { jenis: 'bayar' } | { jenis: 'hapus' };

/**
 * Rincian rekap gaji (F-18 bagian 3). Draf: ubah tambahan & potongan per karyawan, bayar (jurnal gaji + potong
 * kasbon), atau hapus. Setelah dibayar hanya bisa dilihat dan diekspor. Slip gaji per karyawan bisa dicetak kapan saja
 * (v3.35; draf bertanda DRAF).
 */
export default function HalamanDetailRekapGaji({ Rekap, Baris, OpsiAkunKasBank, OpsiAkunBeban }: PropsDetailRekapGaji) {
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const [menghapus, AturMenghapus] = useState(false);
    const draf = Rekap.Status === 'Draf';
    const alamat = `${alamatDaftar}/${Rekap.Uuid}`;
    const Tutup = () => AturDialog(null);

    const Hapus = () => {
        AturMenghapus(true);
        router.delete(alamat, { onFinish: () => AturMenghapus(false) });
    };

    return (
        <TataLetakAplikasi judul={`Rekap gaji ${Rekap.LabelPeriode}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button asChild variant="link" className="h-auto px-0">
                    <Link href={alamatDaftar}>Kembali ke rekap gaji</Link>
                </Button>
                <div className="flex flex-wrap gap-2">
                    <TombolEkspor alamat={`${alamat}/ekspor`} />
                    {Baris.length > 0 ? (
                        <Button
                            asChild
                            variant="outline"
                            className="h-8 pointer-coarse:h-11 px-4 text-label font-semibold"
                        >
                            <a href={`${alamat}/slip`} target="_blank" rel="noreferrer">
                                Cetak semua slip
                            </a>
                        </Button>
                    ) : null}
                    {draf ? (
                        <>
                            <Tombol varian="sekunder" onClick={() => AturDialog({ jenis: 'hapus' })}>
                                Hapus draf
                            </Tombol>
                            <Tombol onClick={() => AturDialog({ jenis: 'bayar' })} disabled={Baris.length === 0}>
                                Bayar gaji
                            </Tombol>
                        </>
                    ) : null}
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-isi sm:grid-cols-4">
                <div className="flex flex-col gap-1">
                    <dt className="text-teks-sekunder">Status</dt>
                    <dd>
                        <LabelStatus jenis={draf ? 'peringatan' : 'sukses'} teks={Rekap.LabelStatus} />
                    </dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-teks-sekunder">Gaji kotor</dt>
                    <dd className="text-teks-utama">{Rupiah(Rekap.TotalKotor)}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-teks-sekunder">Potongan</dt>
                    <dd className="text-teks-utama">{Rupiah(Rekap.TotalPotongan)}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-teks-sekunder">Gaji bersih dibayar</dt>
                    <dd className="font-semibold text-teks-utama">{Rupiah(Rekap.TotalBersih)}</dd>
                </div>
                {!draf ? (
                    <>
                        <div className="flex flex-col gap-1">
                            <dt className="text-teks-sekunder">Tanggal bayar</dt>
                            <dd>{FormatTanggal(Rekap.TanggalBayar)}</dd>
                        </div>
                        <div className="flex flex-col gap-1">
                            <dt className="text-teks-sekunder">Dibayar dari</dt>
                            <dd>{Rekap.AkunKasBank ?? '—'}</dd>
                        </div>
                        <div className="flex flex-col gap-1">
                            <dt className="text-teks-sekunder">Akun beban</dt>
                            <dd>{Rekap.AkunBeban ?? '—'}</dd>
                        </div>
                        <div className="flex flex-col gap-1">
                            <dt className="text-teks-sekunder">Jurnal</dt>
                            <dd>
                                {Rekap.Jurnal ? (
                                    <Link
                                        href={`/kelola/akuntansi/jurnal/${Rekap.Jurnal.Uuid}`}
                                        className="font-mono text-brand underline"
                                    >
                                        {Rekap.Jurnal.Nomor}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </div>
                    </>
                ) : null}
            </dl>

            <TabelData
                id="karyawan-rekap-gaji-baris"
                label="Gaji per karyawan"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Baris }}
                ambilIdBaris={(b) => b.UuidKaryawan}
                labelBaris={(b) => `gaji ${b.Nama}`}
                cari="Cari nama karyawan"
                {...(draf
                    ? {
                          aksiMassal: (konteks: KonteksAksiMassal<BarisGajiKaryawan>) => (
                              <AksiMassalGaji konteks={konteks} alamat={alamat} />
                          ),
                      }
                    : {})}
                aksiBaris={(b: BarisGajiKaryawan) => (
                    <>
                        {draf ? (
                            <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'ubah', baris: b })}>
                                Ubah tambahan & potongan
                            </DropdownMenuItem>
                        ) : null}
                        <DropdownMenuItem asChild>
                            <a href={`${alamat}/slip?karyawan=${b.UuidKaryawan}`} target="_blank" rel="noreferrer">
                                Cetak slip gaji
                            </a>
                        </DropdownMenuItem>
                    </>
                )}
                kosong={{ judul: 'Tidak ada karyawan dengan gaji pokok atau komisi di periode ini.' }}
            />

            {dialog?.jenis === 'ubah' ? (
                <FormUbahBaris alamat={alamat} baris={dialog.baris} saatSelesai={Tutup} />
            ) : null}
            {dialog?.jenis === 'bayar' ? (
                <FormBayar
                    alamat={alamat}
                    totalBersih={Rekap.TotalBersih}
                    opsiKasBank={OpsiAkunKasBank}
                    opsiBeban={OpsiAkunBeban}
                    saatSelesai={Tutup}
                />
            ) : null}
            {dialog?.jenis === 'hapus' ? (
                <DialogKonfirmasi
                    judul={`Hapus draf rekap gaji ${Rekap.LabelPeriode}?`}
                    labelAksi="Hapus draf"
                    memproses={menghapus}
                    saatKonfirmasi={Hapus}
                    saatBatal={Tutup}
                >
                    <p>Tambahan dan potongan yang sudah diisi ikut terhapus. Draf bisa dibuat ulang kapan saja.</p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}

function TanpaDesimalNol(nilai: string): string {
    return nilai.replace(/\.00$/, '');
}

function FormUbahBaris({
    alamat,
    baris,
    saatSelesai,
}: {
    alamat: string;
    baris: BarisGajiKaryawan;
    saatSelesai: () => void;
}) {
    const formulir = useForm({
        Tambahan: TanpaDesimalNol(baris.Tambahan),
        Lembur: TanpaDesimalNol(baris.Lembur),
        PotonganTerlambat: TanpaDesimalNol(baris.PotonganTerlambat),
        PotonganTidakMasuk: TanpaDesimalNol(baris.PotonganTidakMasuk),
        PotonganKasbon: TanpaDesimalNol(baris.PotonganKasbon),
        PotonganLain: TanpaDesimalNol(baris.PotonganLain),
        Catatan: baris.Catatan ?? '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`${alamat}/baris/${baris.UuidKaryawan}`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Gaji ${baris.Nama}`}
            keterangan={`Gaji pokok ${FormatRupiah(baris.GajiPokok)} + komisi ${FormatRupiah(baris.Komisi)}. Sisa kasbon ${FormatRupiah(baris.SisaKasbon)}.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangUang
                    label="Tambahan (lembur, tunjangan)"
                    nilai={formulir.data.Tambahan}
                    saatBerubah={(nilai) => formulir.setData('Tambahan', nilai)}
                    galat={formulir.errors.Tambahan}
                    required
                />
                <BidangUang
                    label={`Lembur${baris.LemburMenit > 0 ? ` (${String(baris.LemburMenit)} menit terhitung)` : ''}`}
                    nilai={formulir.data.Lembur}
                    saatBerubah={(nilai) => formulir.setData('Lembur', nilai)}
                    galat={formulir.errors.Lembur}
                    required
                />
                <BidangUang
                    label={`Potongan terlambat${baris.TerlambatMenit > 0 ? ` (${String(baris.TerlambatMenit)} menit)` : ''}`}
                    nilai={formulir.data.PotonganTerlambat}
                    saatBerubah={(nilai) => formulir.setData('PotonganTerlambat', nilai)}
                    galat={formulir.errors.PotonganTerlambat}
                    required
                />
                <BidangUang
                    label={`Potongan tidak masuk${baris.HariTidakMasuk > 0 ? ` (${String(baris.HariTidakMasuk)} hari)` : ''}`}
                    nilai={formulir.data.PotonganTidakMasuk}
                    saatBerubah={(nilai) => formulir.setData('PotonganTidakMasuk', nilai)}
                    galat={formulir.errors.PotonganTidakMasuk}
                    required
                />
                <BidangUang
                    label="Potongan kasbon"
                    nilai={formulir.data.PotonganKasbon}
                    saatBerubah={(nilai) => formulir.setData('PotonganKasbon', nilai)}
                    galat={formulir.errors.PotonganKasbon}
                    required
                />
                <BidangUang
                    label="Potongan lain"
                    nilai={formulir.data.PotonganLain}
                    saatBerubah={(nilai) => formulir.setData('PotonganLain', nilai)}
                    galat={formulir.errors.PotonganLain}
                    required
                />
                <BidangTeks
                    label="Catatan (opsional)"
                    nilai={formulir.data.Catatan}
                    saatBerubah={(nilai) => formulir.setData('Catatan', nilai)}
                    galat={formulir.errors.Catatan}
                    maxLength={255}
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan gaji
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function KeOpsi(daftar: OpsiAkunGaji[]) {
    return daftar.map((a) => ({ Nilai: a.Uuid, Label: a.Nama }));
}

function FormBayar({
    alamat,
    totalBersih,
    opsiKasBank,
    opsiBeban,
    saatSelesai,
}: {
    alamat: string;
    totalBersih: string;
    opsiKasBank: OpsiAkunGaji[];
    opsiBeban: OpsiAkunGaji[];
    saatSelesai: () => void;
}) {
    const hariIni = TulisTanggal(new Date());
    const formulir = useForm({
        Tanggal: hariIni,
        AkunKasBank: opsiKasBank[0]?.Uuid ?? '',
        AkunBeban: (opsiBeban.find((a) => a.Kode === KODE_BEBAN_GAJI) ?? opsiBeban[0])?.Uuid ?? '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/bayar`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Bayar gaji"
            keterangan={`Gaji bersih ${FormatRupiah(totalBersih)} keluar dari akun kas/bank. Potongan kasbon dicatat sebagai pelunasan kasbon. Setelah dibayar, rekap tidak bisa diubah.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <PemilihTanggal
                    label="Tanggal bayar"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    max={hariIni}
                    required
                />
                <BidangPilihan
                    label="Dibayar dari"
                    nilai={formulir.data.AkunKasBank}
                    opsi={KeOpsi(opsiKasBank)}
                    saatBerubah={(nilai) => formulir.setData('AkunKasBank', nilai)}
                    galat={formulir.errors.AkunKasBank}
                    required
                />
                <div className="sm:col-span-2">
                    <BidangPilihan
                        label="Akun beban gaji"
                        nilai={formulir.data.AkunBeban}
                        opsi={KeOpsi(opsiBeban)}
                        saatBerubah={(nilai) => formulir.setData('AkunBeban', nilai)}
                        galat={formulir.errors.AkunBeban}
                        required
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Bayar dan jurnal
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
