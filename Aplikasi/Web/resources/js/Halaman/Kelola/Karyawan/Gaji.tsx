import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisRekapGaji, PropsDaftarRekapGaji, SiklusGaji } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/gaji';

const kolom: KolomTabel<BarisRekapGaji>[] = [
    {
        id: 'TanggalMulai',
        accessorKey: 'TanggalMulai',
        header: 'Periode',
        meta: { label: 'Periode', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
        cell: ({ row }) => row.original.LabelPeriode,
    },
    {
        id: 'Siklus',
        accessorKey: 'Siklus',
        header: 'Siklus',
        enableSorting: false,
        meta: { label: 'Siklus', prioritas: 'penting' },
        cell: ({ row }) => row.original.LabelSiklus,
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Status === 'Dibayar' ? 'sukses' : 'peringatan'}
                teks={row.original.LabelStatus}
            />
        ),
    },
    {
        id: 'TotalKotor',
        accessorKey: 'TotalKotor',
        header: 'Gaji kotor',
        enableSorting: false,
        meta: { label: 'Gaji kotor', prioritas: 'rendah', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.TotalKotor),
    },
    {
        id: 'TotalPotongan',
        accessorKey: 'TotalPotongan',
        header: 'Potongan',
        enableSorting: false,
        meta: { label: 'Potongan', prioritas: 'rendah', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.TotalPotongan),
    },
    {
        id: 'TotalBersih',
        accessorKey: 'TotalBersih',
        header: 'Gaji bersih',
        meta: { label: 'Gaji bersih', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.TotalBersih),
    },
    {
        id: 'TanggalBayar',
        accessorKey: 'TanggalBayar',
        header: 'Tanggal bayar',
        enableSorting: false,
        meta: { label: 'Tanggal bayar', prioritas: 'rendah' },
        cell: ({ row }) => (row.original.TanggalBayar === null ? '—' : FormatTanggal(row.original.TanggalBayar)),
    },
];

/**
 * Rekap gaji (F-18 bagian 3, D-219): satu rekap per bulan (karyawan bergaji bulanan) atau per tujuh hari (karyawan
 * bergaji mingguan) berisi gaji pokok atau upah harian, komisi bersih, tambahan, dan potongan kasbon per karyawan.
 * Draf bisa diubah; setelah dibayar dijurnal dan tidak bisa diubah lagi.
 */
export default function HalamanRekapGaji({
    Rekap,
    OpsiPeriode,
    OpsiStatus,
    OpsiSiklus,
    UsulanMingguan,
}: PropsDaftarRekapGaji) {
    const [buat, AturBuat] = useState(false);
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((s) => ({ nilai: s.Nilai, label: s.Label })),
        },
        {
            id: 'Siklus',
            label: 'Siklus',
            jenis: 'pilihanBanyak',
            opsi: OpsiSiklus.map((s) => ({ nilai: s.Nilai, label: s.Label })),
        },
    ];

    return (
        <TataLetakAplikasi judul="Rekap gaji">
            <AksiHalaman
                keterangan={
                    <p className="max-w-3xl text-isi text-teks-sekunder">
                        Gaji pokok diambil dari data karyawan dan komisi dari laporan komisi periode itu. Karyawan
                        bergaji bulanan masuk rekap bulanan, yang bergaji mingguan masuk rekap tujuh hari. Sisa kasbon
                        dipotong otomatis dan bisa diubah sebelum gaji dibayar.
                    </p>
                }
            >
                <Tombol onClick={() => AturBuat(true)}>Buat rekap gaji</Tombol>
            </AksiHalaman>
            {UsulanMingguan.JumlahKaryawan > 0 && UsulanMingguan.SudahLewat ? (
                <Pemberitahuan jenis="info">
                    Rekap gaji mingguan {UsulanMingguan.LabelUsulan} untuk {UsulanMingguan.JumlahKaryawan} karyawan
                    sudah bisa dibuat.
                </Pemberitahuan>
            ) : null}
            <TabelData
                id="karyawan-rekap-gaji"
                label="Daftar rekap gaji"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Rekap }}
                ambilIdBaris={(r) => r.Uuid}
                labelBaris={(r) => `rekap gaji ${r.LabelPeriode}`}
                alamatDetail={(r) => `${alamat}/${r.Uuid}`}
                urutBawaan="-TanggalMulai"
                cari="Cari periode, misal 2026-09"
                saring={saring}
                kosong={{ judul: 'Belum ada rekap gaji.', ilustrasi: true }}
            />
            {buat ? (
                <FormBuat
                    opsiPeriode={OpsiPeriode}
                    opsiSiklus={OpsiSiklus}
                    usulan={UsulanMingguan}
                    saatSelesai={() => AturBuat(false)}
                />
            ) : null}
        </TataLetakAplikasi>
    );
}

function FormBuat({
    opsiPeriode,
    opsiSiklus,
    usulan,
    saatSelesai,
}: {
    opsiPeriode: PropsDaftarRekapGaji['OpsiPeriode'];
    opsiSiklus: PropsDaftarRekapGaji['OpsiSiklus'];
    usulan: PropsDaftarRekapGaji['UsulanMingguan'];
    saatSelesai: () => void;
}) {
    const awalBulanan = opsiPeriode[0]?.Nilai ?? '';
    const formulir = useForm<{ Siklus: SiklusGaji; Periode: string }>(
        awalBulanan === ''
            ? { Siklus: 'Mingguan', Periode: usulan.Usulan }
            : { Siklus: 'Bulanan', Periode: awalBulanan },
    );
    const mingguan = formulir.data.Siklus === 'Mingguan';

    const GantiSiklus = (nilai: string) => {
        const berikut: SiklusGaji = nilai === 'Mingguan' ? 'Mingguan' : 'Bulanan';
        formulir.setData({ Siklus: berikut, Periode: berikut === 'Mingguan' ? usulan.Usulan : awalBulanan });
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Buat rekap gaji"
            keterangan={
                mingguan
                    ? 'Draf berisi karyawan aktif bergaji mingguan dan yang mendapat komisi di rentang tujuh hari itu.'
                    : 'Draf berisi karyawan aktif bergaji bulanan dan yang mendapat komisi di bulan itu.'
            }
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangPilihan
                    label="Siklus gaji"
                    nilai={formulir.data.Siklus}
                    opsi={opsiSiklus}
                    saatBerubah={GantiSiklus}
                    galat={formulir.errors.Siklus}
                    required
                />
                {mingguan ? (
                    <div className="flex flex-col gap-1">
                        <PemilihTanggal
                            label="Mulai tanggal"
                            nilai={formulir.data.Periode}
                            saatBerubah={(nilai) => formulir.setData('Periode', nilai)}
                            galat={formulir.errors.Periode}
                            required
                        />
                        <p className="text-keterangan text-teks-sekunder">
                            Rekap mencakup tujuh hari sejak tanggal ini. Usulan: {usulan.LabelUsulan}, sehari setelah
                            rekap mingguan terakhir.
                        </p>
                    </div>
                ) : (
                    <BidangPilihan
                        label="Periode"
                        nilai={formulir.data.Periode}
                        opsi={opsiPeriode}
                        saatBerubah={(nilai) => formulir.setData('Periode', nilai)}
                        galat={formulir.errors.Periode}
                        required
                    />
                )}
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Buat draf
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
