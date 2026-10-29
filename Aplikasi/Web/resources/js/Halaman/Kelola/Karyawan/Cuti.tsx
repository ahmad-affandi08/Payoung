import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { TulisTanggal } from '@/Pustaka/Tanggal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisCuti, JenisCuti, OpsiUuidNama, PropsCuti } from '@/Tipe/Karyawan';

const alamat = '/kelola/karyawan/cuti';

const opsiJenis: { Nilai: JenisCuti; Label: string }[] = [
    { Nilai: 'Cuti', Label: 'Cuti' },
    { Nilai: 'Sakit', Label: 'Sakit' },
    { Nilai: 'Izin', Label: 'Izin' },
];

/** Cuti dan sakit biasanya tetap digaji; izin keperluan pribadi biasanya tidak. Pengelola bisa mengubahnya. */
const berbayarBawaan: Record<JenisCuti, boolean> = { Cuti: true, Sakit: true, Izin: false };

function HariIniLokal(): string {
    return TulisTanggal(new Date());
}

const kolom: KolomTabel<BarisCuti>[] = [
    {
        id: 'Karyawan',
        accessorKey: 'Karyawan',
        header: 'Karyawan',
        enableSorting: false,
        meta: { label: 'Karyawan', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama' },
    },
    {
        id: 'Jenis',
        accessorKey: 'Jenis',
        header: 'Jenis',
        enableSorting: false,
        meta: { label: 'Jenis', prioritas: 'penting' },
    },
    {
        id: 'TanggalMulai',
        accessorKey: 'TanggalMulai',
        header: 'Tanggal',
        meta: { label: 'Tanggal', prioritas: 'penting' },
        cell: ({ row }) =>
            row.original.TanggalMulai === row.original.TanggalSelesai
                ? FormatTanggal(row.original.TanggalMulai)
                : `${FormatTanggal(row.original.TanggalMulai)} s.d. ${FormatTanggal(row.original.TanggalSelesai)}`,
    },
    {
        id: 'Berbayar',
        accessorKey: 'Berbayar',
        header: 'Gaji',
        enableSorting: false,
        meta: { label: 'Gaji', prioritas: 'rendah' },
        cell: ({ row }) => (row.original.Berbayar ? 'Tetap digaji' : 'Tidak digaji'),
    },
    {
        id: 'Status',
        accessorKey: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => (
            <LabelStatus
                jenis={row.original.Status === 'Aktif' ? 'sukses' : 'netral'}
                teks={row.original.LabelStatus}
            />
        ),
    },
    {
        id: 'Alasan',
        accessorKey: 'Alasan',
        header: 'Keterangan',
        enableSorting: false,
        meta: { label: 'Keterangan', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) => row.original.AlasanBatal ?? row.original.Alasan ?? '—',
    },
];

type Dialog = { jenis: 'catat' } | { jenis: 'batal'; cuti: BarisCuti };

/**
 * Cuti, sakit, dan izin karyawan (F-18, EMP-03). Cuti yang tetap digaji tidak dihitung "tidak masuk" di rekap gaji dan
 * menahan pengingat shift serta peringatan belum masuk; yang tidak digaji tetap dipotong sesuai tarif karyawan.
 */
export default function HalamanCuti({ Cuti, OpsiKaryawan, Izin }: PropsCuti) {
    const [dialog, AturDialog] = useState<Dialog | null>(null);
    const Tutup = () => AturDialog(null);
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: [
                { nilai: 'Aktif', label: 'Berlaku' },
                { nilai: 'Dibatalkan', label: 'Dibatalkan' },
            ],
        },
        {
            id: 'Jenis',
            label: 'Jenis',
            jenis: 'pilihanBanyak',
            opsi: opsiJenis.map((j) => ({ nilai: j.Nilai, label: j.Label })),
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
        <TataLetakAplikasi judul="Cuti & izin karyawan">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Catat hari karyawan tidak bekerja. Yang tetap digaji tidak dihitung tidak masuk di rekap gaji, dan
                pengingat shift serta peringatan belum masuk tidak dikirim pada harinya.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman
                    utama={<Tombol onClick={() => AturDialog({ jenis: 'catat' })}>Catat cuti atau izin</Tombol>}
                />
            ) : null}
            <TabelData
                id="karyawan-cuti"
                label="Daftar cuti dan izin"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Cuti }}
                ambilIdBaris={(c) => c.Uuid}
                labelBaris={(c) => `${c.Jenis.toLowerCase()} ${c.Karyawan} ${FormatTanggal(c.TanggalMulai)}`}
                urutBawaan="-TanggalMulai"
                cari="Cari nama karyawan"
                saring={saring}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (c: BarisCuti) =>
                              c.Status === 'Aktif' ? (
                                  <DropdownMenuItem onSelect={() => AturDialog({ jenis: 'batal', cuti: c })}>
                                      Batalkan catatan
                                  </DropdownMenuItem>
                              ) : null,
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada catatan cuti atau izin.' }}
            />
            {dialog?.jenis === 'catat' ? <FormCatat opsiKaryawan={OpsiKaryawan} saatSelesai={Tutup} /> : null}
            {dialog?.jenis === 'batal' ? <FormBatal cuti={dialog.cuti} saatSelesai={Tutup} /> : null}
        </TataLetakAplikasi>
    );
}

