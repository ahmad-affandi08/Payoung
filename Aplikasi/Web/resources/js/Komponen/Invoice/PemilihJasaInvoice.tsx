import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    AmbilHasilCari,
    KerangkaPemilihProduk,
    useNilaiTertunda,
    useSorotPertama,
} from '@/Komponen/Katalog/PemilihProduk';
import { CommandItem } from '@/Komponen/Ui/command';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { HasilCariJasaInvoice } from '@/Tipe/Invoice';

export const AlamatCariJasaInvoice = '/kelola/invoice/produk/cari';

export function BuatUrlCariJasaInvoice(kata: string): string {
    return `${AlamatCariJasaInvoice}?${new URLSearchParams({ kata, batas: '20' }).toString()}`;
}

/**
 * Pemilih jasa/produk tanpa stok untuk baris invoice (F-21): dropdown ber-kotak-cari yang memuat dari server
 * (`/kelola/invoice/produk/cari`). Harga katalog hanya saran yang boleh diubah di baris, karena invoice berisi
 * kesepakatan dengan pelanggan. Barang berstok sengaja tidak muncul: menagihnya lewat surat jalan grosir.
 * Baris bebas (tanpa produk) tetap bisa ditambah lewat tombol terpisah di tabel.
 */
export default function PemilihJasaInvoice({
    saatPilih,
    disabled,
    id,
    alihkanFokusSetelahPilih,
}: {
    saatPilih: (produk: HasilCariJasaInvoice) => void;
    disabled?: boolean;
    /** Entri cepat: id tombol pemicu dan pengalih fokus sesudah memilih (lihat `useEntriCepat`). */
    id?: string;
    alihkanFokusSetelahPilih?: () => boolean;
}) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey: KunciKueri.Invoice.CariJasa(kataCari),
        queryFn: ({ signal }) =>
            AmbilHasilCari<{ Data: HasilCariJasaInvoice[] }>(BuatUrlCariJasaInvoice(kataCari), signal),
        enabled: terbuka,
        staleTime: 0,
        placeholderData: keepPreviousData,
    });
    const hasil = kueri.data?.Data ?? [];

    useSorotPertama(
        hasil.map((p) => p.Uuid),
        AturSorot,
    );

    const Buka = (buka: boolean) => {
        AturTerbuka(buka);

        if (!buka) {
            AturKata('');
        }
    };

    let status: string | null = null;

    if (terbuka && kueri.isPending) {
        status = 'Memuat jasa…';
    } else if (terbuka && kueri.isError) {
        status = 'Pencarian gagal. Periksa koneksi lalu ketik ulang.';
    } else if (terbuka && hasil.length === 0) {
        status =
            kataCari === ''
                ? 'Belum ada jasa atau produk tanpa stok di Produk. Tambahkan di sana, atau ketik barisnya sendiri.'
                : `Tidak ada jasa yang cocok dengan "${kataCari}".`;
    }

    return (
        <KerangkaPemilihProduk
            label="Tambah dari daftar jasa"
            placeholder="Pilih jasa atau produk tanpa stok"
            disabled={disabled}
            kata={kata}
            saatKata={AturKata}
            terbuka={terbuka}
            saatTerbuka={Buka}
            sorot={sorot}
            saatSorot={AturSorot}
            status={status}
            id={id}
            alihkanFokusTutup={alihkanFokusSetelahPilih}
        >
            {hasil.map((p) => (
                <CommandItem
                    key={p.Uuid}
                    value={p.Uuid}
                    onSelect={() => {
                        saatPilih(p);
                        Buka(false);
                    }}
                    className="min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-2 py-1.5 text-isi text-teks-utama data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                >
                    <span className="break-words">{p.Nama}</span>
                    <span className="text-keterangan text-teks-sekunder">
                        {p.Sku ? `${p.Sku} | ` : ''}
                        {p.Harga === null ? 'Tanpa harga katalog' : FormatRupiah(p.Harga)}
                        {p.SimbolSatuan ? ` / ${p.SimbolSatuan}` : ''}
                    </span>
                </CommandItem>
            ))}
        </KerangkaPemilihProduk>
    );
}
