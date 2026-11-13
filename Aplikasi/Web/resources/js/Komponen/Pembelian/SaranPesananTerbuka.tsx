import { Link } from '@inertiajs/react';

import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import type { PesananTerbukaDiterima } from '@/Tipe/Pembelian';

const BATAS_TAMPIL = 3;

/** Alamat form terima barang yang terisi dari sisa satu pesanan. */
export function AlamatTerimaDariPesanan(uuidPesanan: string): string {
    return `${AlamatPembelian}/penerimaan/buat?pesanan=${uuidPesanan}`;
}

/** Pesanan terbuka milik satu pemasok (kosong bila pemasok belum dipilih), yang terlambat lebih dulu. */
export function PesananTerbukaPemasok(
    pesanan: PesananTerbukaDiterima[],
    uuidPemasok: string,
): PesananTerbukaDiterima[] {
    if (uuidPemasok === '') {
        return [];
    }

    return pesanan.filter((p) => p.UuidPemasok === uuidPemasok);
}

/**
 * Saran lembut di form penerimaan tanpa pesanan (F-04): bila pemasok yang dipilih masih punya pesanan yang menunggu
 * barang, tawarkan menerima dari pesanan itu supaya baris terisi sendiri dan status pesanan ikut berubah. Tidak
 * memblokir: penerimaan tanpa pesanan tetap sah (beli langsung, pesanan lewat telepon).
 */
export default function SaranPesananTerbuka({
    pesanan,
    uuidPemasok,
}: {
    pesanan: PesananTerbukaDiterima[];
    uuidPemasok: string;
}) {
    const milikPemasok = PesananTerbukaPemasok(pesanan, uuidPemasok);

    if (milikPemasok.length === 0) {
        return null;
    }

    return (
        <Pemberitahuan
            jenis="info"
            judul={
                milikPemasok.length === 1
                    ? 'Pemasok ini punya satu pesanan yang belum diterima'
                    : `Pemasok ini punya ${String(milikPemasok.length)} pesanan yang belum diterima`
            }
        >
            Kalau barang ini bagian dari pesanan, terima dari pesanannya supaya barisnya terisi sendiri dan statusnya
            ikut berubah.
            <span className="mt-1 flex flex-col gap-0.5">
                {milikPemasok.slice(0, BATAS_TAMPIL).map((p) => (
                    <span key={p.Uuid}>
                        <Link href={AlamatTerimaDariPesanan(p.Uuid)}>Terima dari {p.Nomor}</Link> | sisa{' '}
                        {FormatRupiah(p.NilaiSisa)} di {String(p.JumlahBarisSisa)} baris
                    </span>
                ))}
                {milikPemasok.length > BATAS_TAMPIL ? (
                    <span>
                        <Link href={`${AlamatPembelian}/penerimaan/buat`}>Lihat semua pesanan terbuka</Link>
                    </span>
                ) : null}
            </span>
        </Pemberitahuan>
    );
}

/** Petunjuk di form Belanja stok: belanja langsung tidak menaut ke pesanan, jadi arahkan yang punya pesanan. */
export function PetunjukPesananDiBelanja({ jumlah }: { jumlah: number }) {
    if (jumlah === 0) {
        return null;
    }

    return (
        <Pemberitahuan jenis="info" judul={`Ada ${String(jumlah)} pesanan pembelian yang belum diterima`}>
            Belanja stok untuk pembelian langsung tanpa pesanan. Kalau barangnya dari pesanan,{' '}
            <Link href={`${AlamatPembelian}/penerimaan/buat`}>terima dari pesanan</Link> supaya statusnya ikut berubah.
        </Pemberitahuan>
    );
}
