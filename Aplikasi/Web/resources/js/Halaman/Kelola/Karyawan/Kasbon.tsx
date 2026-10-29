import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisKasbon, OpsiUuidNama, PropsKasbon } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/kasbon';

/** Hari ini `TTTT-BB-HH` menurut jam perangkat. */
function HariIniLokal(): string {
    return TulisTanggal(new Date());
}

const jenisStatus = { Aktif: 'peringatan', Lunas: 'sukses', Dibatalkan: 'netral' } as const;

const kolom: KolomTabel<BarisKasbon>[] = [
    {
        id: 'Karyawan',
        accessorKey: 'Karyawan',
        header: 'Karyawan',
        enableSorting: false,
        meta: { label: 'Karyawan', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
    },
    {
        id: 'Tanggal',
        accessorKey: 'Tanggal',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting' },
        cell: ({ row }) => FormatTanggal(row.original.Tanggal),
    },
    {
        id: 'Jumlah',
        accessorKey: 'Jumlah',
        header: 'Jumlah',
        meta: { label: 'Jumlah', prioritas: 'rendah', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Jumlah),
    },
    {
        id: 'Sisa',
        accessorKey: 'Sisa',
        header: 'Sisa',
        meta: { label: 'Sisa', prioritas: 'penting', angka: true },
        cell: ({ row }) => FormatRupiah(row.original.Sisa),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => <LabelStatus jenis={jenisStatus[row.original.Status]} teks={row.original.LabelStatus} />,
    },
    {
        id: 'Keterangan',
        accessorKey: 'Keterangan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => row.original.AlasanBatal ?? row.original.Keterangan ?? '—',
    },
];

type Dialog = { jenis: 'catat' } | { jenis: 'lunasi' | 'batal'; kasbon: BarisKasbon };

/**
 * Kasbon karyawan (F-18 bagian 3, J-18.1): uang yang dipinjamkan dari kas/bank dicatat sebagai piutang karyawan, lalu
 * dilunasi ke kas/bank atau dipotong dari rekap gaji. Kasbon yang belum pernah dilunasi bisa dibatalkan (jurnal
 * dibalik).
 */
export default function HalamanKasbon({ Kasbon, TotalSisa, OpsiKaryawan, OpsiAkunKasBank, Izin }: PropsKasbon) {
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: [
                { nilai: 'Aktif', label: 'Belum lunas' },
                { nilai: 'Lunas', label: 'Lunas' },
                { nilai: 'Dibatalkan', label: 'Dibatalkan' },
            ],
        },
        {
            id: 'Karyawan',
            label: 'Karyawan',
            jenis: 'pilihanBanyak',
            opsi: OpsiKaryawan.map((k) => ({ nilai: k.Uuid, label: k.Nama })),
        },
        { id: 'Tanggal', label: 'Tanggal', jenis: 'rentangTanggal' },
    ];

    return (
        <TataLetakAplikasi judul="Kasbon karyawan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Kasbon belum lunas: <strong className="tabular-nums text-teks-utama">{FormatRupiah(TotalSisa)}</strong>.
                Sisa kasbon bisa dipotong dari rekap gaji.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman utama={<Tombol onClick={() => AturDialog({ jenis: 'catat' })}>Catat kasbon</Tombol>} />
            ) : null}
            <TabelData
                id="karyawan-kasbon"
                label="Daftar kasbon"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Kasbon }}
                ambilIdBaris={(k) => k.Uuid}
                labelBaris={(k) => `kasbon ${k.Karyawan} ${FormatTanggal(k.Tanggal)}`}
                urutBawaan="-Tanggal"
                cari="Cari nama karyawan"
                saring={saring}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (k: BarisKasbon) =>
                              k.Status === 'Aktif' ? (
                                  <>
                                      <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'lunasi', kasbon: k })}>
                                          Catat pelunasan
                                      </DropdownMenuItem>
                                      {k.Sisa === k.Jumlah ? (
                                          <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'batal', kasbon: k })}>
                                              Batalkan kasbon
                                          </DropdownMenuItem>
                                      ) : null}
                                  </>
                              ) : null,
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada kasbon karyawan.' }}
            />
            {dialog?.jenis === 'catat' ? (
                <FormKasbon opsiKaryawan={OpsiKaryawan} opsiAkun={OpsiAkunKasBank} saatSelesai={Tutup} />
            ) : null}
            {dialog?.jenis === 'lunasi' ? (
                <FormPelunasan kasbon={dialog.kasbon} opsiAkun={OpsiAkunKasBank} saatSelesai={Tutup} />
            ) : null}
            {dialog?.jenis === 'batal' ? <FormBatal kasbon={dialog.kasbon} saatSelesai={Tutup} /> : null}
        </TataLetakAplikasi>
    );
}

function KeOpsi(daftar: OpsiUuidNama[]) {
    return daftar.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }));
}

