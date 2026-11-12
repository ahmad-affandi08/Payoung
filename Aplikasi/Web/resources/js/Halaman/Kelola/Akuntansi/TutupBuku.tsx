import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisPeriodeAkuntansi, BarisTahunBuku, PropsTutupBuku } from '@/Tipe/Akuntansi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

const alamat = '/kelola/akuntansi/tutup-buku';

/** Status periode untuk kolom & saringan. */
export function AmbilStatusPeriode(p: BarisPeriodeAkuntansi): {
    nilai: string;
    teks: string;
    jenis: 'sukses' | 'peringatan' | 'netral';
} {
    if (p.Terkunci) {
        return { nilai: 'Terkunci', teks: 'Terkunci', jenis: 'sukses' };
    }
    if (p.Berjalan) {
        return { nilai: 'Berjalan', teks: 'Berjalan', jenis: 'netral' };
    }
    return p.ShiftBelumDitutup > 0
        ? { nilai: 'Terbuka', teks: `${p.ShiftBelumDitutup} shift belum ditutup`, jenis: 'peringatan' }
        : { nilai: 'Terbuka', teks: 'Terbuka, siap dikunci', jenis: 'netral' };
}

/** Status tahun buku: ditutup, siap ditutup (12 bulan terkunci), atau belum semua bulan terkunci. */
export function AmbilStatusTahun(t: BarisTahunBuku): { teks: string; jenis: 'sukses' | 'peringatan' | 'netral' } {
    if (t.Ditutup) {
        return { teks: 'Ditutup', jenis: 'sukses' };
    }
    return t.BulanTerkunci === 12
        ? { teks: 'Siap ditutup', jenis: 'netral' }
        : { teks: `${t.BulanTerkunci} dari 12 bulan terkunci`, jenis: 'peringatan' };
}

