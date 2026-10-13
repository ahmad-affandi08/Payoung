import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import {
    BuatSaringPembelian,
    HalamanDaftarPembelian,
    KolomNomor,
    KolomPemasok,
    KolomStatus,
    KolomTanggal,
    KolomUang,
    TombolBuat,
} from '@/Komponen/Pembelian/DaftarPembelian';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Button } from '@/Komponen/Ui/button';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import type { BarisDaftarPesanan, PropsDaftarPesanan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/pesanan`;

const kolom: KolomTabel<BarisDaftarPesanan>[] = [
    KolomNomor(alamat),
    KolomTanggal(),
    KolomPemasok(),
    {
        id: 'Lokasi',
        header: 'Lokasi tujuan',
        enableSorting: false,
        meta: { label: 'Lokasi tujuan', prioritas: 'rendah' },
        cell: ({ row: { original: p } }) => (
            <span className="flex flex-col break-words">
                <span>{p.NamaGudang}</span>
                {p.NamaOutlet ? <span className="text-keterangan text-teks-sekunder">{p.NamaOutlet}</span> : null}
            </span>
        ),
    },
    {
        id: 'PerkiraanTiba',
        header: 'Perkiraan tiba',
        enableSorting: false,
        meta: { label: 'Perkiraan tiba', prioritas: 'rendah', kelasSel: 'whitespace-nowrap' },
        cell: ({ row }) => (row.original.PerkiraanTiba ? FormatTanggal(row.original.PerkiraanTiba) : '—'),
    },
    KolomStatus(),
    KolomUang('Total', 'Total', (p) => p.Total, true),
];

/** F-04 fase 1: daftar pesanan pembelian (PO) dengan saring status, pemasok, tanggal. */
export default function HalamanDaftarPesanan({ Pesanan, OpsiStatus, OpsiPemasok, Izin }: PropsDaftarPesanan) {
    const [menyiapkan, AturMenyiapkan] = useState(false);
    // D-23 D: draf PO untuk stok di bawah minimum (juga dijalankan otomatis tiap pagi).
    const SiapkanDraf = () =>
        router.post(
            `${alamat}/draf-otomatis`,
            {},
            { onStart: () => AturMenyiapkan(true), onFinish: () => AturMenyiapkan(false) },
        );
    const tombol = (
        <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                <Link href={`${AlamatPembelian}/permintaan`}>Permintaan pembelian</Link>
            </Button>
            {Izin.Kelola ? (
                <Tombol varian="sekunder" onClick={SiapkanDraf} memproses={menyiapkan}>
                    Siapkan draf dari stok menipis
                </Tombol>
            ) : null}
            <TombolBuat href={`${alamat}/buat`} label="Buat pesanan pembelian" izin={Izin} />
        </div>
    );

    return (
        <HalamanDaftarPembelian
            judul="Pesanan pembelian"
            keterangan="Pesan barang ke pemasok. Pesanan di atas batas persetujuan menunggu persetujuan pemilik sebelum barang bisa diterima."
            izin={Izin}
            objek="pesanan pembelian"
        >
            <AksiHalaman>{tombol}</AksiHalaman>
            <TabelData
                id="pembelian-pesanan"
                label="Daftar pesanan pembelian"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Pesanan }}
                ambilIdBaris={(p) => p.Uuid}
                urutBawaan="-Tanggal"
                cari="Cari nomor pesanan atau catatan"
                saring={BuatSaringPembelian(OpsiStatus, OpsiPemasok)}
                alamatDetail={(p) => `${alamat}/${p.Uuid}`}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada pesanan pembelian.',
                }}
            />
        </HalamanDaftarPembelian>
    );
}