function KeOpsi(daftar: OpsiUuidNama[]) {
    return daftar.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }));
}

function FormCatat({ opsiKaryawan, saatSelesai }: { opsiKaryawan: OpsiUuidNama[]; saatSelesai: () => void }) {
    const formulir = useForm<{
        Karyawan: string;
        Jenis: JenisCuti;
        TanggalMulai: string;
        TanggalSelesai: string;
        Berbayar: boolean;
        Alasan: string;
    }>({
        Karyawan: '',
        Jenis: 'Cuti',
        TanggalMulai: HariIniLokal(),
        TanggalSelesai: HariIniLokal(),
        Berbayar: berbayarBawaan.Cuti,
        Alasan: '',
    });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat, { preserveScroll: true, onSuccess: saatSelesai });
    };

    const UbahMulai = (nilai: string) => {
        formulir.setData((lama) => ({
            ...lama,
            TanggalMulai: nilai,
            // Rentang satu hari adalah kasus tersering: tanggal selesai mengikuti sampai pengelola mengubahnya.
            TanggalSelesai:
                lama.TanggalSelesai < nilai || lama.TanggalSelesai === lama.TanggalMulai ? nilai : lama.TanggalSelesai,
        }));
    };

    return (
        <DialogFormulir
            judul="Catat cuti atau izin"
            keterangan="Boleh mencatat hari yang sudah lewat. Satu catatan paling lama 90 hari."
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
                <BidangPilihan
                    label="Jenis"
                    nilai={formulir.data.Jenis}
                    opsi={opsiJenis}
                    saatBerubah={(nilai) =>
                        formulir.setData((lama) => ({
                            ...lama,
                            Jenis: nilai as JenisCuti,
                            Berbayar: berbayarBawaan[nilai as JenisCuti],
                        }))
                    }
                    galat={formulir.errors.Jenis}
                    required
                />
                <PemilihTanggal
                    label="Tanggal mulai"
                    nilai={formulir.data.TanggalMulai}
                    saatBerubah={UbahMulai}
                    galat={formulir.errors.TanggalMulai}
                    required
                />
                <PemilihTanggal
                    label="Tanggal selesai"
                    nilai={formulir.data.TanggalSelesai}
                    saatBerubah={(nilai) => formulir.setData('TanggalSelesai', nilai)}
                    galat={formulir.errors.TanggalSelesai}
                    min={formulir.data.TanggalMulai}
                    required
                />
                <div className="sm:col-span-2">
                    <KotakCentang
                        label="Tetap digaji (tidak dihitung tidak masuk di rekap gaji)"
                        nilai={formulir.data.Berbayar}
                        saatBerubah={(nilai) => formulir.setData('Berbayar', nilai)}
                    />
                </div>
                <div className="sm:col-span-2">
                    <BidangTeks
                        label="Keterangan (opsional)"
                        nilai={formulir.data.Alasan}
                        saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                        galat={formulir.errors.Alasan}
                        maxLength={255}
                    />
                </div>
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan catatan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

function FormBatal({ cuti, saatSelesai }: { cuti: BarisCuti; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${cuti.Uuid}/batal`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Batalkan ${cuti.Jenis.toLowerCase()} ${cuti.Karyawan}`}
            keterangan="Catatan tetap tersimpan sebagai dibatalkan. Hari itu kembali dihitung menurut jadwal dan absensi."
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
                        Batalkan catatan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Kembali
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
