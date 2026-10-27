import { Link, router } from '@inertiajs/react';

import { AlamatPerintahKerja, BuatKolomPerintahKerja } from '@/Komponen/Bengkel/KolomPerintahKerja';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { LabelAksiStatusBengkel, type BarisDaftarPerintahKerja, type PropsDaftarPerintahKerja } from '@/Tipe/Bengkel';

/**
 * Perintah kerja bengkel (§9.10, SLS-08): kendaraan yang sedang ditangani dari keluhan sampai ditagih di kasir.
 * Sparepart baru memotong stok saat perintah kerja ditagih; harga estimasi selalu dari daftar harga server.
 */
export default function HalamanDaftarPerintahKerja({
    PerintahKerja,
    OpsiStatus,
    OpsiOutlet,
    OpsiMekanik,
}: PropsDaftarPerintahKerja) {
    const UbahStatus = (p: BarisDaftarPerintahKerja, status: string) =>
        router.post(`${AlamatPerintahKerja}/${p.Uuid}/status`, { Status: status }, { preserveScroll: true });
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal masuk', jenis: 'rentangTanggal' },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Perhatian',
            label: 'Perlu perhatian',
            jenis: 'pilihanBanyak',
            opsi: [
                { nilai: 'ServisJatuhTempo', label: 'Servis berkala jatuh tempo' },
                { nilai: 'MenungguLama', label: 'Menunggu persetujuan lebih dari sehari' },
            ],
        },
        ...(OpsiMekanik.length > 0
            ? [
                  {
                      id: 'Mekanik',
                      label: 'Mekanik',
                      jenis: 'pilihan' as const,
                      opsi: OpsiMekanik.map((m) => ({ nilai: m.Uuid, label: m.Nama })),
                  },
              ]
            : []),
        ...(OpsiOutlet.length > 1
            ? [
                  {
                      id: 'Outlet',
                      label: 'Outlet',
                      jenis: 'pilihan' as const,
                      opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
                  },
              ]
            : []),
    ];

    return (
        <TataLetakAplikasi judul="Perintah kerja bengkel">
            <AksiHalaman
                keterangan="Catat keluhan, susun estimasi jasa & sparepart, minta persetujuan pelanggan lewat WhatsApp, lalu tagih di kasir."
                utama={
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPerintahKerja}/buat`}>Buat perintah kerja</Link>
                    </Button>
                }
                lainnya={[{ label: 'Kendaraan pelanggan', href: '/kelola/bengkel/kendaraan' }]}
            />
            <TabelData
                id="bengkel-perintah-kerja"
                label="Daftar perintah kerja"
                kolom={BuatKolomPerintahKerja()}
                sumber={{ mode: 'server', alamat: AlamatPerintahKerja, awal: PerintahKerja }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-DibuatPada"
                cari="Cari nomor perintah kerja atau nomor polisi"
                saring={saring}
                alamatDetail={(p) => `${AlamatPerintahKerja}/${p.Uuid}`}
                aksiBaris={(p: BarisDaftarPerintahKerja) =>
                    p.TujuanStatus.filter((s) => s !== 'Dibatalkan' && s !== 'Diagnosis').length === 0 ? null : (
                        <>
                            {p.TujuanStatus.filter((s) => s !== 'Dibatalkan' && s !== 'Diagnosis').map((s) => (
                                <DropdownMenuItem key={s} onSelect={() => UbahStatus(p, s)}>
                                    {LabelAksiStatusBengkel[s] ?? s}
                                </DropdownMenuItem>
                            ))}
                        </>
                    )
                }
                kosong={{ ilustrasi: true, judul: 'Belum ada perintah kerja.' }}
            />
        </TataLetakAplikasi>
    );
}
