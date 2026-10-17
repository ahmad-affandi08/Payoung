import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import {
    AmbilHasilCari,
    KerangkaPemilihProduk,
    useNilaiTertunda,
    usePilihSetelahPindai,
    useSorotPertama,
} from '@/Komponen/Katalog/PemilihProduk';
import { CommandItem } from '@/Komponen/Ui/command';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { BarisLabelServer, HasilCariLabel } from '@/Tipe/Katalog';

const batas = 20;

/**
 * PRD-10: tambah barang ke daftar label dengan mencari nama, SKU, atau barcode persis (pemindai USB mengetik langsung
 * ke kotak ini). Satu hasil = satu satuan produk, jadi botol dan dus dipilih terpisah.
 */
export default function PencarianBarisLabel({ saatPilih }: { saatPilih: (baris: BarisLabelServer) => void }) {
    const [kata, AturKata] = useState('');
    const [terbuka, AturTerbuka] = useState(false);
    const [sorot, AturSorot] = useState('');
    const kataCari = useNilaiTertunda(kata.trim(), 300);
    const kueri = useQuery({
        queryKey: KunciKueri.Produk.CariLabel(kataCari),
        queryFn: ({ signal }) =>
            AmbilHasilCari<HasilCariLabel>(
                `/kelola/produk/label/cari?${new URLSearchParams({ kata: kataCari, batas: String(batas) }).toString()}`,
                signal,
            ),
        enabled: terbuka,
        staleTime: 30_000,
        placeholderData: keepPreviousData,
    });
    const hasil = kueri.data?.Data ?? [];

    useSorotPertama(
        hasil.map((baris) => baris.Uuid),
        AturSorot,
    );

    const Buka = (buka: boolean) => {
        AturTerbuka(buka);
        if (!buka) {
            AturKata('');
        }
    };

    const Pilih = (baris: BarisLabelServer) => {
        saatPilih(baris);
        Buka(false);
    };
    const SaatPindai = usePilihSetelahPindai({
        kataCari,
        hasil,
        siap: !kueri.isFetching && !kueri.isPlaceholderData,
        Pilih,
        AturKata,
        AturTerbuka,
    });

    let status: string | null = null;

    if (terbuka && kueri.isPending) {
        status = 'Mencari barang…';
    } else if (terbuka && kueri.isError) {
        status = 'Pencarian gagal. Periksa koneksi lalu ketik ulang.';
    } else if (terbuka && hasil.length === 0) {
        status = kataCari === '' ? 'Belum ada barang.' : `Tidak ada barang yang cocok dengan "${kataCari}".`;
    } else if (terbuka && kataCari === '' && hasil.length >= batas) {
        status = `Menampilkan ${String(batas)} barang pertama. Ketik atau pindai untuk mencari yang lain.`;
    }

    return (
        <KerangkaPemilihProduk
            label="Tambah barang"
            placeholder="Cari nama, SKU, atau pindai barcode"
            kata={kata}
            saatKata={AturKata}
            terbuka={terbuka}
            saatTerbuka={Buka}
            sorot={sorot}
            saatSorot={AturSorot}
            status={status}
            saatPindai={SaatPindai}
        >
            {hasil.map((baris) => (
                <CommandItem
                    key={baris.Uuid}
                    value={baris.Uuid}
                    data-slot="pilihan-cari-item"
                    data-nilai={baris.Uuid}
                    onSelect={() => {
                        saatPilih(baris);
                        Buka(false);
                    }}
                    className="flex min-h-9 cursor-pointer flex-col items-start gap-0 rounded-kontrol px-3 py-2 text-isi data-[selected=true]:bg-brand-lembut data-[selected=true]:text-teks-utama pointer-coarse:min-h-11"
                >
                    <span className="font-semibold break-words text-teks-utama">
                        {baris.Nama} ({baris.SimbolSatuan})
                    </span>
                    <span className="text-keterangan text-teks-sekunder">
                        {[baris.Sku ?? 'Tanpa SKU', baris.Barcode[0] ?? 'Belum punya barcode'].join(' | ')}
                    </span>
                </CommandItem>
            ))}
        </KerangkaPemilihProduk>
    );
}
