import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisTutupHarian, PropsTutupHarian } from '@/Tipe/Kasir';

/** Status tanggal bisnis untuk kolom & saringan. */
export function AmbilStatusHari(h: BarisTutupHarian): {
    nilai: string;
    teks: string;
    jenis: 'sukses' | 'peringatan' | 'netral';
} {
    if (h.Ditutup) {
        return { nilai: 'Ditutup', teks: 'Ditutup', jenis: 'sukses' };
    }
    if (h.ShiftBelumDitutup > 0) {
        return { nilai: 'Terbuka', teks: `${h.ShiftBelumDitutup} shift belum ditutup`, jenis: 'peringatan' };
    }
    if (h.Peringatan.length > 0) {
        return { nilai: 'Terbuka', teks: `${h.Peringatan.length} peringatan`, jenis: 'peringatan' };
    }
    return h.Berjalan
        ? { nilai: 'Terbuka', teks: 'Hari berjalan', jenis: 'netral' }
        : { nilai: 'Terbuka', teks: 'Siap ditutup', jenis: 'netral' };
}

const kolom: KolomTabel<BarisTutupHarian>[] = [
    {
        id: 'TanggalBisnis',
        accessorKey: 'TanggalBisnis',
        header: 'Tanggal bisnis',
        meta: { label: 'Tanggal bisnis', prioritas: 'utama', wajib: true, kelasSel: 'text-teks-utama font-semibold' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalBisnis),
    },
    {
        id: 'NamaOutlet',
        accessorKey: 'NamaOutlet',
        header: 'Outlet',
        meta: { label: 'Outlet', prioritas: 'penting' },
    },
    {
        id: 'Status',
        accessorFn: (h) => AmbilStatusHari(h).nilai,
        header: 'Status',
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row }) => {
            const status = AmbilStatusHari(row.original);
            return <LabelStatus jenis={status.jenis} teks={status.teks} />;
        },
    },
    {
        id: 'PenjualanBersih',
        accessorKey: 'PenjualanBersih',
        header: 'Penjualan bersih',
        meta: { label: 'Penjualan bersih', prioritas: 'rendah', angka: true },
        cell: ({ row }) =>
            row.original.PenjualanBersih === null
                ? '—'
                : `${FormatRupiah(row.original.PenjualanBersih)} | ${row.original.JumlahTransaksi ?? 0} trx`,
    },
    {
        id: 'DitutupPada',
        accessorKey: 'DitutupPada',
        header: 'Ditutup',
        meta: { label: 'Ditutup', prioritas: 'rendah', kelasSel: 'text-teks-sekunder' },
        cell: ({ row }) =>
            row.original.Ditutup
                ? `${FormatTanggalWaktu(row.original.DitutupPada)}${
                      row.original.DitutupOtomatis === true
                          ? ' | otomatis'
                          : row.original.DitutupOleh
                            ? ` | ${row.original.DitutupOleh}`
                            : ''
                  }`
                : '—',
    },
];

/**
 * Tutup harian (F-15 End of Day) per outlet: pastikan semua shift tertutup dan kasir sudah sinkron, lalu ringkasan
 * penjualan hari itu dihitung ulang dan disimpan. Peringatan (perangkat belum sinkron, penjualan perlu tinjauan)
 * boleh diabaikan dengan konfirmasi dan tercatat.
 */
export default function HalamanTutupHarian({ Hari, Izin }: PropsTutupHarian) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [pilihan, AturPilihan] = useState<BarisTutupHarian | null>(null);
    const [abaikan, AturAbaikan] = useState(false);
    const [memproses, AturMemproses] = useState(false);

    const Buka = (h: BarisTutupHarian) => {
        AturAbaikan(false);
        AturPilihan(h);
    };

    const Tutup = () => {
        if (pilihan === null) {
            return;
        }
        router.post(
            '/kelola/kasir/tutup-harian',
            { Outlet: pilihan.Outlet, TanggalBisnis: pilihan.TanggalBisnis, AbaikanPeringatan: abaikan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturPilihan(null),
                onError: () => AturPilihan(null),
            },
        );
    };

    const adaPeringatan = (pilihan?.Peringatan.length ?? 0) > 0;
    const bisaDitutup = pilihan !== null && pilihan.ShiftBelumDitutup === 0 && (!adaPeringatan || abaikan);

    return (
        <TataLetakAplikasi judul="Tutup harian">
            <DaftarGalatServer galat={props.errors} />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Tutup hari setelah semua shift ditutup dan semua kasir sudah tersambung internet dan mengirim datanya.
                Ringkasan penjualan hari itu dihitung ulang dari transaksi dan disimpan. Penjualan offline yang baru
                terkirim setelahnya tetap diterima dan ringkasannya ikut diperbarui.
            </p>
            <TabelData
                id="kelola-kasir-tutup-harian"
                label="Daftar tutup harian"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Hari }}
                ambilIdBaris={(h) => h.Kunci}
                labelBaris={(h) => `${h.NamaOutlet} ${FormatTanggal(h.TanggalBisnis)}`}
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Ditutup', label: 'Ditutup' },
                            { nilai: 'Terbuka', label: 'Belum ditutup' },
                        ],
                    },
                ]}
                {...(Izin.Kelola
                    ? {
                          aksiBaris: (h: BarisTutupHarian) =>
                              h.Ditutup ? null : (
                                  <DropdownMenuItem onSelect={() => Buka(h)}>Tutup hari</DropdownMenuItem>
                              ),
                      }
                    : {})}
                kosong={{ ilustrasi: true, judul: 'Belum ada outlet aktif.' }}
            />
            {pilihan !== null ? (
                <DialogKonfirmasi
                    judul={`Tutup hari ${FormatTanggal(pilihan.TanggalBisnis)}?`}
                    labelAksi="Tutup hari"
                    varian="utama"
                    memproses={memproses}
                    nonaktif={!bisaDitutup}
                    saatKonfirmasi={Tutup}
                    saatBatal={() => AturPilihan(null)}
                >
                    <p>{pilihan.NamaOutlet}</p>
                    {pilihan.ShiftBelumDitutup > 0 ? (
                        <p>
                            Masih ada {pilihan.ShiftBelumDitutup} shift yang belum ditutup. Tutup shift di aplikasi
                            kasir dulu.
                        </p>
                    ) : null}
                    {adaPeringatan ? (
                        <>
                            <ul className="list-disc pl-5">
                                {pilihan.Peringatan.map((p) => (
                                    <li key={p.Kode}>{p.Pesan}</li>
                                ))}
                            </ul>
                            <KotakCentang
                                label="Saya sudah memeriksa peringatan ini dan tetap menutup hari"
                                nilai={abaikan}
                                saatBerubah={AturAbaikan}
                            />
                        </>
                    ) : null}
                    {pilihan.ShiftBelumDitutup === 0 && !adaPeringatan ? (
                        <p>Semua shift sudah ditutup dan semua kasir sudah tersambung.</p>
                    ) : null}
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
