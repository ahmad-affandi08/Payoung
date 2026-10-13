import { Link } from '@inertiajs/react';
import { useState } from 'react';

import DialogKendaraan, { AlamatKendaraan } from '@/Komponen/Bengkel/DialogKendaraan';
import { AlamatPerintahKerja } from '@/Komponen/Bengkel/KolomPerintahKerja';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { JudulUnit, KataUnit, PakaiJenisUnitBawaan } from '@/Pustaka/UnitServis';
import type { Kendaraan } from '@/Tipe/Bengkel';

const kolom: KolomTabel<Kendaraan>[] = [
    {
        id: 'NomorPolisi',
        accessorKey: 'NomorPolisi',
        header: 'Nomor polisi / IMEI',
        meta: { label: 'Nomor polisi / IMEI', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: k } }) => (
            <Link href={`${AlamatKendaraan}/${k.Uuid}`} className="font-mono font-semibold text-brand underline">
                {JudulUnit(k)}
            </Link>
        ),
    },
    {
        id: 'Label',
        accessorKey: 'Label',
        header: 'Unit',
        meta: { label: 'Unit', prioritas: 'penting' },
        cell: ({ row: { original: k } }) => {
            const rincian =
                k.Jenis === 'Perangkat' ? `${k.Label}${k.NomorPolisi ? ` | ${k.NomorPolisi}` : ''}` : k.Label;

            return k.Aktif ? rincian : `${rincian} (diarsipkan)`;
        },
    },
    {
        id: 'ServisTerakhirPada',
        header: 'Servis terakhir',
        enableSorting: false,
        meta: { label: 'Servis terakhir', prioritas: 'rendah' },
        cell: ({ row: { original: k } }) =>
            k.ServisTerakhirPada === null ? 'Belum pernah' : FormatTanggal(k.ServisTerakhirPada.slice(0, 10)),
    },
];

/**
 * Kendaraan pelanggan di halaman detail pelanggan (§9.10, hanya untuk pemegang izin `bengkel.kelola`): daftar
 * kendaraannya, tambah kendaraan, dan pintasan membuat perintah kerja.
 */
export default function PanelKendaraanPelanggan({
    pelanggan,
    kendaraan,
}: {
    pelanggan: { Uuid: string; Nama: string };
    kendaraan: Kendaraan[];
}) {
    const [tambah, AturTambah] = useState(false);
    const pertama = kendaraan.find((k) => k.Aktif);
    const bawaan = PakaiJenisUnitBawaan();
    const kata = bawaan === null ? 'Unit servis' : KataUnit(bawaan);

    return (
        <Panel
            judul={kata}
            aksi={
                <div className="flex flex-wrap gap-2">
                    <Tombol varian="sekunder" onClick={() => AturTambah(true)}>
                        Tambah {kata.toLowerCase()}
                    </Tombol>
                    {pertama ? (
                        <Link
                            href={`${AlamatPerintahKerja}/buat?kendaraan=${pertama.Uuid}`}
                            className="self-center text-brand underline"
                        >
                            Buat perintah kerja
                        </Link>
                    ) : null}
                </div>
            }
        >
            <TabelData
                id="pelanggan-kendaraan"
                label={`${kata} ${pelanggan.Nama}`}
                kolom={kolom}
                sumber={{ mode: 'lokal', data: kendaraan }}
                ambilIdBaris={(k) => k.Uuid}
                kosong={{ judul: `Pelanggan ini belum punya ${kata.toLowerCase()} terdaftar.` }}
            />
            {tambah ? (
                <DialogKendaraan kendaraan={null} pelanggan={pelanggan} saatTutup={() => AturTambah(false)} />
            ) : null}
        </Panel>
    );
}
