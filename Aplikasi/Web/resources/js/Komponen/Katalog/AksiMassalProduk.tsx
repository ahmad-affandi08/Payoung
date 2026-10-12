import { router } from '@inertiajs/react';
import { useState } from 'react';

import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogHargaMassal from '@/Komponen/Katalog/DialogHargaMassal';
import type { KonteksAksiMassal } from '@/Komponen/TabelData/TabelData';
import type { BarisProduk } from '@/Tipe/Katalog';

type AksiProduk =
    'Arsipkan' | 'Pulihkan' | 'Kategori' | 'TampilDiPos' | 'SembunyikanDariPos' | 'Favorit' | 'HapusFavorit';

/** Batas server `UbahProdukMassal::MAKS`. */
export const MaksProdukMassal = 200;

/**
 * Audit kemudahan pakai #19 (F-03): aksi untuk produk terpilih di daftar produk — pindah kategori, tampil/sembunyikan
 * di kasir, tandai/lepas favorit (K-16), arsipkan/pulihkan. Hanya baris yang dicentang (bukan seluruh hasil saringan lintas halaman).
 */
export default function AksiMassalProduk({
    konteks,
    kategori,
    bolehUbahHarga = false,
}: {
    konteks: KonteksAksiMassal<BarisProduk>;
    kategori: { Uuid: string; Jalur: string }[];
    /** Izin `produk.harga.ubah`: menampilkan tombol "Ubah harga…". */
    bolehUbahHarga?: boolean;
}) {
    const [dialogHarga, AturDialogHarga] = useState(false);
    const [uuidKategori, AturUuidKategori] = useState('');
    const [memproses, AturMemproses] = useState<AksiProduk | null>(null);
    const uuid = konteks.terpilih.map((p) => p.Uuid);
    const terlaluBanyak = uuid.length > MaksProdukMassal;

    const Jalankan = (aksi: AksiProduk) =>
        router.post(
            '/kelola/produk/massal',
            { Aksi: aksi, Uuid: uuid, UuidKategori: aksi === 'Kategori' ? uuidKategori : null },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(aksi),
                onFinish: () => AturMemproses(null),
                onSuccess: () => konteks.bersihkan(),
            },
        );
    const nonaktif = memproses !== null || terlaluBanyak;

    return (
        <div className="flex flex-wrap items-end gap-2">
            {terlaluBanyak ? (
                <p className="w-full text-keterangan text-bahaya">
                    Maksimal {MaksProdukMassal} produk sekali proses. Kurangi pilihan.
                </p>
            ) : null}
            <div className="w-56">
                <PilihanCari
                    label="Pindah ke kategori"
                    nilai={uuidKategori}
                    opsi={kategori.map((k) => ({ Nilai: k.Uuid, Label: k.Jalur }))}
                    placeholder="Pilih kategori"
                    saatBerubah={AturUuidKategori}
                    aria-label="Kategori tujuan"
                />
            </div>
            <Tombol
                varian="sekunder"
                disabled={nonaktif || uuidKategori === ''}
                memproses={memproses === 'Kategori'}
                onClick={() => Jalankan('Kategori')}
            >
                Pindahkan
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={nonaktif}
                memproses={memproses === 'TampilDiPos'}
                onClick={() => Jalankan('TampilDiPos')}
            >
                Tampilkan di kasir
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={nonaktif}
                memproses={memproses === 'SembunyikanDariPos'}
                onClick={() => Jalankan('SembunyikanDariPos')}
            >
                Sembunyikan dari kasir
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={nonaktif}
                memproses={memproses === 'Favorit'}
                onClick={() => Jalankan('Favorit')}
            >
                Tandai favorit
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={nonaktif}
                memproses={memproses === 'HapusFavorit'}
                onClick={() => Jalankan('HapusFavorit')}
            >
                Lepas favorit
            </Tombol>
            <Tombol
                varian="sekunder"
                disabled={nonaktif}
                memproses={memproses === 'Pulihkan'}
                onClick={() => Jalankan('Pulihkan')}
            >
                Pulihkan
            </Tombol>
            {bolehUbahHarga ? (
                <Tombol varian="sekunder" disabled={nonaktif} onClick={() => AturDialogHarga(true)}>
                    Ubah harga…
                </Tombol>
            ) : null}
            {dialogHarga ? (
                <DialogHargaMassal
                    uuid={uuid}
                    saatTutup={() => AturDialogHarga(false)}
                    saatSelesai={() => {
                        AturDialogHarga(false);
                        konteks.bersihkan();
                    }}
                />
            ) : null}
            <Tombol
                varian="bahaya"
                disabled={nonaktif}
                memproses={memproses === 'Arsipkan'}
                onClick={() => Jalankan('Arsipkan')}
            >
                Arsipkan
            </Tombol>
        </div>
    );
}
