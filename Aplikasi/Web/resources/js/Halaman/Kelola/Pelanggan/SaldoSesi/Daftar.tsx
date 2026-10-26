import { Link } from '@inertiajs/react';

import { AlamatSaldoSesi, BuatKolomSaldoSesi } from '@/Komponen/Pelanggan/KolomSaldoSesi';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisSaldoSesi, PropsDaftarSaldoSesi } from '@/Tipe/Pelanggan';

const kolom: KolomTabel<BarisSaldoSesi>[] = [
    ...BuatKolomSaldoSesi<BarisSaldoSesi>().slice(0, 1),
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row: { original: s } }) =>
            s.Pelanggan ? (
                <Link href={`/kelola/pelanggan/${s.Pelanggan.Uuid}`} className="break-words text-brand underline">
                    {s.Pelanggan.Nama}
                </Link>
            ) : (
                'Pelanggan belum dikenal'
            ),
    },
    ...BuatKolomSaldoSesi<BarisSaldoSesi>().slice(1),
];

const saring: DefinisiSaring[] = [
    {
        id: 'Status',
        label: 'Status',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Aktif', label: 'Aktif' },
            { nilai: 'Habis', label: 'Habis dipakai' },
            { nilai: 'Hangus', label: 'Hangus' },
            { nilai: 'Dibatalkan', label: 'Dibatalkan' },
        ],
    },
    {
        id: 'TanpaPelanggan',
        label: 'Pelanggan',
        jenis: 'pilihanBanyak',
        opsi: [
            { nilai: 'Ya', label: 'Belum dikenal' },
            { nilai: 'Tidak', label: 'Sudah dikenal' },
        ],
    },
];

/** F-16d bagian 2: saldo paket sesi semua pelanggan (sisa sesi & nilai diterima dimuka yang belum diakui). */
export default function HalamanDaftarSaldoSesi({ SaldoSesi }: PropsDaftarSaldoSesi) {
    return (
        <TataLetakAplikasi judul="Saldo paket sesi">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Paket sesi yang sudah dibeli pelanggan. Nilai tersisa adalah uang pelanggan yang belum diakui sebagai
                pendapatan. Diakui per sesi saat dipakai di kasir. Buka paket untuk mengembalikan atau menghanguskan
                sisanya.
            </p>
            <TabelData
                id="pelanggan-saldo-sesi"
                label="Daftar saldo paket sesi"
                kolom={kolom}
                sumber={{ mode: 'server', alamat: AlamatSaldoSesi, awal: SaldoSesi }}
                ambilIdBaris={(s) => s.Uuid}
                urutBawaan="-TanggalBeli"
                cari="Cari nama paket atau nomor penjualan"
                saring={saring}
                labelBaris={(s) => `paket ${s.NamaPaket}`}
                kosong={{ ilustrasi: true, judul: 'Belum ada paket sesi yang terjual.' }}
            />
        </TataLetakAplikasi>
    );
}