const kolomTahun: KolomTabel<BarisTahunBuku>[] = [
    {
        id: 'Tahun',
        accessorKey: 'Tahun',
        header: 'Tahun buku',
        meta: { label: 'Tahun buku', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama font-semibold' },
    },
    {
        id: 'Status',
        accessorFn: (t) => AmbilStatusTahun(t).teks,
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => {
            const status = AmbilStatusTahun(row.original);
            return <LabelStatus jenis={status.jenis} teks={status.teks} />;
        },
    },
    {
        id: 'NomorJurnal',
        accessorKey: 'NomorJurnal',
        header: 'Jurnal penutup',
        meta: { label: 'Jurnal penutup', prioritas: 'rendah' },
        cell: ({ row }) =>
            row.original.UuidJurnal !== null ? (
                <Link
                    href={`/kelola/akuntansi/jurnal/${row.original.UuidJurnal}`}
                    className="font-semibold text-teks-utama underline-offset-2 hover:underline"
                >
                    {row.original.NomorJurnal}
                </Link>
            ) : (
                '—'
            ),
    },
    {
        id: 'DitutupPada',
        accessorKey: 'DitutupPada',
        header: 'Ditutup',
        meta: { label: 'Ditutup', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) =>
            row.original.Ditutup
                ? `${FormatTanggalWaktu(row.original.DitutupPada)}${row.original.DitutupOleh ? ` | ${row.original.DitutupOleh}` : ''}`
                : '—',
    },
];

const kolom: KolomTabel<BarisPeriodeAkuntansi>[] = [
    {
        id: 'Label',
        accessorKey: 'Label',
        header: 'Periode',
        meta: { label: 'Periode', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama font-semibold' },
    },
    {
        id: 'Status',
        accessorFn: (p) => AmbilStatusPeriode(p).nilai,
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => {
            const status = AmbilStatusPeriode(row.original);
            return <LabelStatus jenis={status.jenis} teks={status.teks} />;
        },
    },
    {
        id: 'DikunciPada',
        accessorKey: 'DikunciPada',
        header: 'Dikunci',
        meta: { label: 'Dikunci', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) =>
            row.original.Terkunci
                ? `${FormatTanggalWaktu(row.original.DikunciPada)}${row.original.DikunciOleh ? ` | ${row.original.DikunciOleh}` : ''}`
                : '—',
    },
];

/**
 * Tutup buku (F-15): kunci periode yang sudah lewat agar transaksi back-office tidak lagi masuk ke bulan yang sudah
 * dilaporkan. Transaksi kasir offline yang terlambat tetap diterima dan dibukukan di periode terbuka berikutnya
 * (§18). Buka kunci wajib alasan dan tercatat di log audit.
 */
export default function HalamanTutupBuku({ Periode, Tahun, Izin }: PropsTutupBuku) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [kunci, AturKunci] = useState<BarisPeriodeAkuntansi | null>(null);
    const [buka, AturBuka] = useState<BarisPeriodeAkuntansi | null>(null);
    const [tutupTahun, AturTutupTahun] = useState<BarisTahunBuku | null>(null);
    const [memproses, AturMemproses] = useState(false);

    const Kunci = () => {
        if (kunci === null) {
            return;
        }
        router.post(
            `${alamat}/${kunci.Periode}/kunci`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturKunci(null),
                onError: () => AturKunci(null),
            },
        );
    };

    const TutupTahun = () => {
        if (tutupTahun === null) {
            return;
        }
        router.post(
            `${alamat}/tahun/${tutupTahun.Tahun}/tutup`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturTutupTahun(null),
                onError: () => AturTutupTahun(null),
            },
        );
    };

    return (
        <TataLetakAplikasi judul="Tutup buku">
            <DaftarGalatServer galat={props.errors} kecuali={['Alasan']} />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Kunci periode setelah laporan bulan itu selesai. Transaksi back-office di periode terkunci ditolak.
            </p>
            <TabelData
                id="kelola-akuntansi-tutup-buku"
                label="Daftar periode akuntansi"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Periode }}
                ambilIdBaris={(p) => p.Periode}
                labelBaris={(p) => `periode ${p.Label}`}
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Terkunci', label: 'Terkunci' },
                            { nilai: 'Terbuka', label: 'Terbuka' },
                            { nilai: 'Berjalan', label: 'Berjalan' },
                        ],
                    },
                ]}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (p: BarisPeriodeAkuntansi) =>
                              p.Terkunci ? (
                                  p.TahunDitutup ? null : (
                                      <DropdownMenuItem onSelect={() => AturBuka(p)}>
                                          Buka kunci periode
                                      </DropdownMenuItem>
                                  )
                              ) : p.Berjalan ? null : (
                                  <DropdownMenuItem onSelect={() => AturKunci(p)}>Kunci periode</DropdownMenuItem>
                              ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada periode.' }}
            />
            <h2 className="mt-4 text-subjudul font-semibold text-teks-utama">Tutup tahun</h2>
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Setelah 12 bulan terkunci, tutup tahun memindahkan laba atau rugi ke Laba Ditahan. Kunci bulan tidak
                bisa dibuka lagi.
            </p>
            <TabelData
                id="kelola-akuntansi-tutup-tahun"
                label="Daftar tahun buku"
                kolom={kolomTahun}
                sumber={{ mode: 'lokal', data: Tahun }}
                ambilIdBaris={(t) => String(t.Tahun)}
                labelBaris={(t) => `tahun ${t.Tahun}`}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (t: BarisTahunBuku) =>
                              t.Ditutup || t.BulanTerkunci < 12 ? null : (
                                  <DropdownMenuItem onSelect={() => AturTutupTahun(t)}>Tutup tahun</DropdownMenuItem>
                              ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada tahun buku.' }}
            />
            {tutupTahun !== null ? (
                <DialogKonfirmasi
                    judul={`Tutup tahun buku ${tutupTahun.Tahun}?`}
                    labelAksi="Tutup tahun"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={TutupTahun}
                    saatBatal={() => AturTutupTahun(null)}
                >
                    <p>
                        Saldo pendapatan, HPP, dan beban tahun {tutupTahun.Tahun} dipindahkan ke Laba Ditahan lewat
                        jurnal penutup tanggal 31 Desember {tutupTahun.Tahun}.
                    </p>
                    <p>Kunci bulan-bulan tahun ini tidak bisa dibuka lagi. Koreksi dicatat di tahun berjalan.</p>
                </DialogKonfirmasi>
            ) : null}
            {kunci !== null ? (
                <DialogKonfirmasi
                    judul={`Kunci periode ${kunci.Label}?`}
                    labelAksi="Kunci periode"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={Kunci}
                    saatBatal={() => AturKunci(null)}
                >
                    <p>
                        Jurnal, kas & bank, pembelian, pelunasan, dan mutasi stok bertanggal {kunci.Label} tidak bisa
                        dicatat lagi sampai kuncinya dibuka.
                    </p>
                    {kunci.ShiftBelumDitutup > 0 ? (
                        <p>
                            Masih ada {kunci.ShiftBelumDitutup} shift yang belum ditutup. Tutup shift di aplikasi kasir
                            dulu.
                        </p>
                    ) : null}
                </DialogKonfirmasi>
            ) : null}
            {buka !== null ? <FormBukaKunci periode={buka} saatSelesai={() => AturBuka(null)} /> : null}
        </TataLetakAplikasi>
    );
}

function FormBukaKunci({ periode, saatSelesai }: { periode: BarisPeriodeAkuntansi; saatSelesai: () => void }) {
    const formulir = useForm({ Alasan: '' });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(`${alamat}/${periode.Periode}/buka-kunci`, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <DialogFormulir
            judul={`Buka kunci ${periode.Label}`}
            keterangan="Transaksi bertanggal di periode ini bisa dicatat lagi. Alasan dan nama Anda tercatat di log audit."
            saatTutup={saatSelesai}
        >
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeksPanjang
                    label="Alasan membuka kunci"
                    nilai={formulir.data.Alasan}
                    saatBerubah={(nilai) => formulir.setData('Alasan', nilai)}
                    keterangan="Misal: faktur pemasok September baru diterima."
                    maksimal={500}
                    baris={3}
                    galat={formulir.errors.Alasan}
                    required
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Buka kunci periode
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