function TombolDialog({ label, memproses, saatBatal }: { label: string; memproses: boolean; saatBatal: () => void }) {
    return (
        <DialogFooter className="sm:col-span-2 sm:justify-start">
            <Tombol type="submit" memproses={memproses}>
                {label}
            </Tombol>
            <Tombol varian="sekunder" onClick={saatBatal}>
                Batal
            </Tombol>
        </DialogFooter>
    );
}

function FormKasbon({
    opsiKaryawan,
    opsiAkun,
    saatSelesai,
}: {
    opsiKaryawan: OpsiUuidNama[];
    opsiAkun: OpsiUuidNama[];
    saatSelesai: () => void;
}) {
    const formulir = useForm({
        Karyawan: '',
        Tanggal: HariIniLokal(),
        Jumlah: '',
        AkunKasBank: opsiAkun[0]?.Uuid ?? '',
        Keterangan: '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul="Catat kasbon"
            keterangan="Uang keluar dari akun kas/bank yang dipilih dan dicatat sebagai piutang karyawan."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangPilihan
                    label="Karyawan"
                    nilai={formulir.data.Karyawan}
                    opsi={KeOpsi(opsiKaryawan)}
                    saatBerubah={(nilai) => formulir.setData('Karyawan', nilai)}
                    galat={formulir.errors.Karyawan}
                    required
                />
                <PemilihTanggal
                    label="Tanggal"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    max={HariIniLokal()}
                    required
                />
                <BidangUang
                    label="Jumlah"
                    nilai={formulir.data.Jumlah}
                    saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                    galat={formulir.errors.Jumlah}
                    required
                />
                <BidangPilihan
                    label="Dibayar dari"
                    nilai={formulir.data.AkunKasBank}
                    opsi={KeOpsi(opsiAkun)}
                    saatBerubah={(nilai) => formulir.setData('AkunKasBank', nilai)}
                    galat={formulir.errors.AkunKasBank}
                    required
                />
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Keterangan (opsional)"
                        nilai={formulir.data.Keterangan}
                        saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                        galat={formulir.errors.Keterangan}
                        maxLength={255}
                    />
                </div>
                <TombolDialog label="Simpan kasbon" memproses={formulir.processing} saatBatal={saatSelesai} />
            </form>
        </DialogFormulir>
    );
}

function FormPelunasan({
    kasbon,
    opsiAkun,
    saatSelesai,
}: {
    kasbon: BarisKasbon;
    opsiAkun: OpsiUuidNama[];
    saatSelesai: () => void;
}) {
    const formulir = useForm({
        Tanggal: HariIniLokal(),
        Jumlah: kasbon.Sisa.replace(/\.00$/, ''),
        AkunKasBank: opsiAkun[0]?.Uuid ?? '',
        Keterangan: '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${kasbon.Uuid}/pelunasan`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Pelunasan kasbon ${kasbon.Karyawan}`}
            keterangan={`Sisa kasbon ${FormatRupiah(kasbon.Sisa)}. Uang yang dikembalikan masuk ke akun kas/bank yang dipilih.`}
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <PemilihTanggal
                    label="Tanggal"
                    nilai={formulir.data.Tanggal}
                    saatBerubah={(nilai) => formulir.setData('Tanggal', nilai)}
                    galat={formulir.errors.Tanggal}
                    max={HariIniLokal()}
                    required
                />
                <BidangUang
                    label="Jumlah dikembalikan"
                    nilai={formulir.data.Jumlah}
                    saatBerubah={(nilai) => formulir.setData('Jumlah', nilai)}
                    galat={formulir.errors.Jumlah}
                    required
                />
                <BidangPilihan
                    label="Diterima di"
                    nilai={formulir.data.AkunKasBank}
                    opsi={KeOpsi(opsiAkun)}
                    saatBerubah={(nilai) => formulir.setData('AkunKasBank', nilai)}
                    galat={formulir.errors.AkunKasBank}
                    required
                />
                <BidangTeks
                    label="Keterangan (opsional)"
                    nilai={formulir.data.Keterangan}
                    saatBerubah={(nilai) => formulir.setData('Keterangan', nilai)}
                    galat={formulir.errors.Keterangan}
                    maxLength={255}
                />
                <TombolDialog label="Simpan pelunasan" memproses={formulir.processing} saatBatal={saatSelesai} />
            </form>
        </DialogFormulir>
    );
}

function FormBatal({ kasbon, saatSelesai }: { kasbon: BarisKasbon; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${kasbon.Uuid}/batal`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Batalkan kasbon ${kasbon.Karyawan}`}
            keterangan="Jurnal kasbon dibalik hari ini. Pastikan uangnya memang tidak jadi dipinjamkan."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan membatalkan"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    maksimal={500}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing}>
                        Batalkan kasbon
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
