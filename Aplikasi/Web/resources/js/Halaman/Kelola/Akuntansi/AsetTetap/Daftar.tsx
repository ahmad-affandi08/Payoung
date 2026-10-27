import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisAsetTetap, PropsDaftarAsetTetap, StatusAsetTetap } from '@/Tipe/Akuntansi';

const alamat = '/kelola/akuntansi/aset-tetap';

const jenisStatus: Record<StatusAsetTetap, 'sukses' | 'netral' | 'bahaya'> = {
    Aktif: 'sukses',
    Dilepas: 'netral',
    Dibatalkan: 'bahaya',
};

/** Masa manfaat bulan → "4 tahun", "1 tahun 6 bulan", "Tidak disusutkan". */
export function FormatMasaManfaat(bulan: number): string {
    if (bulan === 0) {
        return 'Tidak disusutkan';
    }

    const tahun = Math.floor(bulan / 12);
    const sisa = bulan % 12;

    return [tahun > 0 ? `${String(tahun)} tahun` : '', sisa > 0 ? `${String(sisa)} bulan` : '']
        .filter(Boolean)
        .join(' ');
}

const kolom: KolomTabel<BarisAsetTetap>[] = [
    {
        id: 'Nomor',
        accessorKey: 'Nomor',
        header: 'Nomor',
        meta: { label: 'Nomor', prioritas: 'penting', kelasSel: 'whitespace-nowrap' },
        cell: ({ row: { original: a } }) => (
            <Link href={`${alamat}/${a.Uuid}`} className="font-mono font-semibold text-brand underline">
                {a.Nomor}
            </Link>
        ),
    },
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Aset',
        meta: { label: 'Aset', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: a } }) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">{a.Nama}</span>
                <span className="text-keterangan text-teks-sekunder">
                    {a.LabelKelompok} | {FormatMasaManfaat(a.UmurBulan)}
                    {a.NamaOutlet ? ` | ${a.NamaOutlet}` : ''}
                </span>
            </span>
        ),
    },
    {
        id: 'TanggalPerolehan',
        accessorKey: 'TanggalPerolehan',
        header: 'Diperoleh',
        meta: { label: 'Tanggal perolehan', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => FormatTanggal(row.original.TanggalPerolehan),
    },
    {
        id: 'HargaPerolehan',
        accessorKey: 'HargaPerolehan',
        header: 'Harga perolehan',
        meta: { label: 'Harga perolehan', angka: true, prioritas: 'penting' },
        cell: ({ row }) => FormatRupiah(row.original.HargaPerolehan),
    },
    {
        id: 'Akumulasi',
        header: 'Akumulasi penyusutan',
        enableSorting: false,
        meta: { label: 'Akumulasi penyusutan', angka: true, prioritas: 'rendah' },
        cell: ({ row }) => FormatRupiah(row.original.Akumulasi),
    },
    {
        id: 'NilaiBuku',
        header: 'Nilai buku',
        enableSorting: false,
        meta: { label: 'Nilai buku', angka: true, prioritas: 'utama' },
        cell: ({ row }) => FormatRupiah(row.original.NilaiBuku),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: a } }) => <LabelStatus jenis={jenisStatus[a.Status]} teks={a.LabelStatus} />,
    },
];

/** Bulan berjalan `TTTT-BB` menurut jam perangkat. */
function BulanIni(): string {
    const d = new Date();

    return `${String(d.getFullYear())}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * Aset tetap & penyusutan (FIN-10): daftar harta berwujud dengan akumulasi penyusutan dan nilai bukunya. Penyusutan
 * garis lurus dijurnal otomatis tiap pagi sampai bulan lalu; "Susutkan sampai bulan ini" untuk tutup bulan lebih awal.
 */
export default function HalamanDaftarAsetTetap({ Aset, OpsiKelompok, OpsiStatus, Izin }: PropsDaftarAsetTetap) {
    const [memproses, AturMemproses] = useState(false);
    const saring: DefinisiSaring[] = [
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((s) => ({ nilai: s.Nilai, label: s.Label })),
        },
        {
            id: 'Kelompok',
            label: 'Kelompok',
            jenis: 'pilihanBanyak',
            opsi: OpsiKelompok.map((k) => ({ nilai: k.Nilai, label: k.Label })),
        },
    ];
    const Susutkan = () => {
        AturMemproses(true);
        router.post(
            `${alamat}/susutkan`,
            { Periode: BulanIni() },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Aset tetap">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Peralatan, kendaraan, dan bangunan usaha. Penyusutan garis lurus dijurnal otomatis setiap awal bulan
                untuk bulan sebelumnya (Dr Beban Penyusutan, Cr Akumulasi Penyusutan). Masa manfaat bawaan mengikuti
                kelompok harta pajak.
            </p>
            {Izin.Kelola ? (
                <AksiHalaman
                    utama={
                        <Button asChild>
                            <Link href={`${alamat}/buat`}>Catat aset</Link>
                        </Button>
                    }
                    lainnya={[
                        {
                            label: memproses ? 'Memproses…' : 'Susutkan sampai bulan ini',
                            saatKlik: Susutkan,
                            nonaktif: memproses,
                        },
                    ]}
                />
            ) : (
                <PesanHanyaLihat izin="akuntansi.kelola" objek="aset tetap" />
            )}
            <TabelData
                id="akuntansi-aset-tetap"
                label="Daftar aset tetap"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Aset }}
                ambilIdBaris={(a) => a.Uuid}
                urutBawaan="-TanggalPerolehan"
                cari="Cari nomor atau nama aset"
                saring={saring}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada aset tetap. Catat peralatan, kendaraan, atau bangunan usaha supaya penyusutannya dijurnal otomatis.',
                }}
            />
        </TataLetakAplikasi>
    );
}
